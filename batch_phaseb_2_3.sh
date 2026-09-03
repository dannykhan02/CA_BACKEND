#!/usr/bin/env bash

echo "=== Item 2: migrate:status (expect only header row, nothing pending) ==="
php artisan migrate:status | grep -v Ran || echo "(no pending migrations — this is the expected/good result)"

echo ""
echo "=== Item 3: smoke test against Neon ==="
bash bin/smoke-test.sh 2>&1 | tee /tmp/smoke_test_prod.txt || echo "[FAILED: smoke-test.sh]"

echo ""
echo "=== DONE ==="
