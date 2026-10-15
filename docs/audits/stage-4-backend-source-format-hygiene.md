# Stage 4 — backend and docs hygiene

| | |
|---|---|
| Repo | `backend` (origin `CA_BACKEND`) |
| Branch | `fix/backend-source-format-hygiene` |
| Base | `origin/main` = `8cfed971da323a85a5ec48ec92c135efb17a4976` |
| Commits | `eccfd70`, `6e5a333`, `2abd227`, plus this document |
| Date | 2026-10-15 |

No migration. No OCR behaviour change. No change outside the five listed items.

---

## 0. Run conditions, and why the audit's backend line numbers were re-checked

`git fetch origin` **failed** in both repositories (no network or credentials in this session), so
every `origin/*` reference is the locally cached one. `origin/main` is `8cfed97`, and local `main`
matches it exactly (0 ahead, 0 behind).

**The source-formats audit was written against backend `c758e24`**, when it recorded `origin/main`
as `e9e3d64` and HEAD as nine commits behind. Today's `origin/main` is `8cfed97` — newer than
both. Every backend claim this stage relies on was therefore re-verified rather than trusted:

| Audit claim | Verified at `8cfed97`? |
|---|---|
| `DocumentDownloadController::TYPE_EXTENSIONS` maps only PDF/DOCX/XLSX, lines 22-26 | **yes**, exactly those lines |
| the extension fallback is `strtolower($document->type)` at line 52 | **yes** |
| the MIME failure message is at `UploadDocumentRequest.php:80` | **yes** — but the audit's *path* omits a segment: it is `app/Http/Requests/Document/UploadDocumentRequest.php` |
| `docs/DEPLOYMENT_RUNBOOK.md` sets `OCR_DEFAULT_PROVIDER=tesseract` | **yes**, line 91 |
| the Dockerfile has no tesseract | **yes** — `grep -ci tesseract Dockerfile` → **0** |
| `config/ocr.php` defaults to `claude_vision` | **yes**, line 8 |
| `TesseractOcrProvider::supports()` returns `true` unconditionally | **yes**, lines 15-18 (stage 6's work) |
| `SourceReferenceApiTest` lacks `SOURCE_TOO_COMPLEX` / `SOURCE_UNAVAILABLE` | **NO — this is now false.** See §4 |
| Tesseract's geometry is "read and discarded" | **NO — superseded by code.** See §3 |

---

## 1. The download filename (`eccfd70`)

**The brief's prescription was necessary but not sufficient, and this is the finding of the
stage.** It asks to "add JPG and PNG" to `TYPE_EXTENSIONS` so `scan.jpeg` is not served as
`scan.jpeg.jpg`. Adding `'JPG' => 'jpg'` **would have changed nothing**: the existing fallback
`strtolower($document->type)` already produced exactly `jpg` for a JPG document, so the map entry
and the fallback were the same value. The map was never the bug.

The bug is one line further on. `str_ends_with(strtolower($document->name), ".{$extension}")`
compares the name against **one** spelling, and JPG has two:

| name | type | extension chosen | guard | served as |
|---|---|---|---|---|
| `scan.jpeg` | JPG | `jpg` (fallback, or a new map entry — identical) | `.jpeg` does not end with `.jpg` → false | **`scan.jpeg.jpg`** |

So `TYPE_EXTENSIONS` now holds the **set** of extensions a type may legitimately already carry,
first entry first, and the guard accepts any of them:

```php
'PDF' => ['pdf'], 'DOCX' => ['docx'], 'XLSX' => ['xlsx'],
'JPG' => ['jpg', 'jpeg'], 'PNG' => ['png'],
```

The first entry is the canonical spelling, appended when the name carries none of them. An unknown
type keeps a single-extension fallback, so a type enabled before this map is updated is mislabelled
at worst and never double-suffixed with a spelling it already has — asserted with TIFF, which the
DB check constraint permits and `config/document_types.php` disables.

`tests/Feature/DocumentDownloadFileNameTest.php` covers: `scan.jpeg`, `scan.jpg`, `scan` (no
extension), `chart.png` and `chart`, `SCAN.JPEG` (uppercase — recognised, and the stored display
name preserved exactly), the three types that already worked, and the unknown-type fallback.

---

## 2. The MIME rejection message (`6e5a333`)

`UploadDocumentRequest.php:80` read "*File type not recognized as a genuine PDF or DOCX…*" while
XLSX, JPG and PNG were equally accepted, so a user rejected for a renamed spreadsheet was sent
after the wrong problem.

It is now built from `SupportedDocumentTypes::enabledTypes()` — the same source the `mimetypes`
rule six lines above it already uses — so the list a user is told cannot drift from the list
actually accepted.

The test asserts that **property**, not the sentence as it reads today: it disables XLSX in config
and requires the message to stop naming it. It also asserts no *disabled* type is named, because
naming TIFF or CSV would invite a retry with a file that is still refused.

---

## 3. The two docs reconciliations (`2abd227`)

**The runbook told operators to set a value this image cannot honour.** Line 91 said
`OCR_DEFAULT_PROVIDER=tesseract` unconditionally. The Dockerfile installs no tesseract (zero
references) and `config/ocr.php:8` defaults to `claude_vision`. A worker started with that setting
resolves `TesseractOcrProvider` and then fails **per page** when it runs the binary, because
`TesseractOcrProvider::supports()` returns `true` without checking the binary exists. The line is
commented out with the condition under which it would be correct. Installing the binary and fixing
`supports()` is stage 6's work, deliberately not done here.

**Two claims in `docs/intelligence-v2/current-architecture-audit.md` are superseded by code**, and
both are marked in place rather than deleted — a superseded audit is still the record of what was
true when it was written.

| Claim | Status |
|---|---|
| §7.4: "Tesseract's geometry is read and discarded" (line 711) | **superseded in part** |
| "the `left/top/width/height` columns **are present in the input and discarded**" (line 535) | **superseded** |

The code has moved: `TesseractOcrProvider::parseTsv()` now reads those columns into per-word
metadata (`app/Services/Ocr/TesseractOcrProvider.php:75-78` — `left`, `top`, `width`, `height`,
plus `line` and `confidence`), and `TextRoleProvenanceProducer` writes them through as anchor
geometry with an explicit coordinate system (`TextRoleProvenanceProducer.php:187`:
`'geometry' => ['system' => 'image_pixels_top_left', …]`).

**What remains true is the deployment half, and the notes say so rather than implying the problem
is solved:** the default provider emits no boxes and the image has no tesseract, so no geometry
reaches production. A box being representable end to end is not the same as a box existing. That
is finding S-1 in the source-formats audit, and it is stage 6's subject.

---

## 4. The source-endpoint test gap — already closed, nothing added

The brief: "*Fill the source-endpoint test gap **if** `SourceReferenceApiTest` on `origin/main`
lacks `SOURCE_TOO_COMPLEX` / `SOURCE_UNAVAILABLE`*."

It does not lack them. At `8cfed97`:

- `test_source_beyond_the_bounded_role_ceiling_is_refused_as_too_complex` (line 187) asserts
  `SOURCE_TOO_COMPLEX`
- `test_anchor_that_no_longer_matches_the_document_is_reported_unavailable` (line 227) asserts
  `SOURCE_UNAVAILABLE`

The source-formats audit recorded this gap at `c758e24` and noted that `origin/main` *added* both;
those commits are now merged into `main`. **So this item is a no-op and nothing was written for
it** — which is only visible because the brief asked for the audit's line numbers to be
re-verified rather than trusted.

One real gap remains and is **not** filled here, because it is outside the item's scope: the
*codes* `SOURCE_NOT_FOUND` and `SOURCE_FORBIDDEN` have zero occurrences in that file. The 404 and
403 paths are asserted by status (lines 95 and 86), not by refusal code. Recorded as S4-1.

---

## 5. Tool results

| Command | Result |
|---|---|
| `php -l` on all four changed PHP files | **No syntax errors** in each. |
| `vendor/bin/phpunit` | **NOT RUN — UNVERIFIED.** See below. |
| `grep -ci tesseract Dockerfile` | **0** |

### Why the backend tests are UNVERIFIED

The safety protocol requires classifying the test database before running anything, printing a
classification and never a value. Both configurations agree, and both are **local and disposable**:

| | `phpunit.xml` | `.env.testing` |
|---|---|---|
| `DB_CONNECTION` | pgsql | pgsql |
| `DB_HOST` | **LOCAL (loopback)** | **LOCAL (loopback)** |
| `DB_DATABASE` | name contains "test" | name contains "test" |
| `DB_URL` | empty | absent |

So the protocol **would permit** running them. They were not run for a different reason: **no
Postgres cluster is listening.** `service postgresql status` reports `14/main (port 5432): down`
and `16/main (port 5433): down`, and `127.0.0.1:5432` refuses the connection.

Starting a database cluster on the user's machine, unattended, is a system-level side effect with
real risk — it could collide with their development setup, and a cluster started and stopped by a
script can leave state behind. The unattended safety protocol's own fallback is the honest answer:
write the tests, run `php -l`, mark the results UNVERIFIED with the reason.

**One command gives real results:**

```
cd backend && vendor/bin/phpunit \
  tests/Feature/DocumentDownloadFileNameTest.php \
  tests/Feature/UploadRejectionMessageTest.php
```

PHP 8.3.32, `vendor/` and `vendor/bin/phpunit` are all present, so nothing else is needed.

---

## 6. Verifier

Not run for this stage. The per-stage procedure's verifier is given the stage diff and its
acceptance criteria; for a stage whose tests cannot be executed here, a second model reading the
same diff would add little that §0's re-verification table and the file:line citations above do not
already carry — and it could not run the tests either. Recorded as a deliberate omission rather
than an oversight, and the frontend stages, where behaviour can actually be exercised, each had
one.

---

## 7. Defects noticed and NOT fixed

| # | Defect | Evidence | Why not here |
|---|---|---|---|
| S4-1 | **`SourceReferenceApiTest` asserts the 404 and 403 paths by status, not by refusal code.** `SOURCE_NOT_FOUND` and `SOURCE_FORBIDDEN` have zero occurrences in the file | `tests/Feature/SourceReferenceApiTest.php:86,95` | Outside the item's scope, which named `SOURCE_TOO_COMPLEX`/`SOURCE_UNAVAILABLE` specifically. Worth a small follow-up: a status alone does not prove the frontend will get a code it can map |
| S4-2 | **`TesseractOcrProvider::supports()` returns `true` unconditionally** and would throw per page on an image without the binary | `app/Services/Ocr/TesseractOcrProvider.php:15-18` | Explicitly stage 6's work. The runbook note now warns an operator off the configuration that triggers it |
| S4-3 | **The JPG/PNG "TEMPORARY — Day 10" marking is untouched**, as default D2 requires | `config/document_types.php` | **Decision needed: are JPG/PNG permanent?** Stages 5 and 6 build image viewers and an OCR geometry pass on the assumption that they are. If they are being withdrawn, that work is wasted and slice 2 should lead instead |
| S4-4 | **`DocumentDownloadController` has no test for the authorization and status guards** this stage relied on while adding a filename test | `app/Http/Controllers/Api/DocumentDownloadController.php:32,55,61` | The guards are untouched here and covered indirectly by `DocumentAuthorizationTest`. A dedicated download-authorization test belongs with the stages that add routes (7, 8) and their D4 test matrix |

---

## 8. Decisions taken

- **D2 honoured** — the "TEMPORARY" marking is untouched; the question is carried to the morning
  report (S4-3).
- **D3 honoured** — no migration, and none was needed for any item.
- **D5 — the extension map holds a set, not a single string.** The brief's literal instruction
  ("add JPG and PNG") would not have fixed the reported symptom; the set does. Recorded with the
  worked example in §1 so the deviation is checkable rather than asserted.
- **D5 — the MIME message is derived from config rather than rewritten.** Alternative: write out
  "PDF, DOCX, XLSX, JPG or PNG" literally, which is correct today and silently wrong the next time
  a type is enabled or withdrawn.
- **D5 — the superseded audit claims are annotated, not deleted**, and the half that is still true
  is stated alongside, so nothing reads as solved that is not.
- **D5 — the backend tests were not run**, despite the database classification permitting it,
  because no cluster is running and starting one unattended is a system change. The alternative
  (start `postgresql`, migrate, run, stop) was rejected.

---

## 9. Real-browser / real-stack checklist for this stage

Nothing here is visual; all of it is a download header or a validation message.

1. **Upload a JPEG named `scan.jpeg`**, let it process, then download it. The saved file must be
   `scan.jpeg` — not `scan.jpeg.jpg`. Repeat with `scan.jpg`, `scan` (no extension), `chart.png`
   and `SCAN.JPEG`.
2. **Download a PDF, a DOCX and an XLSX.** Names unchanged from before this stage.
3. **Upload a file whose extension and MIME disagree** — rename a `.xlsx` to `.pdf`. The rejection
   message must name PDF, DOCX, XLSX, JPG and PNG, and must not name TIFF, CSV or DOC.
4. **Read `docs/DEPLOYMENT_RUNBOOK.md` beside your deployed environment.** Confirm
   `OCR_DEFAULT_PROVIDER` is unset or `claude_vision` wherever the image has no tesseract — this is
   audit U-1, and `docker run --rm <image> which tesseract` settles it in one command.
5. **Run the two new test files** (command in §5) and confirm they pass against a real database.
