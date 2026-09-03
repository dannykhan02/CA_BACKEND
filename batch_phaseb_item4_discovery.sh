#!/usr/bin/env bash

echo "=== auth-related routes ==="
php artisan route:list --path=auth

echo ""
echo "=== signup validation rules (so we send the right fields) ==="
find . -iname "*RegisterRequest*.php" -o -iname "*SignupRequest*.php" -o -iname "*SignUpRequest*.php" 2>/dev/null | xargs -I{} cat {} 2>/dev/null

echo ""
echo "=== AuthController (signup/signin methods) ==="
find . -iname "AuthController.php" -exec cat {} \;

echo ""
echo "=== DONE ==="
