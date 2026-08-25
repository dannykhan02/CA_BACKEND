#!/usr/bin/env bash
set -euo pipefail

echo "==> CA Document Intelligence Platform — Provisioning"
echo "    Environment: ${APP_ENV:-not set}"
echo ""

if [ ! -f .env ]; then
    echo "ERROR: .env not found. Copy .env.example first and configure it." >&2
    exit 1
fi

REQUIRED_VARS=(DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME REDIS_HOST QUEUE_CONNECTION)
for var in "${REQUIRED_VARS[@]}"; do
    if ! grep -q "^${var}=" .env; then
        echo "ERROR: ${var} is not set in .env" >&2
        exit 1
    fi
done
echo "==> .env has required variables present"

echo "==> Installing composer dependencies"
composer install --no-interaction --prefer-dist

if ! grep -q "^APP_KEY=base64" .env; then
    echo "==> Generating APP_KEY"
    php artisan key:generate
else
    echo "==> APP_KEY already set, skipping"
fi

echo "==> Linking storage"
php artisan storage:link || echo "    (already linked, continuing)"

echo "==> Running migrations"
php artisan migrate --force

if [ "${PROVISION_SEED:-false}" = "true" ]; then
    echo "==> Seeding database (PROVISION_SEED=true)"
    php artisan db:seed --force
else
    echo "==> Skipping seed (set PROVISION_SEED=true to seed dev/test data)"
fi

echo "==> Verifying Redis connectivity"
php artisan tinker --execute="try { \Illuminate\Support\Facades\Redis::connection()->ping(); echo 'Redis OK'; } catch (\Throwable \$e) { echo 'Redis FAILED: '.\$e->getMessage(); exit(1); }"

echo "==> Verifying pgvector extension"
php artisan tinker --execute="
\$v = DB::selectOne(\"SELECT extversion FROM pg_extension WHERE extname='vector'\");
if (!\$v) { echo 'pgvector NOT installed'; exit(1); }
echo 'pgvector '.\$v->extversion.' present';
"

if [ -n "${PROVISION_POWERBI_WORKSPACE:-}" ]; then
    echo "==> Provisioning Power BI reader for workspace ${PROVISION_POWERBI_WORKSPACE}"
    php artisan powerbi:create-reader "${PROVISION_POWERBI_WORKSPACE}" --label="provisioned-by-script"
else
    echo "==> Skipping Power BI reader provisioning (set PROVISION_POWERBI_WORKSPACE=<uuid> to provision one)"
fi

echo ""
echo "==> Provisioning complete."
echo "    Next: start Horizon (php artisan horizon) and run scripts/smoke-test.sh"
