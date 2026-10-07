<?php

namespace App\Services\Intelligence;

use App\Models\DocumentEntity;
use App\Models\DocumentEvidence;
use App\Services\AI\Incremental\EvidenceMerger;

/** Resolves only the approved, versioned lexical reporting patterns in a cited quote. */
class AttributionMatcher
{
    public function __construct(private EvidenceMerger $normalizer) {}

    /** @return array{speaker:?string,role:string,reported:bool,evidence_ref:?array} */
    public function match(DocumentEvidence $row): array
    {
        $default = ['speaker' => null, 'role' => 'unattributed', 'reported' => false, 'evidence_ref' => null];
        $data = is_array($row->data) ? $row->data : [];
        $quote = is_string($data['quote'] ?? null) ? $data['quote'] : '';
        $claim = trim((string) ($data['value'] ?? ''));
        if ($quote === '' || $claim === '') {
            return $default;
        }
        $claimOffset = mb_stripos($quote, $claim);
        if ($claimOffset === false) {
            return $default;
        }

        $matches = [];
        foreach (config('intelligence_v2.attribution.patterns', []) as $role => $patterns) {
            foreach ($patterns as $pattern) {
                $escaped = preg_quote($pattern, '/');
                $expression = str_replace(preg_quote('<named speaker>', '/'),
                    '(?<speaker>[\p{Lu}][\p{L}]+(?:\s+[\p{Lu}][\p{L}]+)*)', $escaped);
                preg_match_all('/(?<!\w)'.$expression.'(?!\w)/iu', $quote, $found,
                    PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($found as $occurrence) {
                    $byteOffset = $occurrence[0][1];
                    $start = mb_strlen(substr($quote, 0, $byteOffset));
                    $end = $start + mb_strlen($occurrence[0][0]);
                    $distance = $claimOffset < $start ? $start - $claimOffset
                        : ($claimOffset > $end ? $claimOffset - $end : 0);
                    $matches[] = ['distance' => $distance, 'role' => $role,
                        'speaker_text' => $occurrence['speaker'][0] ?? null];
                }
            }
        }
        if ($matches === []) {
            return $default;
        }
        usort($matches, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        if (isset($matches[1]) && $matches[0]['distance'] === $matches[1]['distance']) {
            return $default;
        }
        $winner = $matches[0];
        $speaker = null;
        if (is_string($winner['speaker_text']) && $row->workspace_id && $row->document_id) {
            $entityId = DocumentEntity::where('workspace_id', $row->workspace_id)
                ->where('document_id', $row->document_id)
                ->where('normalized_value', $this->normalizer->normalize($winner['speaker_text']))
                ->value('id');
            $speaker = $entityId === null ? null : 'entity:'.$entityId;
        }
        $source = ($row->sources ?? [])[0] ?? null;
        $reference = is_array($source) && $row->exists ? [
            'record_id' => (string) $row->id, 'source_id' => (string) $row->source_id,
            'span_id' => $source['span_id'] ?? null,
            'extraction_version' => $source['extraction_version'] ?? null,
            'chunk_id' => (string) ($source['chunk_id'] ?? ''),
            'start_offset' => $source['start_offset'] ?? null,
            'end_offset' => $source['end_offset'] ?? null,
            'quote' => $source['quote'] ?? $quote, 'page' => $source['page'] ?? null,
            'highlight' => ['mode' => 'none', 'start_offset' => null, 'end_offset' => null,
                'needle' => null, 'occurrence' => null, 'occurrences' => null, 'page' => null],
        ] : null;

        return ['speaker' => $speaker, 'role' => $winner['role'],
            'reported' => in_array($winner['role'], config('intelligence_v2.attribution.reported_roles', []), true),
            'evidence_ref' => $reference];
    }
}
