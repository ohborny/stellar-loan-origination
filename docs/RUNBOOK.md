# LoanApp Operations Runbook

**Owner:** dkirkendall (Dave Kirkendall), Application Support
**Created:** 2016-04-11
**Last updated:** 2022-03-30 (see revision log)
**Classification:** Internal

## Revision log

| Date | Who | What |
|---|---|---|
| 2016-04-11 | dkirkendall | first version |
| 2016-09-02 | dkirkendall | added the lockfile note |
| 2019-01-22 | dkirkendall | APPENDED SECTION 9 (funding extract) |
| 2019-08-14 | mpatel | appended section 10, alarms table |
| 2022-03-30 | tnguyen | appended section 11 (partner/DMZ), did not review the rest |

> Sections 1–8 are the original 2016 document. I have not re-verified
> them. Sections 9 onward were appended later and in places contradict
> what is above; where that happens the later section is *probably*
> right, but nobody has actually checked. — tnguyen, 2022

---

## 1. What this system is

LoanApp is the loan origination portal. PHP/Apache, on-prem. Two boxes:

| Host | Role | Notes |
|---|---|---|
| `loanapp-prod01.mtf.internal` | primary web + app | Apache 2.2, mod_php |
| `loanapp-prod02.mtf.internal` | secondary web + app | behind the same VIP |
| `loanapp-batch01.mtf.internal` | Perl batch host | cron lives here |
| `db-mysql-01` (`10.14.22.9`) | database | MySQL 5.5 |

Application root on all web hosts: `/var/www/loanapp`.

## 2. Deploying

There is no CI. Deployment is an rsync from your working copy. Do it from
the jump box, not your laptop, because the app hosts do not accept
connections from the desktop VLAN.

```bash
# 1. get a clean checkout
cd ~/deploy && rm -rf loanapp && svn co svn://svn.mtf.internal/loanapp/trunk loanapp
#    (2019: the repo moved to git. `git clone git@git.mtf.internal:loanapp.git`.
#     Everything below still works.)

# 2. push to both web hosts, in this order, prod02 FIRST
rsync -avz --delete \
      --exclude 'data/' --exclude '.svn' --exclude '.git' \
      ~/deploy/loanapp/ loanapp@loanapp-prod02.mtf.internal:/var/www/loanapp/

rsync -avz --delete \
      --exclude 'data/' --exclude '.svn' --exclude '.git' \
      ~/deploy/loanapp/ loanapp@loanapp-prod01.mtf.internal:/var/www/loanapp/

# 3. push the batch host (batch/ and cron/ only)
rsync -avz ~/deploy/loanapp/batch/ loanapp@loanapp-batch01.mtf.internal:/opt/mtf/loanapp/batch/
rsync -avz ~/deploy/loanapp/cron/  loanapp@loanapp-batch01.mtf.internal:/opt/mtf/loanapp/cron/

# 4. restart Apache on each web host (section 3)
```

**prod02 first.** The VIP drains prod02 last, so pushing prod01 first means
there is a window where the old code is serving. This has bitten us.

**Never rsync `data/`.** The `--exclude 'data/'` is not optional. In 2017
somebody dropped it and overwrote the production SQLite file with a
developer copy. We restored from the 02:00 dump and lost four hours of
applications.

Deployment is not atomic. During the rsync, a request can be served with
half the old files and half the new ones. This is why deploys happen after
19:00.

> // ??? `loanapp-prod02.mtf.internal` does not resolve. The rsync to it
> fails and I have been ignoring the error and continuing with step 2 for
> a year. Ops tells me prod02 was consolidated away in the 2020
> datacenter move and there is only one web host now. If that is right
> then "prod02 first" has been meaningless since 2020 and this section
> has been describing a two-host deploy to a one-host system. Leaving the
> steps as written because I do not know what else the VIP is doing.
> — avaldez, 2025-01

## 3. Restarting Apache

```bash
ssh loanapp@loanapp-prod01.mtf.internal
sudo /etc/init.d/httpd graceful      # preferred
sudo /etc/init.d/httpd restart       # if graceful doesn't pick up the change
```

`graceful` does not reload `php.ini` or opcache. If you changed anything
under `conf/` and it doesn't take effect, do a full `restart`.

If `restart` hangs, it is almost always a wedged child holding a lock on
the SQLite file. `sudo pkill -f httpd` then start it again. Check with
`ps -ef | grep httpd` that nothing is left before starting.

After any restart, hit `http://loanapp.mtf.internal/apply.php` and confirm
you get the form. Do not rely on `cron/healthcheck.sh` to tell you; see the
alarms table in section 10.

## 4. Log locations

| What | Where |
|---|---|
| Apache access/error | `/var/log/httpd/` on each web host |
| Application `error_log()` | same Apache error log — the app has no log of its own |
| Nightly batch | `/opt/mtf/loanapp/data/logs/nightly.log` |
| Reconcile | `/opt/mtf/loanapp/data/logs/nightly_reconcile.log` |
| Funding | `/opt/mtf/loanapp/data/logs/funding_extract.log` |
| Healthcheck status | `/opt/mtf/loanapp/data/logs/healthcheck.status` |

The app logs a lot to the Apache error log, including debug lines that were
left on. `debug = 1` in `conf/loanapp.ini`. Do not turn it off during an
incident; it is the only visibility we have.

## 5. Running the nightly batch by hand

The nightly batch is `cron/run_nightly.sh` and it runs at 02:10. To run it
manually:

```bash
ssh loanapp@loanapp-batch01.mtf.internal
cd /opt/mtf/loanapp

# check nothing is already running
ls -l data/loanapp_nightly.lock
ps -ef | grep -E 'run_nightly|reconcile|funding_extract'

# if a lockfile exists and no process is running, it is stale. remove it.
rm -f data/loanapp_nightly.lock

./cron/run_nightly.sh
tail -f data/logs/nightly.log
```

Stale lockfiles are common because the wrapper does not clean up on a hard
kill and the lockfile now lives under the app root, so it survives reboots.
Check `ps` before deleting; deleting a live lockfile lets a second batch
start on top of the first.

The wrapper exits 0 regardless of what happened inside it. A clean exit
does **not** mean the jobs succeeded. Read the log.

Individual jobs, if you only need one:

```bash
perl batch/nightly_reconcile.pl --verbose            # safe, INSERT only
perl batch/nightly_reconcile.pl --dry-run --verbose  # safer
perl batch/funding_extract.pl                        # NOT safe, see section 9
```

## 6. Restoring the database

Dumps land in `/mnt/backup/loanapp/` at 02:00, 14 days retained.

```bash
mysql -h 10.14.22.9 -u loanapp_svc -p loanorig < /mnt/backup/loanapp/loanorig_YYYYMMDD.sql
```

The password is in the shared password file, and also in
`public/db_config.php`, which is faster to look at.

Nobody has tested a restore since 2016.

## 7. Common support requests

**"An applicant says the rate changed."** It probably did. `apply.php`
shows one number and the underwriter screen shows another. Explain that the
first number was indicative. This is documented in
`docs/KNOWN_ISSUES.md`; it is not something Support can fix.

**"A declined application shows 99.99%."** Expected. Cosmetic.

**"A document upload vanished."** Check whether two uploads went in at the
same second (LOAN-2604). The row is in `documents` with a duplicated
`doc_number`; the file on disk was overwritten by the second one. The first
file is gone. Ask the applicant to re-send it.

**"The funding file didn't arrive."** Section 9.

## 8. Escalation / paging

In hours, then out of hours, in this order:

| Order | Who | Extension | Mobile | Covers |
|---|---|---|---|---|
| 1 | dkirkendall (Dave Kirkendall) | x4417 | (555) 0100 | anything |
| 2 | jchen (Joanna Chen) | x4482 | (555) 0142 | batch, Perl, funding |
| 3 | rwhitfield (Ray Whitfield, HSG) | — | (555) 0177 | original build, `hsg_*` |
| 4 | Ops on-call | x4000 | — | hosts, Apache, network |
| 5 | kmoore (Finance) | x3320 | — | rate questions only |

Ray is a contractor and bills by the hour; get approval from the manager
before calling him.

**If in doubt, call Dave (x4417).** He wrote most of this and knows where
the bodies are buried.

> 2025 note: extensions 4417, 4482 and 4477 do not ring anywhere. The
> phone system was replaced in 2021 and these were not migrated. Dave left
> in 2018, Joanna in 2020, and Halbrook Systems Group was dissolved. I have
> left the table as it was because I do not know what to replace it with.
> Current escalation is: me, and then nobody. — avaldez, 2025-01

## 9. Funding extract failures

*Appended 2019-01-22 (dkirkendall).*

`batch/funding_extract.pl` writes the daily funding file that core banking
picks up off the shared mount at 05:15. If it fails, funding does not go
out that day, and Loan Ops will call you before 07:00.

### 9.1 How you find out

Loan Ops calls. There is no alarm on this job. The log is
`data/logs/funding_extract.log`.

### 9.2 Diagnosis

```bash
ssh loanapp@loanapp-batch01.mtf.internal
cd /opt/mtf/loanapp
tail -100 data/logs/funding_extract.log
ls -l data/outbound/                 # did a file get written at all?
ls -l /mnt/fileprod01/corebank/inbound/   # did it land on the mount?
```

Usual causes, in order of frequency:

1. The NFS mount is not there. `df -h | grep fileprod01`. If the mount is
   missing the script falls back to writing under `data/outbound/` and
   exits 0, so the log looks fine and no file reaches core banking. In that
   case the fix is to copy the file across by hand once the mount is back.
2. DBI/DBD::SQLite missing after a host rebuild. The job degrades to a dry
   run.
3. The job died partway through. This is the one that matters.

### 9.3 Fix: re-run it

```bash
cd /opt/mtf/loanapp
perl batch/funding_extract.pl
ls -l data/outbound/
```

If it comes back with zero loans selected, the loans were already marked
`FUNDED` by the failed run even though no file was produced. Flip them
back and run it again:

```sql
-- find the affected loans (approved-then-funded on the failure date)
SELECT id, status, amount FROM loans
 WHERE status = 'FUNDED' AND date(created_at) <= date('now');

-- put them back
UPDATE loans SET status = 'APPROVED' WHERE id IN (...);
```

then re-run the extract.

This is the standard fix and it works. It has been used maybe a dozen
times since 2018 (the 2018-11-19 NFS hang is the one everybody remembers).

**Caveat, and read this before you do it:** if the failed run *had* already
written part of the file, and Loan Ops had already copied that partial file
across, core banking will receive some of those loans twice. There is no
batch id on the record for them to dedupe on. So before flipping statuses
back, check whether a partial file exists in `data/outbound/` for that date
and whether Loan Ops has touched it.

Separately, there is an open ticket about duplicate funding on retried
runs — LOAN-2388, "duplicate funding on retried batch runs", closed
"could not reproduce". Nobody has been able to make it happen on demand.
If you see a duplicate after following the steps above, note it on that
ticket.

## 10. Known alarms

*Appended 2019-08-14 (mpatel). Reviewed... not since.*

| Alarm | Source | What it means | Action |
|---|---|---|---|
| `LOANAPP_HEALTH_DOWN` | `cron/healthcheck.sh` | app did not return the expected string | **Ignore, known false positive.** Fires continuously. The grep string no longer matches the page. Confirm by hand instead. |
| `LOANAPP_BATCH_LOCK_STALE` | Ops monitoring | lockfile older than 6h | Check `ps`, then remove the lockfile (section 5) |
| `LOANAPP_NIGHTLY_NONZERO` | cron mail | a batch job exited non-zero | **Ignore, known false positive.** The reconcile job exits non-zero whenever DBI is absent, which is every night. |
| `LOANAPP_DISK_DATA` | Ops monitoring | `data/` over 85% | Real. `audit_log` and `apr_variance` grow forever and nothing prunes them. Escalate to Ops for space. |
| `MYSQL_CONN_REFUSED` | app error log | app tried the legacy MySQL host | **Ignore, known false positive.** `FLAG_LEGACY_MYSQL_FALLBACK` is on and the box is gone; the fallback fails slowly and logs. Harmless. |
| `LOANAPP_FUNDING_NOFILE` | Loan Ops, by phone | no funding file | Real. Section 9. |
| `NRPE_STATUS_STALE` | old Nagios | healthcheck status file not updating | **Ignore, known false positive.** Nagios was decommissioned in 2020; the check definition survived the migration. |

Four of the seven entries above are false positives. This has been true
long enough that the on-call rotation treats the whole LoanApp alarm group
as informational, which is a problem in its own right and has been raised
at least twice.

## 11. Partner / DMZ

*Appended 2022-03-30 (tnguyen). Updated in my head several times since;
not on paper.*

Since 2023 the app is also reachable through a reverse proxy in the
partner DMZ. Operationally:

- The proxy is not ours. Network team owns it. Ticket queue `NET-DMZ`.
- Partner traffic hits `partner/endpoint.php` only.
- **Every partner failure returns HTTP 200.** The proxy's monitoring has
  shown 100% success since 2023-07-14 and that number means nothing. If a
  dealer says submissions are failing, believe the dealer, not the
  dashboard.
- The only record of a partner failure is the DMZ box's Apache error log
  and the `partner_submissions` table (`status = 'ERROR'`, `error_text`).
- Rows stuck in `status = 'RECEIVED'` were never picked up. There is no
  sweep job — `batch/partner_sweep.pl` does not exist. Reprocessing is
  manual.

```sql
SELECT id, dealer_code, received_at, status, error_text
  FROM partner_submissions
 WHERE status IN ('RECEIVED','ERROR')
 ORDER BY received_at DESC LIMIT 50;
```

Section 1 of this document says LoanApp is reachable only from the
internal corporate network. That has not been true since 2023. I did not
edit section 1 because it is dkirkendall's document and I did not want to
rewrite history; see `docs/PARTNER_PORTAL.md` for what actually happened.

---

*Original document: dkirkendall, Application Support, 2016-04-11.*
*Appended sections signed as noted. No section has been reviewed since it
was written.*
