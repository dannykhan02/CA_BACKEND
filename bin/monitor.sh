#!/usr/bin/env bash
# bin/monitor.sh — P4 item: production monitoring
#
# Run on a schedule (cron/systemd timer). Each check appends to
# storage/logs/monitor-alerts.log on failure/threshold breach and the
# script exits non-zero if ANY check failed, so cron's own mail-on-error
# (or a wrapper that pages on nonzero exit) is the alerting mechanism.
#
# Growth-based checks (failed_jobs, ERROR log lines, provider errors,
# upload/scan errors) use a state file so only NEW failures since the
# last run trigger an alert, not the same historical rows every time.

set -uo pipefail
cd "$(dirname "$0")/.."

STATE_DIR="storage/monitor"
ALERT_LOG="storage/logs/monitor-alerts.log"
mkdir -p "$STATE_DIR" "$(dirname "$ALERT_LOG")"
FAILED=0

alert() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ALERT: $1" | tee -a "$ALERT_LOG" >&2
    FAILED=1
}

ok() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ok: $1"
}

# 1. API availability + overall health payload (db/redis/queue/storage)
HEALTH_URL="${HEALTH_CHECK_URL:-http://127.0.0.1:8000}/api/health"
if RESPONSE=$(curl -sf --max-time 10 "$HEALTH_URL"); then
    SUCCESS=$(echo "$RESPONSE" | php -r '$d=json_decode(file_get_contents("php://stdin"),true); echo (isset($d["success"]) && $d["success"]) ? "true" : "false";' 2>/dev/null)
    if [ "$SUCCESS" = "true" ]; then
        ok "API health: healthy"
    else
        alert "API health endpoint responded but reported unhealthy: $RESPONSE"
    fi
else
    alert "API health endpoint did not respond (${HEALTH_URL})"
fi

# 2. 5xx / application error rate (proxy: new ERROR-level laravel.log lines since last run)
LOG_FILE="storage/logs/laravel.log"
ERROR_COUNT_FILE="$STATE_DIR/error_line_count"
if [ -f "$LOG_FILE" ]; then
    CURRENT_LINES=$(wc -l < "$LOG_FILE")
    LAST_LINES=$(cat "$ERROR_COUNT_FILE" 2>/dev/null || echo 0)
    if [ "$CURRENT_LINES" -gt "$LAST_LINES" ]; then
        NEW_ERRORS=$(tail -n +"$((LAST_LINES + 1))" "$LOG_FILE" | grep -c '\.ERROR:' || true)
        if [ "$NEW_ERRORS" -gt 0 ]; then
            alert "$NEW_ERRORS new ERROR-level log line(s) since last check"
        else
            ok "no new ERROR-level log lines"
        fi
    else
        ok "no new ERROR-level log lines"
    fi
    echo "$CURRENT_LINES" > "$ERROR_COUNT_FILE"
else
    alert "laravel.log not found at $LOG_FILE"
fi

# 3. Queue failures (failed_jobs growth) + Horizon workers
HORIZON_STATUS=$(php artisan horizon:status 2>&1)
if echo "$HORIZON_STATUS" | grep -qi "running"; then
    ok "Horizon: running"
else
    alert "Horizon is not running: $HORIZON_STATUS"
fi

FAILED_JOBS_COUNT=$(php artisan tinker --execute="echo \DB::table('failed_jobs')->count();" 2>/dev/null | tail -1)
FAILED_JOBS_FILE="$STATE_DIR/failed_jobs_count"
LAST_FAILED_JOBS=$(cat "$FAILED_JOBS_FILE" 2>/dev/null || echo 0)
if [ "${FAILED_JOBS_COUNT:-0}" -gt "$LAST_FAILED_JOBS" ]; then
    alert "failed_jobs grew from $LAST_FAILED_JOBS to $FAILED_JOBS_COUNT"
else
    ok "failed_jobs count stable at $FAILED_JOBS_COUNT"
fi
echo "${FAILED_JOBS_COUNT:-0}" > "$FAILED_JOBS_FILE"

# 4. Redis
if redis-cli ping 2>/dev/null | grep -q PONG; then
    ok "Redis: PONG"
else
    alert "Redis did not respond to PING"
fi

# 5. Database
if php artisan tinker --execute="\DB::connection()->getPdo(); echo 'ok';" 2>/dev/null | grep -q ok; then
    ok "Database: connected"
else
    alert "Database connection failed"
fi

# 6. Disk space
DISK_LINE=$(df -h / | tail -1)
DISK_PCT=$(echo "$DISK_LINE" | awk '{print $5}' | tr -d '%')
if [ "$DISK_PCT" -ge 90 ]; then
    alert "Disk usage critical: ${DISK_PCT}% ($DISK_LINE)"
elif [ "$DISK_PCT" -ge 80 ]; then
    alert "Disk usage high: ${DISK_PCT}% ($DISK_LINE)"
else
    ok "Disk usage: ${DISK_PCT}%"
fi

# 7. Memory
MEM_AVAILABLE_PCT=$(free | awk '/^Mem:/ {printf "%d", $7/$2*100}')
if [ "$MEM_AVAILABLE_PCT" -lt 10 ]; then
    alert "Memory available critical: ${MEM_AVAILABLE_PCT}%"
elif [ "$MEM_AVAILABLE_PCT" -lt 20 ]; then
    alert "Memory available low: ${MEM_AVAILABLE_PCT}%"
else
    ok "Memory available: ${MEM_AVAILABLE_PCT}%"
fi

# 8. CPU (1-min load average vs core count)
NPROC=$(nproc)
LOAD_1M=$(awk '{print $1}' /proc/loadavg)
LOAD_OVER=$(awk -v l="$LOAD_1M" -v c="$NPROC" 'BEGIN { print (l > c * 1.5) ? 1 : 0 }')
if [ "$LOAD_OVER" -eq 1 ]; then
    alert "Load average high: ${LOAD_1M} across ${NPROC} cores"
else
    ok "Load average: ${LOAD_1M} across ${NPROC} cores"
fi

# 9. ClamAV — probe the actual Unix socket ScanUploadedFileJob connects to,
#    using the SAME config key and PING/PONG protocol as the real job
#    (app/Jobs/ScanUploadedFileJob.php:52,55,83). A running clamd process
#    with an unreachable/misconfigured socket previously reported "ok" here
#    while real uploads were silently failing — this replaces that check.
CLAMD_CHECK=$(php artisan tinker --execute="
    \$socket = config('document_processing.clamav_socket');
    if (!\$socket) { echo 'FAIL:clamav_socket not configured'; exit; }
    \$sock = @stream_socket_client(\"unix://{\$socket}\", \$errno, \$errstr, 5);
    if (!\$sock) { echo 'FAIL:'.\$errstr; exit; }
    fwrite(\$sock, \"PING\n\");
    \$resp = trim((string) fread(\$sock, 100));
    fclose(\$sock);
    echo \$resp === 'PONG' ? 'OK' : 'FAIL:unexpected response \"'.\$resp.'\"';
" 2>/dev/null | tail -1)

if [ "$CLAMD_CHECK" = "OK" ]; then
    ok "ClamAV socket: PONG"
else
    alert "ClamAV socket check failed: ${CLAMD_CHECK:-no response from tinker}"
fi

# 10. OCR / AI / embedding provider failures (real log message patterns confirmed in this codebase)
PROVIDER_ERRORS=$(grep -cE "Anthropic API error|Anthropic response (was not valid JSON|failed JSON decode)|GenerateEmbeddingsJob failed after retries" "$LOG_FILE" 2>/dev/null || echo 0)
PROVIDER_ERRORS_FILE="$STATE_DIR/provider_error_count"
LAST_PROVIDER_ERRORS=$(cat "$PROVIDER_ERRORS_FILE" 2>/dev/null || echo 0)
if [ "$PROVIDER_ERRORS" -gt "$LAST_PROVIDER_ERRORS" ]; then
    alert "AI/embedding provider errors grew from $LAST_PROVIDER_ERRORS to $PROVIDER_ERRORS (Anthropic/Voyage)"
else
    ok "AI/embedding provider error count stable at $PROVIDER_ERRORS"
fi
echo "$PROVIDER_ERRORS" > "$PROVIDER_ERRORS_FILE"

# 11. Upload / malware-scan failures (log-based; complements the direct socket probe in #9)
UPLOAD_ERRORS=$(grep -cE "Could not connect to clamd|Clamd scan error" "$LOG_FILE" 2>/dev/null || echo 0)
UPLOAD_ERRORS_FILE="$STATE_DIR/upload_error_count"
LAST_UPLOAD_ERRORS=$(cat "$UPLOAD_ERRORS_FILE" 2>/dev/null || echo 0)
if [ "$UPLOAD_ERRORS" -gt "$LAST_UPLOAD_ERRORS" ]; then
    alert "Upload/malware-scan errors grew from $LAST_UPLOAD_ERRORS to $UPLOAD_ERRORS"
else
    ok "Upload/scan error count stable at $UPLOAD_ERRORS"
fi
echo "$UPLOAD_ERRORS" > "$UPLOAD_ERRORS_FILE"

if [ "$FAILED" -eq 1 ]; then
    echo "==> Monitor check FAILED — see $ALERT_LOG"
    exit 1
fi
echo "==> All monitor checks passed."
exit 0
