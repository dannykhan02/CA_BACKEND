#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${HEALTH_CHECK_URL:-http://127.0.0.1:8000}"

echo "==> Health check against ${BASE_URL}/api/health"

RESPONSE=$(curl -sf "${BASE_URL}/api/health") || {
    echo "FAIL: /api/health did not respond" >&2
    exit 1
}
echo "    Response: ${RESPONSE}"

SUCCESS=$(echo "$RESPONSE" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo (isset($d["success"]) && $d["success"]) ? "true" : "false";')

if [ "$SUCCESS" != "true" ]; then
    echo "FAIL: /api/health reported unhealthy: ${RESPONSE}" >&2
    exit 1
fi
echo "==> /api/health: healthy"

HORIZON_STATUS=$(php artisan horizon:status 2>&1)
if echo "$HORIZON_STATUS" | grep -qi "running"; then
    echo "==> Horizon: running"
else
    echo "FAIL: Horizon is not running" >&2
    echo "$HORIZON_STATUS" >&2
    exit 1
fi

echo "==> Health check passed."
exit 0
