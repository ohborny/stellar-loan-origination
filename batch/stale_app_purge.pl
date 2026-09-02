#!/usr/bin/perl
#
# stale_app_purge.pl
#
# Purges loan applications that have sat in a non-terminal status for
# longer than the retention period.
#
# Author: jchen (Joanna Chen), 2017-02
# Disabled: mpatel 2020-05 (see below)
#
# =====================================================================
#  THIS SCRIPT IS DISABLED. IT HAS BEEN DISABLED SINCE 2020-05-14.
# =====================================================================
#
# On 2020-05-13 this job deleted 400 applications that were NOT stale.
# That is LOAN-1440.
#
# Root cause, as best it was ever established: the retention comparison
# was done against `created_at`, which the PHP tier writes with
# date('c') in server local time, while this script builds its cutoff
# in UTC (same defect family as LOAN-2811). That was not the real
# problem though. The real problem was that the WHERE clause filtered
# on `status NOT IN ('FUNDED','CLOSED')` and a 2019 change to admin.php
# had started writing the status `IN_REVIEW` for anything an
# underwriter had touched but not finished -- which is exactly the set
# of applications that are most active. 400 in-flight applications,
# some of them with signed disclosures, were deleted.
#
# They were restored from the previous night's dump, minus roughly six
# hours of work. Loan Ops re-keyed what they could.
#
# The remediation was to set $PURGE_ENABLED = 0 and "revisit after the
# post-incident review". The post-incident review happened. The action
# item was "add a dry-run report and a hard row-count ceiling before
# re-enabling". Neither was done. mpatel left in 2022.
#
# Consequence: nothing has been purged since 2020. `applicants` and
# `loans` still hold every abandoned half-filled application from the
# last several years. This is the main reason admin.php's queue query
# is slow (see sql/007_indexes_proposed.sql, also never applied).
#
#   TODO(mpatel): re-enable this once we have a dry-run report.
#                 Temporary. -- 2020-05
#   TODO: ^ 2021, still temporary.
#   // ??? is anyone ever going to turn this back on -- avaldez 2025
#
# SAFETY: $PURGE_ENABLED is 0. The DELETE statements below are prepared
# but the whole branch is unreachable while it stays 0. Do not change
# it. If you think you need to change it, read LOAN-1440 first.

use strict;
use warnings;

use POSIX qw(strftime);
use File::Basename qw(basename dirname);

$| = 1;

# =====================================================================
# THE KILL SWITCH. Leave this at 0.
# =====================================================================
my $PURGE_ENABLED = 0;

my $VERSION   = '1.2.0';
my $SCRIPT    = basename($0);
my $REPO_ROOT = dirname($0) . '/..';
my $LOG_DIR   = $REPO_ROOT . '/data/logs';
my $DB_PATH   = $REPO_ROOT . '/data/loans.db';

# ---------------------------------------------------------------------
# RETENTION PERIOD
#
# There are three sources of truth for this and they disagree.
#
#   1. This constant, set in 2017 from a verbal instruction.
#   2. conf/loanapp.ini, key `purge_retention_days`, which says 180.
#   3. docs/RUNBOOK.md, which says "applications are retained for one
#      year" -- 365.
#
# The compliance answer (per a 2018 email that one person remembers and
# nobody can produce) is supposedly 7 years for anything with a credit
# pull attached, which would mean this job should never have existed in
# the form it exists in.
#
# The code uses (1) unless (2) is present and parseable, and (3) is
# never consulted by anything. Nobody has reconciled them because the
# job doesn't run.
# ---------------------------------------------------------------------
my $RETENTION_DAYS_DEFAULT = 90;
my $INI_FILE               = $REPO_ROOT . '/conf/loanapp.ini';
my $RETENTION_DAYS         = $RETENTION_DAYS_DEFAULT;

# Hand-rolled INI scrape. Config::IniFiles is not core and was never
# approved for the batch host, so this greps for the one key it wants
# and ignores sections entirely -- which means if the key appears under
# two sections (it does, [purge] and [legacy]) the LAST one wins, and
# which one is last depends on who edited the file most recently.
if (-f $INI_FILE && open(INIFH, '<', $INI_FILE)) {
    while (my $line = <INIFH>) {
        chomp($line);
        next if $line =~ /^\s*[;#]/;
        if ($line =~ /^\s*purge_retention_days\s*=\s*(\d+)/) {
            $RETENTION_DAYS = $1;
        }
    }
    close(INIFH);
}

# ---------------------------------------------------------------------
# @ARGV, hand-parsed. Copy-pasted from nightly_reconcile.pl; the
# --since flag was never wired to anything here.
# ---------------------------------------------------------------------
my $opt_dry_run = 1;    # defaults ON here, unlike the other two scripts
my $opt_verbose = 0;
my $opt_days    = 0;
my $opt_force   = 0;

while (scalar(@ARGV)) {
    my $arg = shift(@ARGV);
    if ($arg eq '--dry-run' || $arg eq '-n') { $opt_dry_run = 1; }
    elsif ($arg eq '--commit') { $opt_dry_run = 0; }
    elsif ($arg eq '--verbose' || $arg eq '-v') { $opt_verbose = 1; }
    elsif ($arg eq '--days') { $opt_days = shift(@ARGV) || 0; }
    elsif ($arg eq '--force') {
        # --force predates $PURGE_ENABLED and does not override it.
        # Someone tried in 2021. It does nothing. Deliberately.
        $opt_force = 1;
    }
    elsif ($arg eq '--help' || $arg eq '-h') {
        print "Usage: $SCRIPT [--commit] [--days N] [--verbose]\n";
        print "  DISABLED since 2020-05 (LOAN-1440). Reports only.\n";
        exit(0);
    }
    else { warn("$SCRIPT: ignoring '$arg'\n"); }
}
if ($opt_days && $opt_days =~ /^\d+$/) { $RETENTION_DAYS = $opt_days; }

my $LOG_OPEN = 0;
sub log_line {
    my ($m) = @_;
    my $line = '[' . strftime("%Y-%m-%d %H:%M:%S", localtime(time())) . "] [$$] $m\n";
    print $line;
    print PLOG $line if $LOG_OPEN;
    return 1;
}
if (-d $LOG_DIR && open(PLOG, '>>', $LOG_DIR . '/stale_app_purge.log')) { $LOG_OPEN = 1; }

# Cutoff built in UTC (gmtime). The rows it is compared against were
# written in server local time. This is half of LOAN-1440's root cause
# and it has never been corrected, because correcting it in a disabled
# script felt pointless.
my $cutoff_epoch = time() - ($RETENTION_DAYS * 86400);
my $cutoff_iso   = strftime("%Y-%m-%dT%H:%M:%S", gmtime($cutoff_epoch));

log_line("$SCRIPT v$VERSION starting");
log_line("PURGE_ENABLED=$PURGE_ENABLED  (0 means nothing will be deleted)");
log_line("retention_days=$RETENTION_DAYS (default $RETENTION_DAYS_DEFAULT, ini='$INI_FILE', runbook says 365)");
log_line("cutoff (UTC, mismatched with stored local times): $cutoff_iso");

# Statuses considered non-terminal. THE 2019 ADDITION OF 'IN_REVIEW'
# TO admin.php IS WHY LOAN-1440 HAPPENED -- IN_REVIEW rows are active
# work and this list does not exclude them. Left exactly as it was so
# that the incident is still legible from the code.
my @NON_TERMINAL = ('DRAFT', 'APPROVED', 'DECLINED', 'IN_REVIEW', 'PENDING_DOCS', '');

my $HAVE_DBI = 0;
eval { require DBI; DBI->import(); $HAVE_DBI = 1; 1; } or do { $HAVE_DBI = 0; };
my $HAVE_DRIVER = 0;
if ($HAVE_DBI) {
    eval { require DBD::SQLite; $HAVE_DRIVER = 1; 1; } or do { $HAVE_DRIVER = 0; };
}

my $status_list = join(',', map { "'" . $_ . "'" } @NON_TERMINAL);
my $count_sql = "SELECT COUNT(*) FROM loans WHERE status IN ($status_list) AND created_at < '$cutoff_iso'";

my $candidate_count = 0;

if (!$HAVE_DBI || !$HAVE_DRIVER) {
    log_line("DBI/DBD::SQLite unavailable -- reporting only");
    log_line("  would count with: $count_sql");
}
else {
    my $dbh;
    eval {
        $dbh = DBI->connect("dbi:SQLite:dbname=$DB_PATH", '', '',
                            { RaiseError => 0, PrintError => 0, AutoCommit => 1 });
        1;
    } or do { $dbh = undef; };

    if (!defined($dbh)) {
        log_line("cannot open $DB_PATH -- reporting only");
    }
    else {
        my @r = $dbh->selectrow_array($count_sql);
        $candidate_count = (scalar(@r) && defined($r[0])) ? $r[0] : 0;
        log_line("candidate rows older than cutoff: $candidate_count");

        if ($opt_verbose) {
            my $lst = $dbh->prepare("SELECT id, status, created_at FROM loans WHERE status IN ($status_list) AND created_at < '$cutoff_iso' ORDER BY id ASC LIMIT 25");
            if ($lst && $lst->execute()) {
                while (my $row = $lst->fetchrow_hashref()) {
                    log_line(sprintf("  candidate loan_id=%s status=%s created_at=%s",
                                     $row->{'id'}, $row->{'status'} || '', $row->{'created_at'} || ''));
                }
                $lst->finish();
            }
        }

        # =============================================================
        # THE PURGE BRANCH.
        #
        # Unreachable while $PURGE_ENABLED is 0. The statements are kept
        # verbatim so the incident record makes sense, and so nobody
        # rewrites them from scratch and reintroduces the same bug in a
        # new shape.
        #
        # If this ever gets re-enabled, the outstanding action items
        # from the LOAN-1440 review were:
        #   * exclude IN_REVIEW and anything with a row in documents
        #   * exclude anything with a row in bureau_pulls (credit pull
        #     implies a 7-year retention obligation)
        #   * normalize created_at before comparing (LOAN-2811)
        #   * hard ceiling: abort if candidate_count > 50
        #   * a dry-run report signed off by Loan Ops
        # None of those are implemented below.
        # =============================================================
        if ($PURGE_ENABLED && !$opt_dry_run) {
            log_line("PURGING $candidate_count row(s) -- this should not be happening");

            my $del_loans = $dbh->prepare(
                "DELETE FROM loans WHERE status IN ($status_list) AND created_at < '$cutoff_iso'"
            );
            $del_loans->execute() if $del_loans;

            # Orphan cleanup. There is no foreign key between these two
            # tables (see sql/001_initial_schema.sql), so this second
            # statement is the only thing that keeps applicants from
            # accumulating. It runs AFTER the loans delete, so a failure
            # between the two leaves orphaned applicants forever.
            my $del_appl = $dbh->prepare(
                "DELETE FROM applicants WHERE id NOT IN (SELECT applicant_id FROM loans)"
            );
            $del_appl->execute() if $del_appl;

            log_line("purge complete");
        }
        else {
            log_line("purge branch skipped (PURGE_ENABLED=$PURGE_ENABLED dry_run=$opt_dry_run force=$opt_force)");
        }

        $dbh->disconnect();
    }
}

log_line("would have purged $candidate_count row(s); purged 0");
log_line("stale rows have been accumulating since 2020-05-14");
close(PLOG) if $LOG_OPEN;
exit(0);
