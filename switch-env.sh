#!/usr/bin/env bash
# switch-env.sh — activates a profile created by setup-env-profiles.sh and
# restarts the dev server clean. This is the ONLY way DB target should
# change from here on — never by exporting DB_* in a shell by hand, which
# is what caused today's server/CLI split in the first place.
#
# Usage: ./switch-env.sh local
#        ./switch-env.sh production

set -euo pipefail
TARGET="${1:-}"

case "$TARGET" in
    local)
        PROFILE=".env.local-testing"
        ;;
    production)
        PROFILE=".env.production"
        ;;
    *)
        echo "Usage: ./switch-env.sh [local|production]" >&2
        exit 1
        ;;
esac

if [[ ! -f "$PROFILE" ]]; then
    echo "ERROR: $PROFILE not found. Run setup-env-profiles.sh first." >&2
    exit 1
fi

echo "=================================================================="
echo "Switching to: $TARGET  ($PROFILE)"
echo "=================================================================="

cp "$PROFILE" ".env"
php artisan config:clear

echo "Active DB_* now:"
grep -E "^DB_" ".env"

echo ""
echo "Restarting dev server clean (no stale env can leak through this way)..."
SERVER_PID=$(pgrep -f "artisan serve" | head -1 || true)
if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID"
    sleep 1
fi
nohup php artisan serve > /tmp/artisan-serve.log 2>&1 &
NEW_PID=$!
sleep 1
echo "New server PID: $NEW_PID"

echo ""
echo "Confirming server process has no conflicting DB_* env of its own..."
tr '\0' '\n' < /proc/"$NEW_PID"/environ 2>/dev/null | grep -i "^DB_" && \
    echo "^^ WARNING: server process still has its own DB_* vars — check for a wrapper script or systemd unit exporting them." || \
    echo "Clean — server will use whatever .env says, which is now: $TARGET"

echo ""
echo "Confirming CLI agrees..."
php artisan tinker --execute="
\$c = config('database.default');
echo 'CLI resolves to: ' . config(\"database.connections.\$c.host\") . ':' . config(\"database.connections.\$c.port\") . '/' . config(\"database.connections.\$c.database\") . PHP_EOL;
"

if [[ "$TARGET" == "production" ]]; then
    echo ""
    echo "=================================================================="
    echo "YOU ARE NOW POINTED AT PRODUCTION (Neon)."
    echo "Any destructive test (delete, forced updates) will affect real data"
    echo "unless it targets a document you created specifically for testing."
    echo "=================================================================="
fi