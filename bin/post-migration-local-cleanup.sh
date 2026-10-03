
#!/usr/bin/env bash
# =============================================================================
# post-migration-local-cleanup.sh
#
# Brings the LOCAL WSL checkout in line with the 2026-10-02 move of the
# production database from Neon to Railway Postgres (pgvector/pgvector:pg18).
#
# DRY RUN by default. Nothing changes until you pass --apply.
#
#   1. Stops + disables the local supervisor programs (horizon, pulse-work)
#      that kept writing Pulse rows to Neon after the cutover.
#   2. Comments out the local cron heartbeat jobs.
#   3. Re-points the local .env away from Neon/Railway. It "fails closed"
#      (127.0.0.1), so a forgotten local command can never hit production.
#      The old .env is saved OUTSIDE the repo (~/migration-backups, mode 600).
#   4. (--local-postgres) Installs a local Postgres 18 + pgvector for dev.
#   5. Scans the code for leftover Neon / Power BI references (read-only).
#   6. Verifies the result (PASS/FAIL).
#
# It never connects to Railway or Neon and never edits PHP files.
#
# Usage:
#   ./post-migration-local-cleanup.sh                  # dry run
#   ./post-migration-local-cleanup.sh --apply          # do it
#   ./post-migration-local-cleanup.sh --apply --local-postgres
#
# Env overrides: APP_DIR, LOCAL_DB (ca_dev), LOCAL_USER (ca_dev)
# =============================================================================
set -uo pipefail

APPLY=0
LOCAL_PG=0
for arg in "$@"; do
  case "$arg" in
    --apply)          APPLY=1 ;;
    --local-postgres) LOCAL_PG=1 ;;
    -h|--help)        sed -n '2,/^set -uo pipefail/p' "$0" | sed '$d'; exit 0 ;;
    *) echo "unknown option: $arg (try --help)" >&2; exit 2 ;;
  esac
done

APP_DIR="${APP_DIR:-$HOME/Development/code/Wu-Tang/flask/January/CA/backend}"
ENV_FILE="$APP_DIR/.env"
BACKUP_DIR="$HOME/migration-backups"
STAMP="$(date +%Y%m%d-%H%M%S)"
LOCAL_DB="${LOCAL_DB:-ca_dev}"
LOCAL_USER="${LOCAL_USER:-ca_dev}"
REMOTE_RE='neon\.tech|rlwy\.net|railway\.internal|railway\.app'
FAILS=0

say()  { printf '\n== %s\n' "$*"; }
mask() { sed -E 's#(://[^:/@[:space:]]+:)[^@[:space:]]+@#\1****@#g; s#([A-Za-z_]*(PASSWORD|SECRET|TOKEN|KEY)[A-Za-z_]*=)[^[:space:]]*#\1****#g'; }
act()  { if [ "$APPLY" -eq 1 ]; then echo "   + $*"; "$@"; else echo "   [dry-run] $*"; fi; }

set_env() { # set_env KEY VALUE  (replace the line, or append it)
  local k="$1" v="$2"
  if grep -qE "^${k}=" "$ENV_FILE"; then
    sed -i -E "s|^${k}=.*|${k}=${v}|" "$ENV_FILE"
  else
    printf '%s=%s\n' "$k" "$v" >> "$ENV_FILE"
  fi
}

if [ "$APPLY" -eq 1 ]; then
  echo "MODE: APPLY (changes will be made)"
else
  echo "MODE: DRY RUN (nothing is changed; pass --apply to do it)"
fi
if [ ! -d "$APP_DIR" ]; then echo "APP_DIR not found: $APP_DIR" >&2; exit 1; fi
sudo -v || { echo "sudo is needed for supervisor/apt steps" >&2; exit 1; }

# -----------------------------------------------------------------------------
say "1. Local supervisor programs (these kept running Horizon/Pulse against Neon)"
if command -v supervisorctl >/dev/null 2>&1; then
  sudo supervisorctl status 2>&1 | sed 's/^/   /'
  CONFS="$(grep -liE 'horizon|pulse' /etc/supervisor/conf.d/*.conf 2>/dev/null || true)"
  if [ -z "$CONFS" ]; then
    echo "   no horizon/pulse configs found (already disabled?)"
  else
    PROGS="$(grep -hoE '^\[program:[^]]+' $CONFS | cut -d: -f2)"
    for p in $PROGS; do act sudo supervisorctl stop "$p"; done
    for c in $CONFS;  do act sudo mv "$c" "$c.disabled"; done
    act sudo supervisorctl reread
    act sudo supervisorctl update
  fi
else
  echo "   supervisor not installed - skipping"
fi

# -----------------------------------------------------------------------------
say "2. Cron heartbeat jobs"
if crontab -l >/dev/null 2>&1; then
  crontab -l | grep -n 'heartbeat\.sh' | sed 's/^/   /'
  if [ "$APPLY" -eq 1 ]; then
    mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
    crontab -l > "$BACKUP_DIR/crontab.$STAMP"
    crontab -l | sed -E '/heartbeat\.sh/ s/^([^#])/#\1/' | crontab -
    echo "   commented out (backup: $BACKUP_DIR/crontab.$STAMP)"
  else
    echo "   [dry-run] would comment out the heartbeat lines above"
  fi
else
  echo "   no crontab for this user"
fi

# -----------------------------------------------------------------------------
say "3. Local .env ($ENV_FILE)"
if [ ! -f "$ENV_FILE" ]; then
  echo "   not found - skipping"
else
  grep -E '^(DB_CONNECTION|DB_HOST|DB_HOST_POOLED|DB_PORT|DB_DATABASE|DB_USERNAME|DB_SSLMODE|DB_URL|DATABASE_URL|REDIS_HOST|REDIS_URL|QUEUE_CONNECTION)=' "$ENV_FILE" | mask | sed 's/^/   /'
  if grep -qiE "^(DB_HOST|DB_HOST_POOLED|DB_URL|DATABASE_URL)=.*($REMOTE_RE)" "$ENV_FILE"; then
    echo "   -> DB settings point at a REMOTE database (Neon/Railway). Local code must not."
    if [ "$APPLY" -eq 1 ]; then
      mkdir -p "$BACKUP_DIR" && chmod 700 "$BACKUP_DIR"
      cp -p "$ENV_FILE" "$BACKUP_DIR/env.$STAMP" && chmod 600 "$BACKUP_DIR/env.$STAMP"
      echo "   old .env saved to $BACKUP_DIR/env.$STAMP (outside the repo)"
      sed -i -E '/^(DB_URL|DATABASE_URL)=/d' "$ENV_FILE"
      set_env DB_CONNECTION  pgsql
      set_env DB_HOST        127.0.0.1
      set_env DB_HOST_POOLED 127.0.0.1
      set_env DB_PORT        5432
      set_env DB_DATABASE    "$LOCAL_DB"
      set_env DB_USERNAME    "$LOCAL_USER"
      set_env DB_PASSWORD    ""
      set_env DB_SSLMODE     prefer
      echo "   .env now points at 127.0.0.1/$LOCAL_DB (empty password = fails closed)"
    else
      echo "   [dry-run] would back up .env to $BACKUP_DIR and point DB_* at 127.0.0.1/$LOCAL_DB"
    fi
  else
    echo "   DB settings are already local - leaving them alone"
  fi
  if grep -qiE "^(REDIS_HOST|REDIS_URL)=.*($REMOTE_RE)" "$ENV_FILE"; then
    echo "   WARNING: REDIS_* points at Railway Redis. Never run horizon/queue workers locally with it."
  fi
fi

# -----------------------------------------------------------------------------
install_local_postgres() {
  say "4. Local development Postgres 18 + pgvector (db: $LOCAL_DB)"
  if [ "$APPLY" -ne 1 ]; then
    echo "   [dry-run] would apt-install postgresql-18 + postgresql-18-pgvector, start it,"
    echo "   [dry-run] create role/db $LOCAL_USER/$LOCAL_DB, enable 'vector', put a random password in .env"
    return
  fi
  if [ ! -f "$ENV_FILE" ]; then echo "   no .env to update - skipping"; return; fi
  if ! sudo apt-get install -y postgresql-18 postgresql-18-pgvector; then
    echo "   apt install failed (is the PGDG repo enabled?)"; FAILS=$((FAILS+1)); return
  fi
  sudo service postgresql start
  local pw; pw="$(openssl rand -hex 16)"
  ( cd /tmp && sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='$LOCAL_USER') THEN
    CREATE ROLE $LOCAL_USER LOGIN;
  END IF;
END \$\$;
ALTER ROLE $LOCAL_USER PASSWORD '$pw';
SQL
  ) || { echo "   could not create the role"; FAILS=$((FAILS+1)); return; }
  ( cd /tmp && { sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$LOCAL_DB'" | grep -q 1 \
      || sudo -u postgres createdb -O "$LOCAL_USER" "$LOCAL_DB"; } )
  ( cd /tmp && sudo -u postgres psql -d "$LOCAL_DB" -c "CREATE EXTENSION IF NOT EXISTS vector;" )
  set_env DB_PASSWORD "$pw"
  echo "   done. The password was written to .env (not printed). Next: php artisan migrate"
}
if [ "$LOCAL_PG" -eq 1 ]; then
  install_local_postgres
else
  say "4. Local development Postgres"
  echo "   skipped (pass --local-postgres to install one, or point .env at your own)"
fi

# -----------------------------------------------------------------------------
say "5. Leftover Neon / Power BI references in the code (read-only)"
shopt -s nullglob
TARGETS=()
for d in app config routes database resources bin tests docs; do
  [ -d "$APP_DIR/$d" ] && TARGETS+=("$APP_DIR/$d")
done
for f in "$APP_DIR"/*.md "$APP_DIR"/*.yml "$APP_DIR"/*.yaml "$APP_DIR"/*.json "$APP_DIR"/*.toml; do
  TARGETS+=("$f")
done
shopt -u nullglob
if [ "${#TARGETS[@]}" -gt 0 ]; then
  echo "-- Neon / pooler:"
  grep -rnIiE --exclude=post-migration-local-cleanup.sh 'neon\.tech|neondb|neon_|pooler' "${TARGETS[@]}" 2>/dev/null \
    | cut -c1-220 | mask | sed "s#$APP_DIR/##; s/^/   /" | head -40
  echo "-- Power BI (how does the app build connection details for it?):"
  grep -rnIiE --exclude=post-migration-local-cleanup.sh 'powerbi|power_bi' "${TARGETS[@]}" 2>/dev/null \
    | cut -c1-220 | mask | sed "s#$APP_DIR/##; s/^/   /" | head -60
else
  echo "   nothing to scan"
fi

# -----------------------------------------------------------------------------
say "6. Verification$([ "$APPLY" -eq 1 ] || echo ' (current state - FAILs show what --apply will fix)')"
check() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then echo "   PASS  $d"; else echo "   FAIL  $d"; FAILS=$((FAILS+1)); fi; }
no_local_workers() { ! pgrep -f 'artisan (horizon|pulse:work|queue:work)' >/dev/null; }
no_active_cron()   { ! ( crontab -l 2>/dev/null | grep 'heartbeat\.sh' | grep -qvE '^[[:space:]]*#' ); }
env_not_remote()   { [ ! -f "$ENV_FILE" ] || ! grep -qiE "^(DB_HOST|DB_HOST_POOLED|DB_URL|DATABASE_URL)=.*($REMOTE_RE)" "$ENV_FILE"; }
no_enabled_confs() { [ -z "$(grep -liE 'horizon|pulse' /etc/supervisor/conf.d/*.conf 2>/dev/null)" ]; }

if [ "$APPLY" -eq 1 ] && command -v php >/dev/null 2>&1; then
  ( cd "$APP_DIR" && php artisan config:clear >/dev/null 2>&1 )
fi
if command -v php >/dev/null 2>&1; then
  HOST="$(cd "$APP_DIR" && php artisan tinker --execute="echo config('database.connections.pgsql.host');" 2>/dev/null | tail -1)"
  echo "   Laravel's local DB host: ${HOST:-unknown}"
fi
check "no local horizon / pulse / queue worker running"   no_local_workers
check "no supervisor config enabled for horizon / pulse"  no_enabled_confs
check "no active heartbeat cron lines"                    no_active_cron
check ".env does not point at Neon or Railway"            env_not_remote

echo
if [ "$APPLY" -eq 1 ]; then
  [ "$FAILS" -eq 0 ] && echo "All checks passed." || echo "$FAILS check(s) failed - see above."
else
  echo "Dry run finished. Review the output, then re-run with --apply."
fi
cat <<'NOTE'

Remember:
  - Pause the checks in your Healthchecks dashboard, or you will get "down" alerts
    now that the heartbeat jobs are off.
  - The local DB is empty: run `php artisan migrate` after pointing .env at a real
    local database.
  - Never run horizon / queue:work locally against production settings.
NOTE
exit "$FAILS"
