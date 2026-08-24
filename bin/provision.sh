#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if [[ ! -f artisan ]]; then
  echo "Run from CA_BACKEND (artisan not found)."
  exit 1
fi

require_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Missing required command: $1"
    exit 1
  fi
}

echo "==> Checking prerequisites..."
require_cmd php
require_cmd composer

php -v
composer --version

echo "==> Installing PHP dependencies..."
composer install --optimize-autoloader

if [[ ! -f .env ]]; then
  echo "==> Creating .env from .env.example..."
  cp .env.example .env
  php artisan key:generate --force
  echo "Edit .env with production values before continuing (DB, Redis, API keys)."
else
  echo "==> .env already exists — skipping key generation."
fi

echo "==> Clearing caches..."
php artisan optimize:clear

echo "==> Running migrations..."
php artisan migrate --force

if [[ ! -L public/storage ]]; then
  echo "==> Linking storage..."
  php artisan storage:link
fi

echo
echo "Provision complete. Next steps:"
echo "  1. Configure Redis + ClamAV (see docs/start-dev-services.sh for local dev)"
echo "  2. Set HORIZON_AUTHORIZED_EMAILS in .env for Horizon dashboard access"
echo "  3. Run: php artisan horizon   (or configure a process supervisor)"
echo "  4. Create Power BI reader credentials: see docs/POWERBI_SETUP.md"
echo "  5. Verify: ./bin/health-check.sh"
