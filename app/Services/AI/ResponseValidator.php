<?php

namespace App\Services\AI;

class ResponseValidator
{
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

        if (! array_key_exists('entities', $decoded) || ! is_array($decoded['entities'])) {
            throw new \RuntimeException('AI response missing required array field: entities');
        }

        foreach ($decoded['entities'] as $i => $entity) {
            if (! is_array($entity)) {
                throw new \RuntimeException("AI response entities[{$i}] is not an object.");
            }
            if (! isset($entity['entity_type']) || ! in_array($entity['entity_type'], $allowedTypes, true)) {
                throw new \RuntimeException("AI response entities[{$i}].entity_type '" . ($entity['entity_type'] ?? 'null') . "' is not in the controlled vocabulary.");
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

        if (! array_key_exists('risks', $decoded) || ! is_array($decoded['risks'])) {
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
                throw new \RuntimeException("AI response risks[{$i}].severity '" . ($risk['severity'] ?? 'null') . "' is not in the controlled vocabulary.");
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
     * null for anything other than "explicit", and must be a valid
     * YYYY-MM-DD date when present — this is the concrete backend
     * enforcement of the "never let Claude silently convert a relative
     * deadline into a confirmed date" rule.
     */
    public function validateDeadlines(array $decoded): array
    {
        $allowedDateTypes = ['explicit', 'relative', 'inferred'];

        if (! array_key_exists('deadlines', $decoded) || ! is_array($decoded['deadlines'])) {
            throw new \RuntimeException('AI response missing required array field: deadlines');
        }

        foreach ($decoded['deadlines'] as $i => $deadline) {
            if (! is_array($deadline)) {
                throw new \RuntimeException("AI response deadlines[{$i}] is not an object.");
            }
            if (! isset($deadline['title']) || ! is_string($deadline['title']) || trim($deadline['title']) === '') {
                throw new \RuntimeException("AI response deadlines[{$i}] missing required non-empty string field: title");
            }
            if (! isset($deadline['description']) || ! is_string($deadline['description']) || trim($deadline['description']) === '') {
                throw new \RuntimeException("AI response deadlines[{$i}] missing required non-empty string field: description");
            }
            if (! isset($deadline['date_type']) || ! in_array($deadline['date_type'], $allowedDateTypes, true)) {
                throw new \RuntimeException("AI response deadlines[{$i}].date_type '" . ($deadline['date_type'] ?? 'null') . "' is not in the controlled vocabulary.");
            }

            $dueDate = $deadline['due_date'] ?? null;
            if ($deadline['date_type'] !== 'explicit' && $dueDate !== null) {
                throw new \RuntimeException("AI response deadlines[{$i}] has a due_date but date_type is '{$deadline['date_type']}' — only 'explicit' deadlines may have a due_date.");
            }
            if ($deadline['date_type'] === 'explicit') {
                if (! $dueDate || ! is_string($dueDate) || ! \DateTime::createFromFormat('Y-m-d', $dueDate)) {
                    throw new \RuntimeException("AI response deadlines[{$i}] has date_type 'explicit' but due_date is missing or not a valid YYYY-MM-DD date.");
                }
            }

            if (! isset($deadline['confidence']) || ! is_numeric($deadline['confidence'])) {
                throw new \RuntimeException("AI response deadlines[{$i}] missing required numeric field: confidence");
            }
            $confidence = (float) $deadline['confidence'];
            if ($confidence < 0.0 || $confidence > 1.0) {
                throw new \RuntimeException("AI response deadlines[{$i}].confidence {$confidence} is outside the valid range 0.0-1.0.");
            }
            if (! isset($deadline['evidence']) || ! is_string($deadline['evidence']) || trim($deadline['evidence']) === '') {
                throw new \RuntimeException("AI response deadlines[{$i}] missing required non-empty string field: evidence");
            }
        }

        return $decoded;
    }

    /**
     * Validates the grounded document-summary response. Each array field
     * defaults to empty (not required) since an extraction with zero
     * risks/deadlines/etc. should legitimately produce empty arrays here
     * too — only executive_summary and key_findings are treated as
     * required, since a summary with no findings and no summary text
     * would indicate the model didn't actually process the extracted data.
     */
    public function validateSummary(array $decoded): array
    {
        if (! isset($decoded['executive_summary']) || ! is_string($decoded['executive_summary']) || trim($decoded['executive_summary']) === '') {
            throw new \RuntimeException('AI response missing required non-empty string field: executive_summary');
        }

        foreach (['key_findings', 'critical_risks', 'upcoming_deadlines', 'important_entities', 'recommended_attention'] as $field) {
            if (! isset($decoded[$field]) || ! is_array($decoded[$field])) {
                throw new \RuntimeException("AI response missing required array field: {$field}");
            }
            foreach ($decoded[$field] as $i => $item) {
                if (! is_string($item)) {
                    throw new \RuntimeException("AI response {$field}[{$i}] is not a string.");
                }
            }
        }

        return $decoded;
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
                "AI response confidence '" . ($decoded['confidence'] ?? 'null') . "' is not in the controlled vocabulary."
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
