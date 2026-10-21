# DocIntel — Independent product experience and presentation architecture audit

- **Date:** 21 October 2026
- **Auditor role:** independent product architect / UX systems designer / senior frontend engineer. **Read-only. No application source file, prompt, schema, migration, config or feature flag was modified.** The only file created is this report.
- **Repository state:** frontend `CA` at HEAD `7b7fbb5` (working tree clean); backend `CA_BACKEND` at HEAD `8c8991b` (working tree clean apart from the untracked V9 audit). Both inspected as found.
- **Evidence base:** the two repositories as source of truth; the local frontend test suite, executed once (`npx vitest run`, 917 tests, no network, no model call); the backend configuration and API resources. **No paid inference, no deployment, no production data.**
- **Supporting evidence:** `docs/audits/docintel-independent-v9-audit.md` (V9 backend audit), treated as evidence about the data this UI receives, not as a brief to redesign the backend. It is referenced, never restated as fact without a check of my own, and it is not modified.
- **Companion path note:** this report sits beside the V9 audit, in `CA_BACKEND/docs/audits/`, because that is where the brief's own relative path resolves and where the existing audit series lives.

> **One sentence up front.** The verification substrate and the evidence vocabulary in this codebase are better than the interface built on top of them; the dominant presentation problem is not which dashboard template to choose but that two complete document experiences exist side by side, the deployed one is the older of the two, the newer one buries most of its own intelligence inside a collapsed `<details>`, and the strongest intelligence contract the backend serves — the V2 Brief — is not consumed by the frontend at all.

---

## How to read this report

Everything is labelled with what kind of claim it is:

- **Implemented** — code exists on HEAD and runs on the path described.
- **Implemented but flag-gated** — code exists; a named flag decides whether a user sees it. The flag's **current default** is stated.
- **Implemented but unconsumed** — one side of the contract is built and shipped; the other side never reads it.
- **Placeholder** — a declared structure nothing reads.
- **Conceptual** — proposed in this report; does not exist.

Severity, where used: **P1** the product asserts something it has not established, or the user cannot reach what they came for · **P2** real work is lost, duplicated or hidden · **P3** generality, maintenance or polish.

---

# Part A — Current experience

## A.1 There are two document experiences, and production ships the older one

This is the first thing an audit of "the current UI" has to establish, because the answer changes what every other finding means.

`DocumentDetailPage.tsx` renders its whole body inside `WorkspaceFrame` (`src/components/workspace/WorkspaceFrame.tsx`). That component is a single switch on one build-time constant:

```
// src/workspaceFlag.ts
export const DOCUMENT_WORKSPACE_ENABLED = import.meta.env.VITE_DOCUMENT_WORKSPACE === '1';
```

- **Flag off:** `WorkspaceFrame` returns `<>{children}</>`. The page renders its own sticky identity header, a "Key findings & numbers" tab card (`takeaways | numbers | charts`), page-extraction flags, then the 1,269-line `DocumentIntelligencePanel` and `DocumentMatterPanel`. No source pane, no templates, no notes pane, no section nav.
- **Flag on:** `DocumentWorkspace` takes over: compact header, sticky section nav, `DashboardTemplates` as the lead, a docked source/notes column, and the entire classic page demoted into one collapsed disclosure.

The flag's actual state:

| File | Tracked by git | Sets `VITE_DOCUMENT_WORKSPACE` |
|---|---|---|
| `CA/.env` | **No** — `.gitignore:27` ignores `.env` | **Yes, `=1`** |
| `CA/.env.production` | **Yes** | **No** |
| `CA/netlify.toml` → `[build.environment]` | Yes | **No** (only `NODE_VERSION = "22"`) |

Vite loads `.env` in every mode, so a build on *this machine* has the Workspace on. A build on Netlify — `command = "npm run build"`, from the repository, where `.env` does not exist — has it **off**.

**Finding E-01 (P1, Implemented but flag-gated).** The Document Workspace, the dashboard templates, the docked source reader, the section nav and the notes pane are **not reachable in a deployed build** as the repository currently stands. The committed `CA/dist` directory (untracked, built locally) does contain `template-choice`, which is why a local artefact looks like production and is not. Every "current UI" question in the brief therefore has two answers, and the audit gives both.

This is not a reason to turn the flag on blind. It is the reason the roadmap in Part G starts with "decide and record the flag state", because until that is settled no presentation work has a known audience.

## A.2 The templates are not a proposal — they are built

The brief describes three switchable dashboard templates as something "we previously considered". They exist on HEAD:

- `src/components/workspace/dashboardRegistry.ts` (189 lines) — `DASHBOARD_TEMPLATES`, three templates, 14 widget kinds, `DashboardSlot` with `priority`, `span`, `empty`, `interactions`, `exports`; plus `dashboardData()`, `recommendDashboard()`, `widgetAvailable()`, `templateSuitability()`.
- `src/components/workspace/DashboardTemplates.tsx` (471 lines) — every widget, the switcher, the analytical filter bar, the selected-observation panel.
- Tests: `tests/dashboard-registry.test.tsx`, `tests/dashboard-templates.test.tsx`.

The three templates are exactly the three named in the brief: `general_executive_brief` ("General Executive Brief", editorial profile), `financial_performance` ("Executive Performance Review", financial profile), `contract_intelligence` ("Contract Intelligence", contract profile). The architecture is also exactly as described: a registry, shared widgets, ordered slots, responsive spans, evidence-aware empty states, and no extra AI call — `dashboardData()` reads the same `/intelligence` response everything else reads.

**So the question in the brief is not "should we build this". It is "now that it is built, is it the right primary interface". Parts B and C answer that on the evidence.**

### What the template layer does well

1. **Nothing is invented to fill a slot.** `widgetAvailable()` is a per-widget predicate over fields that must actually be present, and a slot whose widget is unavailable is either hidden (`empty: 'hide'`) or rendered with an explicit unavailable sentence (`empty: 'explain'`). A missing executive assessment prints "No executive assessment is available for this published intelligence", not an empty card.
2. **Empty states refuse the inference.** `RiskList`: *"No typed risks are available. This does not establish that the document has no risk."* That sentence is the product's ethic in one line, and it is right.
3. **Classification cannot force an unsuitable template.** `templateSuitability()` returns `'unavailable'` and the switcher button is `disabled`. `hasPayrollContent()` deliberately blocks the financial template for payroll-shaped figures and says so.
4. **Stale intelligence is not rendered.** `dashboardData()` discards the response when `intelligence.document.id !== doc.id`, or when the document's published revision and the response's revision disagree.
5. **Every rendered item carries its source control.** `SourceRows` → `makeSourceRenderer` → `SourceEvidence`, and where no excerpt exists it prints "Exact source navigation is unavailable for this finding" rather than a dead chip.
6. **Figures keep their source unit and their limitations.** `Metrics` prints `Source unit: …` from `findingUnit()` and the full `findingLimitations()` list per card.

### Where the template layer is weaker than the data it sits on

**Finding E-02 (P1, Implemented).** The `primary_chart` widget is titled **"Verified financial visualization"** and renders no origin label at all (`DashboardTemplates.tsx`, `FinancialChart`). But `dashboardData()` builds `charts` from two origins and marks both the same:

```
const source: DashboardChart[] = (doc.charts ?? []).map((chart) =>
  ({ origin: 'document', chart, rec: sourceChartRec(chart) }));
```

`financialCharts` then admits any `origin: 'document'` chart whose unit matches the currency pattern. A `document_visual` chart is an **AI transcription of printed labels read off an image of a page** — `UnifiedCharts` says so explicitly in its own surface ("AI-transcribed exact labels; compare with original visual") and in the CSV verification column. Under the dashboard heading it becomes "Verified". The honest-degradation gate in `sourceChartRec()` does hold the line on *renderability* (anything without `sourceOrigin === 'document_visual'`, a retained source image, `valuesVerified`, `valueStatus === 'exact'` and verified axes becomes an ineligible table and is filtered out), so V9's R-10 legacy chart channel does **not** reach this widget. The defect is narrower and still real: the word *Verified* is applied to a transcription, in the one place that prints no origin.

**Finding E-03 (P2, Implemented).** `compatibleEarlier()` in `analyticalModel.ts` requires both periods to match `/^\d{4}$/`. The flagship fixture document is quarterly (`Q1 2026`, `Q2 2026`, `Q3 2026`). For every quarterly, monthly or half-yearly document, the selected-observation panel can only ever print *"No earlier observation with a verified matching metric, subject and unit is available."* The comparison is not wrong; it is unreachable for the document class the financial template exists to serve.

**Finding E-04 (P3, Placeholder).** Four declared registry structures are read by nothing:

| Structure | Declared | Read by a renderer |
|---|---|---|
| `DASHBOARD_CATEGORIES` (8 values) + `DashboardTemplate.category` | `dashboardRegistry.ts:4-26` | **No** — only asserted by `tests/dashboard-registry.test.tsx:27,33` |
| `DashboardSlot.interactions` | `:21` | **No** |
| `DashboardSlot.exports` | `:22` | **No** |
| `DashboardSlot.span.mobile` | `:19` | **No** — the grid hard-codes `col-span-1` |

The eight `DASHBOARD_CATEGORIES` are also a second, parallel vocabulary: the backend classifies into **twelve** values (`DocumentTypePromptSeeder.php:37`: `compliance_report, financial_report, regulatory_filing, policy_document, contract, correspondence, technical_report, meeting_minutes, research_report, application_form, invoice, other`), and nothing maps the eight to the twelve. `recommendDashboard()` ignores the categories entirely and branches on three of the twelve (`financial_report`, `invoice`, `contract`). A test asserting a placeholder is worse than no test: it makes the dead field look load-bearing.

**Finding E-05 (P2, Implemented).** The template switcher is the first thing in the findings column: three ~92px cards with wireframe previews, then five explanatory lines — "Template choice is saved in this browser…", "Unavailable in this document: …", the omitted-candidates notice, the payroll notice, and on mobile "Scroll sideways to compare templates." A reader who has just uploaded a board pack meets a layout-picker before a finding. The preference is also per `(userId, documentId)` in `localStorage`, so the choice does not carry to the next document, does not survive a browser change, and is invisible to the backend — which rules it out as the basis for anything a second person sees.

## A.3 The newer experience hides most of itself

`DocumentWorkspace.tsx` renders the findings column as exactly two things:

```
{doc.status !== 'Failed' && <DashboardTemplates … />}
<details id="workspace-deep-analysis">
  <summary>Full document analysis and chart inspection</summary>
  <WorkspaceSummaryBand … />
  {chartsLead && <UnifiedCharts … />}
  {children}        {/* ← DocumentIntelligencePanel (1,269 lines) + DocumentMatterPanel */}
</details>
```

Collapsed by default, behind one summary line, is: the document briefing band with the executive summary and all key takeaways; coverage; the whole unified chart surface with its origin labels, inspection modal, CSV/PNG export and original-image view; analytical comparisons; analysis limitations; analysis groups; entities; risks **with their review controls**; obligations **with their tracking controls**; page flags; and the matter panel.

**Finding E-06 (P1, Implemented).** The actions that make a finding actionable are only inside the collapsed disclosure. `PATCH /documents/{document}/risks/{risk}` (status `open|mitigated|closed`, `DocumentRiskReviewController`) is wired at `DocumentIntelligencePanel.tsx:1049`. `POST /tracked-items` for a deadline is wired in `IntelligenceControls.tsx:128`. The dashboard's `RiskList` and `Obligations` widgets are **read-only restatements of the same rows with no control on them**. A reader who stays in the template — which is what the layout invites — can see a critical risk and cannot record a decision about it.

**Finding E-07 (P2, Implemented).** Measured duplication between the lead surface and the disclosure:

| Content | Rendered in |
|---|---|
| Executive summary / assessment | `Assessment` widget (truncated at ~570 chars + "Read full summary") · `WorkspaceSummaryBand` (truncated at 240 chars) · `DocumentIntelligencePanel` Overview — the last suppressed by `hoisted.overview`, the first two not |
| Coverage | `coverage` widget (`WorkspaceCoverage compact`) · again inside `WorkspaceSummaryBand` |
| Findings | `Findings` widget (first 6) · `EvidenceTable` "Evidence ledger" (all, filterable) · `AnalysisGroups` in the panel |
| Charts | `primary_chart` + `comparison` widgets · `UnifiedCharts` (all origins, with the origin labels the widgets lack) |
| Key takeaways | `WorkspaceSummaryBand` disclosure · panel, suppressed by `hoisted.takeaways` |

`hoisted` was clearly built to stop exactly this, and it covers two of five cases. The honest origin labelling and the chart inspection path live only in the copy that is collapsed.

**Finding E-07b (P2, Implemented but unconsumed by the lead surface).** One whole content tier is rendered *only* inside the collapsed panel and has no dashboard equivalent: `intelligenceSummary.materialFindings[]` (title, category, explanation, `why_it_matters`, severity, `basis`, `source_ids`), `.trends[]`, `.tensions[]` and `.questions[]` (`DocumentIntelligencePanel.tsx:1211-1226`), plus the assessment's own `basis` qualifier at `:1201`. `questions` is the most costly omission: it is the only payload that tells a reader what the analysis could not settle, which is precisely what a professional needs before relying on the rest. The dashboard's `Assessment` widget renders the assessment text and its sources and drops all five.

**Finding E-08 (P3, Implemented).** The section nav (`useWorkspaceSections.resolveSections`) finds sections by querying the DOM for `data-testid` values and, on the template path, lists `Assessment · Figures · Performance · Findings · Risks · Obligations · Evidence · Full analysis`. The last entry targets `#workspace-deep-analysis`, a **collapsed** `<details>`: the nav scrolls the reader to a closed summary line. `DashboardTemplates` ends with an `<a href="#workspace-deep-analysis">` that does the same. The `onInspectCharts` callback does better — it sets `detail.open = true` before scrolling — which shows the right behaviour already exists in one path and not the other two.

## A.4 The source-reading experience is the strongest part of the product

This is where the engineering discipline is most visible, and most of it should be preserved as-is.

**Implemented and good:**

- **One honest state machine.** `SourceContent.tsx` has exactly five branches — idle, verifying, refusal, stale, resolved — and each carries an evidence label only where the response earns one. A permission error or dropped connection gets that request's own message and **no** badge, because neither says anything about where a passage is.
- **A closed label vocabulary.** `evidenceLabels.ts` defines five kinds (`verifying`, `supported`, `partial`, `not-located`, `source-changed`), three partial reasons, a `conflicting` overlay that rides on top of a location rather than replacing it, and an OCR qualifier. No percentages, no scores, no confidence rendered as importance. An unrecognised backend state maps to "Not located", not to a new confident word. The file's own comment states the rule and the code keeps it.
- **Capabilities derive from fields, never from file type.** `sourceCapabilities.ts` (383 lines) is the single answer to "what can this source do". An image's `canHighlightExact` reads `imageRegions(source).length > 0` — *the same function the viewer draws from*, so the claim and the drawing cannot drift. `locationUndecided` is the one flag allowed to be true without a field behind it, and the file states why: for XLSX and DOCX a later server route can still resolve the citation, so the chip must not pre-announce a failure the next click disproves.
- **Format truths are preserved, not smoothed.** PDF: `canHighlightExact` is **always false** — "there is no exact PDF highlight", because the mark comes from a browser text search with no glyph geometry. DOCX: jumps to a paragraph or table path, **never to a page**, because a Word document has no pagination until something renders it. XLSX: only a server-verified focus earns a highlight. Images: no region, no highlight and no "zoom to evidence".
- **Republished-analysis safety.** `useSourceResolution` pins the revision being resolved against, does **not** silently re-fetch when the analysis is republished, surfaces `status: 'stale'` with "Resolve again", and drops only the superseded revision's cache.
- **The open reader survives the next selection.** `SourceContent` keeps `openSource.current`, so a second finding does not unmount the viewer, re-download the file, and fire its jump into a component that no longer exists.
- **Five formats render in place.** `adapters/registry.ts` + `manifest.ts`: PDF, DOCX, XLSX, JPG, PNG, with the PassageCard as the honest fallback and an own-property lookup so `doc.type === "constructor"` cannot render `<undefined>`.
- **Reading position is held across layout changes.** `DocumentWorkspace`'s `rememberAnchor`/`pendingRef` scrolls the window by however far the opener moved, so the finding being checked stays under the reader's eye when the panel opens or closes.

**Where it is incomplete:**

**Finding E-09 (P2, Implemented, acknowledged in code).** `SourceContent`'s `EvidenceStrip` takes a `located` parameter that decides whether the recorded passage shows as one truncated line (because the highlight on the page already shows it) or in full (because it is then the only evidence there is). `AdapterSource` never passes it. The code says so: *"`located` is not wired yet: until the viewer's search result is passed down, the strip shows the full recorded passage, which is the honest default."* The default is honest and the cost is real — above every rendered page sits a three-line quote block plus a "Finding" label plus an "In your document" label plus a Show more/less control, in a pane whose minimum width is 480px. The viewer below already computes the outcome (`highlightNote()` in `sourceNotes.ts` distinguishes *found*, *not located on the page*, and *page text unreadable*); the strip simply is not told.

**Finding E-10 (P2, Implemented).** `DocumentWorkspace.tsx:150` calls:

```
sourceCapabilities({ type: doc.type, source: resolution.source })
```

omitting both `citedPage` and `hasOriginal`. `PdfAdapter` passes `hasOriginal: doc.hasOriginal ?? null` correctly; the Workspace-level object that feeds `selection.resolved` does not. So for a PDF, JPG or PNG with `hasOriginal === false`, `sourceCapabilities` cannot take its `noOriginal()` path, `canJump` stays true on the page record alone, and `SourceJumpControl` renders *"Show page N in document"* for a document whose original bytes are not stored. `canJump` also loses the viewer's resolved page, which is the authority the function's own documentation names.

**Finding E-11 (P3, Implemented).** The docked column carries a permanently visible `<input type="range">` labelled "Panel width", 35–55%, with a live `46%` readout, in a 1-line strip above a `Source | Notes` toggle, above the pane's own header. Three rows of chrome before the first pixel of the document. It is also unpersisted — every document starts at 46 again. A drag handle on the column divider, or nothing, would cost less and claim less.

**Finding E-12 (P2, Implemented).** Findings and their evidence are **not** visually connected until a finding is clicked. `SourceJumpControl` only reads `selection.resolved` when `resolved.sourceId === reference`, i.e. for the one finding currently open. Every other chip reads "Inspect source" with no indication of whether anything is there. The response already carries `intelligence.sourcePages` and the `evidence` map for exactly the references the response points at — enough to distinguish *"page 7"* from *"no recorded location"* on every chip at render time, before any click. The caution is deliberate and documented (`sourcePages` being silent is not proof that no page exists), but the current compromise means a reader cannot see which findings are checkable without checking them one at a time.

## A.5 Notes, review and meeting-adjacent capability

- **Notes: Implemented, flag-gated.** `WorkspaceNotesPane` reads `GET /capabilities` → `data.documentNotes.enabled`, and lists/creates/edits/deletes against `/documents/{id}/notes` with optimistic-concurrency `version` and 409 handling. A note can carry **one citation** captured as a snapshot: `finding_label`, `quote_snapshot` (≤1000 chars), `intelligence_revision_id`, `source_id`, `page`, and the response returns a `verificationStatus` the pane prints. That is a genuinely good design for meeting capture — a note that quotes a finding records *which revision it quoted*. `config('document_intelligence.notes_enabled')` defaults to **false** (`config/document_intelligence.php:4`), and `CA_BACKEND/.env` does not set `DOCINTEL_NOTES_ENABLED`, so today the pane says "Notes are not enabled for this workspace."
- **Review: Implemented, buried.** Risk status and deadline tracking, as in E-06.
- **Report export: Implemented.** `GET /documents/{id}/report-snapshot` → `reportBuilder.tsx` → a `.docx`, with a review note, a `limitations` payload and `complete` flag, followed by `POST /documents/{id}/report-generated`. This is the only existing "present this to someone else" path, and it is a file, not a screen.
- **Workspaces are Personal.** `WorkspaceType = 'Personal' | 'Organization'` exists in `types.ts`; nothing in the document experience shares, assigns or comments. Confirmed: organization collaboration is future scope and no part of this report assumes it.
- **No decisions or action items exist.** There is no entity for "we decided X", no assignee, no follow-up flag. `tracked-items` is the nearest thing and it is deadline-shaped (`title`, `due_date`, `status`, `notes`), reachable from `/deadlines` and from the obligation control.

## A.6 The backend serves a better intelligence contract than the frontend reads

**Finding E-13 (P1, Implemented but unconsumed).** `DocumentIntelligenceResource::toArray()` returns, alongside `analysis`:

```
...($brief === null ? [] : ['brief' => $brief]),
```

`BriefReadService::forDocument()` + `BriefAssembler::assemble()` produce a **deterministic, adaptive, evidence-ordered report**:

- **Block types, emitted only when the evidence exists:** `headline`, `attention`, `timeline`, `measure`, `coverage_note`.
- **Every block carries** `cites` (source ids), `evidence[]` with `record_id`, `span_id`, `chunk_id`, `start_offset`, `end_offset`, `quote`, `page` and a `highlight` object with an explicit `mode` (`none` | `page_only`), `typed` values (`value`, `due_date` with a `resolution` of `calendar`/`period`/`relative`, `period_covered`), `tier`, `attention` state, `attribution` (speaker, role, `reported` flag), `assertion` force, `template_id` and `template_version`.
- **Blocks that fail verification are dropped before serialization.** `BriefAssembler` runs `BriefVerifier` (892 lines) over every non-headline block and discards it if `cites_available`, `numbers_grounded`, `dates_grounded`, `periods_grounded`, `units_consistent` or `assertion_force_consistent` failed.
- **Ordering is computed, not configured.** `MaterialityScorer` assigns a tier from class base rates and weighted signals (severity, date proximity, date resolution, attribution authority, obligation consequence, structural prominence, monetary magnitude, comparability, with repetition/boilerplate/unresolved penalties), with forced rules for critical risk, overdue and imminent dated obligations, penalised obligations and high risk. `AttentionStateBuilder` resolves `needs_attention | watch | clear | resolved`. `CoverageStateBuilder` resolves `complete | bounded | partial | unavailable` with reasons.
- **An optional verified narrative (B2)** is layered on only when it was verified against exactly the evidence set being shown, and `fallbackReason` says which of `not_generated | disabled | empty_evidence | insufficient_evidence | rejected` applied. `max_rejected_claims: 0` — one unverifiable claim rejects the whole narrative and the deterministic blocks are served instead.

**The frontend reads none of it.** `grep -rn "brief" CA/src` returns only `stats.briefAiBlocksRejected`, three `general_executive_brief` string literals, and the words "briefing"/"brief" in copy. `DocumentIntelligence` in `types.ts` does not declare a `brief` key.

The partial exceptions are `analysis.tier1` and `analysis.attention`, which `DocumentAnalysisComposer` emits under the same `$v2` guard; `types.ts` declares both as "V2 only", `WorkspaceCoverage` consumes `analysis.attention.coverage`, and `widgetAvailable('attention')` reads `analysis.attention`. **But `config('intelligence_v2.enabled')` is `env('DOCINTEL_INTELLIGENCE_V2', false)`, `.env.example` sets it to `false`, and `CA_BACKEND/.env` does not set it.** So with the current flags: no `brief`, no `tier1`, no `attention`, no coverage `state`, no materiality ordering. The `attention` widget's availability check is false for every document, and `WorkspaceCoverage`'s `STATE_COPY` is unreachable.

## A.7 Loading, partial-analysis and error states

**Implemented and correct:**

- Polling with `nextRefreshDelay`/`refreshNeeded`, and a transient poll failure explicitly does **not** erase already-loaded analysis.
- A republished revision clears `intelligence` rather than mixing revisions (`publishedRevisionChanged`).
- `WorkspaceFrame` is keyed on `documentId`, with a long comment recording the exact bug this fixes: the previous document's `sourceId` being requested against the new document, showing the old document's evidence under the new document's name.
- `doc.status === 'Failed'` suppresses `DashboardTemplates` and `UnifiedCharts` entirely and shows a single failure card.
- `Needs Review` shows the extraction-failure banner with approve/reject/retry gated on `doc.canApprove`.
- A refresh that did not publish says so: *"The latest refresh did not publish. The previous published findings remain available."*
- `pageFlags` with `partial`/`failed` per page are listed — inside the collapsed disclosure in Workspace mode.
- `analysis.limitations` (per-measurement codes, per-visual-group codes, coverage codes) is rendered by `AnalysisLimitations` — also only in the collapsed panel.

**Finding E-14 (P2, Implemented).** Partial analysis is disclosed in four unconnected places at four altitudes: `processingFailure`/`intelligenceProcessingError` banners at the page top; `pageFlags` in a card inside the disclosure; `analysis.limitations` in the panel inside the disclosure; `visualAnalysis.omitted` as a one-line notice under the template switcher; and `WorkspaceCoverage` twice. There is no single statement of *what this document's analysis does and does not cover*, which is precisely the statement a professional needs before quoting a figure in a meeting.

## A.8 Test and build state

Executed once, locally, offline: `npx vitest run` → **917 tests, 897 passed, 20 failed across 6 files**, on a clean working tree at `7b7fbb5`.

| Failing file | Failures | Nature |
|---|---|---|
| `tests/image-source-box.test.tsx` | 9 | OCR region box drawing: expected "Position not recorded" copy and `image-geometry-box` / `image-geometry-overlay` elements absent |
| `tests/image-capability-agrees-with-adapter.test.tsx` | 4 | capability model and image viewer no longer agree on a usable box |
| `tests/xlsx-source-window.test.tsx` | 3 | cell-window keyboard return-to-citation (`C2` vs `B2`), hidden/merged warnings, stored-vs-formula value copy |
| `tests/image-source-viewer.test.tsx` | 1 | animation assertion |
| `tests/source-capabilities.test.tsx` | 1 | "PNG with valid geometry: exact, and zoomable" |
| `tests/document-intelligence-analysis.test.tsx` | 1 | "Every one is backed by evidence" copy not found |

**Finding E-15 (P1, Implemented).** The failures are not spread evenly: 15 of 20 are the image OCR-geometry path and the XLSX cell window — i.e. the two newest source-reading capabilities, landed in `bb07020`/`#37` and backend `6f56302`/`#73`. On a clean HEAD these are regressions, and five of them are assertions about **what the UI tells a reader about evidence location** ("Position not recorded", "The recorded position refers to a different…"). Those are exactly the claims this product cannot afford to get wrong, and they are the ones currently failing. This belongs at the front of the roadmap, ahead of any new presentation surface.

`npm run typecheck` and `npm run lint` were not run as part of the single suite execution; Part G lists them as gates.

## A.9 Responsive behaviour

**Implemented:** docking is decided by the **measured width of the Workspace's own container** (`useDockable`, `ResizeObserver`, `DOCK_MIN_CONTAINER_PX = 520 + 480 + 24`), not a viewport media query — so expanding the app sidebar correctly hands over to `SourceFullScreenSheet` rather than crushing two columns. The reader's height rules are measured and documented per breakpoint (`flex-1 min-h-0` when the pane has a height, explicit `60vh` when it does not, with the 37,848px content-height failure recorded in the comment). The template grid is `grid-cols-1 md:grid-cols-6 xl:grid-cols-12`. Touch pointers skip the hover preview and open the pane directly (`isTouchPointer`). Tables that overflow say "Scroll this table sideways".

**Finding E-16 (P3, Implemented).** On a phone the template path front-loads chrome: a horizontally scrolling row of three ~18rem template cards with "Scroll sideways to compare templates", then up to five notice lines, then single-column widgets in priority order — with `span.mobile` declared and unread. The assessment, which is the thing a reader wants, is below all of it.

---

# Part B — Product diagnosis

The six obstacles, ordered by how much they cost.

### B-1. The product opens on its filing system instead of its answer

On the deployed path the first interactive element under the header is a tab bar (`Takeaways | Key numbers | Charts in the document`). On the Workspace path it is a template picker. Both ask the reader to choose a *view* before the product has told them anything. The assessment, the one thing every one of the nine candidate sections in the brief agrees is first, is below a layout decision in both.

### B-2. Presentation is selected by document class; availability is a property of individual fields

`recommendDashboard()` picks a widget set from `documentType.documentType` plus coarse content heuristics. But whether any given section can be populated is decided field by field — which is what `widgetAvailable()` already does, 14 times, correctly. The template therefore spends its effort choosing a set and then has to un-choose most of it: `templateSuitability()` disables whole templates, `missingSlots` prints a list of what is unavailable, and the grid has a hand-written `fill` expression that re-spans six specific widget pairs when their neighbours are missing. **The class-level decision contributes almost nothing that the field-level decision does not already decide better**, and it adds a failure mode the field-level one cannot have: a correctly classified contract with no typed deadlines, or a financial report with one period, lands in a template built for data it does not have.

This is the structural argument that decides Part C.

### B-3. Two intelligence contracts exist and the UI is built on the weaker one

`analysis` (legacy, always on) gives flat lists plus `stats`. `brief` (V2, flag-off, unconsumed) gives ordered, tiered, individually verified blocks with typed values, attribution, attention state and explicit highlight modes. The frontend's entire presentation layer — templates, widgets, `analyticalModel`, `dashboardData` — reconstructs by hand, in TypeScript, from flat lists, a weaker version of what `BriefAssembler` already computes deterministically in PHP and throws away at the API boundary. `dashboardRegistry.ts`'s regex classifiers are the clearest symptom: `MONEY`, `CONTRACT`, `PAYROLL_CONTENT`, `FINANCIAL_PERFORMANCE`, `OPERATIONS`, `NON_MEASUREMENT_IDENTIFIER` — six regexes in the browser doing a job `MaterialityScorer` does from typed signals.

### B-4. The chart-led layout is wrong for the data the pipeline actually produces

V9 establishes that the Northstar board pack yields **one verified KPI and zero charts**, and that the cause is the model's output contract, not verification (R-01, Proven). I confirmed the frontend consequence: `ChartCandidateBuilder` needs ≥2 eligible observations per group, `AnalysisChart → toChartRec` degrades anything unverified to a table, and `sourceChartRec` degrades anything that is not a fully verified document visual. So on a real board pack the `financial_performance` template's `primary_chart` slot — `empty: 'explain'` — renders *"No eligible financial chart is available. Review the figure cards and their verification limits."*, and its `comparison` slot falls through to a second chart index that is also empty. **The template that classification recommends for the product's flagship document class is, today, two explained-empty slots and a metric grid.** That is not a template problem to solve by picking a different template; it is evidence that chart availability must *order* the page rather than *define* it.

### B-5. Trust is stated per component and nowhere at document level

The per-item vocabulary is excellent (`evidenceLabels`, `measurementPresentation`, `sourceCapabilities`, the chart degradation reasons). What does not exist is one sentence, in one place, that a reader can take into a meeting: *how much of this document was read, how many findings are source-located, which sections failed, and what this analysis does not cover*. The ingredients are all served — `processingDetails.coverage`, `analysis.limitations`, `visualAnalysis.omitted`, `pageFlags`, `stats.groundedSources`, `stats.acceptedFindings` — and they are scattered across five surfaces at two altitudes (E-14), two of which are collapsed.

### B-6. Reading and deciding are in different rooms

The reader reads in the template; the decisions (risk status, obligation tracking) are in the collapsed panel; the notes are in a third pane that replaces the source pane it would quote from; and the only way to show anything to another person is to download a `.docx`. Note the specific friction in the notes path: the `Source | Notes` toggle in the docked column is mutually exclusive, so **writing a note about the passage you are reading hides the passage**, even though `WorkspaceNotesPane` is built to cite exactly that selection (`canCite` requires `selected && quote && revisionId`).

---

# Part C — Presentation comparison

## C.1 Criteria

Each approach is scored 1–5 on seven criteria. Weights reflect what this product is for: a professional has to be able to rely on what it says, and to find it fast.

| Criterion | Weight | What a 5 means here |
|---|---|---|
| Usability | ×3 | The reader meets the most useful thing first, with no view decision asked of them |
| Credibility | ×3 | The layout cannot imply coverage, verification or certainty the backend has not established |
| Data fit | ×3 | Works on both ends of the real distribution: a 1-KPI/0-chart board pack and a 30-measurement report |
| Flexibility | ×2 | New document kinds and new evidence types are absorbed without a new layout |
| Engineering complexity | ×2 | Cost of the **next** increment from HEAD, not from zero |
| Maintainability | ×2 | Number of places a rule lives; number of structures that can drift |
| Meeting usefulness | ×2 | Can be read aloud, projected, and interrogated with evidence, without a workspace tour |

## C.2 Scores

| | A. Fixed templates | B. Adaptive report | C. Evidence-first workspace | D. Hybrid (shared model + adaptive views + presets) |
|---|---|---|---|---|
| Usability ×3 | 2 — view decision first; priority order is fixed regardless of what this document holds | 4 — most useful section first by construction; no decision asked | 3 — excellent for verifying, weaker for "what does this say" | **5** — adaptive order, optional preset, document central when wanted |
| Credibility ×3 | 2 — a slot implies a subject matters; **E-02 is a direct instance** | **5** — a section exists only because supported evidence exists | **5** — the document is the claim | **5** — same rule as B, inherited |
| Data fit ×3 | 1 — **B-4**: the recommended template for the flagship class is two explained-empty slots | **5** — degrades to three sections without looking broken | 4 — always works; thin when the question is analytical | **5** |
| Flexibility ×2 | 2 — a new class wants a 4th template; 12 backend types vs 3 templates vs 8 dead categories | 4 — new evidence kind = new section rule | 3 — new analytics have no home | **5** |
| Complexity ×2 | **5** — already built | 3 — section rules + ordering; backend already computes both | 4 — reader already built and good | 3 — B's work plus a thin preset layer |
| Maintainability ×2 | 2 — registry + `widgetAvailable` + `templateSuitability` + `recommend` + `fill` + 6 regexes + 4 dead fields | 4 — one ordered list of section rules | **5** — almost no presentation logic | 4 |
| Meeting usefulness ×2 | 3 — dense, chrome-heavy, switcher visible | 4 — reads as a brief | 2 — a reader cannot present a file viewer | **5** — a preset is exactly this |
| **Weighted total (max 85)** | **40** | **65** | **59** | **77** |

## C.3 Why the recommendation is D, led by B

**Recommendation: a hybrid, built as an adaptive report over one shared presentation model, with the source reader as a permanent peer and presentation presets as a display-only preference.**

The reasoning, in order:

1. **B's rule is the product's existing ethic applied to layout.** The codebase already refuses to claim what it has not established, field by field. "A section exists because supported evidence for it exists" is the same rule at page scale. A's rule — "this subject gets a slot because of the document's class" — is the opposite rule, and E-02 is what that costs in practice.
2. **B is not a rewrite; it is a demotion of one file and a promotion of a payload already on the wire.** `widgetAvailable()` is already a section-availability predicate. `BriefAssembler` already emits ordered, verified, tiered blocks. The work is to read the second and let it order the first, not to invent an engine.
3. **C is not sufficient alone** and the brief's own list proves it: executive assessment, critical numbers, trends, obligations and required review are not readable from a document viewer. But C is *right about the centre of gravity*: the reader must be a peer of the findings, never a modal detour, which is what `DocumentWorkspace` already gets right and what the collapsed `<details>` undermines.
4. **A survives, demoted.** Not as widget sets — as **ordering profiles**: two or three named orderings over **one** section set, selectable, display-only, never adding or removing a section that evidence availability has not already decided. That keeps the real user value in the current template work (a financial reader wants figures above narrative; a legal reader wants obligations above figures) and discards the part that causes E-02, E-04, E-05 and B-2.

**Verdict on templates: not the primary interface. An optional display preference, reduced to orderings.** Do not abandon the code — `Assessment`, `Metrics`, `RiskList`, `Parties`, `Obligations`, `EvidenceTable`, `ComparisonView` are good section components and should be kept almost unchanged. Abandon the *registry of slots*, the classification-driven template recommendation, the three-card switcher as page-lead, and the four dead structures.

---

# Part D — Proposed experience

*Everything in this Part is **Conceptual** unless a line names the component that already does it.*

## D.1 The one structural idea

> **One ordered list of sections, each with an availability rule and an evidence contract, rendered at one altitude, beside a persistent reader. Document kind changes the order and the wording; it never decides which sections exist. Evidence availability decides which sections exist, and it is the only thing that does.**

## D.2 Page structure

```
┌────────────────────────────────────────────────────────────────────────┐
│ App header (h-16, sticky)                                              │
├────────────────────────────────────────────────────────────────────────┤
│ Document identity bar   name · type · pages · status · [Download]      │  ← WorkspaceDocumentHeader (exists)
│                         [Report] [Present] [⋯]                         │
├────────────────────────────────────────────────────────────────────────┤
│ Coverage line (one sentence, always present)          [What was read ▾] │  ← NEW: the single trust statement (B-5)
├────────────────────────────────────────────────────────────────────────┤
│ Section rail (sticky): the sections that exist, in order               │  ← WorkspaceSectionNav (exists; fix E-08)
├──────────────────────────────────────────┬─────────────────────────────┤
│ REPORT COLUMN (min 520px)                │ READER COLUMN (min 480px)   │
│                                          │ sticky, one viewport        │
│ 1  Assessment                            │                             │
│ 2  Attention            (conditional)    │  [Source] [Notes]           │
│ 3  Key findings                          │  ─────────────────────────  │
│ 4  Figures              (conditional)    │  Evidence label · quote     │
│ 5  Comparisons / Charts (conditional)    │  ─────────────────────────  │
│ 6  Obligations & dates  (conditional)    │  In your document           │
│ 7  Parties              (conditional)    │  ┌───────────────────────┐  │
│ 8  Required review      (conditional)    │  │  PDF / DOCX / XLSX /  │  │
│ 9  Evidence ledger                       │  │  image / passage      │  │
│ 10 Limitations & unprocessed             │  └───────────────────────┘  │
│ 11 Notes & next actions                  │                             │
└──────────────────────────────────────────┴─────────────────────────────┘
```

The reader column is **unchanged from HEAD** in mechanism: `DocumentWorkspace`'s measured docking, `SourceFullScreenSheet` below the dock threshold, `useSourceResolution`'s revision pinning, `SourceContent`'s five states, the adapter registry. Three changes only: the panel-width slider goes (E-11), `located` gets wired (E-09), and `hasOriginal`/`citedPage` get passed (E-10).

The report column is a single flat sequence of sections at one altitude. **No `<details>` wrapping half the product** (E-06, E-07). A section may collapse its own detail; nothing collapses a section.

## D.3 Information hierarchy, with the rule for each section

The brief's nine candidates, resolved. "Shows unavailable" means the section renders with an explicit sentence; "omitted" means no element at all.

| # | Section | Appears when | Collapsed when | Shows unavailable when | Omitted when |
|---|---|---|---|---|---|
| 0 | **Coverage line** | always, for any document past `Processing` | never (it is one sentence) | coverage was not measured → *"Coverage was not measured for this document."* | never |
| 1 | **Assessment** | `intelligenceSummary.executiveAssessment.text` or `.executiveSummary` present | never; long text truncates with "Read full summary" (exists) | present but empty after `presentSummary()` | — |
| 2 | **Attention** | ≥1 item whose attention state is `needs_attention` or `watch` (V2), **or** ≥1 risk of severity `critical`/`high`, **or** ≥1 deadline overdue/within 90 days relative to today | never — if it appears it is the point | — | no qualifying item. **Not** "no risks found": absence of a typed risk is not a finding, per `RiskList`'s existing sentence |
| 3 | **Key findings** | ≥1 source-located finding (`importantFindings` ∪ `analysisGroups[].items` with a `sourceId`), **or** ≥1 `overview.takeaways` | >8 items → first 8 + "Show all N" | findings exist but none is source-located → list them with `SOURCE_LINK_LIMITATION` (exists) | no findings and no takeaways |
| 4 | **Figures** | ≥1 metric finding after `NON_MEASUREMENT_IDENTIFIER` exclusion (exists) | >8 → first 8 by tier/materiality + "Show all N" | every figure present is `analyticalEligible: false` → render the cards **with** their limitations, and say figures are not comparable | no metric findings |
| 5 | **Comparisons & charts** | ≥1 `analyticalComparisons` entry **or** ≥1 chart with `renderEligible !== false` | charts > 2 → first 2 + "Inspect all charts" | **only** when the document holds ≥2 metric findings sharing a concept but no chart qualified — then one line naming the reason from `unavailabilityReason`, because the reader has a right to know why a comparison they can see in the figures is not drawn | no comparison and no eligible chart and no such near-miss. **This is the rule that stops the empty financial dashboard.** |
| 6 | **Obligations & dates** | ≥1 `deadlines` entry **or** ≥1 `analysisGroups[].items` with `kind === 'obligation'` | >6 → first 6 | an obligation exists with no resolvable date → *"Date not established"* per item (exists) | neither |
| 7 | **Parties** | ≥1 entity of type `organization` or `person` | always collapsed to a 6-item list, expandable | — | none. Must keep the existing *"contractual role unverified"* qualifier |
| 8 | **Required review** | ≥1 risk with `status === 'open'` **or** ≥1 untracked deadline **or** `doc.status === 'Needs Review'` | never | review controls unavailable for this user's role → say so, do not hide the items | nothing open |
| 9 | **Evidence ledger** | ≥1 source-located finding | **always collapsed by default** — it is a reference table, not a reading surface | — | no located findings |
| 10 | **Limitations & unprocessed** | any of: `analysis.limitations.*` non-empty · `visualAnalysis.omitted > 0` · ≥1 `pageFlags` not `parsed` · `processingFailure` · `intelligenceProcessingStatus ∈ {Failed, Needs Review}` · coverage state ≠ `complete` | collapsed, with the **count** of disclosures visible on the summary line | — | nothing to disclose. This is the only section that may be absent as a positive statement, and even then the coverage line (0) still speaks |
| 11 | **Notes & next actions** | notes capability `enabled` | collapsed when empty, open when the document has notes | capability `disabled` → *"Notes are not enabled for this workspace."* (exists) | — |
| — | **Original document** | **not a section.** It is the reader column, present whenever a source is selected, and `SourceContent`'s idle state already invites the first selection | | | |

**Three rules that follow from this table and must not be weakened:**

1. **A section's heading may not contain a verification word the section's own data does not carry.** "Verified performance comparisons" is legitimate — `AnalyticalComparison.verification` is literally `'source_verified'`. "Verified financial visualization" over a `document_visual` transcription is not (E-02). Where origins mix in one section, each item carries its own origin label, as `UnifiedCharts.originLabel` already does.
2. **An unavailable section states what is missing, never what the document lacks.** The existing `RiskList` sentence is the model for all of them.
3. **A partially analysed document keeps every valid finding and discloses the rest.** Nothing in the availability rules reads a document-level failure state; sections are driven by their own evidence, so a document whose chart stage failed still renders sections 1–4 and 6–11 in full, with section 10 naming the failure. This is already how `DocumentAnalysisComposer` and `dashboardData()` behave; the change is that the disclosure becomes one section instead of five scattered notices (E-14).

## D.4 Interaction model

- **One selection concept, which exists:** `WorkspaceSelectionContext` — `selected`, `resolved`, `select()`, `clear()`. Every section's items call `select()`; the reader resolves it; nothing else coordinates.
- **Clicking a finding** opens its evidence in the reader column (docked) or the full-screen sheet (narrow). The reading position of the report column is preserved — `rememberAnchor`/`pendingRef` already do this and it is one of the better things in the codebase.
- **A chip says what is known before it is clicked** (E-12 fix): with a recorded page → *"Show page 7 in document"*; with an excerpt but no page → *"Inspect source"*; with neither → *"Inspect source (no recorded location)"*. Derived from `sourcePages[ref]` and `evidence[ref]`, both already on the response, and overridden by `selection.resolved` once `/sources` has answered — which is the current precedence rule, applied to all chips instead of one.
- **Notes do not displace the source.** Replace the mutually exclusive `Source | Notes` toggle with a notes composer **docked under** the reader, pre-filled with the current selection's citation. `WorkspaceNotesPane`'s `canCite` path already captures exactly this.
- **Escape** closes the reader, after the download menu and preview have had their chance — the existing guard in `DocumentWorkspace` is correct and stays.
- **No panel-width slider.** If resizing is wanted, a divider drag; otherwise the measured dock (E-11).

## D.5 Source navigation

Preserve, unchanged, every format truth in `sourceCapabilities.ts`: no exact PDF highlight; DOCX to a path and never a page; XLSX only on a server-verified cell; images only with located regions. Four changes:

1. **Wire `located`** (E-09). When the viewer reports the passage found on the page, the strip collapses to one truncated line; when not found or unknown, it stays full. The viewer already computes it.
2. **Pass `hasOriginal` and `citedPage`** at the Workspace call site (E-10).
3. **Jump-to-next-occurrence stays visible when it exists.** `sourceNotes.highlightNote()` already distinguishes a repeated quote and the stepper exists; the only requirement is that the count is stated.
4. **Long documents:** the single-page view, printed page labels and page-jump control already exist (`pdfPageLabels`, `SourceJumpControl`, `pdfNavigation`). No new navigation is justified by this audit.

## D.6 Present mode

A **display mode of the same page**, not a second page, and not a slideshow. It keeps the same data, the same selection context and the same reader.

Entering Present mode:

- **Pins the revision.** `publishedRevisionId` is captured and displayed; the page does not re-render into a new revision mid-meeting. `useSourceResolution` already implements exactly this discipline for an open source — Present mode extends it to the whole page.
- **Hides:** the section rail, the evidence ledger (9), limitations detail (10 collapses to its count and the coverage line), notes list, and all page chrome except identity and the exit control.
- **Shows, at projection scale (≥18px body, ≥28px figures):** coverage line, Assessment, Attention, Key findings (8 max), Figures (8 max), Comparisons/charts, Obligations.
- **Keeps evidence one click away.** A finding opens the reader as a full-screen sheet over the presentation and returns with Escape. `SourceFullScreenSheet` already is this component.
- **Keeps capture one key away.** A quick-note affordance that writes a `/notes` entry with the current selection's citation — already supported by the notes contract, including the revision stamp.

**What Present mode can do today vs what needs backend work** — see Part F rows F-18 to F-22. Summary: everything above is possible with existing endpoints **except** recording a decision or an action item, which has no entity, and anything multi-user, which is out of scope by instruction.

## D.7 What this deliberately does not become

Not a project-management system. No assignees, no statuses beyond the two that exist (`risks.status`, `tracked_items.status`), no boards, no meeting objects, no agendas, no attendee lists, no comment threads, no sharing. A note with a citation and a tracked deadline are the whole of "next actions" until organization workspaces exist.

---

# Part E — Template architecture

Templates are justified **only** in the reduced form below. The slot registry as built is not, for the reasons in B-2 and C.3.

## E.1 What to delete, keep and change

| Current structure | Verdict |
|---|---|
| `DashboardWidget` union (14 kinds) | **Keep**, renamed `ReportSection`. These are the right sections. |
| `widgetAvailable(widget, data, profile)` | **Keep and promote.** This is already the availability engine Part D needs. The `_profile` parameter is unused — drop it. |
| Section components (`Assessment`, `Attention`, `Findings`, `Metrics`, `ComparisonView`, `RiskList`, `Parties`, `Obligations`, `SupportingAnalysis`, `EvidenceTable`, `FinancialChart`, `WorkspaceCoverage`) | **Keep, nearly unchanged.** Changes: E-02 heading/origin label, and review controls into `RiskList`/`Obligations` (E-06). |
| `dashboardData()` | **Keep** as the view-model builder. Rename `presentationModel()`. Replace the six content regexes with typed signals as they become available (F-13/F-14). |
| `DashboardSlot` (`priority`, `span`, `empty`, `interactions`, `exports`) | **Replace** with `ReportSectionRule` below. `interactions`, `exports`, `span.mobile` are dead (E-04). |
| `DASHBOARD_TEMPLATES` (3 × 7 slots) | **Replace** with one ordered section list + 2–3 ordering profiles. |
| `DASHBOARD_CATEGORIES`, `DashboardTemplate.category` | **Delete** (E-04), with the two assertions in `tests/dashboard-registry.test.tsx`. |
| `recommendDashboard()` | **Reduce** to `recommendProfile()` returning an *ordering*, never a section set. |
| `templateSuitability()` | **Delete.** With one section set, no profile can be "unavailable"; sections are available or not, individually. `hasPayrollContent()` survives as a *profile* guard. |
| The hand-written `fill` expression (6 widget pairs) | **Delete.** Spans come from the section rule and a generic "last in row grows" rule. |
| `localStorage` template preference | **Keep for now** as a local display preference; see F-16 for why it cannot be the basis of anything shared. |

## E.2 The smallest sufficient registry

```ts
// Conceptual. One file, replacing dashboardRegistry.ts's template half.

export type ReportSection =
  | 'coverage_line' | 'assessment' | 'attention' | 'findings' | 'figures'
  | 'comparisons' | 'obligations' | 'parties' | 'required_review'
  | 'evidence_ledger' | 'limitations' | 'notes';

export interface ReportSectionRule {
  id: ReportSection;
  /** Base rank. A profile may reorder; it may never add or remove. */
  rank: number;
  /** Desktop/tablet column span out of 12/6. Mobile is always full width. */
  span: { desktop: number; tablet: number };
  /** What to do when `available` is false. */
  absent: 'omit' | 'state_unavailable';
  /** Collapsed on first render? A section may collapse; it is never hidden. */
  collapsed: boolean;
  /** THE availability predicate — today's widgetAvailable, one per section. */
  available: (m: PresentationModel) => boolean;
  /** Optional: a reason to render a "why not" line instead of omitting. */
  nearMiss?: (m: PresentationModel) => string | null;
}

/** Orderings only. No section sets. */
export interface OrderingProfile {
  id: 'balanced' | 'performance' | 'commitments';
  name: string;
  /** Sections promoted above their base rank, in this order. Nothing else moves. */
  promote: ReportSection[];
  /** Headings/wording overrides, e.g. 'Critical clauses and material risks'. */
  labels?: Partial<Record<ReportSection, string>>;
  /** Hard guard, e.g. payroll content blocks 'performance'. */
  blockedWhen?: (m: PresentationModel) => boolean;
}
```

Three profiles, each a permutation of one list:

| Profile | Promotes | Replaces |
|---|---|---|
| `balanced` (default) | — (base rank) | `general_executive_brief` |
| `performance` | `figures`, `comparisons` | `financial_performance` |
| `commitments` | `obligations`, `parties`, `required_review` | `contract_intelligence` |

`recommendProfile()` maps the backend's twelve classifications plus the payroll guard:

```
financial_report, invoice                         → performance (unless hasPayrollContent)
contract, regulatory_filing, compliance_report    → commitments
everything else (incl. 'other' and null)          → balanced
```

**The whole behavioural difference between profiles is the `promote` array and the `labels` map.** A profile cannot produce an empty section, cannot hide a populated one, and cannot be "unsuitable" — which removes E-02's class of defect by construction, along with `templateSuitability`, `missingSlots`, the `fill` expression and the three-card switcher as page-lead. The profile control becomes one small select in the identity bar, labelled as a display preference, not a 92px-tall card row above the assessment.

**Line-count estimate:** the registry goes from 189 → ~110 lines; `DashboardTemplates.tsx` from 471 → ~380 (the section components are most of it and are kept); the slot/suitability/fill machinery, ~90 lines, is deleted outright.

## E.3 Composition over the shared model

```
/intelligence  ──┐
/documents/{id} ─┴─► presentationModel()  ──►  sectionRules.filter(available)
                     (one object: findings,        ──► sort(rank, profile.promote)
                      figures, comparisons,        ──► render(section, model, labels)
                      charts, risks, obligations,
                      parties, coverage,
                      limitations, notes, revision)
```

One model, built once per `(documentId, revisionId)`, memoised — which `dashboardData()` already is. Every section reads the model; no section fetches. The reader is the only component that calls `/sources`, and it already is.

**This answers the brief's question 7 directly:** no view needs a different AI response, because no view asks the AI anything. `presentationModel()` is the shared presentation-ready view model; the section rules are the evidence-aware component registry; the ordering profiles are the template configuration, reduced to the only part that earned its keep; `documentType` is the capability signal and is advisory only; the `localStorage` profile is the user preference; and `published_intelligence_revision_id` is the stable analysis revision, already enforced by `useSourceResolution` and `dashboardData()`'s revision check.

## E.4 How each view handles each evidence state

One table, because the answer must be the same in every section and in Present mode.

| State | Source of truth | Presentation rule |
|---|---|---|
| Verified observation | `measurementIntegrity.sourceVerification === 'verified'` + `analyticalEligible` | Value, source unit, period, source chip. No badge needed — verified is the baseline, and decorating it devalues the limitation labels |
| Unverified finding | `findingLimitations()` non-empty | Rendered **with** every limitation string inline. Never promoted into a comparison, a chart or Present mode's figure row |
| Unsupported chart | `renderEligible === false` | Degrade to the labelled data table with `unavailabilityReason` — `Chart.tsx:234-235` already does this |
| Missing evidence | `evidence[ref]` absent | *"Exact source navigation is unavailable for this finding."* (exists) — the finding stays visible |
| Partial results | `limitations`, `pageFlags`, `visualAnalysis.omitted`, `coverage.state` | Section 10 + the coverage line. Valid findings are never withheld because something else failed |
| Failed section | `processingFailure.stage`, `intelligenceProcessingStatus` | Named in section 10 by stage. If a previous revision published, the existing *"The latest refresh did not publish…"* line stands |
| Conflicting information | `source.quality.conflictStatus` | The `Conflicting` overlay beside the location label, not instead of it (exists, `EvidenceBadge`) — **both records kept, neither chosen** |
| Historical record, unknown provenance | `sourceOrigin !== 'document_visual'` | `renderEligible: false` → table, with "The origin of this historical chart is unavailable." It must **not** enter `figures` or `comparisons`, and **not** Present mode. (V9 R-10: the better fix is backend-side — stop persisting ungrounded model chart JSON — but the frontend rule holds either way) |
| Transcribed document visual | `sourceOrigin === 'document_visual'` + `valueStatus === 'exact'` | May render, **must** carry "Extracted from document · AI-transcribed printed labels; compare with the original", **must not** sit under a heading containing "Verified" (E-02) |

---

# Part F — Backend/frontend readiness

Legend: **A** Available · **P** Partially available · **M** Missing · **V** Requires independent validation.

"Requires independent validation" means a field exists but I could not establish from the repository alone that it is populated for real documents — it needs one authorised run against live data before a UI is built on it.

| # | Proposed UI feature | Backend field / endpoint | State | Note |
|---|---|---|---|---|
| F-01 | Assessment section | `intelligenceSummary.executiveAssessment{text,basis,source_ids}`, `.executiveSummary` | **A** | `basis: 'explicit' \| 'inferred'` is rendered as *"{basis} synthesis"* at `DocumentIntelligencePanel.tsx:1201` — i.e. only inside the collapsed panel, and **not** by the `Assessment` widget that replaced it. It is exactly the qualifier the lead assessment needs |
| F-01b | Material findings / trends / tensions / open questions | `intelligenceSummary.{materialFindings,trends,tensions,questions}[]`, each with `basis` and `source_ids` | **A, unconsumed by the lead surface** | Rendered only in `DocumentIntelligencePanel.tsx:1211-1226`. The dashboard drops this entire tier, including `questions` — the one payload that tells a reader what the analysis could not settle |
| F-02 | Coverage line (one sentence) | `processingDetails.coverage` (raw counters, always present, value may be null) | **P** | Counters are available; the *state* (`complete/bounded/partial/unavailable`) reaches the frontend only via `analysis.attention.coverage`, which is V2-gated. Without V2 the line must be built from counters + `pageFlags` + `limitations`, and may not claim a state |
| F-03 | Attention section | `analysis.attention{state,needs_attention_count,watch_count,next_due}` | **M today** | Code exists (`AttentionStateBuilder`), gated on `DOCINTEL_INTELLIGENCE_V2`, default **false**. Fallback: derive from `risks[].severity` + `deadlines[].dueDate`, which is **A** |
| F-04 | Materiality ordering of findings/figures | `analysis.tier1[]` (`sourceId`, `kind`, `forced`, `forcedRule`, `tierReasons`) | **M today** | Same flag. Fallback ordering: `confidence`, then `severity`, then presence of a date — weaker and must not be described as materiality |
| F-05 | Adaptive section ordering from verified blocks | `intelligence.brief{blocks[],ai_blocks_available,ai_blocks_rejected,template_version}` | **M today / A in code** | **Built, served, unconsumed (E-13).** Block types `headline\|attention\|timeline\|measure\|coverage_note`, each with `cites`, `evidence[]` (`span_id`, offsets, `quote`, `page`, `highlight.mode`), `typed`, `tier`, `attention`, `attribution`, `assertion`, `template_id/version`. Not declared in `types.ts`. Flag-gated |
| F-06 | Verified narrative paragraph | `brief.narrative.claims[]` + `fallbackReason` | **M today** | `DOCINTEL_V2_BRIEF_NARRATIVE` default **false**; `max_rejected_claims: 0` means it is all-or-nothing, which is the right default for this product. Enabling it is a **paid** inference change and out of scope here |
| F-07 | Key findings list | `analysis.importantFindings[]`, `analysis.analysisGroups[].items[]`, `analysis.overview.takeaways[]` | **A** | `takeaways[].origin` (`synthesis\|metric\|trend\|risk\|obligation`) and `basis` are available and underused |
| F-08 | Figures with verification limits | `analysis.analysisGroups[].items[kind='metric'].measurementIntegrity{…}`, `doc.kpis[]` with `numericVerification`/`sourceVerification`/`unitVerification`/`analyticalEligible`/`limitationReasons` | **A** | Fully wired through `measurementPresentation.ts`. V9 R-07 notes `source_revision_mismatch` is reported on rows that merely have no evidence — a **backend** diagnostic fix; until then the UI may surface that reason as a limitation it cannot explain |
| F-09 | Verified comparisons | `analysis.analyticalComparisons[]` (`kind`, `gap`, `gapUnit`, `direction`, `left/right/reported`, `calculation`, `verification: 'source_verified'`) | **A** | The best-shaped payload on the response. Rendered today inside the collapsed panel and in one dashboard slot |
| F-10 | Charts from verified measurements | `analysis.visualAnalysis.charts[]`, `.omitted`, `.rejected` | **A** | V9 R-01: in practice frequently **zero** for real reports. The UI must be correct at zero, which is what D.3 §5 specifies |
| F-11 | Charts transcribed from document visuals | `doc.charts[]` with `sourceOrigin`, `sourceImageUrl`, `dimensionsVerified`, `valuesVerified`, `valueStatus`, `axis`; `GET /documents/{id}/charts/{chart}/image` | **A** | Gated correctly by `sourceChartRec()`. E-02 is a labelling defect, not a data gap |
| F-12 | Legacy ungrounded chart suppression | `sourceOrigin !== 'document_visual'` | **A (frontend)** / **M (backend)** | V9 R-10: `GenerateInsightsJob.php:231-270` persists the model's chart JSON ungrounded. The frontend already degrades it; the channel should close at source (V9 P8) |
| F-13 | Figure classification (financial / operational / payroll) | — | **M** | Done in the browser by 6 regexes in `dashboardRegistry.ts`. `MeasurementParser`/`MeasurementIntegrity` hold `unitKind` and currency server-side; exposing a `measurementFamily` on each metric finding would retire all six |
| F-14 | Obligation vs clause vs narrative kinds | `analysisGroups[].items[].kind`, `deadlines[].deadlineType`, `dateType`, `relativeText` | **P** | `kind === 'obligation'` is available; the contract-finding set is currently a **regex over labels** (`CONTRACT` in `dashboardRegistry.ts`), which is not a classification |
| F-15 | Document capability signal for ordering | `documentType{documentType,confidence}` (12 values) | **A** | Advisory only in the proposed design, which is the right weight for a model classification with a confidence attached |
| F-16 | User presentation preference | — | **M** | `localStorage` only, per `(userId, documentId)`. Does not persist across browsers and the backend cannot read it. Acceptable for a display preference; **unusable** as the basis of a report, an export or anything a second person sees |
| F-17 | Stable analysis revision for a meeting | `published_intelligence_revision_id`, `active_intelligence_revision_id`, `intelligenceRefreshInProgress` | **A** | Already load-bearing: `useSourceResolution` pins it, `dashboardData()` rejects a revision mismatch, `/sources` is keyed on it, note citations stamp it |
| F-18 | Present mode (display mode of the same data) | — | **A** | Needs no backend work. Pure frontend |
| F-19 | Open evidence during questioning | `GET /documents/{id}/sources/{sourceId}` (+ `/cell-window`, `/docx-window`) | **A** | Including the refusal vocabulary (`SOURCE_UNAVAILABLE`, `SOURCE_AMBIGUOUS`, `SOURCE_TOO_COMPLEX`, `STALE_SOURCE_REFERENCE`, `SOURCE_RATE_LIMITED`) that `evidenceLabels.ts` already maps |
| F-20 | Capture a discussion note, citing a finding | `POST /documents/{id}/notes` with `citations[{finding_label,quote_snapshot,intelligence_revision_id,source_id,page}]` | **A, flag-gated** | `DOCINTEL_NOTES_ENABLED` default **false**. The citation snapshot + revision stamp is the right model for meeting capture |
| F-21 | Record a decision / action item | — | **M** | No entity. Nearest: `POST /tracked-items` (deadline-shaped) and `PATCH /documents/{id}/risks/{risk}` (`open\|mitigated\|closed`). A general decision record is new backend work and is **not** recommended before organization workspaces exist |
| F-22 | Mark a finding for follow-up | `PATCH /documents/{id}/risks/{risk}` · `POST /tracked-items` · `PATCH /tracked-items/{id}` | **P** | Covers risks and dated obligations. A metric or narrative finding cannot be flagged |
| F-23 | Required-review section | `doc.canApprove/canReject/canReprocess/canDelete`, `risks[].status`, `GET /tracked-items/attention?today=` | **A** | Policy flags come from `DocumentPolicy` per the page's own comment; use them, not role checks |
| F-24 | Limitations & unprocessed section | `analysis.limitations{measurements[],visualGroups[],coverage}`, `pageFlags[]`, `visualAnalysis.omitted`, `processingFailure{kind,stage,message,recovery}` | **A** | Everything needed for one consolidated section is on the wire today |
| F-25 | Per-chip "is there a location" before click | `sourcePages{ref:page}`, `evidence{ref:{quote,sources[]}}` | **A** | Both restricted server-side to referenced ids, which is why they are cheap enough to use this way |
| F-26 | Evidence label vocabulary | `/sources` → `state`, `matchState`, `quality.{conflictStatus,ocr,sourceChannel,ambiguity}`, `limits.relationshipsTruncated`, `provenancePolicy`, `revision` | **A** | Complete and correctly consumed. Do not extend without a backend field behind it |
| F-27 | Highlight mode per evidence ref | `brief.blocks[].evidence[].highlight{mode,start_offset,end_offset,needle,occurrence,occurrences,page}` | **M today / A in code** | `BriefAssembler` currently emits `mode: 'none' \| 'page_only'` only, with the offset/needle/occurrence fields present and null. **V** — the richer modes need validation before any UI claims them |
| F-28 | Word report export | `GET /documents/{id}/report-snapshot` (+ `limitations`, `complete`), `reportBuilder.tsx`, `POST …/report-generated` | **A** | Already the honest export path |
| F-29 | Organization collaboration | `WorkspaceType = 'Organization'` type only | **M** | Out of scope by instruction. No part of this design assumes it |

**Three conclusions from this table:**

1. **Everything in Part D's sections 0–2 and 4–11 can be populated from today's non-V2 response**, with the single degradation that ordering is `confidence`/`severity`/`date` rather than materiality, and the coverage line states counters rather than a state (F-02, F-03, F-04).
2. **The highest-leverage backend-dependent item is not new work — it is a flag and a type declaration.** `DOCINTEL_INTELLIGENCE_V2=true` turns on F-03, F-04, F-05 and F-02's state; the frontend then needs a `brief` type and a reader. That is the step that converts the whole presentation layer from reconstructed-in-the-browser to served-and-verified.
3. **Two genuinely missing capabilities** are worth naming so they are not accidentally designed around: a typed `measurementFamily` on metric findings (F-13), which would retire six browser regexes, and a decision/action record (F-21), which should **not** be built yet.

## F.1 Flags, for the record

| Flag | Default | Current local value | Gates |
|---|---|---|---|
| `VITE_DOCUMENT_WORKSPACE` (frontend) | unset → off | `1` in untracked `CA/.env`; **absent** from tracked `.env.production` and `netlify.toml` | Workspace, templates, source pane, notes pane, section nav |
| `DOCINTEL_INTELLIGENCE_V2` | **false** | unset | `brief`, `analysis.tier1`, `analysis.attention`, coverage `state`, materiality |
| `DOCINTEL_V2_BRIEF` | true | unset (→ true) | Brief assembly, but only when V2 is on |
| `DOCINTEL_V2_BRIEF_NARRATIVE` | **false** | unset | B2 verified narrative (paid inference) |
| `DOCINTEL_V2_CHARTS` | true | unset (→ true) | V2 chart path |
| `DOCINTEL_NOTES_ENABLED` | **false** | unset | Notes pane and `/notes` routes |
| `DOCINTEL_NOTES_RICH_TEXT_ENABLED` | **false** | unset | Rich-text notes |
| `DOCINTEL_EVIDENCE_SPANS_ENABLED` | false in `.env.example` | unset | Span-id evidence channel |

No flag was changed by this audit.

---

# Part G — Implementation roadmap

Ordering rules, so the sequence is not negotiable on taste:

1. **Nothing new ships on a red suite.** E-15 is 20 failures in the two newest evidence-location paths; five of them assert what the UI tells a reader about where evidence is.
2. **Decide the audience before building for it.** E-01 — until the flag state is recorded, presentation work has no users.
3. **Correctness of claims before layout.** E-02 and E-10 are the product saying something it has not established.
4. **Reveal what is already built before building more.** E-06/E-07/E-13 are hidden or unconsumed work, not missing work.
5. **Backend flags before frontend features that depend on them.**
6. **New capability last.**

Each item below is file-level, with acceptance criteria (AC) and tests (T). Effort is engineering days, one developer, including tests.

## G.0 Gate — before any of the below

| Step | Command | Gate |
|---|---|---|
| Typecheck | `npm run typecheck` (`tsc -p tsconfig.app.json`) | clean |
| Lint | `npm run lint` | clean |
| Tests | `npx vitest run` | **917/917** |
| A11y | `npm run a11y:workspace` | no new violations |

---

## G.1 Quick improvements to existing components

### Q1 — Repair the 20 failing tests *(1.5 d, P1)*

**Files:** `src/components/workspace/imageRegions.ts`, `imageGeometry.ts`, `adapters/ImageAdapter.tsx`, `sourceCapabilities.ts`, `adapters/XlsxAdapter.tsx`, `src/components/IntelligenceAnalysis.tsx` — and the six test files, which are the specification here, not the code.

**AC:** `npx vitest run` is 917/917. For each failure, the resolution is recorded as either *the code regressed* or *the contract changed and the test was updated* — the latter requires a one-line justification in the test, because every one of these assertions is about an evidence claim.
**T:** the six existing files. Add one assertion per repaired behaviour that `sourceCapabilities.canHighlightExact` and what `ImageAdapter` actually draws cannot disagree — `tests/image-capability-agrees-with-adapter.test.tsx` already exists for exactly this and should be extended, not replaced.

### Q2 — Record the Workspace flag decision *(0.5 d, P1)*

**Files:** `CA/.env.production`, `CA/netlify.toml`, `CA/README` or `CA/docs/`.

**AC:** `VITE_DOCUMENT_WORKSPACE` is set explicitly in the tracked production configuration — `0` or `1`, decided deliberately — and a one-paragraph note records which experience ships and why. A build from a clean checkout produces the intended page. If the answer is `1`, Q3–Q6 are prerequisites and the flag flips after them.
**T:** a test asserting the tracked production env declares the flag (a config assertion, not a UI one).

### Q3 — Stop the "Verified" overstatement *(0.5 d, P1 — E-02)*

**Files:** `src/components/workspace/DashboardTemplates.tsx` (`FinancialChart`), `src/components/workspace/dashboardRegistry.ts` (`DashboardChart.origin`).

**AC:** (a) `dashboardData()` distinguishes `document_visual` from legacy exactly as `UnifiedCharts` does, rather than marking all `doc.charts` as `'document'`. (b) Every chart rendered in a section prints its origin label, reusing `UnifiedCharts.originLabel`. (c) No section heading contains "Verified" unless every item under it is backed by `sourceVerification === 'verified'` or `AnalyticalComparison.verification === 'source_verified'`; the financial chart heading becomes e.g. "Financial visualization".
**T:** extend `tests/dashboard-templates.test.tsx` — given a `document_visual` chart that passes `sourceChartRec`, the rendered section shows "Extracted from document" and no heading in the subtree matches `/verified/i`. Given a legacy chart (`sourceOrigin` absent), it does not appear in any section.

### Q4 — Pass the capability inputs the function documents *(0.5 d, P1 — E-10)*

**File:** `src/components/workspace/DocumentWorkspace.tsx:150`.

**AC:** the call passes `hasOriginal: doc.hasOriginal ?? null` and the viewer's resolved page as `citedPage`. For a PDF with `hasOriginal === false`, `selection.resolved.canJump` is false and `SourceJumpControl` does not offer "Show page N in document".
**T:** extend `tests/no-original-stored.test.tsx` — render the Workspace with `hasOriginal: false` and a resolved PDF source carrying a page; assert the chip offers no page jump and the pane states `no-original-stored`.

### Q5 — Surface what is already in the response on every chip *(1 d, P2 — E-12)*

**Files:** `src/components/intelligenceSources.tsx`, `src/components/workspace/SourceJumpControl.tsx`.

**AC:** before any click, a chip reads "Show page N in document" when `sourcePages[ref]` is an integer; "Inspect source" when an excerpt exists without a page; "Inspect source (no recorded location)" when neither exists. Once `/sources` answers for that reference, `selection.resolved` overrides — the current precedence, unchanged. No wording asserts a location the response does not carry.
**T:** new `tests/source-chip-precedence.test.tsx` — the three pre-click states, plus the override, plus the existing disagreement note ("Listed under page X; located on page Y").

### Q6 — One altitude: dissolve the `<details>` *(1.5 d, P1 — E-06, E-07, E-08)*

**Files:** `src/components/workspace/DocumentWorkspace.tsx`, `DashboardTemplates.tsx`, `useWorkspaceSections.ts`, `src/pages/DocumentDetailPage.tsx` (`hoisted`).

**AC:** (a) `#workspace-deep-analysis` no longer wraps `WorkspaceSummaryBand`, `UnifiedCharts` and the panel; each becomes a sibling section in the report column, collapsed individually per D.3. (b) `hoisted` is extended so the executive summary and coverage render **once**. (c) Risk status and deadline-tracking controls appear in the `risks` and `obligations` sections — reuse the existing handlers, do not duplicate them. (d) Every section-rail target is an element that is visible when scrolled to; where a target can be collapsed, the rail opens it first, as `onInspectCharts` already does.
**T:** extend `tests/document-workspace-structure.test.tsx` — exactly one node matching the executive summary text; exactly one coverage region; a risk-status control reachable without expanding any disclosure; every rail item scrolls to a visible element.

### Q7 — Reclaim the reader's chrome *(0.5 d, P3 — E-09, E-11)*

**Files:** `src/components/workspace/DocumentWorkspace.tsx`, `SourceContent.tsx`, `adapters/PdfAdapter.tsx`.

**AC:** (a) the "Panel width" range input is removed. (b) `located` is passed from the adapter's highlight outcome into `EvidenceStrip`; when the passage was found on the page the strip is one truncated line, otherwise the full passage, with the label unchanged either way. (c) Removing the slider does not change the dock threshold or any measured height rule.
**T:** extend `tests/document-source-view.test.tsx` — with a located highlight the strip has no `source-evidence-toggle` and one truncated line; with `found: false` the full passage and the toggle remain. Keep the existing `tests/source-notes.test.tsx` literals byte-identical.

### Q8 — Fix the period comparison *(0.5 d, P2 — E-03)*

**File:** `src/components/workspace/analyticalModel.ts` (`compatibleEarlier`).

**AC:** periods compare on an ordered period key — reuse `continuousPeriods`' existing parser in `chartLayout.ts`, which already handles `FY2025`, `2025`, `Q3 2026`, `H1 2026` and `2026/07` and already refuses to mix kinds. Two observations join only when kind matches, subject matches, unit matches and revision matches — the current guards stay.
**T:** extend `tests/analytical-model.test.tsx` — a quarterly series resolves the previous quarter; a quarter and a fiscal year do not join; a mixed-unit pair does not join.

### Q9 — Delete the dead registry structures *(0.5 d, P3 — E-04)*

**Files:** `dashboardRegistry.ts`, `tests/dashboard-registry.test.tsx`.

**AC:** `DASHBOARD_CATEGORIES`, `DashboardCategory`, `DashboardTemplate.category`, `DashboardSlot.interactions`, `DashboardSlot.exports` and `span.mobile` are gone, with the two assertions that only guarded them. Typecheck clean, no behaviour change.
**T:** existing suite passes; a new assertion that every field on the slot/rule type is read by the renderer (reflection over the type is not possible in TS at runtime, so assert the complement: rendering a section set produces the declared spans).

**G.1 total: ~7 days. Q1–Q4 are the P1s and are ~4 days.**

---

## G.2 New presentation capabilities

### N1 — `presentationModel()` and the section-rule registry *(3 d, P1)*

**Files:** rename/replace `dashboardRegistry.ts` → `presentationModel.ts` + `sectionRules.ts`; `DashboardTemplates.tsx` → `DocumentReport.tsx`.

**AC:** one memoised model per `(documentId, revisionId)`; twelve section rules per D.3 with `available`, `absent`, `collapsed`, `rank`, `span`; sections render in rank order; `templateSuitability`, `missingSlots`, the `fill` expression and the three-card switcher are deleted. Every existing section component is reused with no change to its evidence handling. **No section may appear whose `available` predicate is false unless its `absent` is `state_unavailable`.**
**T:** new `tests/section-rules.test.tsx` — a table-driven test, one row per section × {available, unavailable, near-miss}, asserting present/omitted/unavailable-sentence. Plus: a model with zero charts and zero comparisons renders **no** comparisons section and no empty chart surface (the B-4 regression guard).

### N2 — The coverage line *(1.5 d, P1 — B-5, E-14)*

**Files:** new `src/components/workspace/CoverageLine.tsx`; `WorkspaceCoverage.tsx` becomes its disclosure.

**AC:** one sentence, always present, built only from fields on the response; it states the coverage **state** only when `analysis.attention.coverage.state` exists (V2), and otherwise states counters and disclosure counts without a state word. No percentages, no page claims, no "nothing found" — `WorkspaceCoverage`'s three documented non-goals are inherited verbatim. A "What was read" disclosure holds the counter grid, `pageFlags`, `analysis.limitations` and `visualAnalysis.omitted` — the five scattered surfaces of E-14, in one place.
**T:** new `tests/coverage-line.test.tsx` — with no coverage data, with counters only, with a V2 state, with page flags, with a failed stage; assert no percentage ever appears and no state word appears without a state field.

### N3 — Ordering profiles *(1.5 d, P2)*

**Files:** `sectionRules.ts`, `DocumentReport.tsx`.

**AC:** three profiles (`balanced`/`performance`/`commitments`) as `promote` arrays + `labels` maps; `recommendProfile()` maps the twelve classifications per E.2 with `hasPayrollContent()` as a guard; the control is one select in the identity bar labelled as a display preference; a profile can never add, remove or empty a section.
**T:** replace `tests/dashboard-templates.test.tsx` with `tests/ordering-profiles.test.tsx` — for each profile, the rendered **section set** is identical and only the order and headings differ; payroll content blocks `performance`; an unknown classification lands on `balanced`.

### N4 — Present mode *(2.5 d, P2)*

**Files:** new `src/components/workspace/PresentMode.tsx`; `DocumentWorkspace.tsx`; `WorkspaceDocumentHeader.tsx`.

**AC:** per D.6 — pins and displays the revision; hides rail/ledger/limitations-detail/notes-list; projection type scale; a finding opens `SourceFullScreenSheet` and Escape returns; a quick note writes a `/notes` entry with the current citation when the capability is enabled, and the control is absent when it is not. **No item appears in Present mode whose limitations are non-empty, and no legacy-origin chart appears at all.**
**T:** new `tests/present-mode.test.tsx` — revision pinned and displayed; an unverified figure is absent; evidence opens and returns focus to the finding; with notes disabled no capture control renders.

### N5 — Notes beside the source, not instead of it *(1 d, P2 — B-6)*

**Files:** `DocumentWorkspace.tsx`, `WorkspaceNotesPane.tsx`.

**AC:** the `Source | Notes` toggle is replaced by a composer docked under the reader, pre-filled with the current selection's citation; the passage stays visible while writing; the saved-notes list moves to section 11 in the report column. The 409/version handling and the citation snapshot are unchanged.
**T:** extend an existing notes test — with a source open, the composer and the passage are both in the tree; saving posts a citation carrying `source_id`, `page` and `intelligence_revision_id`.

**G.2 total: ~9.5 days.**

---

## G.3 Backend dependencies

Nothing here is required for G.1 or G.2. Each item unlocks a named improvement.

| # | Change | Unlocks | Effort | Notes |
|---|---|---|---|---|
| B1 | Decide and record `DOCINTEL_INTELLIGENCE_V2` for a workspace; validate on real documents | F-02 state, F-03 attention, F-04 tier1, F-05 brief | 1 d + validation | **Highest leverage item in this report.** Config + evidence, not code |
| B2 | Declare `brief` in `CA/src/types.ts` and read `blocks[]` for section **ordering and attention only** (not as a replacement for section content) | Materiality-ordered report; verified attention; typed dates with `resolution` | 3 d | Strictly additive: `presentationModel()` falls back to today's ordering when `brief` is absent |
| B3 | Expose a typed `measurementFamily` (`currency`/`percentage`/`count`/`duration`/`payroll`/…) per metric finding from the data `MeasurementParser` already holds | Retires all six regexes in `dashboardRegistry.ts` (F-13) | 2 d | Pure serialization of existing state |
| B4 | V9 P8 — stop persisting ungrounded model chart JSON, or mark the channel explicitly | Closes F-12 at source | — | V9's call, not this audit's. The frontend rule in E.4 holds either way |
| B5 | V9 P3 — correct `source_revision_mismatch` semantics on unevidenced rows | Removes a limitation string the UI cannot explain (F-08) | — | V9 R-07 |
| B6 | Validate `brief.blocks[].evidence[].highlight` modes beyond `page_only` | A precise in-page highlight claim (F-27) | — | **Requires independent validation before any UI reads it** |

## G.4 Future organization / meeting capabilities — and what not to build

**Deferred until organization workspaces exist** (F-29): shared presentation links; a second viewer of the same pinned revision; comments on findings; assignment of review items.

**Deliberately not built, with the reason:**

| Not building | Why |
|---|---|
| A decision/action-item entity (F-21) | No consumer. `risks.status` and `tracked_items` already cover the two cases a single-user workspace can act on. Adding a third record type now is the project-management drift the brief warns against |
| A fourth, fifth or per-class template | B-2. New document kinds change *order*, not the section set |
| Slide export / slideshow Present mode | The reader and the pinned revision are what make a meeting work here. A deck is a worse `.docx` (F-28) |
| Per-section AI regeneration | No view needs a different AI response (E.3). Every section reads the same stored evidence |
| Confidence scores, coverage percentages, verification badges on verified items | `evidenceLabels.ts` and `WorkspaceCoverage` already state why: the backend carries no such number, and one invented in the browser reads as a measurement |
| A chart-first dashboard for financial documents | B-4. Zero charts is the common case on real reports and the layout must be correct there first |

## G.5 Sequence

```
Week 1   Q1 ─ Q2 ─ Q3 ─ Q4            (red suite → green; stop the two overstatements; decide the audience)
Week 2   Q5 ─ Q6 ─ Q7 ─ Q8 ─ Q9       (reveal what is built; reclaim chrome; delete the dead)
Week 3   N1 ─ N2                      (one ordered report; one trust statement)
Week 4   N3 ─ N5 ─ N4                 (profiles; notes beside source; Present mode)
         B1 runs in parallel from week 1 (config + validation, not code)
         B2 ─ B3 follow B1
```

**Checkpoint after week 2:** the existing product, same data, same flags, with nothing hidden, nothing duplicated, nothing overstated. That is shippable on its own and is the honest baseline every later claim rests on.

---

# Part H — Concrete layout specifications

Each scenario marks every element as **[E]** existing component / **[N]** new / **[B]** needs a backend field this build may not have. Wireframes are desktop at ~1440px with the reader open unless stated.

---

## Scenario 1 — Financial performance report

*Verified quarterly measurements, trends, comparisons, source evidence. Classification `financial_report`; profile `performance`.*

> **Every figure below is read from the real fixture**,
> `CA_BACKEND/tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf`
> (7 pages, 81 KB, values in KES million), extracted with `pdftotext -layout`
> and cross-checked against the V9 audit's own reproductions. The first draft
> of this report carried invented values here — USD rather than KES, Q3
> operating costs of 37.0 against the document's 41.0, an operating margin of
> 27.5% against 19.6%, a reconciliation of 14.0 against 10.0, a page count of
> 12 and a size of 2.4 MB. They are corrected. The **counts** on the coverage
> line (evidence records, located findings, disclosures) remain illustrative
> and are marked as such: they depend on a processing run, not on the file.
> **No number in this report is a test expectation**; see the note at the end
> of Part H.

```
─────────────────────────────────────────────────────────────────────────────────
 Northstar_Q3_2026_Board_Performance_Review.pdf                            [E]
 PDF · 81 KB · 7 pages · Internal · 2026 · ● Ready
 [Download] [Report] [Present]            Layout: ⌄ Performance   (display only)[N]
─────────────────────────────────────────────────────────────────────────────────
 7 of 7 pages read · 29 evidence records · 7 source-located findings ·
 2 disclosures            (counts illustrative)    [What was read ▾]       [N]
─────────────────────────────────────────────────────────────────────────────────
 Assessment · Figures · Comparisons · Findings · Risks · Obligations · Evidence[E]
─────────────────────────────────────────────┬───────────────────────────────────
 REPORT                                      │ SOURCE                          │
                                             │ ┌─ Revenue, Q3 2026 ──────── ✕ ─┐│
 ┌─ Executive performance assessment ───────┐ │ │ ● Supported                   ││
 │ Northstar ended Q3 with the highest      │ │ │ ───────────────────────────── ││
 │ quarterly revenue of 2026 to date,       │ │ │ IN YOUR DOCUMENT              ││
 │ reaching KES 51.0m, up from KES 46.0m    │ │ │ ┌───────────────────────────┐ ││
 │ in Q2…                                   │ │ │ │ page 3 of 7               │ ││
 │ Explicit synthesis                  [B]  │ │ │ │ Financial results by      │ ││
 │ Synthesis is not independently verified. │ │ │ │ quarter (KES million)     │ ││
 │ [▸ Read full summary]                    │ │ │ │ ┌───────────────────────┐ │ ││
 │ ⌸ Show page 2 in document                │ │ │ │ │Measure    Q1   Q2  Q3 │ │ ││
 └──────────────────────────────────────────┘ │ │ │ │ │Revenue  42.0 46.0▮51.0│ │ ││
                                              │ │ │ │ │Op costs 33.0 36.0 41.0│ │ ││
 ┌─ Financial and operational figures ──────┐ │ │ │ │ │Op profit 9.0 10.0 10.0│ │ ││
 │ ┌──────────┬──────────┬──────────┬──────┐│ │ │ │ │Op margin21.4%21.7%19.6%│ │ ││
 │ │ Revenue  │ Op costs │ Op margin│ OTD  ││ │ │ │ └───────────────────────┘ │ ││
 │ │ 51.0     │ 41.0     │ 19.6%    │ 87%  ││ │ │ │ Approximate match — the  │ ││
 │ │ KES m    │ KES m    │ %        │ %    ││ │ │ │ mark comes from a text   │ ││
 │ │ Q3 2026  │ Q3 2026  │ Q3 2026  │ Q3   ││ │ │ │ search of the page.  [E] │ ││
 │ │[Inspect] │[Inspect] │[Inspect] │Target│ │ │ └───────────────────────────┘ ││
 │ │⌸ page 3  │⌸ page 3  │⌸ page 3  │95% ⚠ ││ │                                 │
 │ └──────────┴──────────┴──────────┴──────┘│ │                                 │
 │ ▸ Show all 16 figures                [E] │ │                                 │
 └──────────────────────────────────────────┘ │                                 │
                                              │                                 │
 ┌─ Verified performance comparisons ───────┐ │                                 │
 │ On-time delivery vs Q3 target            │ │                                 │
 │ 8 percentage points BELOW target     [E] │ │                                 │
 │ Actual 87% · Target 95%                  │ │                                 │
 │ Revenue − operating costs = KES 10.0m,   │ │                                 │
 │ reconciles with reported operating profit│ │                                 │
 │ ⌸ page 3  ⌸ page 4                       │ │                                 │
 └──────────────────────────────────────────┘ │                                 │
 ┌─ Revenue by quarter ─────────────────────┐ │                                 │
 │ Generated from verified measurements [E] │ │                                 │
 │   51 ┤            ╭──●                   │ │                                 │
 │   46 ┤      ╭──●                         │ │  ← clicking a point selects     │
 │   42 ┤ ●────╯                            │ │    the observation and opens    │
 │      └─Q1───Q2───Q3──  KES million       │ │    its cited cell          [E]  │
 │ [Inspect chart, sources and exports] [E] │ │                                 │
 └──────────────────────────────────────────┘ │                                 │
 ┌─ Selected observation ───────────────────┐ │                                 │
 │ Revenue · 51.0 KES million · Q3 2026     │ │                                 │
 │ Earlier compatible observation:           │ │                                 │
 │ 46.0 KES million in Q2 2026       [E+Q8] │ │                                 │
 └──────────────────────────────────────────┘ │                                 │
 ┌─ Important findings (8 of 23) ───────────┐ │                                 │
 ┌─ Material risks and exceptions ──────────┐ │                                 │
 │ HIGH · operational                       │ │                                 │
 │ R-01 Delivery reliability deterioration  │ │                                 │
 │ Q3 on-time rate 87%; 95% target          │ │                                 │
 │ Status: ⌄ Open  [Mark mitigated]   [E+Q6]│ │                                 │
 └──────────────────────────────────────────┘ │                                 │
 ┌─ Obligations and dates ──────────────────┐ │                                 │
 │ D-01 Board decision on proposed KES 2.4m │ │                                 │
 │      recovery funding · Due 23 Oct 2026  │ │                                 │
 │      ⌸ page 6                            │ │                                 │
 └──────────────────────────────────────────┘ │                                 │
 ┌─ Evidence ledger (23)                  ▾ ┐ │  collapsed                 [E]  │
 ┌─ Limitations and unprocessed (2)       ▾ ┐ │  collapsed                 [N]  │
 ┌─ Your notes                            ▾ ┐ │                            [E]  │
─────────────────────────────────────────────┴───────────────────────────────────
```

**First:** the coverage line, then the assessment. **Navigation:** the section rail; the reader is a peer column, never a modal. **Evidence:** every figure, comparison, finding, risk and obligation carries a chip whose words come from `sourcePages`/`evidence` before the click and from `/sources` after (Q5); a chart point selects an observation (existing). **Empty states:** if zero charts qualify — which is what this very document produces today, per V9 R-01 — the chart card is **absent** and the comparisons section carries a one-line reason from `unavailabilityReason`; the figure grid and the comparisons carry the page. **Responsive:** at <1024px effective width the reader becomes `SourceFullScreenSheet`; the figure grid goes 4→2→1; the rail becomes a horizontal scroller.

**Supported today vs conceptual:** the figure grid, comparisons, chart, selected observation, risks with status, obligations, evidence ledger and notes are all **existing components on existing fields**. New: the coverage line, the consolidated limitations section, the layout select, the flat altitude. `Explicit synthesis` is [B] only in the sense that it exists on the wire and the lead surface does not yet print it (F-01).

**One caution this corrected scenario makes visible.** The document states its own operating-margin trend (21.4% → 21.7% → 19.6%) and its own reconciliation (51.0 − 41.0 = 10.0) in prose on pages 2 and 3. A section that recomputes either is doing arithmetic the document already did; the comparisons section must cite the document's statement where there is one, and `AnalyticalComparison.calculation` is the field that records which it did.

## Scenario 2 — Contract document

*Parties, clauses, risks, obligations; little numeric analysis. Classification `contract`; profile `commitments`.*

> **Invented document.** No contract fixture exists in either repository. The name, clauses, dates and parties below are fictional and exist to exercise the availability rules at a shape the Northstar fixture does not have.

```
─────────────────────────────────────────────────────────────────────────────────
 Supply_Agreement_Northwind_2026.docx · DOCX · 0.6 MB · 31 pages · ● Ready  [E]
 [Download] [Report] [Present]            Layout: ⌄ Commitments              [N]
─────────────────────────────────────────────────────────────────────────────────
 Full text read · 41 evidence records · 18 source-located findings ·
 no disclosures                                       [What was read ▾]      [N]
─────────────────────────────────────────────────────────────────────────────────
 Assessment · Attention · Obligations · Parties · Risks · Findings · Evidence[E]
─────────────────────────────────────────────┬───────────────────────────────────
 ┌─ Executive contract assessment ──────────┐ │ ┌─ Termination for convenience ✕┐│
 │ A three-year supply agreement between…   │ │ │ ● Supported                   ││
 │ Synthesis is not independently verified. │ │ │ ───────────────────────────── ││
 │ ⌸ Inspect source                         │ │ │ IN YOUR DOCUMENT              ││
 └──────────────────────────────────────────┘ │ │ ┌───────────────────────────┐ ││
 ┌─ Requires attention ─────────────────────┐ │ │ │ § 14.2  Termination       │ ││
 │ ⚠ Notice period expires 14 Dec 2026      │ │ │ │ ▮Either party may        │ ││
 │   in 54 days                         [E] │ │ │ │  terminate on 90 days'   │ ││
 │   ⌸ Inspect source                       │ │ │ │  written notice…         │ ││
 │ ⚠ CRITICAL · liability                   │ │ │ └───────────────────────────┘ ││
 │   Uncapped indemnity, clause 11.4        │ │ │ Located at paragraph        ││
 │   Status: ⌄ Open  [Mark mitigated] [E+Q6]│ │ │ 14.2. A Word document has   ││
 └──────────────────────────────────────────┘ │ │ no pages until something    ││
 ┌─ Obligations and dates (9) ──────────────┐ │ │ renders it.            [E]  ││
 │ 1  Minimum volume certification          │ │ └───────────────────────────────┘│
 │    Due 31 Jan 2027 · calendar        [E] │ │                                 │
 │    ⌸ Inspect source  [Track]       [E+Q6]│ │                                 │
 │ 2  Annual price review                   │ │                                 │
 │    Date not established              [E] │ │                                 │
 │ ▸ Show all 9                             │ │                                 │
 └──────────────────────────────────────────┘ │                                 │
 ┌─ Named organizations and people ─────────┐ │                                 │
 │ Northwind Trading Ltd                    │ │                                 │
 │   organization · contractual role        │ │                                 │
 │   unverified                         [E] │ │                                 │
 │ Acme Holdings PLC   organization         │ │                                 │
 └──────────────────────────────────────────┘ │                                 │
 ┌─ Critical clauses and material risks (6) ┐ │                                 │
 ┌─ Contractual findings (8 of 18) ─────────┐ │                                 │
 ┌─ Evidence ledger (18)                  ▾ ┐ │                                 │
 ┌─ Your notes                            ▾ ┐ │                                 │
─────────────────────────────────────────────┴───────────────────────────────────

 ABSENT, not empty: no Figures section, no Comparisons section, no chart
 surface — `available(figures)` and `available(comparisons)` are both false,
 and both rules are `absent: 'omit'`.                                      [N]
```

**Why this is the test case for the recommendation:** under fixed templates the contract template has seven slots and at least `supporting_analysis` and often `contract_findings` arrive empty or regex-derived; under section rules the page is six populated sections and the numeric machinery simply does not exist on it. **Clause labels keep the existing disclaimer** ("Clause and risk labels reflect available findings, not a legal conclusion"). **DOCX truth is preserved:** paragraph paths, never page numbers (`sourceCapabilities`), and `locationUndecided` means the chip says "Inspect source" rather than pre-announcing a failure the docx-window route will disprove.

---

## Scenario 3 — General research or policy document

*Important narrative claims; no meaningful chart data. Classification `research_report` or `policy_document`; profile `balanced`.*

> **Invented document.** Fictional name, findings and page references.

```
─────────────────────────────────────────────────────────────────────────────────
 Regional_Water_Policy_Review_2026.pdf · PDF · 47 pages · ● Ready           [E]
 [Download] [Report] [Present]            Layout: ⌄ Balanced                [N]
─────────────────────────────────────────────────────────────────────────────────
 47 of 47 pages read · 62 evidence records · 24 source-located findings ·
 1 disclosure                                         [What was read ▾]      [N]
─────────────────────────────────────────────────────────────────────────────────
 Assessment · Findings · Parties · Evidence · Limitations                    [E]
─────────────────────────────────────────────────────────────────────────────────
 ┌─ Executive assessment ─────────────────────────────────────────────────────┐
 │ The review finds regional allocation rules inconsistent with the 2024…     │
 │ Inferred synthesis                                                    [B]  │
 │ Synthesis is not independently verified.                              [E]  │
 │ [▸ Read full summary]       ⌸ Show page 4 in document                      │
 └────────────────────────────────────────────────────────────────────────────┘
 ┌─ Important findings (8 of 24) ─────────────────────────────────────────────┐
 │ 1 Allocation rules diverge between the two basin authorities               │
 │   ⌸ Show page 9 in document                                                │
 │ 2 Compliance monitoring has no statutory reporting interval                │
 │   ⌸ Show page 14 in document                                               │
 │ 3 Abstraction licensing backlog                                            │
 │   Unit unverified · Not eligible for analytical chart                 [E]  │
 │   ⌸ Inspect source (no recorded location)                          [E+Q5]  │
 │ ▸ Show all 24 findings                                                     │
 └────────────────────────────────────────────────────────────────────────────┘
 ┌─ Open questions this analysis could not settle ─────────────────── [E+N] ──┐
 │ Does the 2024 framework supersede the basin-level rules?                   │
 │ Reason: both are cited and neither states precedence.                      │
 │ ⌸ Show page 9   ⌸ Show page 31                                             │
 └────────────────────────────────────────────────────────────────────────────┘
 ┌─ Named organizations and people (11)                                     ▾ ┐
 ┌─ Evidence ledger (24)                                                    ▾ ┐
 ┌─ Limitations and unprocessed (1)                                         ▾ ┐
 │ 3 pages were read by OCR; their text may differ from what is printed. [E]  │
 └────────────────────────────────────────────────────────────────────────────┘

 Full width: no source is selected, so the reader column does not exist.
 `SourceContent`'s idle state is the invitation, shown once a finding is
 clicked — not a permanently empty half-screen.                          [E]
```

**The point of this scenario:** a document with no numbers must not look like a broken financial report. Five sections, no figure grid, no chart surface, no comparison card, no "No eligible chart is available" apology — because the comparisons rule's near-miss condition requires ≥2 metric findings sharing a concept, and there are none. The one genuinely new thing here is promoting `intelligenceSummary.questions` out of the collapsed panel (E-07b, F-01b): on a narrative document that is the most valuable section on the page and it is currently three disclosures deep.

---

## Scenario 4 — Partially analysed document

*Some stages succeeded, some failed; only a subset of findings has adequate evidence.*

> **Invented document.** Fictional name, figures, page ranges and failure counts, chosen to put every disclosure state on one screen at once.

```
─────────────────────────────────────────────────────────────────────────────────
 Consolidated_Annual_Pack_2026.pdf · PDF · 318 pages · ⚠ Needs Review       [E]
─────────────────────────────────────────────────────────────────────────────────
 ⚠ Automatic text extraction could not complete on part of this document.
   [Approve & mark ready] [Reject] [Retry processing]        (role-gated) [E]
─────────────────────────────────────────────────────────────────────────────────
 The latest refresh did not publish. The previous published findings remain
 available.                                                                 [E]
─────────────────────────────────────────────────────────────────────────────────
 284 of 318 pages read · 11 pages partial, 23 pages failed ·
 96 evidence records · 31 source-located findings · 4 disclosures
                                                      [What was read ▾]      [N]
─────────────────────────────────────────────────────────────────────────────────
 Assessment · Findings · Figures · Risks · Evidence · LIMITATIONS (4)        [E]
─────────────────────────────────────────────────────────────────────────────────
 ┌─ Executive assessment ─────────────────────────────────────────────────────┐
 │ …renders in full. A failure elsewhere does not withhold it.           [E]  │
 └────────────────────────────────────────────────────────────────────────────┘
 ┌─ Important findings (8 of 31) ─────────────────── every valid finding kept ┐
 ┌─ Relevant figures (14) ───────────────────────────────────────────────────┐
 │ ┌───────────────────┐ ┌───────────────────┐ ┌───────────────────┐          │
 │ │ Net assets        │ │ Headcount         │ │ Grant income      │          │
 │ │ 1,284.0  USD m    │ │ 4,120  employees  │ │ 92.4   USD m      │          │
 │ │ FY2026            │ │ FY2026            │ │ FY2026            │          │
 │ │ ⌸ page 41         │ │ Unit unverified   │ │ Source unverified │          │
 │ │                   │ │ Not eligible for  │ │ Not eligible for  │          │
 │ │                   │ │ analytical chart  │ │ analytical chart  │          │
 │ │                   │ │ ⌸ page 77         │ │ ⌸ Inspect source  │          │
 │ └───────────────────┘ └───────────────────┘ └───────────────────┘     [E]  │
 │ Figures with limitations are shown with them, never withheld and never     │
 │ promoted into a comparison, a chart or Present mode.                  [E]  │
 └────────────────────────────────────────────────────────────────────────────┘
 ┌─ LIMITATIONS AND UNPROCESSED (4)                            expanded  ▴ ───┐
 │ 1 Extraction: 23 pages failed, 11 partial.                                 │
 │   Pages 112–134: text layer could not be read.                             │
 │   Pages 201, 204, 207–214: partial.                               [E]      │
 │ 2 Analysis: the chart stage did not complete for this revision.            │
 │   9 chart candidates were withheld.                               [E]      │
 │ 3 Measurements: 7 figures carry verification limits (listed above). [E]    │
 │ 4 Coverage: coverage was bounded by measured omissions; completeness       │
 │   was not established.                                      [B — V2 only]  │
 │ ─────────────────────────────────────────────────────────────────────────  │
 │ What this means: findings from pages 112–134 are absent, not cleared.      │
 │ This analysis does not establish that those pages contain nothing.  [N]    │
 └────────────────────────────────────────────────────────────────────────────┘

 ABSENT: Comparisons, charts. The chart stage failed, so there is nothing
 to degrade and nothing to apologise for in a section of its own — the
 failure is stated once, in LIMITATIONS.                                 [N]
```

**Rules this scenario pins down.** (1) Section availability never reads a document-level failure state; a failed stage removes only what that stage produced. (2) Findings with limitations are rendered **with** them — the existing `findingLimitations()` strings, unchanged. (3) The LIMITATIONS section is the only section whose rail entry shows a count and whose default state flips to expanded when `pageFlags` contain failures. (4) The closing sentence — *absent, not cleared* — is the one inference this product must actively block, and it is the same discipline as `RiskList`'s existing sentence. (5) The existing approve/reject/retry banner and the "did not publish" line stay exactly where they are.

---

## Scenario 5 — Meeting presentation

*Present mode. The reader presents findings and opens evidence under questioning, without navigating a workspace.*

```
═════════════════════════════════════════════════════════════════════════════════
 Northstar Q3 2026 Board Performance Review              Analysis of 21 Oct 2026
 Revision 9f3c1a…  (pinned for this presentation)                [Exit present]
═════════════════════════════════════════════════════════════════════════════════

   7 of 7 pages read · 7 source-located findings · 2 disclosures
   (counts illustrative)

   ASSESSMENT
   Northstar ended Q3 with the highest quarterly revenue of 2026 to date,
   reaching KES 51.0m, up from KES 46.0m in Q2. Operating profit held at
   KES 10.0m because operating costs increased from KES 36.0m to KES 41.0m.
   Synthesis is not independently verified.

   REQUIRES ATTENTION
   ⚠  HIGH   R-01 Delivery reliability deterioration        → page 5
             Q3 on-time rate 87%; 95% target

   FIGURES
     ┌──────────────┬──────────────┬──────────────┬──────────────┐
     │ Revenue      │ Op costs     │ Op margin    │ On-time del. │
     │  51.0        │  41.0        │  19.6%       │  87%         │
     │  KES million │  KES million │              │  target 95%  │
     │  Q3 2026     │  Q3 2026     │  Q3 2026     │  Q3 2026     │
     └──────────────┴──────────────┴──────────────┴──────────────┘

   COMPARISON
     On-time delivery is 8 percentage points BELOW the Q3 target.
     Actual 87% · Target 95%                                → page 4

   REVENUE BY QUARTER      Generated from verified measurements
       51 ┤            ╭──●
       46 ┤      ╭──●
       42 ┤ ●────╯
          └─Q1───Q2───Q3──   KES million

   OBLIGATIONS
     D-01 Board decision on proposed KES 2.4m
          recovery funding                  Due 23 Oct 2026    → page 6

   ───────────────────────────────────────────────────────────────────────
   Click any figure or finding to open its evidence.   [✎ Note]
═════════════════════════════════════════════════════════════════════════════════

 Under questioning — "where does 87% come from?" — the figure is clicked:

╔═══════════════════════════════════════════════════════════════════════════════╗
║ ← Back            On-time delivery, Q3 2026                   ● Supported     ║
║ ─────────────────────────────────────────────────────────────────────────────  ║
║  page 4 of 7                                                      [Download▾] ║
║  ┌─────────────────────────────────────────────────────────────────────────┐  ║
║  │  3. Operations and customer experience                                  │  ║
║  │  Operational indicator      Q1      Q2      Q3     Q3 target            │  ║
║  │  Shipments completed      12,500  13,700  14,900     14,500             │  ║
║  │  On-time deliveries         92%     90%    ▮87%        95%              │  ║
║  │  Customer complaints         84      96     133        ≤ 90             │  ║
║  └─────────────────────────────────────────────────────────────────────────┘  ║
║  Approximate match — the mark comes from a text search of this page.    [E]   ║
║  ┌─ Note (cites this finding, revision 9f3c1a…) ───────────────── [Save] ─┐  ║
║  │ Ops to confirm the Q4 recovery plan before the December board.         │  ║
║  └───────────────────────────────────────────────────────────────────────┘  ║
╚═══════════════════════════════════════════════════════════════════════════════╝
      Escape returns to the presentation at the same point.                [E]
```

**Possible with current functionality:** everything above except the two items below. The full-screen evidence sheet is `SourceFullScreenSheet` [E]; the highlight and its honest wording are `PdfViewer` + `sourceNotes.highlightNote()` [E]; revision pinning is `published_intelligence_revision_id` + `useSourceResolution`'s existing discipline [E]; the note with its citation and revision stamp is `POST /documents/{id}/notes` [E, flag-gated on `DOCINTEL_NOTES_ENABLED`].

**Needs new frontend work only:** the display mode itself (hiding chrome, projection type scale, the pin indicator) — N4.

**Needs backend work and is therefore not in this layout:** recording a decision or an action item (F-21), and anything a second person sees (F-29). "Mark for follow-up" appears only for a risk or a dated obligation, because those are the two things the API can actually record (F-22).

**Rules Present mode must obey.** No item with non-empty `findingLimitations()` appears. No legacy-origin chart appears. No section heading claims verification its items do not carry. The revision is displayed, not merely pinned — if the analysis is republished mid-meeting the page does **not** change under the presenter; the existing stale-source discipline is extended to the whole page. The coverage line is the one thing that is never hidden.

---

## A note on the numbers in Part H

**Scenarios 1 and 5** use the real fixture
`CA_BACKEND/tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf`:
7 pages, 81 KB, KES million, revenue 42.0 / 46.0 / 51.0 across Q1–Q3,
operating costs 33.0 / 36.0 / 41.0, operating profit 9.0 / 10.0 / 10.0,
operating margin 21.4% / 21.7% / 19.6%, on-time delivery 92% / 90% / 87%
against a 95% target, complaints 84 / 96 / 133 against a ceiling of 90,
shipments 12,500 / 13,700 / 14,900 against 14,500, employees 112 / 118 / 123,
R-01…R-04 in the risk register, a proposed KES 2.40m recovery budget
(1.20 + 0.75 + 0.45) and items D-01 (due 23 Oct 2026) through M-01 (20 Nov
2026). Page references are the document's own printed pages.

**Scenarios 2, 3 and 4** are invented documents — a supply agreement, a water
policy review and a consolidated annual pack — chosen to exercise the
availability rules at shapes the fixture does not have. Their names, figures
and dates are fictional and labelled as such here.

**The coverage-line counts in every scenario are illustrative.** Evidence
record counts, located-finding counts and disclosure counts are properties of
a processing run, not of a file, and this audit ran no paid inference.

**None of these numbers is a test expectation.** No test in either repository
asserts a value from this document, and none should: a layout specification is
not a fixture. A test that needs Northstar values must read them from the
fixture or from a builder in `tests/Concerns/BuildsIntelligenceFixtures.php`,
so that a change to the document fails the test rather than silently
invalidating a figure copied out of a report. The regression tests added
alongside this correction (`CA/tests/chart-origin-truthfulness.test.tsx`,
`CA/tests/workspace-no-original-page-jump.test.tsx`) build their own fixtures
and assert no Northstar figure.

---

# Final answers

**1. What should DocIntel look and behave like when a user opens an analysed document?**

One sentence of coverage, then the executive assessment, then attention, then the sections this document's evidence actually supports, in materiality order, at one altitude, in a single column beside a source reader that is a permanent peer rather than a detour. No template picker, no tab bar, no collapsed disclosure holding half the product. A section exists because supported evidence for it exists; it states what is unavailable rather than implying the document lacks it; and a figure with verification limits is shown with them rather than withheld or promoted.

**2. Templates, adaptive report, evidence-first workspace, or a combination?**

A combination, led by the adaptive report (weighted score 77/85 against 65 for a pure adaptive report, 59 for the evidence-first workspace and 40 for fixed templates). Concretely: one ordered section set whose membership is decided field by field — which `widgetAvailable()` already does correctly, 14 times — with the source reader as a permanent peer column, and templates demoted from the primary interface to two or three **ordering profiles** that reorder and relabel the one section set and can never add, remove or empty a section. Keep the section components; delete the slot registry, the classification-driven template recommendation, the three-card switcher as page-lead, and the four dead structures.

**3. How should document types change the presentation without separate AI analysis?**

They change the **order and the wording**, nothing else. `documentType.documentType` (twelve values, with a confidence) selects an ordering profile; `hasPayrollContent()` guards it. Everything else is decided by evidence availability from one `presentationModel()` built from the single `/intelligence` response — one memoised object per `(documentId, revisionId)`, which `dashboardData()` already is. No view asks the AI anything, so no view needs its own response. The strongest available form of this is already computed and served: `BriefAssembler` emits ordered, tiered, individually verified blocks with typed values and explicit highlight modes, and the frontend does not read them (E-13).

**4. How do we present intelligence professionally without pretending unsupported information is verified?**

By keeping the three rules this codebase already mostly follows, and fixing the two places it does not. The rules: a claim may only be rendered from a field the response actually carries (`evidenceLabels.ts`, `sourceCapabilities.ts`); an absence is reported as an absence of evidence and never as a fact about the document (`RiskList`'s sentence, `WorkspaceCoverage`'s three non-goals); and anything that cannot be verified degrades visibly rather than disappearing (`toChartRec`/`sourceChartRec` → labelled data table with a reason). The two breaches to fix: "Verified financial visualization" over an AI transcription of a printed chart (E-02), and a "Show page N" offer for a document whose original is not stored (E-10). And add the one thing missing at document level: a single coverage sentence, always present, stating a coverage *state* only when the backend supplies one (N2).

**5. What should Codex build next, and what should we deliberately avoid?**

**Next, in order:** repair the 20 failing tests (Q1 — five of them assert what the UI tells a reader about evidence location); record the Workspace flag decision in tracked configuration (Q2 — today a deployed build ships the older experience); stop the two overstatements (Q3, Q4); then reveal what is already built — one altitude, no duplication, review controls where the findings are read (Q5–Q9). Only then the new surfaces: `presentationModel()` plus section rules (N1), the coverage line (N2), ordering profiles (N3), notes beside the source (N5), Present mode (N4). In parallel, as configuration and validation rather than code: decide `DOCINTEL_INTELLIGENCE_V2`, then declare and read `brief` for ordering and attention (B1, B2). The checkpoint at the end of week 2 — the existing product, same data, same flags, nothing hidden, nothing duplicated, nothing overstated — is shippable on its own.

**Deliberately avoid:** a decision/action-item entity; a fourth template; slide export; per-section AI regeneration; confidence scores, coverage percentages or verification badges on verified items; and a chart-first layout for financial documents, because zero verified charts is the common case on a real report and the page has to be right there first.

---

## Appendix A — What was inspected

**Frontend (`CA`, HEAD `7b7fbb5`):** `src/pages/DocumentDetailPage.tsx`, `src/types.ts`, `src/workspaceFlag.ts`, `src/evidenceLabels.ts`, `src/components/intelligenceSources.tsx`, `src/components/measurementPresentation.ts`, `src/components/chartLayout.ts`, `src/components/DocumentIntelligencePanel.tsx` (structure and section order), and `src/components/workspace/`: `WorkspaceFrame`, `DocumentWorkspace`, `DashboardTemplates`, `dashboardRegistry`, `analyticalModel`, `WorkspaceSummaryBand`, `WorkspaceCoverage`, `WorkspaceNotesPane`, `WorkspaceSourcePane`, `SourceContent`, `SourceJumpControl`, `EvidenceBadge`, `useSourceResolution`, `useWorkspaceSections`, `selection`, `sourceCapabilities`, `sourceNotes`, `UnifiedCharts`, `adapters/{registry,manifest}`. Configuration: `.env`, `.env.production`, `.gitignore`, `netlify.toml`, `package.json`, `tests/` (86 files).

**Backend (`CA_BACKEND`, HEAD `8c8991b`):** `routes/api.php`, `app/Http/Resources/DocumentIntelligenceResource.php`, `app/Http/Controllers/Api/{DocumentRiskReviewController,ReportSnapshotController}.php`, `app/Services/Intelligence/DocumentAnalysisComposer.php`, `app/Services/Intelligence/Brief/BriefAssembler.php`, `app/Services/Intelligence/B2/BriefReadService.php`, `config/intelligence_v2.php`, `config/document_intelligence.php`, `database/seeders/DocumentTypePromptSeeder{,V2}.php`, `.env.example`.

**Executed:** `npx vitest run` once (917 tests, offline). No build, no deploy, no model call, no database write, no production data.

## Appendix B — Constraints observed

No UI component implemented. No backend logic or prompt contract changed. No existing application source file modified. No dependency introduced. Nothing deployed or pushed. No paid model call. No production data touched. The V9 audit was read and is not modified. Every feature flag was left exactly as found. The only file created is this report.

## Appendix C — One operational note, outside the scope of this audit

`CA_BACKEND/.env` contains a live-looking Anthropic API key under `DOCINTEL_V9_EVAL_ANTHROPIC_KEY`. The file is correctly gitignored (`.gitignore:3`), so the key is not in version control, and I have not used, copied or transmitted it. Flagging it only because a key sitting in a plaintext working-tree file is worth rotating once the V9 evaluation work is finished.
