Verification email diagnosis, recorded on 2026-09-14 for Railway project `superb-emotion`, environment `production`.

**Confirmed cause: Resend rejects the configured senders before accepting the email.** Production uses a restricted testing sender on `CA_BACKEND` and an unverified placeholder domain on `ca-horizon-worker`. This is a mail/provider configuration failure. A production verification notification was queued, consumed by Horizon, and failed inside the Resend transport. No application fix or production configuration change was made during this investigation.

The read-only production `failed_jobs` lookup returned these three distinct mail failures in the preceding two days. Reading through each service's database configuration returned the same records; these are three failures, not six.

| Failed at (UTC) | Job UUID | Connection / queue | Provider exception message |
| --- | --- | --- | --- |
| 2026-09-14 17:32:11 | `60613027-6084-4fd9-b7f5-d0a695f91032` | `redis` / `default` | You can only send testing emails to your own email address ([EMAIL]). To send emails to other recipients, please verify a domain at resend.com/domains, and change the `from` address to an email using this domain. |
| 2026-09-14 15:02:23 | `d6619a2c-edc1-4c51-b3d9-88fcba095aee` | `redis` / `default` | The yourdomain.com domain is not verified. Please, add and verify your domain on https://resend.com/domains |
| 2026-09-14 14:56:23 | `2ba83a41-af7b-4fd2-b895-fb23b76d7961` | `redis` / `default` | The yourdomain.com domain is not verified. Please, add and verify your domain on https://resend.com/domains |

The original exception class is `Resend\Exceptions\ErrorException`, raised at `/app/vendor/resend/resend-php/src/Transporters/HttpTransporter.php:120`. The outer exception is `Symfony\Component\Mailer\Exception\TransportException`, raised at `/app/vendor/resend/resend-laravel/src/Transport/ResendTransportFactory.php:74`, with message `Request to the Resend API failed. Reason: ` followed by the provider message above. Account email addresses are redacted.

Railway logs on `CA_BACKEND` show `App\Notifications\VerificationCodeNotification` running at 17:32:10 UTC and failing at 17:32:11 UTC (851.14 ms). The matching stored stack includes `SendQueuedNotifications`, `RedisJob`, and Horizon's `WorkCommand`. This establishes actual dispatch, consumption, and provider contact for that notification. The filtered log query on `ca-horizon-worker` returned no matching lines; it does not prove that service is stopped. The latest observed failure is at 20:32:11 East Africa Time. The reporting user's exact signup time was not supplied, so correlation to a particular user account was not attempted.

The saved Railway variables were read without printing credentials:

| Variable | `CA_BACKEND` | `ca-horizon-worker` |
| --- | --- | --- |
| `MAIL_MAILER` | `resend` | `resend` |
| `MAIL_FROM_ADDRESS` | `onboarding@resend.dev` | `noreply@yourdomain.com` |
| `MAIL_FROM_NAME` | `CA Document Intelligence` | `CA Document Intelligence` |
| `RESEND_API_KEY` | Set | Set |
| `MAIL_HOST` | `127.0.0.1` | `127.0.0.1` |
| `MAIL_PORT` | `2525` | `2525` |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Set; values withheld | Set; values withheld |
| `MAIL_ENCRYPTION` | Unset | Unset |
| `MAIL_SCHEME` | Literal environment value `null` | Literal environment value `null` |
| `MAIL_URL` | Unset | Unset |
| `QUEUE_CONNECTION` | `redis` | `redis` |
| `REDIS_QUEUE` | Unset; configured fallback `default` | Unset; configured fallback `default` |
| `LOG_CHANNEL`, `LOG_STACK` | `stack`, `single` | `stack`, `single` |
| `LOG_LEVEL` | `debug` | `debug` |
| `EXPOSE_DEV_VERIFICATION_CODE` | `false` | `false` |

These are saved service variables, not an inspection of each process's cached configuration. The production exceptions independently confirm use of Resend and both rejected sender domains. An authenticated **GET** to `https://api.resend.com/domains` with each service's configured Resend key returned **HTTP 200 with an empty domain list**. No domain was created, modified, or submitted for verification.

`config/mail.php` selects `MAIL_MAILER`, defaulting to `log` only when unset. The deployed selection is Resend's HTTPS API, not SMTP. SMTP host, port, username, password, and encryption settings do not participate in this delivery. The SMTP configuration uses `MAIL_SCHEME`; it does not map `MAIL_ENCRYPTION`. `config/services.php` maps `RESEND_API_KEY`, and the installed `resend/resend-laravel` provider resolves that key and registers the Resend mail transport. The observed response is a sender restriction, not an SMTP connection or authentication error.

The signup and resend flow is:

1. [`AuthController::signup`](../app/Http/Controllers/Api/AuthController.php) creates the user and Personal workspace in a database transaction. After that transaction, it calls `VerificationCode::generateFor($user)` and `$user->notify(new VerificationCodeNotification(...))` directly; no separate dispatch service intervenes.
2. [`VerificationCode::generateFor`](../app/Models/VerificationCode.php) removes outstanding codes, generates a six-digit, zero-padded `random_int` code, stores its hash, and sets a 15-minute expiry. The transient plaintext value is passed to the notification. No verification code is printed in this report.
3. [`VerificationCodeNotification`](../app/Notifications/VerificationCodeNotification.php) implements `ShouldQueue`, uses `Queueable`, and selects only the `mail` channel. It specifies no custom connection, queue, recipient, or mailer. Laravel routes mail to the user's normalized email address. Its subject is `Verify Your Email`; the HTML and text versions contain the code, verification instructions, an expiry notice, and an unsolicited-request notice.
4. Laravel queues `SendQueuedNotifications` on the default Redis queue. Signup returns HTTP 201 with an authentication token after enqueueing. The provider call happens later in the worker; successful signup does not establish provider acceptance or inbox delivery.
5. Horizon's configured production `supervisor-1` consumes `default`, with up to ten processes, one try, and a 60-second timeout. A running worker is established by the live log and stored stack. Backlog totals could not be checked: the Redis service has no configured public endpoint, and remote SSH execution was unavailable. No queue was consumed, retried, cleared, or otherwise changed by the investigation.
6. The worker renders the notification and resolves its own mail sender configuration. The installed Resend transport submits HTML, text, sender, recipient, and subject. Provider exceptions are wrapped and rethrown, allowing the queue worker to record failure. There is no swallowing catch in the signup/resend notification calls or the notification itself. These asynchronous failures cannot alter an already returned HTTP response.
7. `POST /api/auth/resend-verification` exists and is rate limited. For an existing unverified user, it replaces the previous code and queues the same notification again. Its generic success message intentionally also covers unknown or verified addresses. It says a code has been sent, but does not wait for delivery. With the current sender settings, resending encounters the same provider restriction. A successful email verification separately queues a welcome notification.

`LOG_CHANNEL=stack` with `LOG_STACK=single` directs application exception detail to a local Laravel log file. Railway's collected worker output exposed RUNNING/FAIL, while `failed_jobs` retained the actionable exception. Durable stderr/stdout capture remains the separate priority described in [DURABLE_LOG_CAPTURE_PLAN.md](DURABLE_LOG_CAPTURE_PLAN.md); it was not implemented here.

The smallest corrective deployment is to add an owned sending domain to the Resend account used by the application, publish Resend's required verification records, wait for verified status, and set the same real `MAIL_FROM_ADDRESS` on that domain for **every service consuming notification jobs**, including both named services. Keep `MAIL_MAILER=resend` and a key authorized for that domain. Rebuild configuration caches and restart/redeploy the relevant processes so long-running workers load the changes. This requires no signup code change. These actions are recommendations only and were not executed.

Resend documents that its shared `resend.dev` sender is restricted to the account owner's mailbox; this matches the production rejection. See [Resend's explanation of the testing-domain restriction](https://resend.com/docs/knowledge-base/403-error-resend-dev-domain). Domain verification requires the supplied SPF and DKIM records; DMARC can then improve authentication policy and trust. See [Resend domain verification](https://resend.com/docs/dashboard/domains/introduction). No owned sending domain is currently registered under either configured key, so there is no application sending domain whose live SPF/DKIM/DMARC status could meaningfully be validated. These messages were rejected before provider acceptance; there is no evidence that spam placement caused these failures.

After correcting configuration, verify acceptance and inbox receipt using one explicitly designated test mailbox and a fresh signup/resend code. Do not blindly retry old verification jobs, whose codes may be expired or superseded. No real test email was sent during this diagnosis.

[`VerificationEmailDeliveryTest.php`](../tests/Feature/VerificationEmailDeliveryTest.php) adds four cases: actual signup-to-transport rendering and recipient/code checks; the observed testing-sender rejection; the observed unverified-domain rejection; and resend submission with a replacement code. It fakes queue capture only, then serializes/restores and executes the notification job through the real Laravel mail channel, installed Resend transport, and Resend SDK. Guzzle `MockHandler` intercepts all Resend network calls. It does not use `Notification::fake()` or `Mail::fake()`. The tests prove application transport behavior and failure propagation, not live provider configuration or inbox delivery.

Targeted validation passed: **4 tests, 64 assertions, 0 failures**. Test execution used fresh, disposable local PostgreSQL and Redis, skipped repository environment files, and verified the actual PostgreSQL database, address, port, and temporary data directory before running migrations. The targeted JUnit artifact is `/tmp/ca-journey-test.r18P4x/junit.xml`. Pint and `git diff --check` passed.

Production access during this investigation was limited to Railway variable/log reads, standalone PostgreSQL SELECTs inside `BEGIN READ ONLY` with a five-second statement timeout followed by `ROLLBACK`, and Resend domain GET requests. The Redis public-endpoint check stopped before connecting because no endpoint was available. No production account or document was created, changed, or deleted; no mail was sent; no deployment, provider setting, environment variable, or queue job was changed. Repository additions for this task are this report and the transport test; existing unrelated changes were preserved.
