#!/usr/bin/env bash

echo "=== Document model ==="
cat app/Models/Document.php || echo "[FAILED: Document.php]"

echo ""
echo "=== DocumentKpi model ==="
find . -iname "DocumentKpi.php" -exec cat {} \; 2>/dev/null || echo "[not found]"

echo ""
echo "=== migration: add_workspace_id_to_document_children ==="
find . -iname "*add_workspace_id_to_document_children*" -exec cat {} \; 2>/dev/null || echo "[not found]"

echo ""
echo "=== migration: backfill_workspace_id_on_document_children ==="
find . -iname "*backfill_workspace_id_on_document_children*" -exec cat {} \; 2>/dev/null || echo "[not found]"

echo ""
echo "=== Observers ==="
find . -iname "*Observer*.php" -path "*/app/*" 2>/dev/null
echo "--- registrations ---"
grep -rn "observe(" app/Providers/ 2>/dev/null || echo "[none found]"

echo ""
echo "=== DONE ==="
