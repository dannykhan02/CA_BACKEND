# Referrals and Personal document processing

## Referral behavior

Authenticated users retrieve their automatically generated code at
`GET /api/referrals/my-code`. The usual `{success, message, data}` envelope
contains `code`, `link`, `reward_documents`, and `summary` with `signups`,
`rewarded`, and `credits_earned`. The frontend maps these fields to camelCase
and displays the link, code, copy buttons, and totals in Account settings.

Links use `/#/signup?referral_code=CODE`. Password signup and new Google
accounts accept the optional `referral_code`; unrecognized codes are ignored.
Existing Google users cannot acquire or replace a referral when signing in.
Signup records a pending referral and grants no referral credits.

Eligibility is strict: the signup must win the existing trial grant. A match
against **any** existing trial's normalized email, IP, or nonempty fingerprint
leaves the referral pending with `reward_eligible=false` and
`ineligible_reason=trial_abuse_signal_match`, permanently. This includes shared
IPs and signals preserved after account deletion, even if a later purchase is
successful. Null fingerprints do not match other nulls. The trial service's
actual insertion result decides eligibility, including simultaneous signups;
the referral flow does not run a separate approximation of the checks.
Self-referrals are rejected by user ID or normalized email, with an additional
identity check at payout. Email aliases are not collapsed beyond the trial
system's existing lowercase/trim normalization.

The existing deployment uncertainty about trusted proxies and `$request->ip()`
also applies here; see [Document credits](DOCUMENT_CREDITS.md). The fingerprint
is a best-effort client signal, not proof of a distinct person.

After the Paystack handler verifies a successful charge, the first completed
purchase for the authenticated purchaser rewards their eligible referral.
Purchases now snapshot `user_id` at initialization. Historical purchases retain
null because their purchaser cannot reliably be inferred from a shared
workspace. Referrals are available only to new signups, with no retroactive
attribution for existing accounts.

`WorkspaceCreditService` credits trials, purchases, and rewards through one
balance-mutation method. The purchase, reward, and balance updates share a
transaction. Purchaser row locking serializes different purchases across
workspaces; referral row locking and the rewarded status prevent duplicate
rewards. Failed charges, repeated webhooks, and later purchases earn no reward.

Rewards go to the **referrer's Personal workspace**, even when an Organization
workspace is active. If a legacy account has only an Organization workspace,
the service creates a Personal workspace without switching the active workspace
or granting another trial. `credits.referral_reward_documents` is configured
separately from trials by `REFERRAL_REWARD_DOCUMENTS`, defaulting to **10**.
Each referral records its awarded amount, workspace, and timestamp. Rewards do
not inflate purchased totals; summary totals use historical awarded amounts
and survive deletion of referred accounts.

Deploy the backend migration before serving the new API/frontend. Set
`REFERRAL_REWARD_DOCUMENTS` and rebuild Laravel's configuration cache if needed.
No migration of production data is performed by tests.

## Personal documents

The actual `Needs Review` writes are in `ExtractDocumentTextJob::fallbackToOcr`
(provider resolution or PDF rasterization errors) and `OcrPageBatchJob`
(provider/extraction exceptions or no readable OCR text). They are not based on
classification. These paths now set `Failed` for Personal workspaces and keep
the existing `Needs Review` behavior for Organizations. Successful analysis,
including reuse of unchanged analysis, goes directly to `Ready` in
`GenerateInsightsJob` for every classification.

The PostgreSQL `documents_classification_check` permits only Public, Internal,
Confidential, and Restricted. The column is NOT NULL and has no database default.
`UploadDocumentRequest` supplies Internal for omitted/null/empty Personal upload
classification; Organization validation is unchanged. The Personal upload UI
hides both classification controls and omits the field on uploads and retries.
`DocumentPolicy` and Personal listing isolation remain based on uploader
ownership, regardless of classification.

## Verification

Run `php artisan test` in the backend against the dedicated PostgreSQL/pgvector
test database. Tests cover actual OCR review triggers, all classifications,
ownership isolation, signup eligibility, signed payment webhooks, rollback,
history, and independent workers racing code creation, trial signals, and first
purchases. In the frontend, run `npm test`, `npm run typecheck`, `npm run lint`,
and `npm run build`. Interaction tests exercise upload controls and request
fields, referral summaries/copy/retry, and referral propagation through password
and Google signup.
