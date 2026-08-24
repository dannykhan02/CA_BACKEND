#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ ! -f artisan ]]; then
  echo "Run from CA_BACKEND (artisan not found)."
  exit 1
fi

echo "==> Installing PHP dependencies (production)..."
composer install --no-dev --optimize-autoloader

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Clearing stale caches..."
php artisan optimize:clear

echo "==> Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Restarting Horizon workers..."
php artisan horizon:terminate

echo "==> Post-deploy health check..."
"$ROOT/bin/health-check.sh"

echo "Deploy complete."
