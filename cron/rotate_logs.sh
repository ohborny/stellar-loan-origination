#!/bin/bash
#
# rotate_logs.sh
#
# dkirkendall, 2015-10.
#
# Predates the company adopting logrotate. When logrotate did get rolled
# out (2018, as part of a platform standardization effort) this host was
# missed, because the batch host was not in the config-management
# inventory -- it had been built by hand in 2015 and never onboarded.
# So this script is still what rotates the LoanApp batch logs.
#
# There is a logrotate config at /etc/logrotate.d/loanapp on the WEB
# host. It rotates a path that only exists on the batch host. It has
# therefore rotated nothing, ever, and also never errored loudly enough
# for anyone to look at it.
#
# Called from cron/run_nightly.sh, not from cron directly -- the crontab
# entry was commented out in 2016 and never restored. See
# cron/crontab.legacy.

# no set -e here either, for the same reason as run_nightly.sh: a
# missing log dir on a fresh checkout used to abort the whole nightly.

LOG_DIR="$(cd "$(dirname "$0")/../data/logs" 2>/dev/null && pwd)"
ARCHIVE_DIR="$LOG_DIR/archive"
KEEP_DAYS=14
STAMP="$(date +%Y%m%d)"

# ---------------------------------------------------------------------
# SAFETY NOTE (raised in code review, 2016-11, never actioned)
#
# The reviewer's comment, verbatim from the ticket:
#
#   "every rm and find in here interpolates $LOG_DIR unguarded. if that
#    cd fails, LOG_DIR is empty, and `rm -f $LOG_DIR/*.gz` becomes
#    `rm -f /*.gz`. use ${LOG_DIR:?} so the shell aborts instead. this
#    is a one-character-per-line change."
#
# The ${VAR:?} guard was never added. What actually protects this script
# today is (a) the early exit below and (b) the fact that every path is
# scoped under the repo's own data/logs. Both of those are accidents of
# how it happens to be deployed, not a guarantee. If someone runs this
# with a different LOG_DIR, or edits the early exit out, the reviewer's
# scenario is live again.
# ---------------------------------------------------------------------
if [ -z "$LOG_DIR" ] || [ ! -d "$LOG_DIR" ]; then
    echo "rotate_logs.sh: log dir not found, nothing to do"
    exit 0
fi

mkdir -p "$ARCHIVE_DIR"

echo "rotate_logs.sh: rotating in $LOG_DIR (keep ${KEEP_DAYS}d)"

# ---------------------------------------------------------------------
# Roll the active logs.
#
# Note this globs *.log, but nightly_reconcile.pl also spools
# reconcile_report_*.txt into the same directory and those are never
# rotated. There are several thousand of them.
# ---------------------------------------------------------------------
for f in "$LOG_DIR"/*.log; do
    [ -f "$f" ] || continue
    base="$(basename "$f")"
    target="$ARCHIVE_DIR/${base%.log}.$STAMP.log"

    if [ -f "$target" ]; then
        # Second run on the same day appends rather than clobbering.
        # This is why the 2:15 duplicate cron entry (crontab.legacy)
        # produces archive files with two copies of the same night.
        cat "$f" >> "$target"
    else
        cp "$f" "$target"
    fi

    # Truncate in place rather than moving, so the running Perl jobs
    # keep their open file handles pointed somewhere real.
    : > "$f"

    gzip -f "$target" 2>/dev/null
done

# ---------------------------------------------------------------------
# Expire old archives.
#
# OFF BY ONE: KEEP_DAYS is 14 and the intent (per the 2015 comment that
# used to be here) was "keep two weeks". `find -mtime +$KEEP_DAYS`
# matches files strictly older than 14*24h, so this actually keeps 15
# days of archives, not 14. Harmless, but it is the reason the retention
# numbers in docs/RUNBOOK.md never match what is on disk.
#
# The rm below is the line the 2016 reviewer flagged. Kept in the
# flagged form on purpose so the review comment above still makes sense.
# ---------------------------------------------------------------------
find "$LOG_DIR/archive" -maxdepth 1 -type f -name '*.gz' -mtime +$KEEP_DAYS -print 2>/dev/null | while read -r old; do
    echo "rotate_logs.sh: expiring $old"
    rm -f "$old"
done

# Same pattern for stray uncompressed archives left behind when gzip
# failed (full disk, mostly). Also unguarded.
find "$LOG_DIR/archive" -maxdepth 1 -type f -name '*.log' -mtime +$KEEP_DAYS -exec rm -f {} \; 2>/dev/null

# The spooled reconciliation reports. Deliberately NOT expired -- jchen
# asked for them to be kept "until someone actually reads one". Nobody
# has. -- dkirkendall 2017
# find "$LOG_DIR" -maxdepth 1 -name 'reconcile_report_*.txt' -mtime +$KEEP_DAYS -exec rm -f {} \;

echo "rotate_logs.sh: done"
exit 0
