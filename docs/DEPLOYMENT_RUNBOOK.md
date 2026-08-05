# CA Backend — Deployment & Environment Setup Runbook

Living document. Every step here was hit and fixed during real setup on
2026-08-05 — not theoretical. Update it whenever a new environment gotcha
surfaces.

---

## 1. Core services — install & start order

Run in this order. Some of these have dependencies on the previous step
finishing (e.g. clamd needs freshclam's database first).

```bash
# --- Redis (queue + cache backend) ---
sudo apt install redis-server -y
sudo service redis-server start
redis-cli ping   # expect: PONG

# --- ClamAV (malware scanning) ---
sudo apt install clamav-daemon -y
sudo service clamav-freshclam start
# Wait for first virus DB pull to finish (1–5 min on fresh install) before
# starting clamd — it will not start with an empty/partial database.
sudo tail -f /var/log/clamav/freshclam.log   # watch for "daily.cvd updated", then Ctrl+C
sudo service clamav-daemon start
sudo service clamav-daemon status
ls -la /var/run/clamav/   # expect clamd.ctl socket file to exist

# --- Tesseract (local OCR fallback provider) ---
sudo apt install tesseract-ocr -y
which tesseract

# --- ACL tooling (optional — see §3, likely unusable on this env) ---
sudo apt install acl -y
```

⚠️ On non-systemd environments (WSL, some containers), `sudo systemctl
enable --now <service>` will fail with "System has not been booted with
systemd." Use `sudo service <name> start` instead — it does not persist
across reboots, so these all need re-running after every fresh WSL session
unless wrapped in a startup script (see §6).

---

## 2. `.env` — known failure modes

This file broke twice during setup, silently, in ways that were hard to
spot by eye:

1. **Duplicate keys** — `CLAMAV_ENABLED` appeared twice (`true` then
   `false` further down). Laravel's parser silently takes the *last*
   match — so the app was reading `false` even though the person setting
   it up believed they'd set `true`. **Always grep for duplicates after
   editing:**
```bash
   sort .env | uniq -c -w 30 | awk '$1 > 1'
```
   (Adjust `-w 30` if your longest key name is longer than 30 chars —
   this truncates to just the `KEY=` prefix so it catches duplicate keys
   even with different values.)

2. **Merged lines** — two variables ended up on a single line with no
   newline between them (`CLAMAV_SOCKET=...ctlGOOGLE_CLIENT_ID=...`),
   silently corrupting both. Sanity-check after any manual edit:
```bash
   grep -c "=" .env   # rough count, should be close to line count
   wc -l .env
```
   If these numbers diverge a lot, scan the whole file by eye once.

3. Always run `php artisan config:clear` after any `.env` change — see
   §4 for why this alone is *not* sufficient for a running queue worker.

---

## 3. Filesystem permissions — the `clamav` vs. app-user problem

**Root cause found in this environment:** the `documents` storage disk
(`config/filesystems.php`) resolves to `storage/app/private/documents`
with Laravel's `visibility: private` setting, which creates that
directory as `700` — owner-only. `clamd` runs as its own system user
(`clamav`), completely separate from whichever user runs PHP/Horizon.
Result: `clamd` could not read files to scan them, and the daemon
returned a `File path check failure` error for every file — which, before
a related code fix (see §5), was being silently swallowed and reported
as a clean scan.

**ACLs (`setfacl`) do not work in this environment** — confirmed via
`Operation not supported` on every `setfacl` call. This means the
underlying mount doesn't support POSIX ACLs (common on some WSL/ext4
configurations without the `acl` mount option, or on `drvfs`-backed
paths). Diagnose with:
```bash
df --output=target,fstype storage/app/private/documents
```
If ACLs are unsupported here, they'll be unsupported for any fresh WSL
clone of this environment too — don't re-attempt the ACL route on a new
machine without checking this first.

**Working fix — group membership, not ACL:**
```bash
# Add the clamav system user to the app user's group
sudo usermod -aG <app_user> clamav

# Tighten to owner+group only, grant group read + directory-traverse
# (capital X, not lowercase x — this avoids marking regular files as
# executable, only directories get the traverse bit)
sudo chmod 750 storage/app/private/documents
sudo chmod -R g+rX storage/app/private/documents

# clamd must be restarted to pick up its new group membership —
# group changes to a running daemon do not apply live
sudo service clamav-daemon stop
sudo service clamav-daemon start
```

**On a real production deploy**, decide this properly rather than
patching around it: either run `clamd` under the same user/group as the
app (adjust `User` in `/etc/clamav/clamd.conf`), or provision the
uploads directory's group ownership as part of the deploy script, not
as a manual one-off fix. This must be scripted (see §6) — it will not
survive a fresh server provision otherwise.

---

## 4. Horizon / queue workers — config changes require a restart, not just a cache clear

**This caused the most confusing debugging in this session.** A running
Horizon worker loads config, class definitions, and (per PHP's
`realpath_cache`) file-existence checks into memory at process start,
and does not pick up later changes to `.env`, `config/*.php`, or edited
job classes on its own.

`php artisan config:clear` only clears the on-disk cache file — it has
no effect on an already-running worker process.

**Rule: after any of the following, always run `horizon:terminate` and
restart, not just `config:clear`:**
- Any `.env` change
- Any `config/*.php` change
- Any edit to a Job, Service, Observer, or Provider class
- Any `composer install`/`composer update`
- Any new migration that changes model behavior relied on by a job

```bash
php artisan horizon:terminate
sleep 2
php artisan horizon &
sleep 2
php artisan horizon:status
```

`horizon:terminate` waits for in-flight jobs to finish before exiting —
safe to run any time, not just during low-traffic windows in dev.

**Symptom this shows up as:** a fix is applied and verified correct via
`php -l` and direct code review, config values check out fine via
`artisan tinker`, and yet the job still fails with the *old* behavior.
If you see that combination, suspect a stale worker before suspecting
the fix itself.

---

## 5. Application-level bugs found & fixed during this setup

Kept here for context on *why* certain code looks the way it does —
useful if these patterns show up again elsewhere in the pipeline.

- **`ScanUploadedFileJob::scanWithClamd()` silently passed missing/
  unreadable files as "clean".** The original implementation only
  checked the clamd response for the substring `'FOUND'` — a
  file-not-found or permission error response (which contains neither
  `FOUND` nor is otherwise handled) fell through to `return 'OK'`.
  Fixed by adding an explicit `file_exists()`/`is_readable()` guard
  before contacting clamd, and checking the response for `'ERROR'`
  before returning `'OK'`. **Lesson for future pipeline code:** any
  scanner/validator that returns a string response should be checked
  with an explicit allow-list or error-list, never just a single
  "positive case" substring match — the negative/failure case needs
  equally explicit handling, or it silently defaults to "pass."

- **`OcrEngineResolver` constructor signature changed** from taking a
  flat array of pre-instantiated providers to taking the container +
  config-driven provider map (`config/ocr.php`) + default provider key.
  This makes provider registration a one-line config change instead of
  a code change in `AppServiceProvider`, and enables per-workspace
  provider preference via `workspace_settings.ocr_provider` /
  `workspace_ai_configs`.

---

## 6. TODO — turn this into a real provisioning script

Everything above is currently manual, re-run-by-hand knowledge. Before
this goes anywhere beyond a dev machine, this should become:
- A `bin/provision.sh` (or Ansible/Docker equivalent) that installs and
  configures Redis, ClamAV, Tesseract, and the `clamav` group membership
  fix in one idempotent pass.
- A documented decision on the `clamd` user/group question for
  production specifically (§3) — group-hack vs. matching users.
- A process supervisor (systemd unit, or Supervisor/Horizon's own
  daemonization) for Horizon in production, since `php artisan
  horizon &` backgrounded from a shell does not survive a server reboot
  or SSH session ending.
- A pre-deploy checklist item: "restart queue workers" as a mandatory
  step after every deploy, per §4.



## Deferred to frontend UI testing (not yet verified as of 2026-08-05)
- [ ] Real PDF upload through DocumentUploadController::store()
- [ ] Real DOCX upload — confirm table extraction works on actual content
- [ ] Scanned PDF upload — confirm OCR fallback triggers and produces text
- [ ] Confirm GenerateInsightsJob runs cleanly on real extracted text post-fixes