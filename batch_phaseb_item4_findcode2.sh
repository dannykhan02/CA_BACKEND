#!/usr/bin/env bash

echo "=== anything in the log from today at all? ==="
grep -c "2026-09-02" storage/logs/laravel.log

echo ""
echo "=== last 15 lines of the log file, whatever they are ==="
tail -15 storage/logs/laravel.log

echo ""
echo "=== mail config: is there a separate log channel for mail? ==="
grep -A 3 "'log' =>" config/mail.php

echo ""
echo "=== any daily-rotated log files that might hold today's entries instead ==="
ls -la storage/logs/ | tail -10

echo ""
echo "=== queue: is the verification notification actually queued (ShouldQueue) rather than sent inline? ==="
find . -iname "VerificationCodeNotification.php" -exec cat {} \;

echo ""
echo "=== DONE ==="
