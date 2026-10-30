#!/usr/bin/env python3
"""Run PHPUnit only against the isolated local Matters database; HTTP is faked by Tests\\TestCase."""
import os
import subprocess
import sys
from pathlib import Path
root=Path(__file__).resolve().parents[1]
env=os.environ.copy()
env.update(APP_ENV='testing', DB_CONNECTION='pgsql', DB_HOST='127.0.0.1', DB_PORT='55432', DB_DATABASE='ca_document_intelligence_test', DB_USERNAME='postgres', DB_PASSWORD='', DB_URL='', DB_SSLMODE='disable', CACHE_STORE='array', QUEUE_CONNECTION='sync', ANTHROPIC_API_KEY='test-key', VOYAGE_API_KEY='', RESEND_API_KEY='', PAYSTACK_SECRET_KEY='', PULSE_ENABLED='false', TELESCOPE_ENABLED='false', NIGHTWATCH_ENABLED='false')
if (root/'bootstrap/cache/config.php').exists():
    raise SystemExit('Refusing cached configuration')
raise SystemExit(subprocess.call(['php','vendor/bin/phpunit',*sys.argv[1:]],cwd=root,env=env))
