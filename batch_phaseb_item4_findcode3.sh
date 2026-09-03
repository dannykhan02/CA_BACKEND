#!/usr/bin/env bash
DB_URL="postgresql://postgres:password123@127.0.0.1:5433/ca_document_intelligence_local"

echo "=== actual log lines timestamped today (with context) ==="
grep -A 2 "2026-09-02" storage/logs/laravel.log

echo ""
echo "=== is the verification job sitting in the jobs table or failed? ==="
echo "(querying PRODUCTION Neon, since that's what .env currently points at)"
php artisan tinker --execute="
echo 'pending jobs: ' . DB::table('jobs')->count() . PHP_EOL;
echo 'failed jobs: ' . DB::table('failed_jobs')->count() . PHP_EOL;
DB::table('failed_jobs')->orderByDesc('id')->limit(3)->get(['id','queue','exception'])->each(function(\$j) {
    echo '---' . PHP_EOL . substr(\$j->exception, 0, 500) . PHP_EOL;
});
"

echo ""
echo "=== which Redis/queue connection does THIS env actually point at? ==="
grep -E "^QUEUE_CONNECTION=|^REDIS_HOST=|^REDIS_PORT=" .env

echo ""
echo "=== is a Horizon worker actually running RIGHT NOW, watching that connection? ==="
php artisan horizon:status
php artisan horizon:list 2>/dev/null || echo "(horizon:list may not be a real command — ignore if so)"

echo ""
echo "=== DONE ==="
