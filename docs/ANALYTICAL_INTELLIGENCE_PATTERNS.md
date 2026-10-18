# Analytical intelligence patterns for DocIntel

Reviewed before extending the analytical pipeline. These are design references, not dependency choices.

## External patterns and fit

| Source | Useful pattern | DocIntel application |
| --- | --- | --- |
| [Docling document](https://docling-project.github.io/docling/concepts/docling_document/) and [provenance reference](https://docling-project.github.io/docling/reference/docling_document/) | Keep document elements, hierarchy, table cells, page, character span and layout provenance together. | Preserve source value, heading/legend unit, row/column identity and the exact extraction revision as separate field-role anchors. A matching number without its row/heading relationship is insufficient. |
| [Docling Graph provenance](https://docling-project.github.io/docling-graph/fundamentals/graph-management/provenance/) | Keep a deterministic source ledger with explicit resolution strength and unresolved coverage, separate from model assertions. | Use current `document_evidence`, source anchors and revision checks. Report whether an observation is located, field-verified or unresolved. Do not turn a document-level or entity match into KPI proof. |
| [Cube modeling](https://docs.cube.dev/reference/data-modeling/cube) and [Lightdash metrics](https://docs.lightdash.com/get-started/setup-lightdash/how-to-create-metrics) | A metric has a stable definition, dimensions, relationships and calculation rules shared by consumers. | Use a common observation contract for concept, subject, period, aggregation, basis, quantity and unit. Let document-specific concepts be discovered at extraction time; the verification and arithmetic rules stay fixed. Apply workspace isolation to every relation. |
| [Data Formulator concept binding](https://www.microsoft.com/en-us/research/publication/data-formulator-ai-powered-concept-driven-visualization-authoring/) | Separate proposed concepts and transformations from the resulting visualization; keep intermediate data inspectable. | Claude may propose a semantic identity, relationship and dashboard purpose. The Laravel analytical layer validates the source and computes any result. React displays the accepted answer and its supporting inputs. No user axis selection is required. |
| [Draco](https://github.com/uwdata/draco) and [Draco 2](https://dig.cmu.edu/draco2/intro.html) | Hard constraints reject invalid displays; soft preferences rank useful valid displays. | Source, unit, scale, period, subject and quantity compatibility are hard gates. Materiality, chart readability and template suitability rank only eligible answers. A table can be a diagnostic fallback, but must retain limitations. No solver adoption is needed. |

## Existing DocIntel components to reuse

| Capability | Current component | Boundary to preserve |
| --- | --- | --- |
| PDF source spans and exact offsets | `EvidenceGrounding`, `NormalRouteProvenance`, `SourceReferenceResolver` | A span locates text; field roles establish that its value, unit, subject and period support a measurement. |
| XLSX cells and structural relationships | `SpreadsheetTextExtractor`, `TextRoleProvenanceProducer` | Raw/display/formula-cached values and worksheet coordinates should stay linked to headers and rows; never infer a unit from geography or neighboring magnitudes. |
| Typed evidence and revision-scoped persistence | `EvidenceSchema`, `EvidenceMerger`, `DocumentEvidence`, `DocumentRevisionPublisher` | Keep raw extracted records and validation rejections. Scope by workspace, document, pipeline and published revision. |
| Semantic KPI identity | `KpiIdentityResolver`, `KpiIdentityProfile`, `DocumentKpi.identity_metadata` | Concepts are dynamic. Canonical field meanings must match across normal and incremental routes; targets and actuals remain distinct. |
| Deterministic numeric interpretation | `MeasurementParser`, `PeriodParser`, `MetricCollector` | Preserve written value/unit separately from base magnitude and display scale. Parsing validity is independent of source and unit validity. |
| Candidate generation and source-chart safeguards | `ChartCandidateBuilder`, `SourceChartContract`, `ChartResource`, frontend `chartLayout` | Keep strict chart origin, exact-value, compatible-dimension and period checks. A verified analytical answer need not be a chart. |
| Dashboard and export presentation | `DocumentAnalysisComposer`, `DashboardTemplates`, `dashboardRegistry`, `Chart`, `UnifiedCharts`, `reportBuilder` | Every consumer must receive the same measurement state and raw representation. Template ranking must use eligible answers and purpose, not a company/document name. |

## Common contract and decision order

1. Extract an observation with raw representation and source context. Claude may propose dynamic concept, subject, basis and relationships; no proposal is verified by its confidence score.
2. Verify field-role anchors against the original extraction and published revision. Use table/header/cell structure where available. Retain unresolved or ambiguous fields explicitly.
3. Parse numeric value, unit and scale once. Mark actual, target, proposed, approved, ratio, index and aggregation independently. Never treat account numbers or identifiers as measures.
4. Form relationships only between compatible subject, period, quantity, basis and units, or an explicitly supported accounting identity. Compute arithmetic deterministically.
5. Apply hard chart gates before ranking valid charts, cards and tables by decision usefulness. Unknown units remain diagnostic, including in export.
6. Select an automatic dashboard from analytical purpose and eligible observations, risks, actions and decisions. Domain profiles can suggest terms and ordering, but cannot bypass the common gates.

This is a mapping to the existing Laravel and React path, not a proposal to install Docling, Cube, Lightdash, Data Formulator or Draco.
# Phase 2 implementation note

`document_insights` v8 is seeded inactive. The active prompt remains a database decision; deploying code or running a repository seeder does not activate v8. When v8 is active, normal processing renders stable evidence spans and accepts `source_context` IDs on KPIs. `NormalKpiGrounder` publishes a `metric` evidence row only after checking the value and concept in the cited row or sentence, exact field text, and a governing table heading relationship. For XLSX, a native workbook table plus its exact row and column header cells is required; arbitrary adjacent cells are insufficient. Legacy KPIs remain visible as limited observations. Source navigation uses the value occurrence and exposes separately cited field context.

Incremental extraction still emits its established route-specific `metric_type` and `value_basis` meanings. `CanonicalMetricIdentity` maps those meanings at merge and read time; raw checkpoint output and historical evidence remain unchanged. This avoids a silent prompt contract change for in-flight chunks. The next prompt version for incremental extraction can emit canonical fields directly after fixture evaluation.
