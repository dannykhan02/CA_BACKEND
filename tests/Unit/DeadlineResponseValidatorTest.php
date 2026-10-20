<?php

namespace Tests\Unit;

use App\Services\AI\ResponseValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The production shape: failed_jobs 109 (2026-10-20, queue "extraction") lost a
 * whole analysis — insights text, no findings, no anchors, credit consumed —
 * because deadlines[1] claimed date_type "explicit" for "by 30 November", a
 * date the document never completes. One unusable deadline must never discard
 * the deadlines beside it.
 */
class DeadlineResponseValidatorTest extends TestCase
{
    private function deadline(array $overrides = []): array
    {
        return array_replace([
            'deadline_type' => 'filing', 'title' => 'Annual return',
            'description' => 'File the annual return.', 'due_date' => '2026-07-14',
            'date_type' => 'explicit', 'relative_text' => null,
            'confidence' => 0.8, 'evidence' => 'due 14 July 2026',
        ], $overrides);
    }

    public function test_an_explicit_deadline_without_a_due_date_does_not_discard_the_others(): void
    {
        $good = $this->deadline();
        $result = (new ResponseValidator)->validateDeadlines(['deadlines' => [
            $good,
            $this->deadline(['title' => 'Submission', 'description' => 'Submit by the stated month.',
                'due_date' => null, 'relative_text' => 'by 30 November',
                'evidence' => 'must be submitted by 30 November']),
        ]]);

        self::assertCount(2, $result['deadlines']);
        self::assertSame($good, $result['deadlines'][0]);
        self::assertSame('relative', $result['deadlines'][1]['date_type']);
        self::assertNull($result['deadlines'][1]['due_date']);
        self::assertSame('by 30 November', $result['deadlines'][1]['relative_text']);
        self::assertSame(['explicit_without_due_date_converted_to_relative' => 1],
            $result['_deadline_repairs']);
    }

    public function test_a_recurring_obligation_keeps_its_own_words_and_never_gains_a_date(): void
    {
        $result = (new ResponseValidator)->validateDeadlines(['deadlines' => [
            $this->deadline(['title' => 'Monthly report', 'description' => 'Report each month.',
                'due_date' => null, 'relative_text' => 'by the 5th of each month',
                'evidence' => 'reports are due by the 5th of each month']),
        ]]);

        self::assertSame('relative', $result['deadlines'][0]['date_type']);
        self::assertNull($result['deadlines'][0]['due_date']);
        self::assertSame('by the 5th of each month', $result['deadlines'][0]['relative_text']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusableDates(): iterable
    {
        yield 'missing due_date' => [['due_date' => null]];
        yield 'empty due_date' => [['due_date' => '']];
        yield 'not a string' => [['due_date' => 20261130]];
        yield 'wrong format' => [['due_date' => '30 November 2026']];
        yield 'partial date' => [['due_date' => '2026-11']];
        yield 'impossible calendar date' => [['due_date' => '2026-11-31']];
    }

    #[DataProvider('unusableDates')]
    public function test_an_explicit_deadline_with_no_usable_date_and_no_relative_text_is_dropped_alone(array $overrides): void
    {
        $good = $this->deadline();
        $result = (new ResponseValidator)->validateDeadlines(['deadlines' => [
            $good, $this->deadline($overrides + ['title' => 'Unusable']),
        ]]);

        self::assertSame([$good], $result['deadlines']);
        self::assertSame(['deadlines' => 1], $result['_optional_items_dropped']);
        self::assertSame(['deadlines' => ['explicit_without_due_date' => 1]],
            $result['_optional_drop_reasons']);
    }

    #[DataProvider('unusableDates')]
    public function test_an_unusable_date_with_relative_text_becomes_relative_and_is_kept(array $overrides): void
    {
        $result = (new ResponseValidator)->validateDeadlines(['deadlines' => [
            $this->deadline($overrides + ['relative_text' => 'within 14 days of receipt']),
        ]]);

        self::assertCount(1, $result['deadlines']);
        self::assertSame('relative', $result['deadlines'][0]['date_type']);
        self::assertNull($result['deadlines'][0]['due_date']);
        self::assertArrayNotHasKey('_optional_items_dropped', $result);
    }

    public function test_a_valid_explicit_deadline_is_returned_untouched_with_no_metadata(): void
    {
        $good = $this->deadline();
        $result = (new ResponseValidator)->validateDeadlines(['deadlines' => [$good]]);

        self::assertSame([$good], $result['deadlines']);
        self::assertArrayNotHasKey('_optional_items_dropped', $result);
        self::assertArrayNotHasKey('_deadline_repairs', $result);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unusableDeadlines(): iterable
    {
        yield 'no title' => [['title' => ''], 'missing_title'];
        yield 'title is not a string' => [['title' => ['Annual return']], 'missing_title'];
        yield 'no description' => [['description' => '   '], 'missing_description'];
        yield 'date_type outside the vocabulary' => [['date_type' => 'recurring'], 'invalid_date_type'];
        yield 'relative with a due_date' => [['date_type' => 'relative',
            'relative_text' => 'within 14 days'], 'due_date_for_non_explicit_type'];
        yield 'inferred with a due_date' => [['date_type' => 'inferred'], 'due_date_for_non_explicit_type'];
        yield 'confidence is not numeric' => [['confidence' => 'high'], 'invalid_confidence'];
        yield 'confidence out of range' => [['confidence' => 1.4], 'invalid_confidence'];
        yield 'no evidence' => [['evidence' => ''], 'missing_evidence'];
        yield 'not an object' => [[], 'not_an_object'];
    }

    #[DataProvider('unusableDeadlines')]
    public function test_any_other_unusable_deadline_is_dropped_alone_with_its_reason(array $overrides, string $reason): void
    {
        $good = $this->deadline();
        $result = (new ResponseValidator)->validateDeadlines(['deadlines' => [
            $good, $overrides === [] ? 'not an object' : $this->deadline($overrides),
        ]]);

        self::assertSame([$good], $result['deadlines']);
        self::assertSame(['deadlines' => 1], $result['_optional_items_dropped']);
        self::assertSame(['deadlines' => [$reason => 1]], $result['_optional_drop_reasons']);
    }

    public function test_the_kept_deadlines_stay_a_list_and_an_empty_response_is_valid(): void
    {
        $result = (new ResponseValidator)->validateDeadlines(['deadlines' => [
            'not an object', $this->deadline(),
        ]]);

        self::assertSame([0], array_keys($result['deadlines']));
        self::assertSame([], (new ResponseValidator)->validateDeadlines(['deadlines' => []])['deadlines']);
    }

    public function test_a_missing_or_non_array_deadlines_envelope_is_still_a_clean_failure(): void
    {
        foreach ([[], ['deadlines' => null], ['deadlines' => 'none']] as $decoded) {
            try {
                (new ResponseValidator)->validateDeadlines($decoded);
                self::fail('Expected the envelope to be rejected.');
            } catch (\RuntimeException $e) {
                self::assertSame('AI response missing required array field: deadlines', $e->getMessage());
            }
        }
    }
}
