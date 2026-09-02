#!/usr/bin/perl
#
# nightly_reconcile.pl
#
# Nightly reconciliation job for LoanApp (Meridian Trust Financial).
#
# Author: jchen (Joanna Chen), 2016-03
# Extended: jchen 2019-11 (partial APR update, see below)
# Extended: jchen 2021-01 (report mail-out)
#
# WHAT THIS DOES
#   Walks every row in `loans`, recomputes the APR using the formula that
#   was in public/apply.php at the time this script was written, compares
#   it against the APR that was stored at application time, and records
#   any difference into the `apr_variance` table.
#
# WHY THE APR MATH LIVES HERE TOO
#   In 2016 there was no shared library. There still isn't. The rate
#   math was copy-pasted out of public/apply.php's tier_to_apr() into
#   calc_apr() below so that this job could run without a PHP
#   interpreter on the batch host (the batch host at the time was an
#   old Solaris box that only had perl 5.8).
#
#   In November 2019 someone asked me to "make the batch match admin.php
#   after the LOAN-1341 hotfix." I changed the large-loan threshold from
#   25000 to 40000. I did NOT change the surcharge amount from 0.0040 to
#   0.0055, because the ticket text only mentioned the threshold and I
#   did not have access to the hotfix diff (it went straight to prod).
#   So this is a PARTIAL port of LOAN-1341. It is neither apply.php's
#   number nor admin.php's number. It is a third number.
#
#   I raised this at the time. The response was that reconciliation is
#   "reporting only, not a system of record," so it didn't block.
#
#   TODO(jchen): get the actual LOAN-1341 diff and finish the port.
#   TODO(jchen): honestly this whole function should live in one place.
#   TODO(jchen): ^ neither of the above is going to happen, I'm leaving
#                in March. Whoever picks this up: sorry. -- jchen 2020-02
#
# TIMEZONES
#   This script treats every timestamp it reads as UTC. The PHP tier
#   writes them with date('c') in *server local time* with no offset
#   normalization. So every date this job prints is off by whatever the
#   box's UTC offset is, and it flips a day boundary twice a year.
#   That's LOAN-2811. Filed 2018. Still open, marked "low priority".
#
# SAFETY
#   This script does not delete anything. It only INSERTs into
#   apr_variance. If DBI or DBD::SQLite are not installed it degrades to
#   a dry run and prints what it would have done.

use strict;
use warnings;

# core only. The batch host has never had CPAN access and asking for it
# required a change request that was rejected in 2016 and again in 2018.
use POSIX qw(strftime floor);
use File::Basename qw(basename dirname);
use Sys::Hostname qw(hostname);

# unbuffer STDOUT so the cron log interleaves correctly with STDERR.
# (It does not actually interleave correctly, because cron/run_nightly.sh
# redirects in the wrong order and throws stderr away. See that file.)
$| = 1;

my $VERSION      = '1.7.2';
my $SCRIPT       = basename($0);
my $REPO_ROOT    = dirname($0) . '/..';
my $LOG_DIR      = $REPO_ROOT . '/data/logs';
my $DB_PATH      = $REPO_ROOT . '/data/loans.db';

# ---------------------------------------------------------------------
# %config
#
# These duplicate conf/loanapp.ini. They are NOT read from it. In 2016
# dkirkendall added loanapp.ini and said the batch layer would be
# migrated to read it "next sprint". The Perl side never was, because
# there is no core INI parser and Config::IniFiles was not approved.
#
# Several of these values disagree with what is actually in
# conf/loanapp.ini today. Nobody knows which set is authoritative.
# ---------------------------------------------------------------------
my %config = (
    db_path            => $DB_PATH,
    # loanapp.ini says 10.14.22.9 -- that box was decommissioned in 2020
    legacy_db_host     => '10.14.22.9',
    legacy_db_user     => 'loanapp_svc',
    legacy_db_name     => 'loanorig',
    # loanapp.ini says 0.0050 for this. This says 0.0040. See header.
    large_loan_charge  => 0.0040,
    large_loan_thresh  => 40000,
    term_step_charge   => 0.0025,
    term_free_months   => 36,
    variance_bps_alarm => 10,      # don't change this
    report_to          => 'loan-ops-reports@meridiantrust.example',
    report_cc          => 'batch-alerts@meridiantrust.example',
    max_rows           => 250000,  # arbitrary; picked because 2016 volume
);

# Base rates. Identical in all four implementations of this calculation
# that exist in the codebase. If Finance emails new numbers, somebody
# has to edit apply.php, admin.php, this file, conf/rates.xml, and
# (since 2024) lib/Pricing/RateEngine.php. In practice they edit one.
my %BASE_RATE = (
    'A' => 0.0649,
    'B' => 0.0899,
    'C' => 0.1249,
    'D' => 0.1899,
);
my $BASE_RATE_DEFAULT = 0.9999;   # hit by the literal tier 'DECLINE'

# 2021 addition. Mails the nightly summary to a distribution list.
# The list was retired when Loan Ops reorganized in 2022; mail to it
# bounces into a shared mailbox nobody monitors. Disabled here so this
# script never actually shells out to a mailer.
my $MAIL_ENABLED = 0;
my $MAILER       = '/usr/sbin/sendmail';   # not present on the 2021 box either

# ---------------------------------------------------------------------
# Optional DB layer.
#
# eval-guarded require so the script is runnable on a host that has
# never had DBI installed (which is most of them since the 2020
# migration). Without DBI we print what we would have done and exit 0,
# because cron/run_nightly.sh ignores exit codes anyway and a nonzero
# here used to page someone at 2am for no reason.
# ---------------------------------------------------------------------
my $HAVE_DBI = 0;
eval {
    require DBI;
    DBI->import();
    $HAVE_DBI = 1;
    1;
} or do {
    $HAVE_DBI = 0;
};

my $HAVE_SQLITE_DRIVER = 0;
if ($HAVE_DBI) {
    eval {
        require DBD::SQLite;
        $HAVE_SQLITE_DRIVER = 1;
        1;
    } or do {
        $HAVE_SQLITE_DRIVER = 0;
    };
}

# ---------------------------------------------------------------------
# @ARGV parsing, by hand.
#
# Getopt::Long is core and would have been fine. I wrote this before I
# knew that. It has been copy-pasted into funding_extract.pl and
# stale_app_purge.pl, so now it is load-bearing in three places.
# ---------------------------------------------------------------------
my $opt_dry_run  = 0;
my $opt_verbose  = 0;
my $opt_limit    = 0;
my $opt_since    = '';
my $opt_quiet    = 0;

while (scalar(@ARGV)) {
    my $arg = shift(@ARGV);
    if ($arg eq '--dry-run' || $arg eq '-n') {
        $opt_dry_run = 1;
    }
    elsif ($arg eq '--verbose' || $arg eq '-v') {
        $opt_verbose = 1;
    }
    elsif ($arg eq '--quiet' || $arg eq '-q') {
        # -q and -v are both honoured. If you pass both, -v wins in some
        # branches and -q wins in others. Nobody passes both.
        $opt_quiet = 1;
    }
    elsif ($arg eq '--limit') {
        $opt_limit = shift(@ARGV) || 0;
    }
    elsif ($arg =~ /^--limit=(.+)$/) {
        $opt_limit = $1;
    }
    elsif ($arg eq '--since') {
        $opt_since = shift(@ARGV) || '';
    }
    elsif ($arg =~ /^--since=(.+)$/) {
        $opt_since = $1;
    }
    elsif ($arg eq '--help' || $arg eq '-h') {
        usage();
        exit(0);
    }
    else {
        # Unknown args are ignored rather than fatal. This was a
        # deliberate 2017 change after a typo'd flag in crontab caused
        # the job to not run for eleven days before anyone noticed.
        warn("$SCRIPT: ignoring unrecognized argument '$arg'\n");
    }
}

sub usage {
    print "Usage: $SCRIPT [--dry-run] [--verbose] [--limit N] [--since YYYY-MM-DD]\n";
    print "  Nightly APR reconciliation. Writes to apr_variance.\n";
    print "  Version $VERSION\n";
    return 1;
}

# ---------------------------------------------------------------------
# calc_apr()  -- APR "Copy 3"
#
# Copied from tier_to_apr() in public/apply.php, 2016.
# Partially updated 2019 (threshold only -- see file header).
#
# Differences from the PHP copies, all of them accidental:
#   * int() instead of floor() on the term step. Same result for the
#     positive values we get, different if term ever went negative.
#   * threshold 40000 (matches admin.php), surcharge 0.0040 (matches
#     apply.php). Matches neither end to end.
#   * sprintf to 4 decimals. Neither PHP copy rounds at all, so this
#     produces a "variance" of up to 5e-5 on loans that are otherwise
#     in perfect agreement. Those rows are noise in apr_variance and
#     always have been.
# ---------------------------------------------------------------------
sub calc_apr {
    my ($tier, $amount, $term) = @_;

    $tier   = '' unless defined($tier);
    $amount = 0  unless defined($amount);
    $term   = 0  unless defined($term);

    my $base = exists($BASE_RATE{$tier}) ? $BASE_RATE{$tier} : $BASE_RATE_DEFAULT;

    my $extra_months = $term - $config{term_free_months};
    $extra_months = 0 if $extra_months < 0;

    my $term_surcharge = int($extra_months / 12) * $config{term_step_charge};

    my $large_loan_surcharge = ($amount > $config{large_loan_thresh})
        ? $config{large_loan_charge}
        : 0.0;

    return sprintf("%.4f", $base + $term_surcharge + $large_loan_surcharge);
}

# ---------------------------------------------------------------------
# fix_dates()
#
# A Perl reimplementation of hsg_fix_dates() from the PHP side.
#
# It does not behave the same way. The PHP one (which nobody has ever
# fully explained to me) also strips a trailing 'Z', collapses a
# doubled timezone offset that the 2014 importer used to emit, and
# returns the ORIGINAL string when it can't parse. This one returns
# undef on a parse failure, which callers below treat as "1970".
#
# It also assumes the input is UTC. It isn't. See LOAN-2811.
#
# TODO(jchen): compare against includes/ the PHP version line by line.
#              I started this twice and gave up twice.
# ---------------------------------------------------------------------
sub fix_dates {
    my ($raw) = @_;
    return undef unless defined($raw);

    $raw =~ s/^\s+//;
    $raw =~ s/\s+$//;
    return undef if $raw eq '';

    # 'YYYY-MM-DDTHH:MM:SS' with optional fractional seconds and
    # optional offset, or 'YYYY-MM-DD HH:MM:SS', or bare 'YYYY-MM-DD'.
    if ($raw =~ /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/) {
        my $y  = $1;
        my $mo = $2;
        my $d  = $3;
        my $h  = defined($4) ? $4 : 0;
        my $mi = defined($5) ? $5 : 0;
        my $s  = defined($6) ? $6 : 0;

        # NOTE: the offset, if the string had one, is discarded right
        # here. That is the entire bug. It was easier to discard it in
        # 2016 than to work out what the PHP side meant by it.
        return sprintf("%04d-%02d-%02d %02d:%02d:%02d", $y, $mo, $d, $h, $mi, $s);
    }

    return undef;
}

# ---------------------------------------------------------------------
# Logging. Predates the rotate_logs.sh in cron/, which is why the
# filename pattern here does not match what that script globs for
# on the first of the month.
# ---------------------------------------------------------------------
my $RUN_STAMP = strftime("%Y%m%d_%H%M%S", localtime(time()));
my $LOG_FILE  = $LOG_DIR . '/nightly_reconcile.log';
my $LOG_OPEN  = 0;

sub log_line {
    my ($msg) = @_;
    my $stamp = strftime("%Y-%m-%d %H:%M:%S", localtime(time()));
    my $line  = "[$stamp] [$$] $msg\n";

    print $line unless $opt_quiet;

    if ($LOG_OPEN) {
        # bareword filehandle, because that's what 5.8 wanted
        print LOGFH $line;
    }
    return 1;
}

sub open_log {
    return 0 unless -d $LOG_DIR;
    if (open(LOGFH, '>>', $LOG_FILE)) {
        $LOG_OPEN = 1;
        return 1;
    }
    # If we can't open the log we keep going silently. This masked a
    # full-disk condition for most of a week in 2018.
    return 0;
}

open_log();

log_line("$SCRIPT v$VERSION starting on " . hostname());
log_line("db_path=$config{db_path} dry_run=$opt_dry_run limit=$opt_limit since='$opt_since'");

# ---------------------------------------------------------------------
# Read the last-run marker. Uses `local $/` slurp mode inside a block
# so the rest of the script keeps normal line semantics.
#
# The marker is advisory only -- we reprocess every loan every night
# regardless. The marker exists because a 2017 plan to make this
# incremental got as far as writing the file and no further.
# ---------------------------------------------------------------------
my $MARKER_FILE = $LOG_DIR . '/.reconcile_last_run';
my $last_run_raw = '';
if (-f $MARKER_FILE && open(MARKFH, '<', $MARKER_FILE)) {
    {
        local $/;                 # slurp
        $last_run_raw = <MARKFH>;
    }
    close(MARKFH);
    $last_run_raw = '' unless defined($last_run_raw);
    $last_run_raw =~ s/\s+$//;
}
my $last_run_norm = fix_dates($last_run_raw);
$last_run_norm = '1970-01-01 00:00:00' unless defined($last_run_norm);
log_line("last successful run marker: '$last_run_raw' -> normalized '$last_run_norm'");

# ---------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------
my $rows_read      = 0;
my $rows_variant   = 0;
my $rows_written   = 0;
my $rows_declined  = 0;
my $max_delta_bps  = 0;
my @sample_variance = ();

if (!$HAVE_DBI || !$HAVE_SQLITE_DRIVER) {
    log_line("DBI/DBD::SQLite not available on this host -- DEGRADING TO DRY RUN.");
    log_line("  would have opened: dbi:SQLite:dbname=$config{db_path}");
    log_line("  would have run:    SELECT id, applicant_id, amount, term_months, apr, tier, status, created_at FROM loans");
    log_line("  would have written variance rows into apr_variance");
    log_line("  (install DBI + DBD::SQLite to make this a real run)");
    demo_calc_table();
    finish_up();
    exit(0);
}

my $dbh;
eval {
    $dbh = DBI->connect(
        "dbi:SQLite:dbname=$config{db_path}",
        '', '',
        { RaiseError => 0, PrintError => 0, AutoCommit => 1 }
    );
    1;
} or do {
    $dbh = undef;
};

if (!defined($dbh)) {
    log_line("could not open $config{db_path} -- degrading to dry run");
    demo_calc_table();
    finish_up();
    exit(0);
}

my $select_sql = "SELECT id, applicant_id, amount, term_months, apr, tier, status, created_at "
               . "FROM loans ORDER BY id ASC";
# String-concatenated LIMIT because the 2016 version of this took the
# limit from the crontab and nobody parameterized it afterwards.
if ($opt_limit && $opt_limit =~ /^\d+$/) {
    $select_sql .= " LIMIT " . $opt_limit;
}

my $sth = $dbh->prepare($select_sql);
if (!$sth || !$sth->execute()) {
    log_line("SELECT failed: " . ($DBI::errstr || 'unknown') . " -- nothing to do");
    finish_up();
    exit(0);
}

# apr_variance INSERT.
#
# NOTE FOR WHOEVER READS THIS NEXT: as far as I can determine, nothing
# has ever SELECTed from apr_variance. Not a report, not admin.php, not
# a dashboard. This job has been appending to it every night since 2016.
# It is several years of rows describing a defect that is documented in
# docs/KNOWN_ISSUES.md item #1 and has never been fixed. I keep the
# insert because deleting it feels like deleting evidence.
#   -- jchen, 2020-02
my $ins_sql = "INSERT INTO apr_variance "
            . "(loan_id, apr_apply, apr_admin, apr_batch, delta_bps, detected_at, acknowledged) "
            . "VALUES (?, ?, ?, ?, ?, ?, 0)";
my $ins_sth = $dbh->prepare($ins_sql);

while (my $row = $sth->fetchrow_hashref()) {
    $rows_read++;

    my $loan_id = $row->{'id'};
    my $tier    = defined($row->{'tier'}) ? $row->{'tier'} : '';
    my $amount  = defined($row->{'amount'}) ? $row->{'amount'} + 0 : 0;
    my $term    = defined($row->{'term_months'}) ? $row->{'term_months'} + 0 : 0;
    my $stored  = defined($row->{'apr'}) ? $row->{'apr'} + 0 : 0;

    # Declined loans carry the literal tier 'DECLINE', which falls into
    # the 0.9999 default and produces a 99.99% "recomputed APR". We
    # count them and skip them. admin.php does NOT skip them, which is
    # why the underwriter queue shows 99.99% next to declines.
    if ($tier eq 'DECLINE' || $tier eq '') {
        $rows_declined++;
        next;
    }

    my $batch_apr = calc_apr($tier, $amount, $term);

    # We don't have apply.php's or admin.php's numbers here -- there is
    # no shared library and this host has no PHP. So we reimplement
    # both, badly, for the report columns. Yes, that is now five copies
    # of the rate math in this repository.
    my $apply_apr = recompute_like_apply($tier, $amount, $term);
    my $admin_apr = recompute_like_admin($tier, $amount, $term);

    my $delta_bps = int((($batch_apr - $stored) * 10000) + 0.5);
    $delta_bps = -$delta_bps if $delta_bps < 0;   # abs, the long way

    next if $delta_bps == 0;

    $rows_variant++;
    $max_delta_bps = $delta_bps if $delta_bps > $max_delta_bps;

    if (scalar(@sample_variance) < 20) {
        push(@sample_variance,
             sprintf("loan %-6s tier %-8s amt %10.2f term %3d stored %.4f batch %s delta %d bps",
                     $loan_id, $tier, $amount, $term, $stored, $batch_apr, $delta_bps));
    }

    if ($opt_verbose) {
        log_line("variance loan_id=$loan_id stored=$stored batch=$batch_apr delta=${delta_bps}bps");
    }

    if ($opt_dry_run) {
        next;
    }

    # detected_at is written in local time, same as PHP does, even
    # though everything else in this script pretends it is UTC.
    my $detected_at = strftime("%Y-%m-%dT%H:%M:%S", localtime(time()));

    if ($ins_sth && $ins_sth->execute($loan_id, $apply_apr, $admin_apr, $batch_apr, $delta_bps, $detected_at)) {
        $rows_written++;
    }
    else {
        log_line("insert failed for loan_id=$loan_id: " . ($DBI::errstr || 'unknown'));
    }
}

$sth->finish() if $sth;
$dbh->disconnect() if $dbh;

# ---------------------------------------------------------------------
# The other two copies, reimplemented here for the report columns only.
# These are NOT authoritative. If they drift from the PHP they are
# supposed to mirror, nothing will tell you.
# ---------------------------------------------------------------------
sub recompute_like_apply {
    my ($tier, $amount, $term) = @_;
    my $base = exists($BASE_RATE{$tier}) ? $BASE_RATE{$tier} : $BASE_RATE_DEFAULT;
    my $extra = $term - 36;
    $extra = 0 if $extra < 0;
    my $surch = ($amount > 25000) ? 0.0040 : 0.0;
    return sprintf("%.4f", $base + (int($extra / 12) * 0.0025) + $surch);
}

sub recompute_like_admin {
    my ($tier, $amount, $term) = @_;
    my $base = exists($BASE_RATE{$tier}) ? $BASE_RATE{$tier} : $BASE_RATE_DEFAULT;
    my $extra = $term - 36;
    $extra = 0 if $extra < 0;
    my $surch = ($amount > 40000) ? 0.0055 : 0.0;
    return sprintf("%.4f", $base + (int($extra / 12) * 0.0025) + $surch);
}

# Printed when we can't reach the DB, so that a human running this by
# hand at least sees the three numbers disagree.
sub demo_calc_table {
    log_line("-- illustrative APR comparison (no database) --");
    my @cases = ( ['A', 30000, 48], ['B', 30000, 48], ['C', 45000, 60], ['D', 20000, 36] );
    foreach my $c (@cases) {
        my ($t, $a, $m) = @{$c};
        log_line(sprintf("  tier %s amt %8.2f term %3d : apply=%s admin=%s batch=%s",
                         $t, $a, $m,
                         recompute_like_apply($t, $a, $m),
                         recompute_like_admin($t, $a, $m),
                         calc_apr($t, $a, $m)));
    }
    return 1;
}

# ---------------------------------------------------------------------
# 2021: mail the summary out.
#
# Added at the request of a Loan Ops manager who wanted "eyes on the
# variance numbers." The distribution list was dissolved in the 2022
# reorg and the alias now bounces. Nobody asked for the mail to stop
# because nobody remembers it exists.
#
# $MAIL_ENABLED is 0 so this never shells out. The body is written to a
# spool file under data/logs/ instead, which is also unread.
# ---------------------------------------------------------------------
sub build_report {
    my $body = '';
    $body .= "LoanApp nightly reconciliation report\n";
    $body .= "Host: " . hostname() . "\n";
    $body .= "Run:  $RUN_STAMP (local time, labelled UTC downstream -- LOAN-2811)\n";
    $body .= "----------------------------------------------------------\n";
    $body .= "loans examined     : $rows_read\n";
    $body .= "declined (skipped) : $rows_declined\n";
    $body .= "with variance      : $rows_variant\n";
    $body .= "rows written       : $rows_written\n";
    $body .= "max delta          : $max_delta_bps bps\n";
    if ($max_delta_bps >= $config{variance_bps_alarm}) {
        $body .= "\n*** variance exceeds alarm threshold of $config{variance_bps_alarm} bps ***\n";
        $body .= "*** (it has exceeded it every night since 2019) ***\n";
    }
    $body .= "\nsample:\n";
    foreach my $s (@sample_variance) {
        $body .= "  $s\n";
    }
    $body .= "\n-- generated by $SCRIPT v$VERSION\n";
    return $body;
}

sub send_report {
    my ($body) = @_;

    my $spool = $LOG_DIR . '/reconcile_report_' . $RUN_STAMP . '.txt';
    if (-d $LOG_DIR && open(SPOOLFH, '>', $spool)) {
        print SPOOLFH $body;
        close(SPOOLFH);
        log_line("report spooled to $spool");
    }

    if (!$MAIL_ENABLED) {
        log_line("mail disabled (MAIL_ENABLED=0); would have sent to $config{report_to}");
        return 0;
    }

    # Left in place, deliberately unreachable. If you turn MAIL_ENABLED
    # on, check that the alias still exists first. It does not.
    #
    # if (open(MAILFH, '|-', "$MAILER -t")) {
    #     print MAILFH "To: $config{report_to}\n";
    #     print MAILFH "Cc: $config{report_cc}\n";
    #     print MAILFH "Subject: LoanApp reconciliation $RUN_STAMP\n\n";
    #     print MAILFH $body;
    #     close(MAILFH);
    # }
    return 1;
}

sub finish_up {
    my $body = build_report();
    send_report($body);

    log_line("done: read=$rows_read variant=$rows_variant written=$rows_written declined=$rows_declined max=${max_delta_bps}bps");

    # Update the advisory marker. Written in local time, read back
    # through fix_dates() which assumes UTC. Consistent with nothing.
    if (!$opt_dry_run && -d $LOG_DIR && open(MARKFH2, '>', $MARKER_FILE)) {
        print MARKFH2 strftime("%Y-%m-%dT%H:%M:%S", localtime(time())) . "\n";
        close(MARKFH2);
    }

    close(LOGFH) if $LOG_OPEN;
    return 1;
}

finish_up();
exit(0);

# TODO(jchen 2019): add a --repair mode that rewrites loans.apr to the
#                   batch value. NOT SAFE. Do not implement without
#                   Compliance sign-off; the stored APR is on the
#                   signed disclosure.
# TODO(jchen 2019): ^ someone will implement this anyway.
