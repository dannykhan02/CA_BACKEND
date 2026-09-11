# Document credits implementation status

The balance, trial guard, upload gate, completion accounting, read endpoint,
browser fingerprint, and Paystack purchase flow are implemented. The confirmed
fixed package is **KES 2,000 for 100 documents**, configured once in
`config/credits.php` as **200,000 KES cents**. No exchange-rate conversion is used.

## Deployment question still pending (unchanged)

- There is no explicit trusted-proxy configuration in `bootstrap/app.php`.
  Railway documents `X-Real-IP`; Laravel's usual forwarded-header trust alone
  does not establish that this header is authentic. The checked-in Docker
  command starts Horizon, and does not establish how public HTTP is served.
  Confirm the public server and whether it is accessible only through Railway's
  edge before configuring trust. Signup currently uses `$request->ip()`;
  trial IP enforcement is **not verified for production** and may record the
  internal proxy address. No blanket header trust has been added.

Sources checked on 2026-09-11:

- [Paystack Kenya pricing and supported currencies](https://paystack.com/ke/pricing)
- [Paystack currency subunits](https://paystack.com/docs/api/#supported-currency): KES uses cents; amounts are multiplied by 100.
- [Initialize Transaction](https://paystack.com/docs/api/transaction/): server
  bearer secret, email, amount in the currency subunit, currency and unique
  reference; authorization URL is returned in `data.authorization_url`.
- [Webhook authentication](https://paystack.com/docs/payments/webhooks/):
  HMAC-SHA512 of the raw body using the secret key, compared against
  `x-paystack-signature` before interpreting payload content.
- [Railway request headers](https://docs.railway.com/networking/public-networking/specs-and-limits)

## Implemented behavior

- UUID workspace/user foreign keys follow the existing schema. Workspace
  observers initialize one zero balance; the migration backfills existing
  workspaces with zero. No retroactive trial grants are assumed.
- Both password signup and new Google accounts pass IP and optional fingerprint
  through `WorkspaceService`. Missing request context in console/setup calls
  creates a zero balance without granting a trial.
- Trial grants normalize email and match email OR IP OR non-null fingerprint.
  Each signal has its own unique index, so simultaneous competing grants cannot
  both win. Trial insertion and the ten-credit increment are transactional.
  Trials do not increment purchased totals. Account deletion preserves the grant
  with a null user ID. Workspace deletion is restricted while a trial grant
  references it, preserving the abuse guard.
- Upload authorization/validation remains in `UploadDocumentRequest`. The
  controller returns 402 at zero/below before file storage or dispatch. Uploads
  at one credit remain accepted without reserving or consuming credits.
- Both paths to Ready in `GenerateInsightsJob` lock the document and balance
  rows in a transaction. The balance floors at zero, with a warning for an
  overrun. `documents.credit_accounted_at` makes accounting once per document,
  including retries, reprocessing and zero-balance completions. Existing Ready
  documents are marked accounted during migration to avoid retroactive billing.
- `GET /api/workspace/credits` requires Sanctum and returns the usual
  `{success, message, data}` envelope with `documents_remaining` and
  `documents_purchased_total`. Both workspace types use `current_workspace_id`;
  client-supplied workspace IDs have no effect.
- The sibling frontend sends a SHA-256 hash of browser properties and canvas
  output on password signup and Google sign-in. No dependency was added.
  Browser API failures return null so signup remains available. As with any
  client-generated signal, clients can omit/change it; email and IP remain
  independent checks.

## Purchase API and deployment setup

- `POST /api/workspace/credits/purchases` requires Sanctum. Send
  `{"package":"documents-100"}`. Any member of the current workspace can
  purchase; supplied workspace IDs, prices and document counts are ignored.
- A successful initialization returns HTTP 201 with
  `{"success":true,"message":"Payment initialized.","data":{"authorization_url":"https://checkout.paystack.com/...","reference":"credits-..."}}`.
  Redirect the browser to `data.authorization_url`.
- Set `PAYSTACK_SECRET_KEY` and `PAYSTACK_PUBLIC_KEY` in deployment secrets.
  Their config and blank `.env.example` entries are already present.
- Apply migrations with `php artisan migrate --force` during deployment and
  rebuild cached configuration/routes using the normal deployment process.
- Configure `https://<api-domain>/api/paystack/webhook` in Paystack's dashboard.
  This public route uses raw-body HMAC-SHA512 authentication, with constant-time
  comparison; it requires no bearer token. Initialization explicitly sends
  `callback_url: https://classy-narwhal-44186a.netlify.app/#/billing/return`.
  **Manual dashboard step:** set this same URL as Paystack's default callback
  URL for fallback and reference. This dashboard setting has not been changed
  or verified by this implementation. Returning to that URL does not grant credits; the signed
  webhook does. Refresh `GET /api/workspace/credits` to show the resulting balance.

The ledger stores the server-generated unique reference, workspace, documents,
amount, currency, status and full decoded webhook payload. The purchase is
persisted before initialization so an early webhook can find it. Workspace
foreign keys restrict deletion to preserve the ledger. Currency and amount are
snapshots: later package edits cannot change the interpretation of a payment.

Only a signed `charge.success` with successful transaction status and matching
amount/currency completes a pending purchase. Purchase and balance row locks
serialize repeated deliveries and concurrent purchases; the completion, both
balance counters and payload are saved in one transaction. Repeated completed
references return 200 without changing balances or the original payload.
Unknown references and unrelated events are acknowledged without crediting.
Mismatched charges return 422, remain pending, and retain the payload for audit.
Invalid/missing signatures return 401; missing server credentials return 503.

Initialization uses the authenticated user's email and server-owned package
values. A definitive rejection becomes `failed`. Timeouts, server errors and
malformed successful responses remain `pending` because Paystack may have
accepted the request. These return 502 without exposing upstream details;
reconcile such references against Paystack before manual adjustment. There is
no automatic retry of initialization, and a late success webhook can complete
an ambiguous pending purchase. A concurrent webhook completion cannot be
replaced by an initialization error. Failed purchases are not credited.

Proxy-trust work remains pending deployment confirmation and was not changed
as part of the purchase implementation.

## File inventory

Validation: all 167 Laravel tests passed (658 assertions), including 22 mocked
purchase tests and two
independent PostgreSQL workers completing against one credit. The frontend
`npm run typecheck`, PHP formatting, and both repositories' diff whitespace
checks passed. Tests used an isolated PostgreSQL cluster under `/tmp`; no
production migrations or external payment requests were run.

Created in the backend:

- `app/Http/Controllers/Api/CreditPurchaseController.php`
- `app/Exceptions/PaystackInitializationException.php`
- `app/Services/PaystackClient.php`
- `app/Models/CreditPurchase.php`
- `database/migrations/2026_09_11_000002_create_credit_purchases_table.php`
- `tests/Feature/CreditPurchaseTest.php`
- `app/Http/Controllers/Api/WorkspaceCreditController.php`
- `app/Models/TrialGrant.php`
- `app/Models/WorkspaceCredit.php`
- `app/Services/WorkspaceCreditService.php`
- `config/credits.php`
- `database/migrations/2026_09_11_000001_create_workspace_credits_table.php`
- `database/migrations/2026_09_11_000003_create_trial_grants_table.php`
- `database/migrations/2026_09_11_000004_add_credit_accounted_at_to_documents.php`
- `tests/Feature/WorkspaceCreditConcurrencyTest.php`
- `tests/Feature/WorkspaceCreditsTest.php`
- `docs/DOCUMENT_CREDITS.md`

Changed in the backend:

- `.env.example`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/DocumentUploadController.php`
- `app/Http/Requests/Auth/GoogleSigninRequest.php`
- `app/Http/Requests/Auth/SignupRequest.php`
- `app/Jobs/GenerateInsightsJob.php`
- `app/Models/Workspace.php`
- `app/Observers/WorkspaceObserver.php`
- `app/Services/WorkspaceService.php`
- `config/services.php`
- `routes/api.php`
- `tests/Feature/DocumentUploadAuthorizationTest.php`

Sibling frontend (`../CA`): created `src/lib/browserFingerprint.ts`; changed
`src/auth.tsx`.

Files added in the KES purchase follow-up: `CreditPurchaseController.php`,
`PaystackInitializationException.php`, `PaystackClient.php`, `CreditPurchase.php`,
`2026_09_11_000002_create_credit_purchases_table.php`, and
`tests/Feature/CreditPurchaseTest.php`. Files updated in that follow-up:
`app/Models/Workspace.php`, `config/credits.php`, `routes/api.php`, and this document.
