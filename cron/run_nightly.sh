#!/bin/bash
#
# run_nightly.sh -- nightly batch wrapper for LoanApp
#
# originally written by dkirkendall, 2015-09
# patched 2016-02 (dkirkendall) - lockfile
# patched 2017-06 (jchen)       - added funding extract
# patched 2019-03 (mpatel)      - removed set -e, see below
# patched 2021-08 (soyelaran)   - stopped echoing the db password
# patched 2023-10 (tnguyen)     - partner submission sweep
#
# This is the only thing cron actually calls. Everything else hangs off
# it. If you change the order of the jobs below, the funding extract can
# run before reconciliation, which nobody has ever tested.
#
# NOTE ON set -e:
#   This used to have `set -e` at the top. It was added in Feb 2019 by
#   someone doing a "hardening pass" and removed three weeks later,
#   because the reconcile job exits non-zero whenever DBI is missing
#   (which is every night on the replacement host) and `set -e` then
#   killed the wrapper before the funding extract ran. Funding did not
#   go out for two business days. So: no set -e. Do not add it back
#   without fixing the exit codes in batch/*.pl first.
#
#   Consequence: every failure below is silently ignored. This wrapper
#   exits 0 no matter what happens. cron therefore never mails anybody.
#
# NOTE ON LOGGING:
#   The redirect on the perl calls is `2>&1 >> $LOGFILE`, which is not
#   what it looks like. It points stderr at the *current* stdout (the
#   terminal / cron's mail pipe) and only then redirects stdout to the
#   log. So stderr never reaches the log file. Every warning and die
#   message from the Perl jobs since 2015 has gone to cron's mail
#   spool, which is not configured, which means it went nowhere.
#   Nobody noticed because the logs look full -- they're full of stdout.

# PATH pinned to the 2015 host layout. /opt/mtf/bin and
# /usr/local/perl5/bin do not exist on the current box; /usr/local/bin
# is what actually resolves perl now. Left as-is because changing PATH
# in this file was blamed (probably wrongly) for a 2017 outage.
PATH=/opt/mtf/bin:/usr/local/perl5/bin:/usr/local/bin:/usr/bin:/bin
export PATH

# umask so the funding file is group-readable for the Loan Ops copy step
umask 002

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_ROOT="$SCRIPT_DIR/.."
BATCH_DIR="$APP_ROOT/batch"
LOG_DIR="$APP_ROOT/data/logs"
LOGFILE="$LOG_DIR/nightly.log"
# The lockfile lived in /tmp until 2016, when tmpwatch on the 2015 host
# started reaping it mid-run and two batches overlapped. Moved under the
# app root, which is also why it now survives reboots -- see below.
LOCKFILE="$APP_ROOT/data/loanapp_nightly.lock"
RUN_DATE="$(date +%Y-%m-%d)"
RUN_STAMP="$(date +%Y%m%d_%H%M%S)"

mkdir -p "$LOG_DIR"

# ---------------------------------------------------------------------
# Environment file.
#
# .env is NOT in the repo and never has been. It lives on the batch host
# and holds the DB path override plus a couple of vendor SFTP creds that
# are no longer used. Nobody knows where the canonical copy is; the one
# on the current host was reconstructed from memory in 2020.
#
# The [ -f ] guard means that when the file is missing (e.g. on any
# developer machine, or after a host rebuild) everything below runs with
# defaults and appears to succeed.
# ---------------------------------------------------------------------
if [ -f "$APP_ROOT/.env" ]; then
    . "$APP_ROOT/.env"
else
    echo "[$RUN_STAMP] .env not found at $APP_ROOT/.env - continuing with defaults" >> "$LOGFILE"
fi

# soyelaran 2021: this used to `echo "DB_PASS=$DB_PASS"` into the log on
# every run. The log is world-readable. Removed. The credential itself
# was not rotated (LOAN-SEC-12, risk-accepted).
# echo "DB_PASS=$DB_PASS" >> "$LOGFILE"

# ---------------------------------------------------------------------
# Lock.
#
# No stale-lock detection. If the box reboots mid-run, or a job is
# killed, the lockfile survives and the nightly batch silently does not
# run until somebody deletes data/loanapp_nightly.lock by hand. This has
# happened at least four times that anyone remembers; the longest gap
# was nine days in early 2022, discovered when Loan Ops asked why the
# funding file was old.
#
# The obvious fix (check the PID in the lockfile, clear it if the
# process is gone) was written up in a ticket that got closed as a
# duplicate of something unrelated.
# ---------------------------------------------------------------------
if [ -f "$LOCKFILE" ]; then
    echo "[$RUN_STAMP] lockfile $LOCKFILE exists, another run in progress? exiting" >> "$LOGFILE"
    exit 0
fi
echo "$$" > "$LOCKFILE"

echo "===================================================" >> "$LOGFILE"
echo "[$RUN_STAMP] nightly batch starting for $RUN_DATE" >> "$LOGFILE"
echo "  host=$(hostname) user=$(whoami) path=$PATH" >> "$LOGFILE"

# ---------------------------------------------------------------------
# Job 1: reconciliation.
#
# Exit code deliberately ignored (see set -e note). We don't even
# capture it.
# ---------------------------------------------------------------------
echo "[$(date +%H:%M:%S)] job 1: nightly_reconcile.pl" >> "$LOGFILE"
perl "$BATCH_DIR/nightly_reconcile.pl" --verbose 2>&1 >> "$LOGFILE"

# ---------------------------------------------------------------------
# Job 2: funding extract.
#
# Runs unconditionally, including on nights when job 1 failed outright.
# jchen argued in 2017 that this should be gated on job 1 succeeding.
# It was not, on the grounds that reconciliation is "reporting only".
# ---------------------------------------------------------------------
echo "[$(date +%H:%M:%S)] job 2: funding_extract.pl" >> "$LOGFILE"
perl "$BATCH_DIR/funding_extract.pl" 2>&1 >> "$LOGFILE"

# ---------------------------------------------------------------------
# Job 3: stale application purge.
#
# Disabled inside the script itself since 2020 (LOAN-1440). It still
# runs every night and still logs "purged 0". Left in the wrapper so
# that if anyone ever re-enables it, the schedule is already there --
# which, given how LOAN-1440 happened, is arguably the wrong call.
# ---------------------------------------------------------------------
echo "[$(date +%H:%M:%S)] job 3: stale_app_purge.pl (disabled internally)" >> "$LOGFILE"
perl "$BATCH_DIR/stale_app_purge.pl" 2>&1 >> "$LOGFILE"

# ---------------------------------------------------------------------
# Job 4: partner submission sweep (tnguyen 2023).
#
# The sweep script was supposed to be batch/partner_sweep.pl. It was
# never written -- the dealer launch shipped without it and the
# reprocessing is done by hand from admin.php. The guard means this is
# a no-op, so nothing draws attention to the gap.
# ---------------------------------------------------------------------
if [ -f "$BATCH_DIR/partner_sweep.pl" ]; then
    echo "[$(date +%H:%M:%S)] job 4: partner_sweep.pl" >> "$LOGFILE"
    perl "$BATCH_DIR/partner_sweep.pl" 2>&1 >> "$LOGFILE"
else
    echo "[$(date +%H:%M:%S)] job 4: partner_sweep.pl not present, skipping" >> "$LOGFILE"
fi

# ---------------------------------------------------------------------
# Log rotation, called from here rather than from cron because in 2016
# the crontab entry for it was "temporarily" commented out and this was
# the workaround. See cron/crontab.legacy.
# ---------------------------------------------------------------------
if [ -x "$SCRIPT_DIR/rotate_logs.sh" ]; then
    "$SCRIPT_DIR/rotate_logs.sh" >> "$LOGFILE" 2>&1
fi

echo "[$(date +%H:%M:%S)] nightly batch finished for $RUN_DATE" >> "$LOGFILE"

rm -f "$LOCKFILE"

touch "$LOG_DIR/.nightly_ok"   # added by dave 2016, don't remove

exit 0
