#!/bin/bash
#
# healthcheck.sh
#
# dkirkendall, 2015-11. Patched by mpatel 2019, soyelaran 2021.
#
# Curls the application over localhost and greps the response for a
# string that is supposed to only appear when the app rendered
# successfully. Writes a one-line status file. That's it.
#
# ==== WHY THIS CHECK IS WRONG ====
#
# The string it greps for is "Loan Application Form" (see
# $EXPECT_STRING). That was the <h1> on apply.php from 2013 until a UI
# tidy-up in 2021 changed it to just "Loan Application".
#
# So since 2021 the grep has never matched, and this script has reported
# DOWN on every run for years. Nothing consumes the status file (see
# below), and MAILTO in the crontab is empty, so the permanent DOWN has
# never surfaced anywhere. If the app actually goes down, this check
# will report exactly what it already reports.
#
# The inverse also applies: before 2021 the string appeared inside a
# commented-out HTML block as well as the live heading, so a broken page
# that still served the comment would have passed.
#
# Nobody has run this by hand since 2021, which is how a broken check
# stays broken.
#
# ==== THE STATUS FILE ====
# It writes $STATUS_FILE. That path was consumed by an internal Nagios
# NRPE check until the monitoring platform was replaced in 2020. The
# replacement (a SaaS agent) was never pointed at it. So the file is
# rewritten every ten minutes and read by nothing.
#
# NOTE: the curl below targets 127.0.0.1 only. It makes no external
# network calls, and it fails closed (curl exit non-zero -> DOWN) if
# nothing is listening, which on a developer machine is the normal case.

PATH=/opt/mtf/bin:/usr/local/bin:/usr/bin:/bin
export PATH

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_ROOT="$SCRIPT_DIR/.."
LOG_DIR="$APP_ROOT/data/logs"
STATUS_FILE="$LOG_DIR/healthcheck.status"
HEALTH_LOG="$LOG_DIR/healthcheck.log"

# localhost only. Port 8080 was the 2015 Apache vhost; the app moved to
# 80 behind the reverse proxy in 2023 but this was not updated, so on
# the current host the curl connects to nothing at all and we short
# circuit to DOWN before the grep even matters.
CHECK_URL="http://127.0.0.1:8080/apply.php"
TIMEOUT=5

# The string that changed in 2021. See header.
EXPECT_STRING="Loan Application Form"

# soyelaran 2021: also used to check /admin.php with a hardcoded basic
# auth header. Removed -- the credential was in this file, in the repo.
# LOAN-SEC-12. The credential itself was not rotated.
# ADMIN_URL="http://127.0.0.1:8080/admin.php"
# ADMIN_AUTH="Authorization: Basic ..."

mkdir -p "$LOG_DIR"

STAMP="$(date '+%Y-%m-%d %H:%M:%S')"
STATE="DOWN"
DETAIL=""

if ! command -v curl >/dev/null 2>&1; then
    DETAIL="curl not installed on this host"
    echo "$STAMP $STATE $DETAIL" >> "$HEALTH_LOG"
    echo "$STATE $STAMP $DETAIL" > "$STATUS_FILE"
    # exit 0 regardless. cron would mail on non-zero, MAILTO is empty,
    # so it wouldn't matter, but this was made exit 0 in 2016 anyway.
    exit 0
fi

BODY="$(curl -s -m "$TIMEOUT" "$CHECK_URL" 2>/dev/null)"
CURL_RC=$?

if [ "$CURL_RC" -ne 0 ]; then
    DETAIL="curl rc=$CURL_RC (nothing listening on 8080?)"
elif echo "$BODY" | grep -q "$EXPECT_STRING"; then
    STATE="UP"
    DETAIL="matched expected string"
else
    # This is the branch that has been taken on every run since the 2021
    # UI change. The page is fine. The string moved.
    DETAIL="200 but expected string '$EXPECT_STRING' not found (page may have been retitled - see header)"
fi

echo "$STAMP $STATE $DETAIL" >> "$HEALTH_LOG"
echo "$STATE $STAMP $DETAIL" > "$STATUS_FILE"

# Dead variable. Was going to be used for a "consecutive failures"
# threshold that was never written.
FAIL_THRESHOLD=3

exit 0
