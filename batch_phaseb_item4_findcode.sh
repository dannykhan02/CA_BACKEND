#!/usr/bin/env bash

echo "=== searching log for the mailed verification email to this address ==="
grep -n "dannykhan614@gmail.com" storage/logs/laravel.log | tail -20

echo ""
echo "=== last block of log containing 'Verification' (case-insensitive), with context ==="
grep -n -i "verification" storage/logs/laravel.log | tail -10

echo ""
echo "=== if a match exists above, print 40 lines starting at the LAST matching line number ==="
LAST_LINE=$(grep -n -i "verification code" storage/logs/laravel.log | tail -1 | cut -d: -f1)
if [ -n "$LAST_LINE" ]; then
  sed -n "${LAST_LINE},$((LAST_LINE+40))p" storage/logs/laravel.log
else
  echo "[no 'verification code' text match found — log may format it differently, see raw matches above]"
fi

echo ""
echo "=== DONE ==="
