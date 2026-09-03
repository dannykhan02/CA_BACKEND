#!/usr/bin/env bash
BASE_URL="http://127.0.0.1:8000"
TEST_EMAIL="dannykhan614@gmail.com"
TEST_PASSWORD="Qx7$(openssl rand -hex 5)!Rt"

echo "Generated password (save this — shown once):"
echo "$TEST_PASSWORD"

echo ""
echo "=== Signing up ==="
curl -s -X POST "$BASE_URL/api/auth/signup" \
  -H "Content-Type: application/json" \
  -d "{\"full_name\":\"Phase B QA Test\",\"email\":\"$TEST_EMAIL\",\"password\":\"$TEST_PASSWORD\",\"password_confirmation\":\"$TEST_PASSWORD\"}"

echo ""
echo ""
echo "=== Checking configured log channel ==="
grep -E "^LOG_CHANNEL=" .env.production

echo ""
echo "=== Last 60 lines of the log (should contain the 'sent' verification email) ==="
tail -n 60 storage/logs/laravel.log 2>/dev/null || echo "[log file not at that path — see LOG_CHANNEL above]"

echo ""
echo "=== DONE ==="
