#!/bin/bash
cd /home/dan/Development/code/Wu-Tang/flask/January/CA/backend

export $(grep HEALTHCHECKS_HORIZON_URL .env | xargs)

if php artisan horizon:status | grep -q "running"; then
    curl -fsS -m 10 "$HEALTHCHECKS_HORIZON_URL" > /dev/null
else
    curl -fsS -m 10 "$HEALTHCHECKS_HORIZON_URL/fail" > /dev/null
fi
