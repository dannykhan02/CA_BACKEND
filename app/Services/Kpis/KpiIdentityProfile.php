<?php

namespace App\Services\Kpis;

class KpiIdentityProfile
{
    public const TYPES = ['amount', 'count', 'percentage', 'rate', 'duration', 'ratio', 'index'];

    public const CONTEXT = ['scope', 'metric_type', 'unit', 'quantity_kind', 'aggregation', 'value_basis'];

    public function __construct(private KpiLabelNormalizer $normalizer) {}

    public function make(array $kpi): array
    {
        $label = (string) ($kpi['label'] ?? '');
        $metadata = $kpi['identity'] ?? [];
        $valid = is_array($metadata);
        $metadata = $valid ? $metadata : [];
        foreach ($metadata as $value) {
            if ($value !== null && (! is_string($value) || mb_strlen($value) > 255)) {
                $valid = false;
            }
        }
        if (! $valid) {
            $metadata = [];
        }
        $supplied = fn ($key) => isset($metadata[$key]) && trim($metadata[$key]) !== '' ? $this->normalizer->normalize($metadata[$key]) : null;
        $normalized = $this->normalizer->normalize($label);
        [$base, $period] = $this->normalizer->period($label, $metadata['period'] ?? null);
        $signals = $this->signals($base);
        foreach ($this->signals($supplied('concept') ?? '') as $key => $signal) {
            if ($signal !== null && $signals[$key] !== null && $signal !== $signals[$key]) {
                $valid = false;
            }
            $signals[$key] ??= $signal;
        }
        ['scope' => $scope, 'quantity_kind' => $quantity, 'aggregation' => $aggregation, 'value_basis' => $basis] = $signals;
        $unit = $this->unit($kpi['unit'] ?? null);
        if (! $unit && str_contains((string) ($kpi['value'] ?? ''), '%')) {
            $unit = 'percent';
        }
        $type = null;
        foreach ([$this->type($unit, ''), $this->type(null, $base), $this->type(null, $supplied('concept') ?? '')] as $signal) {
            // A percentage is commonly labelled a rate; this is not a count/rate conflict.
            if ($type === 'percentage' && $signal === 'rate') {
                continue;
            }
            if ($signal !== null && $type !== null && $signal !== $type) {
                $valid = false;
            }
            $type ??= $signal;
        }

        // AI metadata may add information, but cannot contradict explicit source wording.
        foreach (['scope' => $scope, 'quantity_kind' => $quantity, 'aggregation' => $aggregation, 'value_basis' => $basis, 'metric_type' => $type] as $key => $explicit) {
            $value = $supplied($key);
            if ($explicit !== null && $value !== null && $explicit !== $value) {
                $valid = false;
            }
        }
        $profile = [
            'label' => $label, 'normalized_label' => $normalized, 'base_name' => $base,
            'concept' => $supplied('concept'), 'scope' => $scope ?? $supplied('scope'),
            'metric_type' => $type ?? $supplied('metric_type'), 'unit' => $unit,
            'quantity_kind' => $quantity ?? $supplied('quantity_kind') ?? 'absolute',
            'aggregation' => $aggregation ?? $supplied('aggregation'),
            'value_basis' => $basis ?? $supplied('value_basis') ?? 'actual',
            'period' => $period, 'rule' => 'label', 'valid' => $valid && $normalized !== '',
        ];
        foreach (['metric_type' => self::TYPES, 'quantity_kind' => ['absolute', 'change'], 'aggregation' => ['total', 'average'], 'value_basis' => ['actual', 'target']] as $key => $allowed) {
            if ($profile[$key] !== null && ! in_array($profile[$key], $allowed, true)) {
                $profile['valid'] = false;
            }
        }

        // Deliberately narrow, inspectable rules for the observed production family.
        // Pending overdue tickets are NOT equated to all pending tickets.
        if (preg_match('/^(internal|external) (?:service )?charter (?:average )?performance$/u', $base)) {
            $profile['concept'] = 'service charter performance';
            $profile['rule'] = 'charter';
        } elseif (preg_match('/^(internal|external) (?:service )?charter tickets closed (?:outside (?:target )?timelines?|out of target)$/u', $base)) {
            $profile['concept'] = 'service charter tickets closed outside timelines';
            $profile['rule'] = 'charter';
        } elseif (preg_match('/^(internal|external) (?:service )?charter pending tickets$/u', $base)) {
            $profile['concept'] = 'service charter pending tickets';
            $profile['rule'] = 'charter';
        } elseif ($profile['concept'] !== null) {
            $profile['rule'] = 'structured';
        } else {
            // Without semantic support, a period stays part of legacy label identity.
            $profile['base_name'] = $normalized;
            $profile['concept'] = $normalized;
        }

        $profile['qualifiers'] = [];
        if ($profile['rule'] === 'structured') {
            // Preserve unexplained modifiers (countries, products, denominators, etc.).
            // Equal AI concepts alone must not erase them.
            $explained = explode(' ', $profile['concept'].' '.($profile['scope'] ?? ''));
            $profile['qualifiers'] = array_values(array_unique(array_diff(explode(' ', $base), $explained)));
            sort($profile['qualifiers']);
        }
        $profile['context_key'] = hash('sha256', json_encode(array_intersect_key($profile, array_flip(self::CONTEXT))));
        $profile['identity_key'] = hash('sha256', json_encode([$profile['concept'], $profile['qualifiers'], $profile['context_key']]));

        return $profile;
    }

    public function compatible(array $a, array $b): bool
    {
        // Missing information is not a wildcard for a scoped/typed metric.
        foreach (self::CONTEXT as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    public function relationship(array $a, array $b): string
    {
        if ($a['valid'] && $b['valid'] && $this->compatible($a, $b) && $a['identity_key'] === $b['identity_key']) {
            return 'same';
        }
        $words = array_intersect(explode(' ', $a['concept']), explode(' ', $b['concept']));

        return count($words) >= 2 ? 'related' : 'unrelated';
    }

    private function unit(?string $unit): ?string
    {
        if ($unit === null || trim($unit) === '') {
            return null;
        }
        $unit = $this->normalizer->normalize($unit);

        return match ($unit) {
            'percentage', 'pct', 'percent' => 'percent',
            'ksh', 'kshs', 'kes' => 'kes',
            default => $unit,
        };
    }

    private function type(?string $unit, string $base): ?string
    {
        if (($unit && str_starts_with($unit, 'percent')) || preg_match('/\b(percentage|percent)\b/u', $base)) {
            return 'percentage';
        }
        if ($unit && preg_match('/^(kes|usd|gbp|eur|dollar)(?: |$)/', $unit)) {
            return 'amount';
        }
        if ($unit && preg_match('/^(hours?|days?|months?|years?|minutes?|seconds?)$/', $unit)) {
            return 'duration';
        }
        if (preg_match('/\b(rate|ratio)\b/u', $base, $match)) {
            return $match[1];
        }
        if (preg_match('/\b(number of|count)\b/u', $base)) {
            return 'count';
        }
        if (preg_match('/\b(duration)\b/u', $base)) {
            return 'duration';
        }
        if ($unit && preg_match('/^(tickets?|documents?|people|cases?|incidents?)$/', $unit)) {
            return 'count';
        }

        return null;
    }

    private function signals(string $text): array
    {
        preg_match_all('/\b(internal|external)\b/u', $text, $scopes);
        $basisText = preg_replace('/\b(outside (?:target )?timelines?|out of target)\b/u', '', $text);

        return [
            'scope' => $scopes[0] ? implode(' ', array_unique($scopes[0])) : null,
            'quantity_kind' => preg_match('/\b(increase|decrease|change|growth|delta|variance|reduction)\b/u', $text) ? 'change' : (preg_match('/\babsolute\b/u', $text) ? 'absolute' : null),
            'aggregation' => preg_match('/\b(average|mean|avg)\b/u', $text) ? 'average' : (preg_match('/\b(total|sum)\b/u', $text) ? 'total' : null),
            'value_basis' => preg_match('/\b(target|goal|threshold|budgeted|forecast|projected)\b/u', $basisText) ? 'target' : (preg_match('/\bactual\b/u', $basisText) ? 'actual' : null),
        ];
    }
}
