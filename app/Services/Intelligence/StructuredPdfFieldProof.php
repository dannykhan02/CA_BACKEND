<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Services\AI\Incremental\EvidenceGrounding;

/** Derives claims from one published value-to-header/table path, not model wording. */
final class StructuredPdfFieldProof
{
    public function __construct(private PeriodParser $periods,
        private MeasurementParser $measurements,
        private GoverningOwnerProof $owners,
        private MatchingTextNormalizer $normalizer) {}

    /** Binds a stored verdict to the exact claim it verified. */
    public static function claimFingerprint(array $data): string
    {
        return hash('sha256', json_encode(array_map(
            static fn ($key) => trim((string) ($data[$key] ?? '')),
            ['label', 'value', 'period', 'unit', 'subject', 'value_basis', 'accounting_basis']
        ), JSON_THROW_ON_ERROR));
    }

    public function prove(Document $document, array $data, array $proof): array
    {
        $path = $proof['pdf_table_path'] ?? null;
        $value = $proof['roles']['value'][0] ?? null;
        if (! is_array($path) || ! is_array($value)
            || ($path['set_id'] ?? null) !== ($data['source_provenance_set_id'] ?? $path['set_id'])) {
            return ['roles' => [], 'sources' => [], 'rejection' => 'table_path_unverified'];
        }
        $row = $path['row'];
        $headers = $path['headers'];
        $caption = count($path['captions']) === 1 ? $path['captions'][0] : null;
        $entity = count($path['entities']) === 1 ? $path['entities'][0] : null;
        $sources = [];
        $roles = [];
        $add = static function (array $anchor, string $role) use (&$sources): void {
            if (! is_int($anchor['start_offset'] ?? null) || ! is_int($anchor['end_offset'] ?? null)
                || ! is_string($anchor['text'] ?? null)) {
                return;
            }
            $id = $anchor['anchor_id'];
            $sources[$id] ??= ['span_id' => $id, 'quote' => $anchor['text'],
                'page' => $anchor['page'], 'start_offset' => $anchor['start_offset'],
                'end_offset' => $anchor['end_offset'], 'roles' => [],
                'source_graph_derived' => true];
            if (! in_array($role, $sources[$id]['roles'], true)) {
                $sources[$id]['roles'][] = $role;
            }
        };
        $add($value, 'value');
        $add($row, 'metric');
        if ($this->normalizer->fold((string) ($data['value'] ?? ''))
            !== $this->normalizer->fold((string) $value['text'])) {
            return ['roles' => [], 'sources' => array_values($sources),
                'rejection' => 'value_source_mismatch'];
        }
        $roles[] = 'value';
        if ($this->normalizer->fold((string) ($data['label'] ?? ''))
            !== $this->normalizer->fold((string) $row['text'])) {
            return ['roles' => $roles, 'sources' => array_values($sources),
                'rejection' => 'concept_source_mismatch'];
        }
        $roles[] = 'metric';
        if ($headers === [] || count($headers) > 4
            || count(array_unique(array_column($headers, 'anchor_id'))) !== count($headers)
            || count(array_unique(array_column($headers, 'page'))) !== 1
            || $headers[0]['page'] !== $value['page']) {
            return ['roles' => $roles, 'sources' => array_values($sources),
                'rejection' => 'period_path_ambiguous'];
        }
        $rawPeriod = implode(' ', array_column($headers, 'text'));
        $period = $this->periods->parse($rawPeriod);
        $claimed = $this->periods->parse((string) ($data['period'] ?? ''));
        if ($period === null || $claimed === null || $period->label !== $claimed->label) {
            return ['roles' => $roles, 'sources' => array_values($sources),
                'rejection' => 'period_source_mismatch'];
        }
        foreach ($headers as $header) {
            $add($header, 'period');
        }
        $roles[] = 'period';

        $unit = trim((string) ($data['unit'] ?? ''));
        $sourceUnit = $caption['text'] ?? '';
        $perShare = preg_match('/\bper\s+(?:common\s+)?share\b/iu', $row['text']) === 1;
        $sourceScale = PdfCaptionScale::forRow($sourceUnit, $row['text']);
        $claimMeasurement = $this->measurements->parse((string) ($data['value'] ?? ''), $unit,
            (string) ($data['label'] ?? ''));
        $printedPercent = $unit === '%' && str_ends_with(trim((string) $value['text']), '%')
            && $claimMeasurement?->canonicalUnit === '%' && $claimMeasurement->currency === null;
        if ($printedPercent) {
            $add($value, 'unit');
            $roles[] = 'unit';
            $roles[] = 'scale';
        } elseif ($caption === null || $claimMeasurement === null || $sourceScale === null
            || ($perShare && preg_match('/except\s+per\s+share/iu', $sourceUnit) !== 1)) {
            return ['roles' => $roles, 'sources' => array_values($sources),
                'rejection' => 'unit_source_mismatch'];
        }
        if (! $printedPercent) {
            $expectedScale = $perShare ? 1.0 : match ($sourceScale) {
                'thousand' => 1e3, 'million' => 1e6, 'billion' => 1e9,
                default => null,
            };
            if ($expectedScale === null || $claimMeasurement->scale !== $expectedScale) {
                return ['roles' => $roles, 'sources' => array_values($sources),
                    'rejection' => 'scale_source_mismatch'];
            }
            $add($caption, 'scale');
            $roles[] = 'scale';
            $markers = $path['currencies'] ?? [];
            $sourceCurrency = implode(' ', [$sourceUnit, ...array_column($headers, 'text'),
                $row['text'], (string) $value['text'], ...array_column($markers, 'text')]);
            $codes = $this->measurements->explicitCurrencyCodes($sourceCurrency);
            $claimedCurrency = $claimMeasurement->currency;
            // A qualified dollar notation is a stronger claim than the bare symbol.
            // The measurement parser groups both under '$', so retain the written qualifier here.
            preg_match_all('/(?<!\p{L})(?:\p{L}{1,4})\$/u', $unit, $qualifiedMarkers);
            foreach ($qualifiedMarkers[0] as $qualifiedMarker) {
                if (! str_contains($sourceCurrency, $qualifiedMarker)) {
                    return ['roles' => $roles, 'sources' => array_values($sources),
                        'rejection' => 'currency_source_mismatch'];
                }
            }
            if ($claimedCurrency === null || ($claimedCurrency === '$'
                ? count($markers) !== 2 || $markers[0]['text'] !== '$'
                    || $markers[1]['text'] !== '$' || $codes !== []
                : count($codes) !== 1 || $codes[0] !== $claimedCurrency)) {
                return ['roles' => $roles, 'sources' => array_values($sources),
                    'rejection' => 'currency_source_mismatch'];
            }
            $add($caption, 'unit');
            foreach ($markers as $marker) {
                $add($marker, 'currency');
            }
            if ($claimedCurrency !== '$') {
                foreach ([$caption, ...$headers, $row, $value] as $anchor) {
                    if ($this->measurements->isoCurrency($anchor['text'] ?? '') === $claimedCurrency) {
                        $add($anchor, 'currency');
                        break;
                    }
                }
            }
            $roles[] = 'unit';
            $roles[] = 'currency';
        }

        $basisText = $row['text'].' '.($caption['text'] ?? '');
        $sourceBasis = preg_match('/\bnon[\s-]?gaap\b/iu', $basisText) === 1
            ? 'non-gaap' : (preg_match('/\bgaap\b/iu', $basisText) === 1 ? 'gaap' : null);
        $claimedBasis = mb_strtolower(trim((string) ($data['accounting_basis'] ?? '')));
        $legacyClaim = mb_strtolower(trim((string) ($data['value_basis'] ?? '')));
        if (($claimedBasis !== '' && $sourceBasis !== $claimedBasis)
            || (in_array($legacyClaim, ['gaap', 'non-gaap'], true)
                && $sourceBasis !== $legacyClaim)) {
            return ['roles' => $roles, 'sources' => array_values($sources),
                'rejection' => 'accounting_basis_unverified'];
        }
        if ($sourceBasis !== null) {
            $add(preg_match('/\b(?:non[\s-]?gaap|gaap)\b/iu', $row['text']) === 1
                ? $row : $caption, 'basis');
            $roles[] = 'basis';
        }

        $owner = $this->owners->resolveStructured($document, $path, $value,
            (string) ($data['subject'] ?? ''));
        if ($owner === null) {
            $owner = $this->owners->resolveReportOwner($document, $path, $value,
                (string) ($data['subject'] ?? ''));
        }
        if ($owner === null) {
            return ['roles' => $roles, 'sources' => array_values($sources),
                'rejection' => 'governing_owner_unverified'];
        }
        $ownerAnchors = $owner['relationship'] === 'structured_report_owner'
            ? [$path['report_cover'][0], $path['report_footer'][0]] : [$entity];
        foreach ($ownerAnchors as $anchor) {
            $add($anchor, 'subject');
            $add($anchor, 'governing_owner');
        }
        $roles[] = 'subject';
        foreach ([$row, $value] as $anchor) {
            $add($anchor, 'governing_owner');
        }
        $roles[] = 'governing_owner';

        return ['roles' => $roles, 'sources' => array_values($sources),
            'rejection' => null, 'raw_period' => $rawPeriod,
            'source_unit' => $sourceUnit, 'governing_owner' => $owner,
            'verified_subject' => $owner['entity'],
            'accounting_basis' => $sourceBasis,
            'source_extraction_version' => app(EvidenceGrounding::class)->version($document)];
    }
}
