#!/usr/bin/env bash
DB_URL="postgresql://postgres:password123@127.0.0.1:5433/ca_document_intelligence_local"

echo "=== workspaces table schema ==="
psql "$DB_URL" -c "\d workspaces" || echo "[FAILED: workspaces schema check]"

echo ""
echo "=== workspace_type enum values ==="
psql "$DB_URL" -c "SELECT t.typname, e.enumlabel FROM pg_enum e JOIN pg_type t ON e.enumtypid = t.oid WHERE t.typname ILIKE '%workspace%';" || echo "[FAILED: enum check]"

echo ""
echo "=== WorkspaceType PHP enum ==="
find . -iname "WorkspaceType.php" -exec cat {} \; 2>/dev/null || echo "[not found]"

echo ""
echo "=== current DocumentSeeder.php ==="
cat database/seeders/DocumentSeeder.php || echo "[FAILED: could not read DocumentSeeder.php]"

echo ""
echo "=== power_bi_kpis row counts by workspace ==="
psql "$DB_URL" -c "SELECT workspace_id, COUNT(*) FROM power_bi_kpis GROUP BY workspace_id;" || echo "[FAILED: power_bi_kpis check — table may not exist yet]"

echo ""
echo "=== DONE ==="
