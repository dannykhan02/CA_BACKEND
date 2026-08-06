#!/bin/bash
echo "Starting Redis..."
sudo service redis-server start

echo "Starting ClamAV..."
sudo service clamav-daemon start
sleep 3

echo "Verifying..."
redis-cli ping
ls -la /var/run/clamav/clamd.ctl 2>&1 || echo "WARNING: clamd socket not found"

echo "Done. Now start Horizon and 'php artisan serve' manually in their own panes."
