#!/usr/bin/env bash
BASE_URL="http://127.0.0.1:8000"
TOKEN="PASTE_TOKEN_HERE"
DOC_ID="01a060e2-8ed9-73bd-8589-d56a2df293f2"

echo "=== Polling document status (up to 12 tries, 5s apart = 60s max) ==="
for i in $(seq 1 12); do
  RESPONSE=$(curl -s -X GET "$BASE_URL/api/documents/$DOC_ID" -H "Authorization: Bearer $TOKEN")
  STATUS=$(echo "$RESPONSE" | grep -o '"status":"[^"]*"' | head -1 | cut -d'"' -f4)
  echo "[try $i] status=$STATUS"
  if [ "$STATUS" = "Ready" ] || [ "$STATUS" = "Failed" ]; then
    echo ""
    echo "=== Final document state ==="
    echo "$RESPONSE"
    break
  fi
  sleep 5
done

echo ""
echo "=== Cleaning up: soft-deleting the test document regardless of outcome ==="
curl -s -X DELETE "$BASE_URL/api/documents/$DOC_ID" -H "Authorization: Bearer $TOKEN"

echo ""
echo ""
echo "=== Confirming it's gone from the normal document list ==="
curl -s -X GET "$BASE_URL/api/documents/$DOC_ID" -H "Authorization: Bearer $TOKEN"

echo ""
echo "=== DONE ==="
