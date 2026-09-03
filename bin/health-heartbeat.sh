#!/bin/bash
cd /home/dan/Development/code/Wu-Tang/flask/January/CA/backend

export $(grep HEALTHCHECKS_DB_REDIS_DISK_URL .env | xargs)

if ./bin/monitor.sh; then
    curl -fsS -m 10 "$HEALTHCHECKS_DB_REDIS_DISK_URL" > /dev/null
else
    curl -fsS -m 10 "$HEALTHCHECKS_DB_REDIS_DISK_URL/fail" > /dev/null
fi
