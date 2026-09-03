#!/usr/bin/env bash

echo "=== horizon.php: which queues does each supervisor watch? ==="
cat config/horizon.php

echo ""
echo "=== does VerificationCodeNotification (or its base Notification class) specify a queue name? ==="
grep -rn "onQueue\|->queue\|public \$queue" app/Notifications/ 2>/dev/null || echo "[no explicit queue name set — would default to 'default']"

echo ""
echo "=== what's actually sitting in Redis right now, across common queue names? ==="
redis-cli -h 127.0.0.1 -p 6379 keys "*queues:*" 2>/dev/null
echo "---"
for q in default notifications mail high low; do
  echo "queue '$q' length: $(redis-cli -h 127.0.0.1 -p 6379 llen "queues:$q" 2>/dev/null)"
done

echo ""
echo "=== DONE ==="
