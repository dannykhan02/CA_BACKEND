#!/usr/bin/env bash
BASE_URL="http://127.0.0.1:8000"
TOKEN="PASTE_TOKEN_HERE"

echo "=== full /api/documents route list (need the GET-by-id route for polling) ==="
php artisan route:list --path=documents

echo ""
echo "=== UPLOADING 'No 18 (1).pdf' to PRODUCTION ==="
UPLOAD_RESPONSE=$(curl -s -X POST "$BASE_URL/api/documents" \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@./No 18 (1).pdf" \
  -F "classification=Internal")
echo "$UPLOAD_RESPONSE"

echo ""
echo "=== extracting document ID for polling/cleanup ==="
DOC_ID=$(echo "$UPLOAD_RESPONSE" | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "DOCUMENT_ID=$DOC_ID"
echo "$DOC_ID" > /tmp/phaseb_test_doc_id.txt

echo ""
echo "=== DONE ==="
