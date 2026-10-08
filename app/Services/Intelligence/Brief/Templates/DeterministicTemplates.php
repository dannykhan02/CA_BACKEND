<?php

namespace App\Services\Intelligence\Brief\Templates;

use App\Services\Intelligence\Values\ValueFormatter;

/** Pure text templates. Callers supply only stored record fields and typed values. */
class DeterministicTemplates
{
    public function __construct(private ValueFormatter $values) {}

    public function render(string $id, array $input): string
    {
        $label = trim((string) ($input['label'] ?? ''));
        $date = $input['date'] ?? [];
        $value = $input['value'] ?? [];

        $rendered = match ($id) {
            'headline.document_identity' => trim((string) ($input['document_type'] ?? 'Document').' — '.(string) ($input['document_name'] ?? '')),
            'measure.period_value' => $label.': '.$this->values->raw((string) ($value['raw'] ?? ''), $value['unit'] ?? null)
                .(is_string($input['period'] ?? null) && $input['period'] !== '' ? ' ('.$input['period'].')' : ''),
            'timeline.calendar_due' => $label.' — due '.($date['raw'] ?? ''),
            'timeline.period_due' => $label.' — due in '.($date['period']['text'] ?? ''),
            'timeline.relative_due' => $label.' — due '.($date['duration']['text'] ?? ''),
            'attention.overdue' => $label.' was due '.($date['raw'] ?? '').' and is still open.',
            'attention.imminent' => $label.' is due '.($date['raw'] ?? '').' and needs attention.',
            'attention.critical_risk' => $label.' is a critical risk requiring attention.',
            'coverage_note.partial', 'coverage_note.bounded', 'coverage_note.unavailable',
            'coverage_note.legacy_route' => 'Coverage: '.implode(', ', $input['reasons'] ?? []).'.',
            default => throw new \InvalidArgumentException('Unknown Brief template: '.$id),
        };
        $attribution = $input['attribution'] ?? null;
        if (($attribution['reported'] ?? false) && is_string($attribution['role'] ?? null)) {
            return ucfirst(str_replace('_', ' ', $attribution['role'])).' report: '.$rendered;
        }

        return $rendered;
    }
}
