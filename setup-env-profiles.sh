#!/usr/bin/env bash
# setup-env-profiles.sh — one-time setup. Creates two named, switchable env
# profiles instead of relying on shell-exported vars (which is what caused
# the server/CLI DB split earlier).
#
#   .env.production    — exact copy of your CURRENT .env (Neon), untouched
#   .env.local-testing  — same file, with only DB_* swapped to local Postgres
#
# Your real .env is never guessed at or hand-edited here — production
# values are captured by copying the file you already have working.
#
# Run once, from the Laravel project root.

set -euo pipefail
ENV_FILE=".env"

if [[ ! -f "$ENV_FILE" ]]; then
    echo "ERROR: $ENV_FILE not found. Run from the project root." >&2
    exit 1
fi

if [[ -f ".env.production" ]]; then
    echo ".env.production already exists — not overwriting it. Delete it first if you want to recapture."
else
    cp "$ENV_FILE" ".env.production"
    echo "Captured current .env -> .env.production (this is your real Neon config, untouched)."
fi

echo ""
echo "Current DB_* in .env.production:"
grep -E "^DB_" ".env.production"

if [[ -f ".env.local-testing" ]]; then
    echo ""
    echo ".env.local-testing already exists — leaving it as-is."
else
    cp ".env.production" ".env.local-testing"

    declare -A LOCAL_DB=(
      [DB_CONNECTION]=pgsql
      [DB_HOST]=127.0.0.1
      [DB_PORT]=5433
      [DB_DATABASE]=ca_document_intelligence_local
      [DB_USERNAME]=postgres
      [DB_PASSWORD]=password123
      [DB_SSLMODE]=prefer
    )

    for KEY in "${!LOCAL_DB[@]}"; do
        VALUE="${LOCAL_DB[$KEY]}"
        if grep -q "^${KEY}=" ".env.local-testing"; then
            sed -i "s|^${KEY}=.*|${KEY}=${VALUE}|" ".env.local-testing"
        else
            echo "${KEY}=${VALUE}" >> ".env.local-testing"
        fi
    done

    echo ""
    echo "Created .env.local-testing — DB_* overridden to local Postgres:"
    grep -E "^DB_" ".env.local-testing"
fi

echo ""
echo "Done. Two profiles now exist:"
echo "  .env.production     (Neon — real data)"
echo "  .env.local-testing  (local Postgres — safe to break)"
echo ""
echo "Neither has been activated yet. Use switch-env.sh to activate one."