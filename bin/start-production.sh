#!/bin/sh
set -eu

role=${1:-web}
case "$role" in
    web|worker) ;;
    *) echo "FATAL: expected web or worker role" >&2; exit 1 ;;
esac

database_dir=${CLAMAV_DATABASE_DIRECTORY:-/var/lib/clamav}
mkdir -p "$database_dir"
chmod -R a+rwX "$database_dir"

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan config:check-production-safety

if freshclam --quiet --datadir="$database_dir"; then
    echo "ClamAV signatures updated or already current"
else
    echo "WARNING: freshclam update failed; validating existing signatures" >&2
fi

if [ ! -s "$database_dir/daily.cvd" ] && [ ! -s "$database_dir/daily.cld" ]; then
    echo "FATAL: no ClamAV daily signatures loaded" >&2
    exit 1
fi

# File presence is insufficient: load the database and scan harmless data.
probe=$(mktemp)
trap 'rm -f "$probe"' EXIT HUP INT TERM
printf '%s\n' 'DocIntel startup scanner check' > "$probe"
if ! clamscan --database="$database_dir" --no-summary "$probe"; then
    echo "FATAL: ClamAV cannot scan using the installed signatures" >&2
    exit 1
fi
rm -f "$probe"
trap - EXIT HUP INT TERM

case "$role" in
    web) exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile ;;
    worker) exec php artisan horizon ;;
esac
