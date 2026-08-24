#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${HEALTH_CHECK_BASE_URL:-http://localhost:8000}"
TIMEOUT="${HEALTH_CHECK_TIMEOUT:-10}"

response=$(curl -sS -m "$TIMEOUT" -w "\n%{http_code}" \
  -H "Accept: application/json" \
  "$BASE_URL/api/health" || true)

body=$(echo "$response" | head -n -1)
status=$(echo "$response" | tail -n 1)

if [[ "$status" != "200" && "$status" != "503" ]]; then
  echo "FAIL  /api/health unreachable or unexpected HTTP status ($status)"
  exit 1
fi

if ! echo "$body" | grep -q '"success"'; then
  echo "FAIL  /api/health response missing success field"
  exit 1
fi

if ! echo "$body" | grep -q '"services"'; then
  echo "FAIL  /api/health response missing services field"
  exit 1
fi

if echo "$body" | grep -Eiq 'password|secret|exception|stack'; then
  echo "FAIL  /api/health response may leak sensitive data"
  exit 1
fi

if [[ "$status" == "200" ]]; then
  echo "PASS  /api/health ($status, healthy)"
  exit 0
fi

echo "WARN  /api/health ($status, degraded — one or more services unhealthy)"
exit 1
