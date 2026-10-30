<?php

namespace App\Services\AI;

class ResponseValidator
{
    private const SUMMARY_TEXT_LIMIT = 350;

    private const SUMMARY_WORD_BOUNDARY_LOOKBACK = 80;

    private const SUMMARY_PROSE_FIELDS = ['text', 'title', 'explanation', 'why_it_matters', 'observation', 'significance', 'question', 'reason'];

    public function validateInsights(array $decoded, bool $observationContract = false): array
    {
        foreach ($observationContract ? ['kpis', 'insights'] : ['kpis', 'charts', 'insights'] as $field) {
            if (! isset($decoded[$field]) || ! is_array($decoded[$field]) || ! array_is_list($decoded[$field])) {
                throw new \RuntimeException("AI response missing required array field: {$field}");
            }
        }
        foreach ($decoded['insights'] as $insight) {
            if (! is_string($insight) || trim($insight) === '') {
                throw new \RuntimeException('AI response insight must be a non-empty string.');
            }
        }
        if ($observationContract) {
            if (array_key_exists('charts', $decoded) && $decoded['charts'] !== []) {
                throw new \RuntimeException('V10 response must not contain model-authored charts.');
            }
            $decoded['charts'] = [];
        }

        return $decoded;
    }

    /** A missing role or identity field in V10 is a malformed observation, not a partial success. */
    public function validateV10Observations(array $kpis): void
    {
        $identityFields = ['concept', 'scope', 'metric_type', 'quantity_kind', 'aggregation',
            'value_basis', 'period'];
        $citationFields = ['value_span_id', 'unit_span_id', 'period_span_id', 'subject_span_id'];
        foreach ($kpis as $index => $kpi) {
            if (! is_array($kpi) || array_is_list($kpi)
                || ! is_string($kpi['label'] ?? null) || trim($kpi['label']) === ''
                || ! is_string($kpi['value'] ?? null) || trim($kpi['value']) === ''
                || ! array_key_exists('unit', $kpi)
                || ($kpi['unit'] !== null && ! is_string($kpi['unit']))
                || ! array_key_exists('trend', $kpi) || ! array_key_exists('trendValue', $kpi)
                || ($kpi['trend'] ?? null) !== null || ($kpi['trendValue'] ?? null) !== null
                || ! is_array($kpi['identity'] ?? null)
                || ! is_array($kpi['source_context'] ?? null)) {
                throw new \RuntimeException("Invalid V10 observation at index {$index}.");
            }
            foreach ($identityFields as $field) {
                if (! array_key_exists($field, $kpi['identity'])) {
                    throw new \RuntimeException("Missing V10 identity role {$field} at index {$index}.");
                }
            }
            foreach ($citationFields as $field) {
                if (! array_key_exists($field, $kpi['source_context'])) {
                    throw new \RuntimeException("Missing V10 source role {$field} at index {$index}.");
                }
            }
        }
        $this->validateKpiIdentities($kpis);
    }

    /** Optional metadata: old prompt versions and existing responses remain valid. */
    public function validateKpiIdentities(array $kpis): void
    {
        foreach ($kpis as $kpi) {
            if (isset($kpi['source_context'])) {
                if (! is_array($kpi['source_context'])) {
                    throw new \RuntimeException('Invalid KPI source context.');
                }
                foreach ($kpi['source_context'] as $field => $value) {
                    if (! in_array($field, ['value_span_id', 'unit_span_id', 'period_span_id', 'subject_span_id'], true)
                        || ($value !== null && (! is_string($value) || ! preg_match('/^E[0-9]{3,}$/D', $value)))) {
                        throw new \RuntimeException('Invalid KPI source span.');
                    }
                }
            }
            if (! isset($kpi['identity'])) {
                continue;
            }
            if (! is_array($kpi['identity'])) {
                throw new \RuntimeException('Invalid KPI identity metadata.');
            }
            foreach ($kpi['identity'] as $field => $value) {
                if (! in_array($field, ['concept', 'scope', 'metric_type', 'quantity_kind', 'aggregation', 'value_basis', 'period'], true)
                    || ($value !== null && (! is_string($value) || mb_strlen($value) > 255))) {
                    throw new \RuntimeException('Invalid KPI identity metadata field.');
                }
            }
        }
    }

    public function validate(array $decoded, array $schema): array
    {
        foreach ($schema as $field => $expectedType) {
            if (! array_key_exists($field, $decoded)) {
                continue;
            }

            $actualType = gettype($decoded[$field]);
            $valid = match ($expectedType) {
                'array' => $actualType === 'array',
                'string' => $actualType === 'string',
                'numeric' => is_numeric($decoded[$field]),
                default => true,
            };

            if (! $valid) {
                throw new \RuntimeException(
                    "AI response field '{$field}' expected type '{$expectedType}', got '{$actualType}'"
                );
            }
        }

        return $decoded;
    }

    public function validateComparison(array $decoded, array $context): array
    {
        if (! isset($decoded['changes']) || ! is_array($decoded['changes']) || ! array_is_list($decoded['changes']) || count($decoded['changes']) > 20) {
            throw new \RuntimeException('Invalid comparison changes.');
        }
        $changes = [];
        foreach ($decoded['changes'] as $change) {
            foreach (['label', 'description', 'category'] as $field) {
                if (! is_string($change[$field] ?? null) || trim($change[$field]) === '' || mb_strlen($change[$field]) > 3000) {
                    throw new \RuntimeException('Invalid comparison field.');
                }
            }
            if (! in_array($change['category'], ['terms', 'requirements', 'amounts', 'obligations', 'dates', 'potential_conflict'], true)) {
                throw new \RuntimeException('Invalid comparison category.');
            }
            $normalized = ['label' => $change['label'], 'category' => $change['category'], 'description' => $change['description'], 'method' => 'ai',
                'outcome' => 'potential_conflict', 'reasons' => ['ai_suggestion_requires_review']];
            foreach (['before' => 'base', 'after' => 'compared'] as $side => $key) {
                if (! array_key_exists($side, $change)) {
                    throw new \RuntimeException('Missing comparison side.');
                }
                $value = $change[$side];
                $normalized[$side] = [];
                if ($value === null) {
                    continue;
                }
                if (! is_array($value) || ! is_string($value['value'] ?? null) || trim($value['value']) === '' || mb_strlen($value['value']) > 3000
                    || ! is_string($value['quote'] ?? null) || trim($value['quote']) === '' || ! is_string($value['chunk_id'] ?? null)) {
                    throw new \RuntimeException('Invalid comparison source.');
                }
                $chunk = collect($context[$key]['excerpts'])->firstWhere('chunk_id', $value['chunk_id']);
                if (! $chunk || ! str_contains($chunk['text'], $value['quote'])) {
                    throw new \RuntimeException('Unsupported comparison citation rejected.');
                }
                $normalized[$side][] = ['value' => ['state' => $value['value']], 'source' => [
                    'document_id' => $context[$key]['document_id'], 'document_name' => $context[$key]['document_name'],
                    'chunk_reference' => $value['chunk_id'], 'evidence' => $value['quote'],
                    'intelligence_revision_id' => $context[$key]['intelligence_revision_id'] ?? null,
                    'location_kind' => 'unknown',
                ]];
            }
            if (! $normalized['before'] && ! $normalized['after']) {
                throw new \RuntimeException('Comparison change has no evidence.');
            }
            if (! $normalized['before']) {
                $normalized['outcome'] = 'only_b';
                $normalized['description'] = 'Newly observed in selected excerpts; verify in both originals.';
            } elseif (! $normalized['after']) {
                $normalized['outcome'] = 'only_a';
                $normalized['description'] = 'Not observed in compared excerpts; removal is not confirmed.';
            } else {
                $normalized['description'] = 'Potential change: '.$normalized['description'];
            }
            $changes[] = $normalized;
        }

        return ['changes' => $changes];
    }

    public function validateDocumentType(array $decoded): array
    {
        $allowedTypes = [
            'compliance_report', 'financial_report', 'regulatory_filing', 'policy_document',
            'contract', 'correspondence', 'technical_report', 'meeting_minutes',
            'research_report', 'application_form', 'invoice', 'other',
        ];

        if (! array_key_exists('document_type', $decoded) || ! is_string($decoded['document_type'])) {
            throw new \RuntimeException('AI response missing required string field: document_type');
        }

        if (! in_array($decoded['document_type'], $allowedTypes, true)) {
            throw new \RuntimeException(
                "AI response document_type '{$decoded['document_type']}' is not in the controlled vocabulary."
            );
        }

        if (! array_key_exists('confidence', $decoded) || ! is_numeric($decoded['confidence'])) {
            throw new \RuntimeException('AI response missing required numeric field: confidence');
        }

        $confidence = (float) $decoded['confidence'];
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new \RuntimeException("AI response confidence {$confidence} is outside the valid range 0.0-1.0.");
        }

        if (! array_key_exists('reasoning', $decoded) || ! is_string($decoded['reasoning']) || trim($decoded['reasoning']) === '') {
            throw new \RuntimeException('AI response missing required non-empty string field: reasoning');
        }

        return $decoded;
    }

    /**
     * Validates the {"entities": [...]} envelope and every item inside it.
     * Unlike validateDocumentType(), this validates a LIST of structured
     * items, not a single flat object — each entity is checked individually
     * so one malformed entity doesn't need to silently drop the rest; instead
     * the whole response is rejected, forcing a clean retry rather than
     * persisting a partially-trustworthy list.
     */
    public function validateEntities(array $decoded): array
    {
        $allowedTypes = ['organization', 'person', 'department', 'location', 'regulator', 'contract', 'reference', 'date', 'other'];

        if (! array_key_exists('entities', $decoded) || ! is_array($decoded['entities']) || ! array_is_list($decoded['entities'])) {
            throw new \RuntimeException('AI response missing required array field: entities');
        }

        foreach ($decoded['entities'] as $i => $entity) {
            if (! is_array($entity)) {
                throw new \RuntimeException("AI response entities[{$i}] is not an object.");
            }
            if (! isset($entity['entity_type']) || ! in_array($entity['entity_type'], $allowedTypes, true)) {
                throw new \RuntimeException("AI response entities[{$i}].entity_type '".($entity['entity_type'] ?? 'null')."' is not in the controlled vocabulary.");
            }
            if (! isset($entity['value']) || ! is_string($entity['value']) || trim($entity['value']) === '') {
                throw new \RuntimeException("AI response entities[{$i}] missing required non-empty string field: value");
            }
            if (! isset($entity['confidence']) || ! is_numeric($entity['confidence'])) {
                throw new \RuntimeException("AI response entities[{$i}] missing required numeric field: confidence");
            }
            $confidence = (float) $entity['confidence'];
            if ($confidence < 0.0 || $confidence > 1.0) {
                throw new \RuntimeException("AI response entities[{$i}].confidence {$confidence} is outside the valid range 0.0-1.0.");
            }
        }

        return $decoded;
    }

    public function validateRisks(array $decoded): array
    {
        $allowedSeverities = ['low', 'medium', 'high', 'critical'];

        if (! array_key_exists('risks', $decoded) || ! is_array($decoded['risks']) || ! array_is_list($decoded['risks'])) {
            throw new \RuntimeException('AI response missing required array field: risks');
        }

        foreach ($decoded['risks'] as $i => $risk) {
            if (! is_array($risk)) {
                throw new \RuntimeException("AI response risks[{$i}] is not an object.");
            }
            if (! isset($risk['title']) || ! is_string($risk['title']) || trim($risk['title']) === '') {
                throw new \RuntimeException("AI response risks[{$i}] missing required non-empty string field: title");
            }
            if (! isset($risk['description']) || ! is_string($risk['description']) || trim($risk['description']) === '') {
                throw new \RuntimeException("AI response risks[{$i}] missing required non-empty string field: description");
            }
            if (! isset($risk['severity']) || ! in_array($risk['severity'], $allowedSeverities, true)) {
                throw new \RuntimeException("AI response risks[{$i}].severity '".($risk['severity'] ?? 'null')."' is not in the controlled vocabulary.");
            }
            if (! isset($risk['confidence']) || ! is_numeric($risk['confidence'])) {
                throw new \RuntimeException("AI response risks[{$i}] missing required numeric field: confidence");
            }
            $confidence = (float) $risk['confidence'];
            if ($confidence < 0.0 || $confidence > 1.0) {
                throw new \RuntimeException("AI response risks[{$i}].confidence {$confidence} is outside the valid range 0.0-1.0.");
            }
            if (! isset($risk['evidence']) || ! is_string($risk['evidence']) || trim($risk['evidence']) === '') {
                throw new \RuntimeException("AI response risks[{$i}] missing required non-empty string field: evidence");
            }
        }

        return $decoded;
    }

    /**
     * Enforces the explicit/relative/inferred distinction: due_date must be
     * null for anything other than "explicit", and must be a real YYYY-MM-DD
     * calendar date when present — this is the concrete backend enforcement
     * of the "never let Claude silently convert a relative deadline into a
     * confirmed date" rule.
     *
     * One unusable deadline is dropped on its own, never the response around
     * it: the envelope is still a clean failure, but a single bad item used
     * to discard the whole extraction (insights, risks, entities, every
     * anchor) after the credit had already been consumed. An "explicit"
     * deadline whose due_date is missing or is not a real date is repaired
     * to "relative" when the model supplied the document's own relative or
     * recurring wording in relative_text, because that wording is what the
     * document actually states; with nothing to fall back on it is dropped.
     * No date is ever invented, completed or shifted.
     */
    public function validateDeadlines(array $decoded): array
    {
        if (! array_key_exists('deadlines', $decoded) || ! is_array($decoded['deadlines']) || ! array_is_list($decoded['deadlines'])) {
            throw new \RuntimeException('AI response missing required array field: deadlines');
        }

        $kept = [];
        $dropped = 0;
        $dropReasons = [];
        $repairs = [];
        foreach ($decoded['deadlines'] as $i => $deadline) {
            try {
                $kept[] = $this->validateDeadline($deadline, (string) $i, $repairs);
            } catch (DeadlineRejection $rejection) {
                $dropped++;
                $dropReasons[$rejection->reason] = ($dropReasons[$rejection->reason] ?? 0) + 1;
            }
        }
        if ($decoded['deadlines'] !== [] && $kept === []) {
            throw new \RuntimeException('AI response contained deadlines but none passed validation.');
        }
        $decoded['deadlines'] = $kept;
        // Metadata only, never evidence or raw responses.
        if ($dropped > 0) {
            $decoded['_optional_items_dropped']['deadlines'] = $dropped;
            $decoded['_optional_drop_reasons']['deadlines'] = $dropReasons;
        }
        if ($repairs !== []) {
            $decoded['_deadline_repairs'] = $repairs;
        }

        return $decoded;
    }

    /**
     * @param  array<string, int>  $repairs  reason => count, by reference across the list
     *
     * @throws DeadlineRejection when this one deadline cannot be used as it stands
     */
    private function validateDeadline(mixed $deadline, string $path, array &$repairs): array
    {
        if (! is_array($deadline)) {
            throw new DeadlineRejection('not_an_object', "AI response deadlines[{$path}] is not an object.");
        }
        foreach (['title', 'description', 'evidence'] as $field) {
            if (! isset($deadline[$field]) || ! is_string($deadline[$field]) || trim($deadline[$field]) === '') {
                throw new DeadlineRejection("missing_{$field}",
                    "AI response deadlines[{$path}] missing required non-empty string field: {$field}");
            }
        }
        if (! isset($deadline['date_type']) || ! in_array($deadline['date_type'], ['explicit', 'relative', 'inferred'], true)) {
            throw new DeadlineRejection('invalid_date_type', "AI response deadlines[{$path}].date_type '"
                .(is_string($deadline['date_type'] ?? null) ? $deadline['date_type'] : 'null')."' is not in the controlled vocabulary.");
        }

        $dueDate = $deadline['due_date'] ?? null;
        if ($deadline['date_type'] !== 'explicit' && $dueDate !== null) {
            throw new DeadlineRejection('due_date_for_non_explicit_type', "AI response deadlines[{$path}] has a due_date but date_type is '{$deadline['date_type']}' — only 'explicit' deadlines may have a due_date.");
        }
        if ($deadline['date_type'] === 'explicit' && ! $this->isCalendarDate($dueDate)) {
            $relative = $deadline['relative_text'] ?? null;
            if (! is_string($relative) || trim($relative) === '') {
                throw new DeadlineRejection('explicit_without_due_date', "AI response deadlines[{$path}] has date_type 'explicit' but due_date is missing or not a valid YYYY-MM-DD date.");
            }
            // The document states a period, not a date. Keep its own words and
            // say so in the type; do not guess the day, month or year.
            $deadline['date_type'] = 'relative';
            $deadline['due_date'] = null;
            $reason = 'explicit_without_due_date_converted_to_relative';
            $repairs[$reason] = ($repairs[$reason] ?? 0) + 1;
        }

        if (! isset($deadline['confidence']) || ! is_numeric($deadline['confidence'])) {
            throw new DeadlineRejection('invalid_confidence', "AI response deadlines[{$path}] missing required numeric field: confidence");
        }
        $confidence = (float) $deadline['confidence'];
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new DeadlineRejection('invalid_confidence', "AI response deadlines[{$path}].confidence {$confidence} is outside the valid range 0.0-1.0.");
        }

        return $deadline;
    }

    /** A real calendar date in YYYY-MM-DD, so 2026-11-31 is not silently rolled into December. */
    private function isCalendarDate(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Validates the grounded document-summary response. Each array field
     * defaults to empty (not required) since an extraction with zero
     * risks/deadlines/etc. should legitimately produce empty arrays here
     * too — only executive_summary and key_findings are treated as
     * required, since a summary with no findings and no summary text
     * would indicate the model didn't actually process the extracted data.
     */
    public function validateSummary(array $decoded, array $availableSourceIds = []): array
    {
        if (! isset($decoded['executive_summary']) || ! is_string($decoded['executive_summary']) || trim($decoded['executive_summary']) === '') {
            throw new \RuntimeException('AI response missing required non-empty string field: executive_summary');
        }

        $dropped = [];
        $dropReasons = [];
        $recordDrop = static function (string $field, string $reason) use (&$dropped, &$dropReasons): void {
            $dropped[$field] = ($dropped[$field] ?? 0) + 1;
            $dropReasons[$field][$reason] = ($dropReasons[$field][$reason] ?? 0) + 1;
        };
        foreach (['key_findings', 'critical_risks', 'upcoming_deadlines', 'important_entities', 'recommended_attention'] as $field) {
            if (! isset($decoded[$field]) || ! is_array($decoded[$field]) || ! array_is_list($decoded[$field])) {
                if ($field === 'key_findings') {
                    throw new \RuntimeException("AI response missing required array field: {$field}");
                }
                if (isset($decoded[$field])) {
                    $recordDrop($field, 'invalid_list_shape');
                }
                $decoded[$field] = [];
            }
            $valid = [];
            foreach ($decoded[$field] as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $valid[] = $item;
                } else {
                    $recordDrop($field, 'invalid_list_item');
                }
            }
            $decoded[$field] = $valid;
        }

        $sourceValidationError = function (array $item, string $path) use ($availableSourceIds): ?string {
            if (! isset($item['source_ids']) || ! is_array($item['source_ids']) || ! array_is_list($item['source_ids'])
                || count($item['source_ids']) < 1 || count($item['source_ids']) > 4) {
                return "Invalid structured assessment field {$path}.source_ids: requires one to four source references.";
            }
            foreach ($item['source_ids'] as $index => $sourceId) {
                if (! is_string($sourceId)
                    || ! preg_match('/\A(?:entity|risk|deadline|kpi|fact):.+\z/', $sourceId)
                    || ! in_array($sourceId, $availableSourceIds, true)) {
                    return "Invalid structured assessment field {$path}.source_ids[{$index}]: unavailable source.";
                }
            }

            return null;
        };
        $validateSources = function (array $item, string $path) use ($sourceValidationError): void {
            if (($error = $sourceValidationError($item, $path)) !== null) {
                throw new \RuntimeException($error);
            }
        };
        $validateText = function (array &$item, string $path, array $fields, bool $requireSources = true) use ($validateSources): void {
            foreach ($fields as $field) {
                $fieldPath = "{$path}.{$field}";
                $limit = self::SUMMARY_TEXT_LIMIT;
                if (! array_key_exists($field, $item)) {
                    throw new \RuntimeException("Invalid structured assessment field {$fieldPath}: missing.");
                }
                if (! is_string($item[$field])) {
                    throw new \RuntimeException("Invalid structured assessment field {$fieldPath}: expected string, got ".get_debug_type($item[$field]).'.');
                }
                if (trim($item[$field]) === '') {
                    throw new \RuntimeException("Invalid structured assessment field {$fieldPath}: must not be empty.");
                }
                $length = mb_strlen($item[$field]);
                if ($length > $limit) {
                    if (! in_array($field, self::SUMMARY_PROSE_FIELDS, true)) {
                        throw new \RuntimeException("Invalid structured assessment field {$fieldPath}: exceeds {$limit} characters (received {$length}).");
                    }
                    $item[$field] = $this->truncateSummaryProse($item[$field], $limit, $fieldPath);
                }
            }
            if ($requireSources) {
                $validateSources($item, $path);
            }
            if (isset($item['basis']) && ! in_array($item['basis'], ['explicit', 'inferred'], true)) {
                throw new \RuntimeException("Invalid structured assessment field {$path}.basis: expected explicit or inferred.");
            }
        };
        if (array_key_exists('executive_assessment', $decoded) && $decoded['executive_assessment'] !== null) {
            try {
                if (! is_array($decoded['executive_assessment'])) {
                    throw new \RuntimeException('Invalid executive assessment.');
                }
                $validateText($decoded['executive_assessment'], 'executive_assessment', ['text']);
            } catch (\RuntimeException $e) {
                $decoded['executive_assessment'] = null;
                $recordDrop('executive_assessment', $this->summaryDropReason($e));
            }
        }
        foreach (['material_findings' => [4, ['title', 'category', 'explanation', 'why_it_matters', 'severity']],
            'trends' => [2, ['observation', 'significance']],
            'tensions' => [2, ['observation', 'significance']],
            'questions' => [3, ['question', 'reason']]] as $field => [$limit, $fields]) {
            if (! array_key_exists($field, $decoded)) {
                continue;
            }
            $items = $decoded[$field];
            $decoded[$field] = [];
            if (! is_array($items) || ! array_is_list($items)) {
                $recordDrop($field, 'invalid_list_shape');

                continue;
            }
            foreach ($items as $index => $item) {
                try {
                    if (! is_array($item) || count($decoded[$field]) >= $limit) {
                        throw new \RuntimeException('Invalid optional item.');
                    }
                    $validateText($item, "{$field}[{$index}]", $fields);
                    if ($field === 'material_findings' && ! in_array($item['severity'], ['low', 'medium', 'high', 'critical'], true)) {
                        throw new \RuntimeException('Invalid severity.');
                    }
                    $decoded[$field][] = $item;
                } catch (\RuntimeException $e) {
                    $recordDrop($field, $this->summaryDropReason($e));
                }
            }
        }
        // Metadata only, never log evidence or raw responses.
        if ($dropped) {
            $decoded['_optional_items_dropped'] = $dropped;
            $decoded['_optional_drop_reasons'] = $dropReasons;
        }

        return $decoded;
    }

    private function summaryDropReason(\RuntimeException $error): string
    {
        $message = $error->getMessage();

        return match (true) {
            str_contains($message, 'source_ids') => 'invalid_source_reference',
            str_contains($message, 'exceeds') => 'text_over_limit',
            str_contains($message, 'expected string') => 'wrong_field_type',
            str_contains($message, 'must not be empty') => 'empty_field',
            str_contains($message, '.basis') => 'invalid_basis',
            str_contains($message, 'Invalid severity') => 'invalid_severity',
            str_contains($message, 'Invalid optional item') => 'invalid_item_or_limit',
            default => 'invalid_optional_item',
        };
    }

    private function truncateSummaryProse(string $text, int $limit, string $fieldPath): string
    {
        $prefix = mb_substr($text, 0, $limit);
        $next = mb_substr($text, $limit, 1);
        if (preg_match('/^\s$/u', $next) || preg_match('/\s$/u', $prefix)) {
            $candidate = rtrim($prefix);
            if (mb_strlen($candidate) >= $limit - self::SUMMARY_WORD_BOUNDARY_LOOKBACK) {
                return $candidate;
            }
        }
        if (preg_match('/^(.*)\s+\S*$/us', $prefix, $matches)) {
            $candidate = rtrim($matches[1]);
            if (mb_strlen($candidate) >= $limit - self::SUMMARY_WORD_BOUNDARY_LOOKBACK) {
                return $candidate;
            }
        }

        // A very long unbroken token may be an identifier or URL. Do not
        // damage it merely to make a response pass validation.
        throw new \RuntimeException("Invalid structured assessment field {$fieldPath}: no safe word boundary within {$limit} characters.");
    }

    /**
     * Day 9 Batch 5/6 — validates the {answer, confidence, cited_document_ids}
     * envelope AND enforces the hallucination guard in code, not just via
     * prompt instruction: any cited document ID that is not in the set
     * actually retrieved for this query is rejected outright, forcing a
     * clean failure rather than persisting an answer that cites content
     * the model was never shown.
     */
    public function validateQaResponse(array $decoded, array $availableDocumentIds): array
    {
        $allowedConfidence = ['strong', 'weak', 'none'];

        if (! isset($decoded['answer']) || ! is_string($decoded['answer']) || trim($decoded['answer']) === '') {
            throw new \RuntimeException('AI response missing required non-empty string field: answer');
        }

        if (! isset($decoded['confidence']) || ! in_array($decoded['confidence'], $allowedConfidence, true)) {
            throw new \RuntimeException(
                "AI response confidence '".($decoded['confidence'] ?? 'null')."' is not in the controlled vocabulary."
            );
        }

        if (! isset($decoded['cited_document_ids']) || ! is_array($decoded['cited_document_ids'])) {
            throw new \RuntimeException('AI response missing required array field: cited_document_ids');
        }

        foreach ($decoded['cited_document_ids'] as $i => $documentId) {
            if (! is_string($documentId)) {
                throw new \RuntimeException("AI response cited_document_ids[{$i}] is not a string.");
            }
            if (! in_array($documentId, $availableDocumentIds, true)) {
                throw new \RuntimeException(
                    "AI response cited document ID '{$documentId}' was not present in the retrieved context — hallucinated citation rejected."
                );
            }
        }

        if ($decoded['confidence'] === 'none' && ! empty($decoded['cited_document_ids'])) {
            throw new \RuntimeException(
                "AI response confidence is 'none' but cited_document_ids is non-empty — a no-answer response must not cite documents."
            );
        }

        return $decoded;
    }
}
