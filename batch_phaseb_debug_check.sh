#!/usr/bin/env bash

echo "=== APP_ENV / APP_DEBUG as switch-env.sh actually set them ==="
grep -E "^APP_ENV=|^APP_DEBUG=" .env

echo ""
echo "=== What .env.production itself specifies for these two keys ==="
grep -E "^APP_ENV=|^APP_DEBUG=" .env.production

echo ""
echo "=== What switch-env.sh actually copies/touches (not the DB password line) ==="
grep -n "APP_ENV\|APP_DEBUG\|cp \|cat " switch-env.sh

echo ""
echo "=== DONE ==="
