#!/usr/bin/env bash

echo "=== developer config flags (expose_verification_code / expose_password_reset_token) ==="
grep -A 5 "'developer'" config/auth.php

echo ""
echo "=== what these config keys resolve to right now (production env) ==="
php artisan tinker --execute="echo 'expose_verification_code: ' . var_export(config('auth.developer.expose_verification_code'), true) . PHP_EOL; echo 'expose_password_reset_token: ' . var_export(config('auth.developer.expose_password_reset_token'), true) . PHP_EOL;"

echo ""
echo "=== mail driver configured for production (does an email actually get sent/delivered?) ==="
grep -E "^MAIL_MAILER=" .env.production

echo ""
echo "=== DONE ==="
