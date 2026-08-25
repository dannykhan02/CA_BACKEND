#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${SMOKE_TEST_URL:-http://127.0.0.1:8000}"
FAILURES=0

check() {
    local desc="$1"
    local result="$2"
    if [ "$result" = "0" ]; then
        echo "  [PASS] ${desc}"
    else
        echo "  [FAIL] ${desc}"
        FAILURES=$((FAILURES + 1))
    fi
}

echo "==> Smoke test against ${BASE_URL}"
echo ""

echo "-- Health endpoint --"
HEALTH_RESPONSE=$(curl -sf "${BASE_URL}/api/health" 2>/dev/null) && HEALTH_OK=0 || HEALTH_OK=1
check "GET /api/health responds" "$HEALTH_OK"
if [ "$HEALTH_OK" = "0" ]; then
    echo "    Response: ${HEALTH_RESPONSE}"
fi

echo ""
echo "-- Database --"
php artisan migrate:status > /tmp/migrate_status.txt 2>&1
if grep -q "Pending" /tmp/migrate_status.txt; then
    check "No pending migrations" 1
    grep "Pending" /tmp/migrate_status.txt
else
    check "No pending migrations" 0
fi

echo ""
echo "-- Queue infrastructure --"
php artisan tinker --execute="
try {
    \Illuminate\Support\Facades\Redis::connection()->ping();
    echo 'REDIS_OK';
} catch (\Throwable \$e) {
    echo 'REDIS_FAILED: '.\$e->getMessage();
}
" > /tmp/redis_test.txt 2>&1
grep -q "REDIS_OK" /tmp/redis_test.txt && check "Redis reachable" 0 || check "Redis reachable" 1

HORIZON_STATUS=$(php artisan horizon:status 2>&1)
echo "$HORIZON_STATUS" | grep -qi "running" && check "Horizon running" 0 || check "Horizon running" 1

echo ""
echo "-- Real queue dispatch (not a direct ->handle() call) --"
php artisan tinker --execute="
dispatch(function () { logger()->info('smoke-test job executed'); })->onQueue('smoke-test');
sleep(2);
echo 'dispatched';
" > /tmp/dispatch_test.txt 2>&1
grep -q "dispatched" /tmp/dispatch_test.txt && check "Job dispatch does not error" 0 || check "Job dispatch does not error" 1
echo "    NOTE: this only proves dispatch doesn't throw — inspect Horizon dashboard to confirm the smoke-test queue actually drains."

echo ""
echo "-- Power BI views --"
php artisan tinker --execute="
try {
    DB::select('SELECT 1 FROM power_bi_kpis LIMIT 1');
    DB::select('SELECT 1 FROM power_bi_chart_points LIMIT 1');
    echo 'views queryable';
} catch (\Throwable \$e) {
    echo 'FAILED: '.\$e->getMessage();
    exit(1);
}
" > /tmp/pbi_test.txt 2>&1
grep -q "views queryable" /tmp/pbi_test.txt && check "Power BI views queryable" 0 || check "Power BI views queryable" 1

echo ""
echo "==> Smoke test complete: ${FAILURES} failure(s)"
[ "$FAILURES" -eq 0 ] && exit 0 || exit 1
