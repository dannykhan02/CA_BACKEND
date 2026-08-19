CA Backend — Deployment & Environment Setup Runbook

Keep everything you already have through §6. Then add these sections.

7. Environment separation: development vs production

The local .env must never be treated as the production configuration.

Each environment should have its own environment variables:

Development
    .env

Production
    server/platform environment variables
    OR production-only .env outside source control
Development

Typical values:

APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
Production

Production must use:

APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
Important rule

Never commit:

.env
.env.production
.env.local

to Git.

Verify:

git status
git ls-files .env

Expected:

.env should NOT appear in git ls-files

Also verify .gitignore contains:

.env
.env.*
!.env.example
8. Production environment variables

Create and maintain a complete .env.example.

It should contain variable names but never real secrets.

Example:

APP_NAME="CA Backend"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=pgsql
DB_HOST=
DB_PORT=5432
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

CACHE_STORE=redis
QUEUE_CONNECTION=redis

REDIS_HOST=
REDIS_PORT=6379
REDIS_PASSWORD=

FILESYSTEM_DISK=local

CLAMAV_ENABLED=true
CLAMAV_SOCKET=/var/run/clamav/clamd.ctl

OCR_DEFAULT_PROVIDER=tesseract

VOYAGE_API_KEY=
VOYAGE_MODEL=voyage-4

OPENAI_API_KEY=
ANTHROPIC_API_KEY=

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=

RESEND_API_KEY=
MAIL_MAILER=
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=

POWERBI_CLIENT_ID=
POWERBI_CLIENT_SECRET=
POWERBI_TENANT_ID=
POWERBI_WORKSPACE_ID=

Before deployment, compare the production environment against this file.

A useful check:

php artisan about

Then:

php artisan config:show database
php artisan config:show cache
php artisan config:show queue

Do not print secret values into logs or terminal output.

9. Secret rotation rule

During the testing above, a real Voyage API key and Sanctum tokens were exposed in terminal output.

Those credentials should now be considered compromised.

Rotate:
Voyage API key
Any exposed Sanctum token
Any other credential pasted into chat, GitHub, screenshots, logs, or tickets

Do not reuse the exposed values.

For future testing, use:

TOKEN="..."

locally and never commit the command to a script.

Also make sure:

git grep "pa-" 

and:

git grep "VOYAGE_API_KEY="

do not reveal a real key in tracked files.

10. Deployment prerequisites

Before deploying a fresh server/container, verify the following exist:

Runtime
php -v
composer --version
php artisan --version

Required PHP extensions should be confirmed with:

php -m

At minimum verify the extensions required by the Laravel application and database driver.

Node/frontend

If the backend build does not require Node, keep frontend deployment separate.

If frontend assets are built in this repository:

node -v
npm -v
npm ci
npm run build
External services

Confirm:

PostgreSQL/MySQL
Redis
ClamAV
Tesseract
Voyage
LLM provider
Google OAuth
Email provider
Power BI
Object/file storage

are available before marking deployment complete.

11. Fresh-server deployment procedure

The production deployment should eventually follow this exact sequence.

git clone <repository>
cd backend

Install PHP dependencies:

composer install --no-dev --optimize-autoloader

Create production configuration:

cp .env.example .env

Then populate production environment variables.

Generate the application key if this is a new application installation:

php artisan key:generate --force

Do not regenerate the key during a normal deployment of an existing production application.

Clear old cached configuration:

php artisan optimize:clear

Run migrations:

php artisan migrate --force

Cache production configuration:

php artisan config:cache
php artisan route:cache
php artisan view:cache

If the application uses storage linking:

php artisan storage:link

Restart queue processing:

php artisan horizon:terminate

Then start/restart Horizon using the production process supervisor.

12. Production process management

Do not use:

php artisan horizon &

as the final production solution.

The process must survive:

SSH logout
server restart
application deployment
process crash

Recommended production architecture:

                    ┌──────────────┐
                    │    Nginx     │
                    └──────┬───────┘
                           │
                           ▼
                    ┌──────────────┐
                    │ PHP-FPM      │
                    │ Laravel API  │
                    └──────┬───────┘
                           │
             ┌─────────────┼──────────────┐
             ▼             ▼              ▼
          Database       Redis         File Storage
                           │
                           ▼
                       Horizon
                           │
             ┌─────────────┼──────────────┐
             ▼             ▼              ▼
          OCR jobs      AI jobs       Scan jobs

Use either:

systemd

or:

Supervisor

for Horizon.

13. Horizon production configuration

Create a production Horizon configuration.

Example:

'environments' => [

    'production' => [

        'supervisor-main' => [

            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 5,
            'tries' => 3,
            'timeout' => 120,
            'maxTime' => 3600,
            'maxJobs' => 500,
        ],
    ],

    'local' => [
        // development configuration
    ],
],

Tune these values according to the production server resources.

After deployment:

php artisan horizon:status

Expected:

Horizon is running
14. Health checks

Add a simple health endpoint before production.

Example:

GET /api/health

Expected:

{
    "success": true,
    "message": "Service healthy."
}

It should verify basic application availability.

Do not expose:

database passwords
API keys
stack traces
filesystem paths
internal exception messages

A deeper internal health check can verify Redis/database connectivity without exposing those details publicly.

15. Error handling and information disclosure

This was one of the important things you just tested.

Provider failure

A Voyage failure must never expose:

Voyage API status
Voyage response body
RuntimeException
class names
file paths
line numbers
stack trace
API credentials

Expected external response:

HTTP/1.1 503 Service Unavailable
{
    "success": false,
    "message": "Service temporarily unavailable.",
    "errors": []
}

The actual provider error should only be available internally through logging.

16. Error-handling regression tests

These should become permanent deployment tests.

Test 1: normal Q&A
curl -i -s -X POST \
  "http://localhost:8000/api/documents/query" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"question":"What is the unique marker phrase in the isolation test document?"}'

Expected:

HTTP/1.1 200 OK

and:

{
    "success": true
}
Test 2: no-answer behavior
curl -i -s -X POST \
  "http://localhost:8000/api/documents/query" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"question":"What was the population of Mars according to this report?"}'

Expected:

HTTP/1.1 200 OK

with:

{
    "success": true,
    "message": "No answer.",
    "data": {
        "confidence": "none",
        "cited_document_ids": []
    }
}
Test 3: invalid authentication
curl -i -s -X GET \
  "http://localhost:8000/api/documents/$DOCUMENT_ID" \
  -H "Authorization: Bearer invalid-token-12345" \
  -H "Accept: application/json"

Expected:

HTTP/1.1 401 Unauthorized
{
    "success": false,
    "message": "Unauthenticated.",
    "errors": []
}
Test 4: missing authentication
curl -i -s -X GET \
  "http://localhost:8000/api/documents/$DOCUMENT_ID" \
  -H "Accept: application/json"

Expected:

HTTP/1.1 401 Unauthorized
Test 5: authenticated but unauthorized

Create/use a token belonging to another workspace/user:

OTHER_TOKEN="..."

Then:

curl -i -s -X GET \
  "http://localhost:8000/api/documents/$DOCUMENT_ID" \
  -H "Authorization: Bearer $OTHER_TOKEN" \
  -H "Accept: application/json"

Expected:

HTTP/1.1 403 Forbidden
{
    "success": false,
    "message": "This action is unauthorized.",
    "errors": []
}

You have already successfully demonstrated this test on 2026-08-13.

17. Upstream provider failure test

This should become part of the deployment checklist.

Temporarily configure:

VOYAGE_API_KEY=invalid-test-key

Then:

php artisan optimize:clear
php artisan horizon:terminate

Run:

curl -i -s -X POST \
  "http://localhost:8000/api/documents/query" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"question":"What is the unique marker phrase in the isolation test document?"}'

Expected:

HTTP/1.1 503 Service Unavailable
{
    "success": false,
    "message": "Service temporarily unavailable.",
    "errors": []
}

Restore the real production secret immediately after testing.

18. Production APP_DEBUG verification

Before deployment:

php artisan tinker --execute="dump(config('app.debug'));"

Production must output:

false

Also verify:

grep '^APP_DEBUG=' .env

Expected:

APP_DEBUG=false

Never rely on the exception handler as the primary security mechanism.

The correct model is:

APP_DEBUG=false
        +
generic exception responses
        +
internal logging
        +
sanitized provider exceptions
19. Logging policy

Production logs should contain enough information for debugging without leaking secrets.

Good:

Voyage embedding request failed
provider=voyage
status=401
document_id=...
workspace_id=...

Bad:

Authorization: Bearer ...
VOYAGE_API_KEY=...
password=...
full provider response containing credentials

Never log:

API keys
passwords
Sanctum tokens
OAuth client secrets
session tokens
authorization headers
20. File upload security checks

Before production, verify:

Extension validation
MIME validation
Maximum file size
Filename sanitization
Storage outside public directory
Malware scanning
OCR handling
Extraction failure handling
Authorization
Workspace ownership

The upload pipeline should be:

Upload
  ↓
Validate
  ↓
Store privately
  ↓
Malware scan
  ↓
Extract text
  ↓
OCR fallback if required
  ↓
Chunk document
  ↓
Generate embeddings
  ↓
Store vectors
  ↓
Generate insights
  ↓
Ready

A failure at any stage must result in an explicit failed state, not silently become:

Ready
21. Deployment database procedure

Before migrations:

php artisan migrate:status

Then:

php artisan migrate --force

Never use:

php artisan migrate:fresh

on production.

Never run:

php artisan db:wipe

on production.

For destructive schema changes:

Backup
↓
Migration
↓
Application deployment
↓
Smoke test
↓
Monitor
22. Deployment rollback plan

Every production deployment must have a rollback strategy.

Before deployment:

git rev-parse HEAD

Record the commit:

Previous release:
abc123...

If deployment fails:

git checkout <previous-good-commit>
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan horizon:terminate

Then restart PHP/Horizon using the production process manager.

Database warning

Code rollback does not automatically mean database rollback.

Prefer forward-compatible migrations.

For example:

Release A
   ↓
Add nullable column
   ↓
Release B uses column
   ↓
Release C makes column required

rather than immediately deleting/renaming columns that an older application version still requires.

23. Deployment smoke-test checklist

After every deployment:

[ ] API responds
[ ] Health endpoint works
[ ] Authentication works
[ ] Unauthorized access returns 403
[ ] Unauthenticated access returns 401
[ ] Document listing works
[ ] Document retrieval works
[ ] Document upload works
[ ] Malware scan works
[ ] OCR works
[ ] DOCX extraction works
[ ] PDF extraction works
[ ] Q&A works
[ ] No-answer behavior works
[ ] Voyage failure returns 503
[ ] Horizon is running
[ ] Redis is connected
[ ] Database is connected
[ ] Logs contain no secrets
[ ] APP_DEBUG=false
[ ] HTTPS works
24. Automated deployment checks

This is the big missing piece I would prioritize.

Right now you are manually proving things with curl.

Eventually create:

tests/
├── Feature/
│   ├── AuthenticationTest.php
│   ├── DocumentAuthorizationTest.php
│   ├── DocumentUploadTest.php
│   ├── DocumentQaTest.php
│   ├── ErrorHandlingTest.php
│   └── HealthCheckTest.php
└── Unit/
    ├── VoyageEmbeddingClientTest.php
    ├── OcrEngineResolverTest.php
    └── ...

Then deployment can run:

php artisan test

instead of relying entirely on manual testing.

25. CI/CD pipeline

Eventually your deployment should become:

git push
    ↓
GitHub
    ↓
Install dependencies
    ↓
Static checks
    ↓
PHP syntax checks
    ↓
Laravel tests
    ↓
Security checks
    ↓
Build
    ↓
Deploy
    ↓
Run migrations
    ↓
Clear/cache config
    ↓
Restart Horizon
    ↓
Health check
    ↓
Smoke tests
    ↓
Deployment complete

Recommended GitHub workflow:

.github/
└── workflows/
    ├── tests.yml
    └── deploy.yml
26. bin/ deployment scripts

Your current §6 should eventually become:

bin/
├── provision.sh
├── deploy.sh
├── health-check.sh
└── smoke-test.sh
deploy.sh

Eventually responsible for:

set -e

composer install --no-dev --optimize-autoloader

php artisan migrate --force

php artisan optimize:clear

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan horizon:terminate

# restart application workers/processes

./bin/health-check.sh

Do not put actual secrets inside these scripts.

27. Dependency reproducibility

Always deploy using:

composer install

not:

composer update

Production should use the committed:

composer.lock

Check:

git status
git diff composer.lock

before deployment.

If composer.lock changes unexpectedly, stop and investigate.

28. Storage and backup strategy

Before production, document:

Database backup frequency
Document backup location
Retention period
Recovery procedure
Maximum acceptable data loss
Maximum acceptable downtime

At minimum:

Database
    ↓
automated backups

Uploaded documents
    ↓
persistent/private storage

Do not rely on the server's local disk as the only copy of uploaded documents.

29. Production monitoring

Add monitoring for:

API availability
5xx responses
Queue failures
Horizon workers
Redis
Database
Disk space
Memory
CPU
ClamAV
OCR failures
AI provider failures
Embedding provider failures
Upload failures

Particularly monitor:

failed_jobs

and Horizon.

Useful commands:

php artisan horizon:status
php artisan queue:failed
30. Final production readiness gate

Do not call the backend production-ready until all of these are green:

Infrastructure
[ ] Database
[ ] Redis
[ ] ClamAV
[ ] Tesseract
[ ] File storage
[ ] Process supervisor
Security
[ ] APP_DEBUG=false
[ ] HTTPS
[ ] Secrets outside Git
[ ] Secrets rotated
[ ] Authorization tested
[ ] 401 tested
[ ] 403 tested
[ ] Error disclosure tested
[ ] Upload validation tested
[ ] Malware scanning tested
AI/document pipeline
[ ] DOCX extraction
[ ] PDF extraction
[ ] OCR fallback
[ ] Chunking
[ ] Embeddings
[ ] Vector retrieval
[ ] Q&A
[ ] No-answer behavior
[ ] Provider failure → 503
[ ] Insight generation
Operations
[ ] Automated deployment
[ ] Automated tests
[ ] Health endpoint
[ ] Logging
[ ] Monitoring
[ ] Database backups
[ ] Document backups
[ ] Rollback procedure
[ ] Horizon auto-restart
31. Current status as of 2026-08-13

Add this because your document is a living runbook.

Confirmed
[GREEN] Voyage provider failure is sanitized to HTTP 503
[GREEN] Provider failure does not expose Voyage status in response
[GREEN] Provider failure does not expose stack trace
[GREEN] Provider failure does not expose filesystem path
[GREEN] Normal Q&A still works after recovery
[GREEN] No-answer Q&A behavior works
[GREEN] Invalid token returns 401
[GREEN] Missing authentication returns 401
[GREEN] Cross-workspace document access returns 403
[GREEN] Cross-workspace error message is sanitized
[GREEN] Security headers are present
[GREEN] Horizon restart procedure confirmed
Still needs verification
[YELLOW] Production APP_DEBUG=false
[YELLOW] Production .env/environment variables documented
[YELLOW] Production secrets rotated
[YELLOW] Real PDF upload
[YELLOW] Real DOCX upload
[YELLOW] Scanned PDF OCR fallback
[YELLOW] GenerateInsightsJob on real document
[YELLOW] Failed-job recovery
[YELLOW] Production Horizon supervisor
[YELLOW] Production database backup
[YELLOW] Production document backup
[YELLOW] Health endpoint
[YELLOW] Automated feature tests
[YELLOW] CI/CD pipeline
[YELLOW] Deployment script
[YELLOW] Rollback procedure tested
[YELLOW] Production HTTPS/reverse proxy
Important security note

The credentials appearing in the terminal output above should not be preserved in this runbook. Record only that credential rotation is required, never the actual key/token.

The deployment path I would use for your project

Don't jump straight from your current WSL setup to production. Build it in this order:

CURRENT STATE
     │
     ▼
1. Finish application tests
     │
     ▼
2. Create .env.example
     │
     ▼
3. Remove/rotate exposed secrets
     │
     ▼
4. Add health endpoint
     │
     ▼
5. Convert curl tests → Laravel Feature tests
     │
     ▼
6. Create smoke-test script
     │
     ▼
7. Create deploy.sh
     │
     ▼
8. Containerize OR provision a clean Linux server
     │
     ▼
9. Configure PostgreSQL + Redis + ClamAV + Tesseract
     │
     ▼
10. Configure Nginx + PHP-FPM + HTTPS
     │
     ▼
11. Configure Supervisor/systemd + Horizon
     │
     ▼
12. Configure production secrets
     │
     ▼
13. Deploy
     │
     ▼
14. Run migrations + caches
     │
     ▼
15. Run automated tests
     │
     ▼
16. Run smoke tests
     │
     ▼
17. Upload real documents
     │
     ▼
18. Test complete AI pipeline
     │
     ▼
19. Test provider failure / 401 / 403
     │
     ▼
20. Verify logs + monitoring
     │
     ▼
          🟢 PRODUCTION

The most important next work is not another manual curl. Your 503, 401 and 403 evidence is already solid. The next maturity jump is turning those exact checks into automated Laravel tests and a repeatable deployment script. That is what turns your current "I know how I fixed this machine" runbook into "someone can deploy this backend to a fresh server and get the same result."






TOKEN="PASTE_TOKEN"
for f in \
  "Service_Charter_Monitoring_Q1_FY2025-2026.xlsx" \
  "No_5_Quarter_snd_mid-year_performance_contract_monitoring_and_Evaluation_Reports.pdf" \
  "ANNUAL_REPORT_OF_SERVICE_CHARTER_COMPLIANCE_MONITORING_AND_EVALUATION_FOR_THE_FINANCIAL_YEAR_2024-2025_final.docx" \
  "Annual_Report_Service_Charter_Compliance_FY_2025-2026.pdf"
do
  echo "=== $f ==="
  curl -s -w "\nHTTP_STATUS:%{http_code}\n" \
    -H "Authorization: Bearer $TOKEN" \
    -F "file=@$f" \
    -F "classification=Internal" \
    http://localhost:8000/api/documents
  echo ""
done



TOKEN="23|ZYnDSFtFvbOd84UrPXloAbScU05SFE9scOoCFLnj943f7c40"
for f in \
  "Service_Charter_Monitoring_Q1_FY2025-2026.xlsx" \
  "No_5_Quarter_snd_mid-year_performance_contract_monitoring_and_Evaluation_Reports.pdf" \
  "ANNUAL_REPORT_OF_SERVICE_CHARTER_COMPLIANCE_MONITORING_AND_EVALUATION_FOR_THE_FINANCIAL_YEAR_2024-2025_final.docx" \
  "Annual_Report_Service_Charter_Compliance_FY_2025-2026.pdf"
do
  echo "=== $f ==="
  curl -s -w "\nHTTP_STATUS:%{http_code}\n" \
    -H "Authorization: Bearer $TOKEN" \
    -F "file=@$f" \
    -F "classification=Internal" \
    http://localhost:8000/api/documents
  echo ""
done