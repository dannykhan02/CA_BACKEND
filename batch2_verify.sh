#!/usr/bin/env bash
DB_URL="postgresql://postgres:password123@127.0.0.1:5433/ca_document_intelligence_local"

echo "=== migrate:fresh --seed ==="
php artisan migrate:fresh --seed --force 2>&1 || echo "[FAILED: migrate:fresh --seed]"

echo ""
echo "=== null workspace_id check + demo workspace id ==="
php artisan tinker --execute="
\$nullCount = DB::table('documents')->whereNull('workspace_id')->count();
echo \$nullCount === 0 ? 'All documents have workspace_id: PASS' . PHP_EOL : 'STILL BROKEN: ' . \$nullCount . ' null rows' . PHP_EOL;
\$w = App\Models\Workspace::where('name', 'Communications Authority Demo')->first();
echo 'Demo workspace ID: ' . \$w->id . PHP_EOL;
" || echo "[FAILED: tinker verification]"

echo ""
echo "=== document_kpis row counts by workspace (correct table name) ==="
psql "$DB_URL" -c "SELECT workspace_id, COUNT(*) FROM document_kpis GROUP BY workspace_id;" || echo "[FAILED: document_kpis check]"

echo ""
echo "=== DONE ==="
