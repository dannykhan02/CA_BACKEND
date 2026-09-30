# DocIntel legal and privacy implementation audit — 2026-09-30

This is an implementation inventory and a publication review list, not a legal opinion or a claim of regulatory compliance. It was prepared from the checked-in React and Laravel repositories and existing infrastructure notes. Live account settings, provider contracts, deployed environment variables, and current provider regions were not inspected. Do not publish the policy drafts as approved legal terms until the open items below are resolved.

## Data flow evidenced by the repositories

1. The React app collects account details and a browser-signal fingerprint for signup and Google authentication (`CA/src/pages/SignUpPage.tsx`, `CA/src/lib/browserFingerprint.ts`, `CA/src/auth.tsx`). The Laravel API stores account, workspace, verification, authentication token, role and activity records (`app/Http/Controllers/Api/AuthController.php`, `app/Models/User.php`, `database/migrations`). Google Identity Services is loaded by the frontend auth pages and its token is verified by the API.
2. Users upload documents to the Laravel API. Private object storage is configured through the S3-compatible filesystem, and deployment documentation identifies Cloudflare R2 (`config/filesystems.php`, `app/Http/Controllers/Api/DocumentUploadController.php`). Queued jobs extract text, classify documents, detect findings, generate OCR and embeddings, and store results and processing metadata in PostgreSQL (`app/Jobs`, `app/Models/Document.php`, `app/Services/AnthropicClient.php`, `app/Services/Embeddings/VoyageEmbeddingClient.php`). The repository documents Neon as the deployed database and Railway as API/worker hosting (`docs/PHASE_2_VERIFICATION_REPORT.md`, `docs/ENV_2_WORKER_ISOLATION_2026_09_12.md`). The latter reports a US East Neon endpoint; current deployment must be checked again.
3. Anthropic receives document text, excerpts, prompts or page images for applicable analysis and OCR features. Voyage AI receives extracted text chunks for embeddings. Results are persisted in document, intelligence, Matter, comparison, KPI and related tables; `DocumentAiRun` records provider/model/prompt-version and usage metadata. Matter intelligence and What Changed can send document-derived data through these same AI pathways (`app/Services`, `app/Jobs`, Matter and comparison controllers). Some operations are user requested and some run automatically after upload; document classification affects processing but does not prove every sensitive category is blocked.
4. Paystack receives checkout and payment data; the API stores transaction references, subscription and purchase state, and selected provider responses (`config/services.php`, payment controllers/services, `app/Models/CreditPurchase.php`). The app does not present a raw card-entry form. Laravel sends verification, reset, welcome and other transactional notifications through the configured mail transport (`config/mail.php`, `app/Notifications`); Resend is integrated but the effective production transport requires confirmation.
5. Application and worker logs, queue/failed-job records, Pulse and Horizon telemetry, and optional Sentry error reporting may contain account identifiers, document metadata or exception context (`config/logging.php`, `config/sentry.php`, `routes/console.php`, `config/horizon.php`). Export features create downloadable derived information. Workers use temporary files during processing; failure and backup cleanup require operational verification. A Power BI database reader can be provisioned for a workspace, but this is conditional, not evidence that Microsoft receives every customer's data.

## Recipient and subprocessor inventory

| Recipient | What the code or deployment notes support | Status to confirm |
| --- | --- | --- |
| Anthropic | Third-party model API for document analysis, classification, OCR, findings, questions and comparisons | Confirm exact account contract, region, training/retention settings and transfer terms |
| Voyage AI | Embedding API receiving extracted text chunks | Confirm contract, retention/training settings and region |
| Cloudflare R2 | Private document object storage configured via S3-compatible disk | Confirm bucket region/jurisdiction, retention and deletion behavior |
| Neon | PostgreSQL application data; prior deployment evidence records US East endpoint | Confirm live branch, region, backups and retention |
| Railway | Laravel API and workers; logs/queues may process account and document-derived data | Confirm live services/regions, Redis/queue configuration and log retention |
| Paystack | Hosted checkout, subscriptions and payment verification | Confirm merchant entity, DPA/terms, transfer and retention details |
| Google | Optional identity sign-in; Identity Services script and Google Fonts requests are present in the shared HTML and load on page visits | Confirm Google client configuration, browser storage and applicable notice/choice requirements |
| Resend | Supported transactional mail transport | Confirm effective production transport; otherwise name actual provider |
| Sentry | Conditional backend error reporting when DSN is configured | Confirm activation, PII settings, sampling, region and retention |
| Microsoft Power BI | Conditional workspace reporting reader | Confirm whether enabled and customer agreement before listing as active |

`@supabase/supabase-js` appears in frontend dependencies, but no active source import was found; it is not listed as a current recipient. Composer also supports alternative mail transports; a package alone does not establish active processing. Frontend hosting/CDN, domain/DNS, production backups and any external support tool are not reliably identified in the reviewed code and require an operations inventory.

## Retention and rights implementation gaps

- `Document` uses soft deletion, and `DocumentObserver::deleted` cleans selected relational records. The audited deletion path does not prove removal of the R2 object, all derived text/embeddings, backups, provider copies or logs. A verified deletion workflow and restoration/backups policy are needed before promising erasure deadlines.
- `routes/console.php` schedules expiry cleanup for verification codes, failed jobs and Pulse data. These jobs depend on the scheduler running. There is no verified retention schedule for accounts, files, extracted text, AI outputs, Matters, comparisons, billing data, audit data, logs, support records or backups. Set and implement periods with lawful exceptions.
- The code review found no complete self-service privacy rights workflow for access, portability, objection or erasure. Establish a staffed request channel and a process for customer-controlled document requests.
- The legal acceptance migration records user, version, method, IP and time on new email/Google accounts. Existing accounts are not retroactively accepted; future material updates need an explicit versioned reacceptance process if required.

## Required business and legal review before publication

1. Replace the user-approved placeholders for legal entity, registered/business address and privacy contact. Confirm the operator's Kenyan registration, notices address and complaint channel. `hello@docintel.io` is the existing general contact, not a verified privacy officer address.
2. Determine controller/processor roles by customer arrangement; assess ODPC registration, sensitive-data safeguards, lawful bases and cross-border-transfer conditions. Offer a customer DPA where DocIntel is a processor. The DPA should cover instructions, confidentiality, subprocessors and notice of changes, transfers, security, incidents, assistance with rights, retention/deletion, and audit evidence. A signed DPA cannot be inferred from these drafts.
3. Review provider agreements and live account settings for Anthropic and Voyage training/retention, and data transfer safeguards for each recipient. Do not promise zero retention or training exclusion without account-level evidence. Verify Railway, Neon, R2, mail, Sentry and backup regions and terms. Assess any required notices or consent for third-party browser technologies; the shared HTML loads Google Identity Services and Google Fonts on page visits, so this exposure needs a specific legal decision. The reviewed app has no optional first-party advertising or analytics tag, so a marketing-cookie consent banner was not added.
4. Approve commercial terms: legal entity, governing law/forum, cancellation and refund rules, liability cap, service levels, confidentiality commitments and effective publication date. Check checkout wording against Paystack subscription behavior and local consumer law.
5. Define and implement a retention schedule and operational deletion process, including object storage, embeddings, temporary files, queue data, logs, backups and processors. Test incident notification and request handling. Review product suitability for regulated or sensitive document categories.
6. Confirm frontend hosting, DNS/CDN, support tools and any analytics or email changes before finalizing the subprocessor list. Repeat this inventory whenever integrations or deployment regions change.

## External legal and provider references for reviewer

- Kenya Office of the Data Protection Commissioner: [data subject rights](https://www.odpc.go.ke/rights-of-a-data-subject/), [data protection laws](https://www.odpc.go.ke/data-protection-laws-kenya/), [registration FAQs](https://www.odpc.go.ke/faqs/).
- [Anthropic commercial API retention explanation](https://privacy.anthropic.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data) describes service-dependent terms; verify the actual DocIntel account rather than incorporating a generic promise.
- [Cloudflare R2 data location documentation](https://developers.cloudflare.com/r2/reference/data-location/) and [Railway region documentation](https://docs.railway.com/deployments/regions/) show why the selected account/deployment configuration matters.
- [Resend DPA](https://resend.com/legal/dpa) is relevant only if Resend is the effective transport.
