#!/usr/bin/env bash
BASE_URL="http://127.0.0.1:8000"
TEST_EMAIL="dannykhan614@gmail.com"
TOKEN="PASTE_TOKEN_HERE"

echo "=== Verifying email ==="
curl -s -X POST "$BASE_URL/api/auth/verify-email" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$TEST_EMAIL\",\"code\":\"196836\"}"

echo ""
echo ""
echo "=== Confirming /api/auth/me now shows verified ==="
curl -s -X GET "$BASE_URL/api/auth/me" \
  -H "Authorization: Bearer $TOKEN"

echo ""
echo ""
echo "=== Document upload route + validation rules ==="
php artisan route:list --path=documents | grep -i "POST\|upload"
find . -iname "*UploadRequest*.php" -o -iname "*StoreDocument*.php" 2>/dev/null | grep -i "Requests\|Http" | xargs -I{} cat {} 2>/dev/null

echo ""
echo "=== DONE ==="
