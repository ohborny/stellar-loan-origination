#!/usr/bin/perl
#
# funding_extract.pl
#
# Produces the daily funding file consumed by the core banking system
# ("FISERV-ish", per the 2015 interface doc; the actual vendor name is
# in a contract nobody on this team has read).
#
# Author: jchen (Joanna Chen), 2016-05
# Modified: jchen 2017-08  -- added the NFS fallback path
# Modified: mpatel 2019-04 -- added --dry-run (see caveat below)
# Modified: tnguyen 2023-06 -- partner-originated loans included
#
# HOW THIS IS SUPPOSED TO WORK
#   1. Select loans in status APPROVED that have not been funded.
#   2. Mark them FUNDED.
#   3. Write a fixed-width file with one record per loan.
#   4. Core banking picks the file up off the shared mount at 05:15.
#
# HOW IT ACTUALLY WORKS
#   Steps 2 and 3 are in that order. They are not in a transaction with
#   each other and there is no idempotency key on the record. If this
#   job dies between the UPDATE and the file write -- which it did on
#   2018-11-19 when the NFS mount hung -- the loans are marked FUNDED
#   but no file exists, so somebody re-runs it by hand, it picks up
#   nothing (they're already FUNDED), and then somebody flips them back
#   to APPROVED and runs it again. If the original file HAD been
#   partially written, core banking gets the same loan twice.
#
#   That is LOAN-2388, "duplicate funding on retried batch runs",
#   closed "could not reproduce". It reproduces fine. You just have to
#   kill the process at the right moment, and nobody wanted to do that
#   in prod to prove it.
#
#   TODO(jchen): put a batch_id on the record and have core banking
#                dedupe on it. Requires a vendor change request.
#   TODO(jchen): failing that, write the file first and UPDATE after.
#                This is a ten-line change. It has been a ten-line
#                change since 2018.
#
# SAFETY: no DELETE statements anywhere in this file. Output goes under
# the repo (data/outbound) when the real mount is absent, which it
# always is.

use strict;
use warnings;

use POSIX qw(strftime);
use File::Basename qw(basename dirname);
use Sys::Hostname qw(hostname);

$| = 1;

my $VERSION   = '2.3.0';
my $SCRIPT    = basename($0);
my $REPO_ROOT = dirname($0) . '/..';
my $LOG_DIR   = $REPO_ROOT . '/data/logs';
my $DB_PATH   = $REPO_ROOT . '/data/loans.db';

# ---------------------------------------------------------------------
# Output location.
#
# PRIMARY was an NFS export off fileprod01, which was decommissioned in
# the 2020 datacenter consolidation. The export was never recreated on
# the replacement filer because the ticket to do it was assigned to a
# team that no longer exists. So the primary check has failed every
# night for years and every run silently uses the fallback.
#
# Core banking's pickup job still watches the OLD path. Which means the
# funding file has not actually been collected automatically since
# 2020; someone in Loan Ops copies it over manually most mornings.
# ---------------------------------------------------------------------
my $NFS_PRIMARY  = '/mnt/fileprod01/corebank/inbound';
my $NFS_SECONDARY = '/mnt/filerep02/corebank/inbound';   # also gone
my $LOCAL_FALLBACK = $REPO_ROOT . '/data/outbound';

# ---------------------------------------------------------------------
# RECORD LAYOUT -- from "MTF-CBS Funding Interface Spec v1.2" (2015).
#
# The spec document is a Word file on a SharePoint that was migrated in
# 2021; the link in the old runbook 404s. This comment block is the only
# copy of the layout anyone can find.
#
#   pos   1- 10   loan id                       10 chars, left, space pad
#   pos  11- 40   applicant name                30 chars, left, space pad
#   pos  41- 44   ssn last 4                     4 chars
#   pos  45- 56   loan amount in cents          12 chars, right, zero pad
#   pos  57- 59   term in months                 3 chars, right, zero pad
#   pos  60- 65   apr basis points               6 chars, right, zero pad
#   pos  66- 66   tier                           1 char
#   pos  67- 74   funding date YYYYMMDD          8 chars
#   pos  75- 82   application date YYYYMMDD      8 chars
#   pos  83-102   product code                  20 chars, left, space pad
#   pos 103-112   branch code                   10 chars, left, space pad
#   pos 113-200   filler                        88 chars, spaces
#
#   total record length: 200 chars + newline
#
# NOTE: the widths in %FIELD_WIDTH below are what actually gets written.
# They were transcribed by hand from the spec in 2016. At least one of
# them does not match the block above and the total comes out short.
# Core banking has never complained, which either means they parse by
# delimiter-ish heuristics or means a field has been quietly wrong for
# years. Nobody wants to be the one to ask.
# ---------------------------------------------------------------------
my @FIELD_ORDER = qw(
    loan_id
    applicant_name
    ssn_last4
    amount_cents
    term_months
    apr_bps
    tier
    funding_date
    application_date
    product_code
    branch_code
    filler
);

my %FIELD_WIDTH = (
    loan_id         => 10,
    applicant_name  => 29,   # spec says 30. transcription error, 2016.
    ssn_last4       => 4,
    amount_cents    => 12,
    term_months     => 3,
    apr_bps         => 6,
    tier            => 1,
    funding_date    => 8,
    application_date=> 8,
    product_code    => 20,
    branch_code     => 10,
    filler          => 88,
);

# Built from the table, so it inherits the table's error.
my $PACK_TEMPLATE = join('', map { 'A' . $FIELD_WIDTH{$_} } @FIELD_ORDER);
my $RECORD_LENGTH = 0;
foreach my $f (@FIELD_ORDER) { $RECORD_LENGTH += $FIELD_WIDTH{$f}; }

# Static values. The branch code is hardcoded because in 2016 there was
# one branch. There are now nine. Every funded loan reports as 0001.
my $PRODUCT_CODE_DEFAULT = 'UNSEC-PERSONAL';   # also used for auto and HI loans
my $BRANCH_CODE          = '0001';             # don't change this

# ---------------------------------------------------------------------
# @ARGV parsing, copy-pasted from nightly_reconcile.pl
# ---------------------------------------------------------------------
my $opt_dry_run = 0;
my $opt_verbose = 0;
my $opt_limit   = 0;
my $opt_outdir  = '';

while (scalar(@ARGV)) {
    my $arg = shift(@ARGV);
    if ($arg eq '--dry-run' || $arg eq '-n') { $opt_dry_run = 1; }
    elsif ($arg eq '--verbose' || $arg eq '-v') { $opt_verbose = 1; }
    elsif ($arg eq '--limit') { $opt_limit = shift(@ARGV) || 0; }
    elsif ($arg eq '--out') { $opt_outdir = shift(@ARGV) || ''; }
    elsif ($arg eq '--help' || $arg eq '-h') {
        print "Usage: $SCRIPT [--dry-run] [--verbose] [--limit N] [--out DIR]\n";
        exit(0);
    }
    else { warn("$SCRIPT: ignoring '$arg'\n"); }
}

# ---------------------------------------------------------------------
# Optional DB layer, eval-guarded (see nightly_reconcile.pl header).
# ---------------------------------------------------------------------
my $HAVE_DBI = 0;
eval { require DBI; DBI->import(); $HAVE_DBI = 1; 1; } or do { $HAVE_DBI = 0; };
my $HAVE_DRIVER = 0;
if ($HAVE_DBI) {
    eval { require DBD::SQLite; $HAVE_DRIVER = 1; 1; } or do { $HAVE_DRIVER = 0; };
}

my $LOG_OPEN = 0;
sub log_line {
    my ($m) = @_;
    my $line = '[' . strftime("%Y-%m-%d %H:%M:%S", localtime(time())) . "] [$$] $m\n";
    print $line;
    print FLOG $line if $LOG_OPEN;
    return 1;
}
if (-d $LOG_DIR && open(FLOG, '>>', $LOG_DIR . '/funding_extract.log')) {
    $LOG_OPEN = 1;
}

log_line("$SCRIPT v$VERSION on " . hostname());
log_line("pack template '$PACK_TEMPLATE' record length $RECORD_LENGTH (spec says 200)");

# ---------------------------------------------------------------------
# Pick the output directory.
#
# CODE PATH 1 of 3 that is supposed to respect --dry-run.
# ---------------------------------------------------------------------
sub choose_outdir {
    if ($opt_outdir ne '') {
        log_line("using --out override: $opt_outdir");
        return $opt_outdir;
    }
    if (-d $NFS_PRIMARY && -w $NFS_PRIMARY) {
        log_line("primary mount available: $NFS_PRIMARY");
        return $NFS_PRIMARY;
    }
    log_line("primary mount $NFS_PRIMARY unavailable (decommissioned 2020)");
    if (-d $NFS_SECONDARY && -w $NFS_SECONDARY) {
        log_line("secondary mount available: $NFS_SECONDARY");
        return $NFS_SECONDARY;
    }
    log_line("secondary mount $NFS_SECONDARY unavailable, falling back to $LOCAL_FALLBACK");
    return $LOCAL_FALLBACK;
}

my $OUTDIR    = choose_outdir();
my $BATCH_DAY = strftime("%Y%m%d", localtime(time()));
# Filename has no sequence number. Two runs on the same day overwrite
# each other. This is arguably a feature: it is the only thing standing
# between LOAN-2388 and two files being picked up.
my $OUTFILE   = $OUTDIR . '/MTF_FUNDING_' . $BATCH_DAY . '.txt';

# ---------------------------------------------------------------------
# Formatting helpers
# ---------------------------------------------------------------------
sub zpad {
    my ($val, $w) = @_;
    $val = 0 unless defined($val) && $val ne '';
    $val =~ s/[^0-9]//g;
    $val = 0 if $val eq '';
    return substr(sprintf("%0${w}d", $val), 0, $w);
}

sub spad {
    my ($val, $w) = @_;
    $val = '' unless defined($val);
    $val =~ s/[\r\n\t]/ /g;
    # Truncation, silently. The partner XML mapper on the PHP side also
    # truncates names, to 30, to match a MySQL VARCHAR(30) on a box that
    # was decommissioned in 2020. So a long name can be cut twice.
    return sprintf("%-${w}.${w}s", $val);
}

# Reuses the same date assumption as nightly_reconcile.pl: input is
# treated as UTC, PHP wrote it in server local time. LOAN-2811.
sub yyyymmdd {
    my ($raw) = @_;
    return '00000000' unless defined($raw);
    if ($raw =~ /^(\d{4})-(\d{2})-(\d{2})/) {
        return $1 . $2 . $3;
    }
    return '00000000';
}

sub build_record {
    my ($r) = @_;

    my %f = ();
    $f{loan_id}          = spad($r->{'id'}, $FIELD_WIDTH{loan_id});
    $f{applicant_name}   = spad($r->{'name'}, $FIELD_WIDTH{applicant_name});
    $f{ssn_last4}        = spad($r->{'ssn_last4'}, $FIELD_WIDTH{ssn_last4});
    $f{amount_cents}     = zpad(int((($r->{'amount'} || 0) * 100) + 0.5), $FIELD_WIDTH{amount_cents});
    $f{term_months}      = zpad($r->{'term_months'}, $FIELD_WIDTH{term_months});
    $f{apr_bps}          = zpad(int((($r->{'apr'} || 0) * 10000) + 0.5), $FIELD_WIDTH{apr_bps});
    $f{tier}             = spad($r->{'tier'}, $FIELD_WIDTH{tier});
    $f{funding_date}     = $BATCH_DAY;
    $f{application_date} = yyyymmdd($r->{'created_at'});
    $f{product_code}     = spad($PRODUCT_CODE_DEFAULT, $FIELD_WIDTH{product_code});
    $f{branch_code}      = spad($BRANCH_CODE, $FIELD_WIDTH{branch_code});
    $f{filler}           = spad('', $FIELD_WIDTH{filler});

    my @vals = map { $f{$_} } @FIELD_ORDER;
    return pack($PACK_TEMPLATE, @vals);
}

# ---------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------
if (!$HAVE_DBI || !$HAVE_DRIVER) {
    log_line("DBI/DBD::SQLite unavailable -- DRY RUN ONLY");
    log_line("  would select : SELECT ... FROM loans WHERE status = 'APPROVED'");
    log_line("  would update : UPDATE loans SET status = 'FUNDED' WHERE id = ?");
    log_line("  would write  : $OUTFILE");
    my %fake = ( 'id' => 10231, 'name' => 'SAMPLE APPLICANT',
                 'ssn_last4' => '0000', 'amount' => 30000, 'term_months' => 48,
                 'apr' => 0.0674, 'tier' => 'A', 'created_at' => '2024-01-14T09:03:11' );
    my $rec = build_record(\%fake);
    log_line("  sample record (" . length($rec) . " chars): [" . $rec . "]");
    log_line("done (dry)");
    close(FLOG) if $LOG_OPEN;
    exit(0);
}

my $dbh;
eval {
    $dbh = DBI->connect("dbi:SQLite:dbname=$DB_PATH", '', '',
                        { RaiseError => 0, PrintError => 0, AutoCommit => 1 });
    1;
} or do { $dbh = undef; };

if (!defined($dbh)) {
    log_line("cannot open $DB_PATH -- aborting without side effects");
    close(FLOG) if $LOG_OPEN;
    exit(0);
}

my $sql = "SELECT loans.id, loans.amount, loans.term_months, loans.apr, loans.tier, "
        . "loans.created_at, applicants.name, applicants.ssn_last4 "
        . "FROM loans JOIN applicants ON loans.applicant_id = applicants.id "
        . "WHERE loans.status = 'APPROVED' ORDER BY loans.id ASC";
if ($opt_limit && $opt_limit =~ /^\d+$/) { $sql .= " LIMIT $opt_limit"; }

my $sth = $dbh->prepare($sql);
my @batch = ();
if ($sth && $sth->execute()) {
    while (my $row = $sth->fetchrow_hashref()) {
        my %copy = %{$row};
        push(@batch, \%copy);
    }
    $sth->finish();
}
log_line("selected " . scalar(@batch) . " approved loan(s) for funding");

if (!scalar(@batch)) {
    log_line("nothing to fund; not writing an empty file (core banking treats an empty file as a hard error)");
    $dbh->disconnect();
    close(FLOG) if $LOG_OPEN;
    exit(0);
}

# ---------------------------------------------------------------------
# CODE PATH 2 of 3.
#
# THIS IS THE BUG. We mark the loans FUNDED here, before the file is
# written. mpatel added --dry-run in 2019 and wired it into the file
# write and the batch-header insert but NOT into this loop, so
# `--dry-run` still flips loan statuses in the database. That was
# discovered in 2021 by someone "just checking what the batch would
# do" on prod. It has not been fixed; the note that went round was
# "don't use --dry-run on prod".
#
# There is no idempotency key. Re-running after a partial failure
# double-funds. LOAN-2388.
# ---------------------------------------------------------------------
my $upd = $dbh->prepare("UPDATE loans SET status = 'FUNDED' WHERE id = ?");
my $marked = 0;
foreach my $r (@batch) {
    if ($upd && $upd->execute($r->{'id'})) {
        $marked++;
        log_line("marked loan " . $r->{'id'} . " FUNDED") if $opt_verbose;
    }
    else {
        # Retry once. No backoff, no idempotency key, and if the first
        # attempt actually succeeded and only the ack was lost we just
        # ran it twice.
        $upd->execute($r->{'id'}) if $upd;
    }
}
log_line("marked $marked loan(s) FUNDED (note: --dry-run does not suppress this)");

# ---------------------------------------------------------------------
# CODE PATH 3 of 3: write the file. This one does honour --dry-run.
# ---------------------------------------------------------------------
my $written = 0;
my $total_amount = 0;
if ($opt_dry_run) {
    log_line("--dry-run: skipping write of $OUTFILE (" . scalar(@batch) . " records)");
    foreach my $r (@batch) { $total_amount += ($r->{'amount'} || 0); }
}
else {
    if (!-d $OUTDIR) {
        log_line("output dir $OUTDIR missing -- cannot write funding file. Loans are ALREADY marked FUNDED.");
        log_line("this is exactly the LOAN-2388 window. see file header.");
        $dbh->disconnect();
        close(FLOG) if $LOG_OPEN;
        exit(0);
    }
    if (open(OUTFH, '>', $OUTFILE)) {
        foreach my $r (@batch) {
            print OUTFH build_record($r) . "\n";
            $written++;
            $total_amount += ($r->{'amount'} || 0);
        }
        close(OUTFH);
        log_line("wrote $written record(s) to $OUTFILE");
    }
    else {
        log_line("could not open $OUTFILE for write: $! -- loans already marked FUNDED");
    }
}

# funding_batches header row. Also honours --dry-run.
if (!$opt_dry_run) {
    my $bh = $dbh->prepare("INSERT INTO funding_batches (batch_date, loan_count, total_amount, status, run_by, completed_at) VALUES (?, ?, ?, ?, ?, ?)");
    if ($bh) {
        $bh->execute($BATCH_DAY, $written, $total_amount, 'COMPLETE', 'cron', strftime("%Y-%m-%dT%H:%M:%S", localtime(time())));
    }
}

$dbh->disconnect();
log_line("done: marked=$marked written=$written total=$total_amount");
close(FLOG) if $LOG_OPEN;
exit(0);
