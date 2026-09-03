#!/usr/bin/env bash
DB_URL="postgresql://postgres:password123@127.0.0.1:5433/ca_document_intelligence_local"
DEMO_WS="01a060c6-5d38-72d5-baa1-318564b71430"

echo "=== creating fresh negative-case workspace (zero documents/KPIs) ==="
NEG_WS=$(php artisan tinker --execute="echo App\Models\Workspace::create(['type' => 'Organization', 'name' => 'RLS Negative Test Workspace'])->id;" 2>/dev/null)
if [ -z "$NEG_WS" ]; then echo "[FAILED: could not create negative workspace]"; fi
echo "Negative-case workspace: $NEG_WS"

echo ""
echo "=== creating demo (positive) reader role ==="
DEMO_OUTPUT=$(php artisan powerbi:create-reader "$DEMO_WS" --label="Batch3 demo reader" 2>&1)
echo "$DEMO_OUTPUT"
DEMO_ROLE=$(echo "$DEMO_OUTPUT" | grep "Created Power BI reader role:" | sed 's/.*role: //')
DEMO_PASSWORD=$(echo "$DEMO_OUTPUT" | sed -n '/Password (shown once/{n;p;}')
if [ -z "$DEMO_ROLE" ] || [ -z "$DEMO_PASSWORD" ]; then echo "[FAILED: could not extract demo role/password]"; fi

echo ""
echo "=== creating negative reader role ==="
NEG_OUTPUT=$(php artisan powerbi:create-reader "$NEG_WS" --label="Batch3 negative reader" 2>&1)
echo "$NEG_OUTPUT"
NEG_ROLE=$(echo "$NEG_OUTPUT" | grep "Created Power BI reader role:" | sed 's/.*role: //')
NEG_PASSWORD=$(echo "$NEG_OUTPUT" | sed -n '/Password (shown once/{n;p;}')
if [ -z "$NEG_ROLE" ] || [ -z "$NEG_PASSWORD" ]; then echo "[FAILED: could not extract negative role/password]"; fi

echo ""
echo "DEMO_ROLE=$DEMO_ROLE"
echo "NEG_ROLE=$NEG_ROLE"

echo ""
echo "=== POSITIVE CASE: demo reader querying power_bi_kpis (expect > 0) ==="
psql "postgresql://${DEMO_ROLE}:${DEMO_PASSWORD}@127.0.0.1:5433/ca_document_intelligence_local" -c "SELECT COUNT(*) FROM power_bi_kpis;" || echo "[FAILED: positive-case query]"

echo ""
echo "=== NEGATIVE CASE: empty-workspace reader querying power_bi_kpis (expect 0) ==="
psql "postgresql://${NEG_ROLE}:${NEG_PASSWORD}@127.0.0.1:5433/ca_document_intelligence_local" -c "SELECT COUNT(*) FROM power_bi_kpis;" || echo "[FAILED: negative-case query]"

echo ""
echo "=== revoking both credentials (app-level) and dropping both roles (Postgres-level) ==="
php artisan tinker --execute="
DB::table('powerbi_credentials')->whereIn('db_role', ['$DEMO_ROLE','$NEG_ROLE'])->update(['revoked_at' => now()]);
echo 'Credentials marked revoked.' . PHP_EOL;
" || echo "[FAILED: credential revoke]"
psql "$DB_URL" -c "DROP ROLE IF EXISTS \"$DEMO_ROLE\"; DROP ROLE IF EXISTS \"$NEG_ROLE\";" || echo "[FAILED: role drop]"

echo ""
echo "=== DONE ==="
