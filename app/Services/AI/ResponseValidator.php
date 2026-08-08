<?php

namespace App\Services\AI;

class ResponseValidator
{
    /**
     * Minimal structural validation, deliberately not a full schema engine
     * (deep field-level schemas are Day 2+, per the Day 1 scope). Wraps
     * AnthropicClient::decodeJsonContent()'s output — does not duplicate
     * its JSON-decoding/fence-stripping logic.
     *
     * Checks type only when a key is present, rather than requiring
     * presence: parseInsightsResponse() already defaults missing keys to
     * [] via null-coalescing, and Day 1 must not change that existing
     * tolerance. This only guards against a key being present but the
     * WRONG type (e.g. Claude returning "kpis": "none" as a string instead
     * of an array), which would otherwise reach the database and break
     * downstream consumers silently.
     */
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

    /**
     * Semantic contract validation for document-type classification —
     * a step beyond validate()'s structural-only checks. Enforces:
     *   - document_type: required, string, must be in the controlled
     *     vocabulary (kept in sync with the
     *     document_type_classifications_type_check DB constraint)
     *   - confidence: required, numeric, 0.0-1.0 inclusive
     *   - reasoning: required, non-empty string
     *
     * Unlike validate(), fields here are REQUIRED, not merely type-checked
     * when present — this response has no existing lenient/null-coalescing
     * consumer to stay compatible with (document_type is a brand-new Day 2
     * capability), so there's no behavioral-compatibility reason to allow
     * silent absence the way Day 1's insights validation does.
     */
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
}
