#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${SMOKE_TEST_BASE_URL:-http://localhost:8000}"
TOKEN="${SMOKE_TEST_TOKEN:-}"

pass=0
fail=0

check() {
  local name="$1" method="$2" path="$3" expected="$4" auth="${5:-yes}"
  local headers=(-H "Accept: application/json")

  if [[ "$auth" == "yes" ]]; then
    if [[ -z "$TOKEN" ]]; then
      echo "SKIP  $name (no SMOKE_TEST_TOKEN set)"
      return
    fi
    headers+=(-H "Authorization: Bearer $TOKEN")
  fi

  status=$(curl -s -o /dev/null -w "%{http_code}" -X "$method" "${headers[@]}" "$BASE_URL$path")
  if [[ "$status" == "$expected" ]]; then
    echo "PASS  $name ($status)"
    pass=$((pass + 1))
  else
    echo "FAIL  $name (expected $expected, got $status)"
    fail=$((fail + 1))
  fi
}

check "Health endpoint"    GET  "/api/health"           200 no
check "Documents index"    GET  "/api/documents"         200
check "Dashboard summary"  GET  "/api/dashboard/summary" 200

echo
echo "Passed: $pass  Failed: $fail"
[[ $fail -eq 0 ]]
