<?php

namespace App\Services\AI\Incremental;

use InvalidArgumentException;

/** Provider transport only. Canonical evidence begins at expand(). */
final class CompactEvidenceExpander
{
    public const VERSION = 'compact-json-v1';

    public const CANONICAL_SCHEMA_SHA256 = '69dcb0125c131c024670b325496d8abcc868a2a23cebb9df8c21b7071380c873';
    public const CONTROL_PROMPT_SHA256 = '026063bffca0cfc4c82dd9f4eddd7330cf1be26fc316e62cbac387ec54f56985';

    private const KEYS = [
        'label' => 'l', 'value' => 'v', 'subject' => 's', 'reference' => 'r',
        'evidence_ids' => 'e', 'entity_type' => 'et', 'unit' => 'u', 'period' => 'p',
        'date_type' => 'dt', 'due_date' => 'dd', 'severity' => 'sv',
        'metric_type' => 'mt', 'value_basis' => 'vb', 'aggregation' => 'ag',
        'quantity_kind' => 'qk', 'kind' => 'k', 'confidence' => 'cf', 'aliases' => 'a',
    ];

    // Field descriptions mirror the frozen Control instructions. The prompt hash guard below
    // forces a review if those semantic instructions ever change.
    private const DESCRIPTIONS = [
        'label' => 'Canonical label: identify the concept, not the observation.',
        'value' => 'Canonical value: the observation or factual statement. Preserve distinct years, targets, actuals, populations and measurement bases.',
        'subject' => 'Canonical subject: the responsible entity or measured population, never an inferred actor.',
        'reference' => 'Canonical reference: source reference, including an unresolved antecedent phrase when kind is unresolved. Do not invent a reference.',
        'evidence_ids' => 'Canonical evidence_ids: exact identifiers shown in this slice, normally one and at most the supplied max_evidence_ids; cite the smallest local span set that fully supports the record. Never guess or renumber.',
        'entity_type' => 'Canonical entity_type: organization, person, location, date, reference, department, regulator, contract or other when explicitly supported; otherwise omit for null.',
        'unit' => 'Canonical unit: preserve the explicit measurement unit exactly; omit for null.',
        'period' => 'Canonical period: preserve the faithful explicit period text; partial dates such as a month, year, FY or quarter belong here, without inventing a complete date. Omit for null.',
        'date_type' => 'Canonical date_type: explicit only for a complete date; relative for relative timing; inferred only for an inferred deadline or obligation; otherwise omit for null.',
        'due_date' => 'Canonical due_date: YYYY-MM-DD only when a complete date is explicitly stated and date_type is explicit; otherwise omit for null.',
        'severity' => 'Canonical severity: low, medium, high or critical for risks when supported; otherwise omit for null.',
        'metric_type' => 'Canonical metric_type: distinguish actual, target and change when explicit; otherwise omit for null.',
        'value_basis' => 'Canonical value_basis: distinguish total, average and rate when explicit; otherwise omit for null.',
        'aggregation' => 'Canonical aggregation: describe the aggregation only when explicit; otherwise omit for null.',
        'quantity_kind' => 'Canonical quantity_kind: describe the measurement kind only when explicit; otherwise omit for null.',
        'kind' => 'Canonical kind: entity, metric, deadline, obligation, risk, fact, definition or unresolved, following the frozen extraction scope and selection rules.',
        'confidence' => 'Canonical confidence: number from 0 to 1; confidence is not evidence.',
        'aliases' => 'Canonical aliases: only aliases explicitly present in cited spans; never guess abbreviations; use an empty array when irrelevant.',
    ];

    public static function canonicalHash(): string
    {
        return hash('sha256', json_encode(['type' => 'json_schema', 'schema' => EvidenceSchema::extraction(EvidenceGrounding::SPANS)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function schema(): array
    {
        if (self::canonicalHash() !== self::CANONICAL_SCHEMA_SHA256) {
            throw new InvalidArgumentException('compact_expansion_error: canonical schema hash drift');
        }
        if (hash('sha256', EvidenceSchema::instructions(EvidenceGrounding::SPANS)) !== self::CONTROL_PROMPT_SHA256) {
            throw new InvalidArgumentException('compact_expansion_error: canonical field instruction drift');
        }
        $canonical = EvidenceSchema::extraction(EvidenceGrounding::SPANS);
        $source = $canonical['properties']['records']['items'];
        $keys = array_keys($source['properties']);
        if ($keys !== array_keys(self::KEYS) || $keys !== array_keys(self::DESCRIPTIONS) || count(array_unique(self::KEYS)) !== count(self::KEYS)) {
            throw new InvalidArgumentException('compact_expansion_error: canonical mapping drift');
        }
        $properties = [];
        $required = [];
        foreach (self::KEYS as $field => $short) {
            $rule = $source['properties'][$field];
            $nullable = self::nullable($rule);
            if (! in_array($field, $source['required'], true)) {
                throw new InvalidArgumentException('compact_expansion_error: canonical requiredness drift');
            }
            if ($nullable) {
                // Omission represents exactly canonical null; preserve every other rule.
                $rule = isset($rule['anyOf']) ? $rule['anyOf'][0] : [...$rule, 'type' => 'string'];
            } else {
                $required[] = $short;
            }
            $properties[$short] = [...$rule, 'description' => self::DESCRIPTIONS[$field]];
        }
        return ['type' => 'object', 'properties' => ['m' => ['type' => 'array', 'items' => [
            'type' => 'object', 'properties' => $properties, 'required' => $required,
            'additionalProperties' => false,
        ]]], 'required' => ['m'], 'additionalProperties' => false];
    }

    private static function nullable(array $rule): bool
    {
        return in_array('null', (array) ($rule['type'] ?? []), true)
            || in_array(['type' => 'null'], $rule['anyOf'] ?? [], true);
    }

    public static function expand(array $payload): array
    {
        self::schema();
        if (array_keys($payload) !== ['m'] || ! is_array($payload['m']) || ! array_is_list($payload['m'])) {
            throw new InvalidArgumentException('compact_expansion_error: root');
        }
        $source = EvidenceSchema::extraction(EvidenceGrounding::SPANS)['properties']['records']['items'];
        $inverse = array_flip(self::KEYS);
        $records = [];
        foreach ($payload['m'] as $row) {
            if (! is_array($row) || array_is_list($row) || array_diff(array_keys($row), array_keys($inverse))) {
                throw new InvalidArgumentException('compact_expansion_error: unknown key');
            }
            $record = [];
            foreach (self::KEYS as $field => $short) {
                $rule = $source['properties'][$field];
                if (! array_key_exists($short, $row)) {
                    if (! self::nullable($rule)) {
                        throw new InvalidArgumentException('compact_expansion_error: missing required field');
                    }
                    $record[$field] = null;
                    continue;
                }
                $value = $row[$short];
                if ($value === null || ! self::validType($value, $rule)) {
                    throw new InvalidArgumentException('compact_expansion_error: wrong field type');
                }
                $record[$field] = $value;
            }
            $records[] = $record;
        }
        return ['records' => $records];
    }

    private static function validType(mixed $value, array $rule): bool
    {
        if (isset($rule['anyOf'])) {
            return self::validType($value, $rule['anyOf'][0]);
        }
        return match ($rule['type']) {
            'string', ['string', 'null'] => is_string($value) && (! isset($rule['enum']) || in_array($value, $rule['enum'], true)),
            'number' => (is_int($value) || is_float($value)) && ! is_bool($value),
            'array' => is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value) && count($value) >= ($rule['minItems'] ?? 0),
            default => false,
        };
    }

    /** Deterministic fixture encoder; production sends only the provider schema. */
    public static function encode(array $canonical): array
    {
        self::schema();
        if (array_keys($canonical) !== ['records'] || ! is_array($canonical['records']) || ! array_is_list($canonical['records'])) {
            throw new InvalidArgumentException('compact_expansion_error: canonical root');
        }
        $rows = [];
        foreach ($canonical['records'] as $record) {
            $row = [];
            foreach (self::KEYS as $field => $short) {
                if (! array_key_exists($field, $record)) throw new InvalidArgumentException('compact_expansion_error: canonical field missing');
                if ($record[$field] !== null) $row[$short] = $record[$field];
            }
            $rows[] = $row;
        }
        self::expand(['m' => $rows]);
        return ['m' => $rows];
    }

    /** Return only a contiguous prefix of fully closed, strictly expandable objects. */
    public static function salvage(string $raw): array
    {
        if (! preg_match('/\A\s*\{\s*"m"\s*:\s*\[/', $raw, $match)) {
            return [];
        }
        $offset = strlen($match[0]);
        $rows = [];
        $length = strlen($raw);
        while ($offset < $length) {
            while ($offset < $length && ctype_space($raw[$offset])) $offset++;
            if ($offset >= $length || $raw[$offset] !== '{') break;
            $start = $offset;
            $depth = 0; $quoted = false; $escaped = false;
            for (; $offset < $length; $offset++) {
                $c = $raw[$offset];
                if ($quoted) {
                    if ($escaped) $escaped = false;
                    elseif ($c === '\\') $escaped = true;
                    elseif ($c === '"') $quoted = false;
                } elseif ($c === '"') $quoted = true;
                elseif ($c === '{') $depth++;
                elseif ($c === '}' && --$depth === 0) { $offset++; break; }
            }
            if ($depth !== 0) break;
            try {
                $row = json_decode(substr($raw, $start, $offset - $start), true, 512, JSON_THROW_ON_ERROR);
                self::expand(['m' => [$row]]);
            } catch (\Throwable) { break; }
            $rows[] = $row;
            while ($offset < $length && ctype_space($raw[$offset])) $offset++;
            if ($offset >= $length || $raw[$offset] !== ',') break;
            $offset++;
        }
        return $rows;
    }
}
