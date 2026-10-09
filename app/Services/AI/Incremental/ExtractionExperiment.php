<?php

namespace App\Services\AI\Incremental;

/** Explicit, request-scoped diagnostic options. Production callers omit this object. */
final readonly class ExtractionExperiment
{
    public function __construct(public bool $collector = false, public bool $metadata = false) {}

    public function promptVersion(string $controlVersion): string
    {
        return match ([$this->collector, $this->metadata]) {
            [true, true] => 'diagnostic-collector-metadata-v1',
            [true, false] => 'diagnostic-collector-v1',
            [false, true] => 'diagnostic-metadata-v1',
            default => $controlVersion,
        };
    }

    public function instructions(string $control): string
    {
        if ($this->collector) {
            $control = str_replace('risks, material facts and definitions.', 'risks, facts and definitions.', $control);
            $control = str_replace('Prefer material evidence to repetitive boilerplate.',
                'Capture qualifying independently supported observations without ranking them by business or material importance.', $control);
            $control = str_replace('When the slice holds more qualifying observations than max_records, keep the most material ones (deadlines, obligations, risks, headline totals and key figures, named parties) ahead of row-level table detail and repeated boilerplate.',
                'Do not omit an otherwise qualifying observation merely because another seems more important. Keep distinct values and claims separate. If qualifying observations exceed max_records or practical response capacity, return only what fits and finish valid JSON; coverage may be incomplete.', $control);
        }
        if ($this->metadata) {
            $control .= ' OPTIONAL METADATA DISCIPLINE: Populate period, date, currency, unit, deadline and temporal-scope fields only when the cited evidence span or spans (or verbatim quote in legacy mode) support that field under the existing grounding rules. Otherwise use null for nullable fields or leave the information absent where the schema permits. Do not infer metadata to make a record eligible for downstream use.';
        }

        return $control;
    }
}
