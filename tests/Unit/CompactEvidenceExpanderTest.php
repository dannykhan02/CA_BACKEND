<?php

namespace Tests\Unit;

use App\Services\AI\Incremental\CompactEvidenceExpander as Codec;
use App\Services\AI\Incremental\EvidenceSchema;
use App\Services\AI\Incremental\EvidenceGrounding;
use Tests\TestCase;

class CompactEvidenceExpanderTest extends TestCase
{
    private function saved(): array
    {
        return array_merge(glob(base_path('docs/intelligence-v2/diagnostics/unicef-4call/*.response.json')),
            glob(base_path('docs/intelligence-v2/diagnostics/experiment-flags/paid-study/*.response.json')));
    }

    private function canonical(string $file): array
    {
        return json_decode(json_decode(file_get_contents($file), true)['content'][0]['text'], true);
    }

    public function test_schema_is_the_frozen_canonical_mapping(): void
    {
        self::assertSame(Codec::CANONICAL_SCHEMA_SHA256, Codec::canonicalHash());
        $schema = Codec::schema();
        self::assertSame('1bc072a68427391d49cccc99dbdec1f7214558db1f92fe0215159a7c248404f1',
            hash('sha256', json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $item = $schema['properties']['m']['items'];
        self::assertCount(10, array_diff(array_keys($item['properties']), $item['required']));
        self::assertCount(0, array_filter($item['properties'], fn ($rule) => isset($rule['anyOf']) || is_array($rule['type'] ?? null)));
        self::assertCount(count(EvidenceSchema::extraction(EvidenceGrounding::SPANS)['properties']['records']['items']['properties']), $item['properties']);
    }

    public function test_all_912_saved_records_round_trip_and_fail_closed(): void
    {
        $count = 0;
        foreach ($this->saved() as $file) {
            $canonical = $this->canonical($file);
            $count += count($canonical['records']);
            self::assertSame($canonical, Codec::expand(Codec::encode($canonical)), $file);
        }
        self::assertSame(912, $count);
        $row = Codec::encode(['records' => [$this->canonical($this->saved()[0])['records'][0]]])['m'][0];
        foreach (['v', 'e', 'cf'] as $key) {
            $bad = $row; unset($bad[$key]);
            try { Codec::expand(['m' => [$bad]]); self::fail('Missing field accepted'); }
            catch (\InvalidArgumentException $e) { self::assertStringContainsString('compact_expansion_error', $e->getMessage()); }
        }
        foreach ([['bogus', 'x'], ['v', 1], ['e', 'E001'], ['cf', true], ['u', 2]] as [$key, $value]) {
            $bad = $row; $bad[$key] = $value;
            $this->expectFailure($bad);
        }
    }

    private function expectFailure(array $row): void
    {
        try { Codec::expand(['m' => [$row]]); self::fail('Malformed row accepted'); }
        catch (\InvalidArgumentException $e) { self::assertStringContainsString('compact_expansion_error', $e->getMessage()); }
    }

    public function test_every_byte_cut_returns_exact_complete_prefix(): void
    {
        foreach ($this->saved() as $file) {
            $records = array_slice($this->canonical($file)['records'], 0, 2);
            $compact = Codec::encode(['records' => $records]);
            $raw = json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $first = strpos($raw, '},{');
            $second = strlen($raw) - 3;
            for ($cut = 0, $length = strlen($raw); $cut <= $length; $cut++) {
                $expected = $cut > $second ? 2 : ($cut > $first ? 1 : 0);
                $rows = Codec::salvage(substr($raw, 0, $cut));
                $emitted = json_decode(json_encode(array_slice($compact['m'], 0, $expected), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
                self::assertSame($emitted, $rows, basename($file).' byte '.$cut);
            }
        }
    }
}
