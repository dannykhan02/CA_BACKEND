#!/usr/bin/env bash
DB_URL="postgresql://postgres:password123@127.0.0.1:5433/ca_document_intelligence_local"

echo "=== views/tables the powerbi_reader role can actually select from ==="
psql "$DB_URL" -c "
SELECT table_schema, table_name, privilege_type
FROM information_schema.role_table_grants
WHERE grantee = 'powerbi_reader'
ORDER BY table_name;
" || echo "[FAILED: role grants check]"

echo ""
echo "=== powerbi:create-reader command source ==="
find . -iname "*CreatePowerBiReader*.php" -exec cat {} \; 2>/dev/null || echo "[not found]"

echo ""
echo "=== PowerBiIsolationTest.php (the already-proven connection pattern) ==="
cat tests/Feature/PowerBiIsolationTest.php || echo "[FAILED: could not read test file]"

echo ""
echo "=== does the earlier TEST_WS still exist with zero KPI rows? ==="
psql "$DB_URL" -c "SELECT id, name FROM workspaces WHERE id = '01a05ce5-9dde-72cd-b54c-b45b2941e727';" || echo "[FAILED]"
psql "$DB_URL" -c "SELECT COUNT(*) FROM document_kpis WHERE workspace_id = '01a05ce5-9dde-72cd-b54c-b45b2941e727';" || echo "[FAILED]"

echo ""
echo "=== DONE ==="
