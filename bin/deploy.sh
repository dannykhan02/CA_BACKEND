#!/usr/bin/env bash
set -euo pipefail

echo "==> CA Document Intelligence Platform — Deploy"

echo "==> Installing production dependencies"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Running migrations"
php artisan migrate --force

echo "==> Clearing cached config/routes/views"
php artisan optimize:clear

echo "==> Caching config/routes/views for production"
php artisan config:cache
php artisan route:cache
php artisan view:cache

if [ -d public/storage ] || [ -L public/storage ]; then
    echo "==> Storage already linked"
else
    php artisan storage:link
fi

echo "==> Signaling queue workers to restart"
php artisan horizon:terminate || echo "    (Horizon was not running, continuing)"
# NOTE: this only signals a graceful stop. The production process
# supervisor (systemd/Supervisor, per runbook §12 — tracked as 4-Item7,
# not yet built) is what actually restarts `php artisan horizon`
# afterward. This script deliberately does not start Horizon itself.

echo "==> Running post-deploy health check"
./bin/health-check.sh

echo ""
echo "==> Deploy complete."
