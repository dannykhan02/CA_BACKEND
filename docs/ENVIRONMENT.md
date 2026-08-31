# Environment Diagnostics

## `php artisan env:info`

Read-only diagnostic command reporting the exact facts that would have
caught two real environment-confusion incidents immediately: a server
process resolving to the wrong database, and Horizon writing to a Redis
prefix nobody was watching.

Run it any time you're not sure what environment you're actually pointed
at — especially after switching profiles via `switch-env.sh`, or before
trusting any other verification in this repo.


Reports:
- Resolved DB connection name, host, port, database
- Resolved Redis host, port, key prefix (both `database.redis.options.prefix`
  and Horizon's own `horizon.prefix`), plus a live `Redis::connection()->ping()`
- Current `app.env` and `app.debug`

This command is diagnostic only — it always exits `0` and never fails a
build or deploy. `php artisan config:check-production-safety` is the
actual hard gate for production safety; this command exists so a human
can eyeball the resolved config before trusting anything else.

It's wired in as the first command run by both `bin/smoke-test.sh`
and `bin/provision.sh`.
