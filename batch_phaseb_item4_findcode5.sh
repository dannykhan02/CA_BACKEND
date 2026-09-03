#!/usr/bin/env bash
PREFIX="ca-document-intelligence-platform-database-queues"

echo "=== all matching queue keys, full names ==="
redis-cli -h 127.0.0.1 -p 6379 keys "${PREFIX}:*"

echo ""
echo "=== length of the 'default' queue with the correct prefix ==="
redis-cli -h 127.0.0.1 -p 6379 llen "${PREFIX}:default"

echo ""
echo "=== if non-empty, peek at the job payloads without removing them ==="
redis-cli -h 127.0.0.1 -p 6379 lrange "${PREFIX}:default" 0 -1

echo ""
echo "=== DONE ==="
