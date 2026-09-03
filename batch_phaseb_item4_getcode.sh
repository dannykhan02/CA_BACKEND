#!/usr/bin/env bash
BASE_URL="http://127.0.0.1:8000"
TEST_EMAIL="dannykhan614@gmail.com"

echo "=== Enabling dev verification-code exposure (temporarily) ==="
sed -i 's/^EXPOSE_DEV_VERIFICATION_CODE=.*/EXPOSE_DEV_VERIFICATION_CODE=true/' .env.production
if ! grep -q "^EXPOSE_DEV_VERIFICATION_CODE=" .env.production; then
  echo "EXPOSE_DEV_VERIFICATION_CODE=true" >> .env.production
fi
php artisan config:clear >/dev/null

echo ""
echo "=== Requesting a fresh code via resend-verification ==="
RESEND_RESPONSE=$(curl -s -X POST "$BASE_URL/api/auth/resend-verification" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$TEST_EMAIL\"}")
echo "$RESEND_RESPONSE"

echo ""
echo "=== Disabling dev verification-code exposure again (immediately) ==="
sed -i 's/^EXPOSE_DEV_VERIFICATION_CODE=.*/EXPOSE_DEV_VERIFICATION_CODE=false/' .env.production
php artisan config:clear >/dev/null
grep "^EXPOSE_DEV_VERIFICATION_CODE=" .env.production

echo ""
echo "=== DONE ==="
