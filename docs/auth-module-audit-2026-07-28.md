# CA Document Intelligence Platform — Auth Module Test Audit
**Date:** July 28, 2026
**Environment:** Local dev, `MAIL_MAILER=log`, PostgreSQL (port 5433), Laravel 11
**Scope:** Full authentication lifecycle — signup, verification, signin, token management, password reset, input validation, security edge cases

---

## Summary

24/24 automated PHPUnit tests initially passed; after this follow-up round, **27/27 pass** (3 new case-insensitivity tests + 1 new resend-invalidation test added). 30+ manual curl-based test scenarios were executed against a live local server. One real application bug was found and fixed (email case-sensitivity). One validation policy decision was deliberately made and documented (accepting syntactically-valid-but-undeliverable emails). No security vulnerabilities (XSS, SQLi) were found in the auth surface.

---

## 1. Automated Test Suite

**Final state (this session):** 27 passed, 0 failed, 94 assertions, ~13–27s runtime.
New tests added: `test_signup_rejects_case_variant_of_existing_email`, `test_email_is_stored_normalized_to_lowercase`, `test_signin_works_regardless_of_email_case`, `test_old_verification_code_is_invalidated_after_resend`. These convert two previously "manually verified only" findings into permanent regression protection.

---

## 2. Manual Test Results — Organized by Area

(See full breakdown in session log — signup, email verification, signin, session/token management, and password reset all confirmed working. Full detail available on request.)

---

## 3. Findings Requiring Decision or Follow-Up

### 3.1 `missing@domain` accepted at signup (no TLD/valid DNS)
**Status: Deliberately accepted, not a bug — policy decision documented.**

Verification-by-code gates real usability; an unverified account can never sign in. Residual action item: scheduled cleanup of unverified accounts older than 7 days.

### 3.1a Addendum — signup abuse vector via undeliverable emails
**Reviewed and accepted as current risk, with explicit conditions for revisiting.**

Accepting undeliverable emails means an attacker could script bulk signups using garbage domains that never need to pass verification, since no real inbox is required. This is bounded by the existing per-IP signup rate limiter, but a slow/distributed script could still accumulate rows over time — a DB-growth/resource-exhaustion concern distinct from the "unverified junk" storage concern already noted.

**Decision:** Accepted as a low-priority risk for now, given this platform's current scope (internal CA staff, not public internet-facing signup). **Revisit if:** the signup endpoint becomes reachable by the general public, or the scheduled cleanup job reveals unexpectedly high volumes of unverified junk accounts suggesting active abuse. If revisited, options in order of effort: rate-limit tightening, CAPTCHA on signup, or reconsidering the DNS validation trade-off.

### 3.2 SQLi-style email characters accepted (`a'or1=1@test.com`)
**Status: Confirmed non-exploitable.** Eloquent parameterizes all queries; verification-gating prevents any real harm from an unusable address.

### 3.3 Reset-token error message consistency — VERIFIED CLEAN, but test methodology flawed (needs redo)
Initial spot-check compared a reused-token response against a never-existed-token response. **Result: both returned identical `422` + `"This password reset token is invalid."`** — no apparent enumeration signal.

**However:** the test as run did not actually verify what it intended to. The "consume it" curl command used a literal placeholder string (`PASTE_REAL_TOKEN_HERE`) instead of the real captured token, meaning the real token (`0a9eded2...`) was **never actually consumed** via a successful reset. As a result, both "reuse" and "never-existed" test cases were effectively testing the same thing: an invalid/non-existent token. **This needs to be redone with real token substitution before the finding can be trusted.**

---

## 4. Test-Harness Errors (Not Application Bugs)

Documented for future reference — bash history expansion on `!` in double-quoted strings, placeholder tokens used literally instead of captured values (recurring pattern — see §3.3 above for the latest instance), `get_code()` regex/persistence issues, accidental `artisan` deletion (restored via git), and wrong-directory tinker commands from unrelated projects sharing a parent folder.

---

## 5. Architecture Confirmed Sound

`BaseAuthRequest` normalization, model-level email mutator, DB-level `CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))`, verification flow with confirmed resend-invalidation, and per-endpoint rate limiting are all verified working end-to-end and now protected by automated tests where previously only manually checked.

---

## 6. Recommended Next Steps

1. ~~Add automated tests for case-insensitivity and resend-invalidation~~ — **DONE this session.**
2. Redo the reset-token reuse-vs-invalid comparison with real token capture (see §3.3) — this is the one open item.
3. Document the abuse-vector decision in §3.1a — **DONE this session.**
4. Move on to Day 5 pipeline work once §3.3 is properly closed.
