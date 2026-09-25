# Canonical KPI identity

KPI observations previously had no identity beyond free-text `document_kpis.label`. Workspace trends grouped `TRIM(LOWER(label))`; structured document comparisons grouped lowercased, whitespace-collapsed labels. Independent extraction wording therefore split histories. Matter intelligence and Power BI return individual observations rather than performing label-based aggregation.

## Schema and ownership

The two September 25 migrations are additive. They do not classify history or rewrite labels.

| Change | Purpose |
| --- | --- |
| `kpi_definitions` | UUID, workspace, representative canonical name, normalized name, concept, scope, metric type, unit, matching metadata, identity hash, timestamps |
| `kpi_aliases` | Original variant, normalized variant, definition, workspace, context hash, matching metadata, learning method, timestamps |
| `document_kpis.kpi_definition_id` | Nullable canonical identity; composite FK verifies workspace ownership |
| `document_kpis.identity_metadata`, `period` | Optional extraction metadata and observation period, separate from source label |
| `document_ai_runs` purpose `kpi_identity` | Records model, prompt version and input/output tokens for optional adjudication |

Definitions are unique by workspace plus identity hash, **not by name**. Aliases are unique by workspace, normalized label and measurement-context hash. Identical words can therefore represent distinct counts/rates, scopes, units or measurement bases. Composite foreign keys enforce workspace agreement; model validation also checks that linked observations belong to their document's workspace. Deleting a definition clears the observation's identity without deleting its original label. Existing workspace deletion semantics are preserved.

## Resolution and cost

`KpiIdentityResolver` owns resolution and alias learning. It runs before the existing insights persistence transaction so optional network requests do not hold document/credit locks. Each final definition/alias write uses a short transaction and a workspace advisory lock, with a fresh lookup after obtaining the lock. Concurrent deterministic extractions converge on the same definition.

1. Build a conservative profile from the exact label, optional extraction metadata and unit. Case, punctuation, whitespace and dash variants normalize for lookup. Measurement symbols remain meaningful.
2. Reuse a workspace alias only when its measurement context agrees. Check canonical normalized names next.
3. Compare structured identity hashes: concept, scope, type, unit, absolute/change, actual/target, aggregation and unexplained qualifiers. Equal AI concept text alone cannot remove differing country/product/other modifiers.
4. Narrow deterministic rules recognize the observed internal/external service-charter performance, closed-outside-timelines and pending-ticket wording. Explicit quarter/fiscal-year suffixes are separated only when charter rules or structured metadata support identity. An arbitrary year is not stripped from a generic legacy label.
5. If explicitly enabled, retrieve a small set of lexical candidates and ask Claude whether one is the **same**, **related but different**, or **unrelated** metric. Lexical overlap is never a match decision. Only a returned, workspace-scoped candidate with `same`, confidence at least 0.98 and compatible protected dimensions can be accepted.
6. Otherwise create a separate definition for a valid new observation. Contradictory metadata or multiple competing exact definitions leave the observation unlinked. Do not guess.

Internal/external, absolute/change, actual/target, count/rate/percentage, incompatible units, duration/percentage and total/average are protected dimensions. Source wording and concept contradictions invalidate metadata rather than letting it override the label. A percentage labelled a “rate” is allowed; it is not treated as a count/rate conflict. All pending tickets are not equated to overdue pending tickets.

Every accepted variant becomes an alias. Later occurrences with the same context take the alias path without another model request. Even a separately created definition records its own alias, so repeatedly seeing a related-but-different label does not repeatedly invoke adjudication.

`KPI_SEMANTIC_MATCHING_ENABLED=false` is the default. Production charter examples and normalized/structured matches need **zero additional AI calls**. When enabled, the budget is one optional request per extraction, at most three candidates, 400 output tokens, an eight-second timeout and no retries. Existing successful audits suppress further requests for the same document/content hash; an atomic shared-cache reservation also protects concurrent attempts and timeout retries for 24 hours. Use the application's shared cache across workers for that reservation. Provider errors are nonfatal to the observation.

Voyage was inspected: its stored vectors represent whole-document chunks and have no KPI-definition vectors or index. Reusing those vectors as KPI identities would be unsound. This change does not add a new embedding pipeline or embed every new KPI. Candidate retrieval here is bounded lexical retrieval for optional adjudication.

## Extraction and consumers

`DocumentInsightsPromptSeederV7` extends the existing v6 response with an optional `identity` object: concept, scope, metric_type, quantity_kind, aggregation, value_basis and period. Unit remains in its existing top-level KPI field. This uses the existing document insights request. Old response shapes remain accepted. The new version is seeded inactive; rerunning this seeder does not deactivate an already activated v7 or rewrite an existing v7 template.

`GenerateInsightsJob` preserves `label` byte-for-byte while persisting the new nullable ID and metadata. Definitions can survive a later document persistence failure; they represent learned identities, not proof of a completed document. No automatic cleanup/reclassification is performed.

Workspace trends group by canonical ID where available. Unlinked rows retain their existing lower/trim label grouping in a separate namespace and are not absorbed into a canonical series by label alone. `reportsIncluded` still counts distinct documents, not KPI rows. Trend points include their original labels and periods; the display label remains the latest visible observation's wording.

Structured comparisons use canonical IDs with their existing whitespace-normalized fallback for unlinked observations. Before/after source labels remain intact. Matter intelligence continues returning individual source observations and naturally exposes `kpi_definition_id` and metadata; it does not collapse findings. Document KPI resources expose `kpiDefinitionId` and `period` additively. Existing comparison snapshots remain historical snapshots; newly requested comparisons fingerprint the new identity-aware snapshot.

The Power BI view and frontend reports were inspected and do not perform server-side KPI identity grouping. Their existing observation projections are unchanged; external BI models are not rewritten by this change.

## Historical data and deployment

Deploy migrations before serving code that reads the new columns. Run in the intended environment, using the normal deployment process:

```sh
php artisan migrate --force
php artisan db:seed --class=DocumentInsightsPromptSeederV7 --force
php artisan tinker --execute="App\Models\AiPrompt::activate('document_insights', 7);"
php artisan config:cache
php artisan horizon:terminate
```

The prompt activation is an explicit deployment step; migrating alone does not activate it. Keep semantic matching disabled initially unless optional adjudication is wanted. No full `DatabaseSeeder` run is required. Restart/reload other long-running application processes using the existing deployment process as appropriate.

Historical backfill is a separate, workspace-scoped command. Default mode is read-only preview; neither mode invokes AI. Apply links existing guarded aliases/definitions, narrow charter rules and normalized exact labels repeated in distinct documents with compatible contexts. Uncertain singletons stay null. Only nondeleted Ready documents are eligible.

```sh
php artisan kpis:backfill-identities --workspace=WORKSPACE_UUID
php artisan kpis:backfill-identities --workspace=WORKSPACE_UUID --apply
```

The command defaults to 500 observations, caps `--limit` at 5000, and prints an `--after-id` cursor for the next batch. This lets processing advance past unresolved rows. Repeated-label evidence is local to the inspected batch; expanding the limit or revisiting an earlier batch after aliases are learned can link additional observations. Preview does not simulate new learned aliases, so an apply run can additionally link later equivalent rows. Apply is idempotent and updates only the nullable identity/period fields, never labels.

No production migration, prompt activation, backfill or AI request was run while implementing this change.

## Deliberately conservative boundaries

- Missing scope/type/unit is not a wildcard for a populated dimension. Incomplete historical metadata can leave otherwise plausible matches separate.
- There is no universal synonym ontology, unit conversion, embedding index, manual merge UI or automatic merging of already established definitions.
- Canonical names are representative labels, not unique keys. They may retain the first observation's period wording; period is excluded from identity only under the rules above.
- Unsupported reporting-period formats, differing unexplained qualifiers, low-confidence or oversized candidate sets remain separate. Optional semantic adjudication is a model judgment, not proof of equivalence.
- A shared cache is required to coordinate the optional AI-attempt reservation across workers. Successful provider audits also persist the per-document/hash budget.

## Changed files and verification

| Area | Files |
| --- | --- |
| Schema/models | `database/migrations/2026_09_25_000001_add_canonical_kpi_identity.php`, `2026_09_25_000002_allow_kpi_identity_ai_runs.php`; `app/Models/{KpiDefinition,KpiAlias,DocumentKpi}.php` |
| Resolver | `app/Services/Kpis/{KpiLabelNormalizer,KpiIdentityProfile,KpiIdentityResolver}.php`; `config/kpi_identity.php`; `.env.example` |
| Extraction/AI | `app/Jobs/GenerateInsightsJob.php`; `app/Services/AnthropicClient.php`; `app/Services/AI/ResponseValidator.php`; `database/seeders/{DocumentInsightsPromptSeederV7,DatabaseSeeder}.php` |
| Consumers/backfill | `app/Http/Controllers/Api/WorkspaceInsightsController.php`; `app/Http/Resources/KpiResource.php`; `app/Services/DocumentComparisonService.php`; `app/Console/Commands/BackfillKpiIdentities.php` |
| Tests | `tests/Unit/KpiIdentityProfileTest.php`; `tests/Feature/{KpiIdentityTest,KpiIdentityConcurrencyTest,WorkspaceInsightsTrendsTest,MatterIntelligenceTest}.php` |

Tests cover production variants, normalized labels, aliases, period handling, explicit conflicts, incomplete metadata, workspace FK isolation, alias cost reuse, provider failure/budgets, original-label preservation in extraction, conservative/idempotent backfill, canonical trends/coverage, comparison fallbacks, Matter source rows, concurrent resolution, definition/workspace deletion and additive migration over populated history.

Final verification on 25 September 2026:

| Run | Result |
| --- | --- |
| Focused (`KpiIdentity`, `WorkspaceInsightsTrends`, `GenerateInsightsJob`, `MatterIntelligence`) | 60 passed; 281 assertions; zero failures/errors/skips |
| Full backend suite | 402 tests: 396 passed, 6 skipped; 2,333 assertions; zero failures/errors |
| Formatting / whitespace | Pint passed for new PHP files; `git diff --check` passed |

The six skips are existing PDF-rasterizer tests requiring the missing five-page PDF fixture (five tests) and the ClamAV CLI test requiring `clamscan` (one test). All runs used local `ca_document_intelligence_test`, dedicated local Redis, array cache/session/mail, and empty live AI credentials. JUnit evidence was written to `/tmp/docintel-kpi-focused.xml` and `/tmp/docintel-kpi-full.xml`.
