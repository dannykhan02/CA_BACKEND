<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\Subscription;
use App\Models\SubscriptionUsagePeriod;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCredit;
use App\Services\AiCredits\CreditAccountant;
use App\Services\CreditLedger;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditsUsageEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-10-15 12:00:00';

    private User $user;

    private Workspace $workspace;

    private static int $hashes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_credits.enabled' => true]);
        $this->travelTo(Carbon::parse(self::NOW));
        $this->workspace = $this->makeWorkspace();
    }

    private function makeWorkspace(bool $act = true): Workspace
    {
        $user = User::factory()->create();
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        if ($act) {
            $this->user = $user->fresh();
            Sanctum::actingAs($this->user);
        }

        return $workspace;
    }

    private function row(string $reference, string $unit, string $direction, int $amount, ?string $relatedType = null, ?string $relatedId = null, ?Workspace $workspace = null, ?string $at = null): void
    {
        if ($at !== null) {
            $this->travelTo(Carbon::parse($at));
        }
        app(CreditLedger::class)->record(($workspace ?? $this->workspace)->id, $reference, $unit, $direction, $amount, 'internal operator reason', $relatedType, $relatedId);
        $this->travelTo(Carbon::parse(self::NOW));
    }

    private function document(string $name = 'Report.pdf', string $type = 'PDF'): Document
    {
        return Document::create(['name' => $name, 'type' => $type, 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public', 'year' => 2026,
            'workspace_id' => $this->workspace->id, 'uploaded_by' => $this->user->id, 'file_hash' => hash('sha256', 'usage-fixture'.self::$hashes++)]);
    }

    /** @return array{0:Subscription,1:SubscriptionUsagePeriod} an active starter subscription whose current period began on 2026-10-01 */
    private function subscribe(int $allowed = 100, int $used = 0): array
    {
        $sub = Subscription::create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'plan_key' => 'starter', 'billing_interval' => 'monthly',
            'status' => 'active', 'current_period_start' => '2026-10-01 00:00:00', 'current_period_end' => '2026-11-01 00:00:00']);

        return [$sub, $this->period($sub, '2026-10-01', '2026-11-01', $allowed, $used)];
    }

    private function period(Subscription $sub, string $start, string $end, ?int $allowed, int $used): SubscriptionUsagePeriod
    {
        return $sub->periods()->create(['period_start' => $start, 'period_end' => $end, 'documents_allowed' => 20, 'comparisons_allowed' => 5,
            'storage_bytes' => 1073741824, 'ai_credits_allowed' => $allowed, 'ai_credits_used' => $used]);
    }

    private function qaRun(float $cost, string $at = self::NOW, string $purpose = 'document_qa', ?Document $document = null): void
    {
        $document ??= $this->document('Asked.pdf');
        DocumentAiRun::create(['workspace_id' => $this->workspace->id, 'document_id' => $document->id, 'purpose' => $purpose, 'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5',
            'estimated_cost_usd' => $cost, 'status' => 'success', 'request_attempt' => 1, 'created_at' => Carbon::parse($at)]);
    }

    private function usage(string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/credits/usage'.$query);
    }

    private function keysOf(array $data): array
    {
        $keys = [];
        $walk = function ($value) use (&$walk, &$keys) {
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    $keys[] = (string) $key;
                    $walk($child);
                }
            }
        };
        $walk($data);

        return $keys;
    }

    public function test_unauthenticated_requests_get_401(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/credits/usage')->assertStatus(401);
    }

    public function test_a_user_who_is_not_a_member_of_the_current_workspace_gets_403(): void
    {
        $other = $this->makeWorkspace(false);
        $this->user->forceFill(['current_workspace_id' => $other->id])->save();
        Sanctum::actingAs($this->user->fresh());

        $this->usage()->assertStatus(403);
    }

    public function test_the_range_is_validated(): void
    {
        $this->usage('?range=cycle')->assertOk();
        $this->usage('?range=6m')->assertOk();
        $this->usage('?range=1y')->assertStatus(422);
    }

    public function test_disabled_mode_returns_enabled_false_and_empty_sections(): void
    {
        $this->row('ai_debit:q1', CreditAccountant::SAVED, 'debit', 5);
        [, $period] = $this->subscribe();
        $period->update(['ai_credits_allowed' => null]);

        $this->usage()->assertOk()->assertJsonPath('data', ['enabled' => false, 'plan' => null, 'limits' => null, 'on_hold' => [], 'totals' => null,
            'series' => ['range' => 'cycle'], 'top_documents' => [], 'by_type' => [], 'saved' => null]);

        config(['ai_credits.enabled' => false]);
        $this->usage('?range=6m')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.series', ['range' => '6m']);
    }

    public function test_the_daily_series_is_zero_filled_and_splits_charged_from_returned(): void
    {
        $document = $this->document();
        $saved = CreditAccountant::SAVED;
        $this->row('ai_debit:q1', $saved, 'debit', 5, 'document', $document->id, null, '2026-10-13 09:00:00');
        $this->row('ai_release_unused:q1', $saved, 'release', 2, 'document', $document->id, null, '2026-10-13 09:00:01');
        $this->row('ai_release:q2', $saved, 'release', 3, 'document', $document->id, null, '2026-10-15 08:00:00');
        $this->row('ai_debit:q3', CreditAccountant::MONTHLY, 'debit', 4, 'qa', 'not-a-document', null, '2026-10-15 10:00:00');
        // Not part of the series: reservations, grants and a charge older than the 30-day window.
        $this->row('ai_reserve:q1', $saved, 'reserve', 9, 'document', $document->id, null, '2026-10-14 09:00:00');
        $this->row('referral:r1', $saved, 'credit', 30, 'referral', null, null, '2026-10-14 09:00:00');
        $this->row('ai_debit:old', $saved, 'debit', 77, 'document', $document->id, null, '2026-09-10 09:00:00');

        $data = $this->usage()->assertOk()->json('data');
        $days = collect($data['series']['days'])->keyBy('date');

        $this->assertSame('cycle', $data['series']['range']);
        $this->assertCount(6, $data['series']['months']);
        $this->assertCount(30, $days);
        $this->assertSame('2026-09-16', $data['series']['days'][0]['date']);
        $this->assertSame('2026-10-15', $data['series']['days'][29]['date']);
        $this->assertSame(['date' => '2026-10-13', 'charged' => 5, 'returned' => 2], $days['2026-10-13']);
        $this->assertSame(['date' => '2026-10-14', 'charged' => 0, 'returned' => 0], $days['2026-10-14']);
        $this->assertSame(['date' => '2026-10-15', 'charged' => 4, 'returned' => 3], $days['2026-10-15']);
        $this->assertSame(0, $days['2026-09-16']['charged']);
        $this->assertSame(9, $data['totals']['charged']);
        $this->assertSame(5, $data['totals']['returned']);
        $this->assertSame(14, $data['totals']['held']);
        $this->assertSame(1, $data['totals']['documents']);
    }

    public function test_a_subscription_cycle_starts_at_the_period_start(): void
    {
        $this->subscribe();
        $this->row('ai_debit:q1', CreditAccountant::MONTHLY, 'debit', 5, null, null, null, '2026-09-30 09:00:00');
        $this->row('ai_debit:q2', CreditAccountant::MONTHLY, 'debit', 6, null, null, null, '2026-10-02 09:00:00');

        $data = $this->usage()->assertOk()->json('data');

        $this->assertCount(15, $data['series']['days']);
        $this->assertSame('2026-10-01', $data['series']['days'][0]['date']);
        $this->assertSame(6, $data['totals']['charged']);
        $this->assertSame('starter', $data['plan']['key']);
        $this->assertSame('monthly', $data['plan']['interval']);
        $this->assertSame('2026-10-01', Carbon::parse($data['plan']['cycle_start'])->toDateString());
        $this->assertSame('2026-11-01', Carbon::parse($data['plan']['resets_at'])->toDateString());
    }

    public function test_top_documents_are_limited_to_five_and_retries_of_one_document_are_grouped(): void
    {
        $saved = CreditAccountant::SAVED;
        $retried = $this->document('Retried.pdf', 'DOCX');
        foreach ([2, 3, 4] as $i => $amount) {
            $this->row('ai_debit:retry'.$i, $saved, 'debit', $amount, 'document', $retried->id);
        }
        $this->row('ai_release_unused:retry0', $saved, 'release', 1, 'document', $retried->id);
        foreach ([1, 2, 3, 4, 5, 6] as $amount) {
            $other = $this->document('Doc'.$amount.'.pdf', 'PDF');
            $this->row('ai_debit:other'.$amount, $saved, 'debit', $amount, 'ocr', $other->id);
        }
        $failed = $this->document('Failed.pdf');
        $this->row('ai_release:failed', $saved, 'release', 8, 'document', $failed->id);

        $data = $this->usage()->assertOk()->json('data');
        $top = $data['top_documents'];

        $this->assertCount(5, $top);
        $this->assertSame(['Retried.pdf', 'Doc6.pdf', 'Doc5.pdf', 'Doc4.pdf', 'Doc3.pdf'], array_column($top, 'document_name'));
        $this->assertSame(['document_id' => $retried->id, 'document_name' => 'Retried.pdf', 'type' => 'DOCX', 'charged' => 9, 'held' => 10, 'returned' => 1], $top[0]);
        $this->assertSame(8, $data['totals']['documents']);
        $this->assertSame(9 + 21, $data['totals']['charged']);
        $this->assertSame([['type' => 'PDF', 'documents' => 7, 'charged' => 21], ['type' => 'DOCX', 'documents' => 1, 'charged' => 9]], $data['by_type']);
    }

    public function test_a_non_uuid_related_id_never_reaches_the_documents_query(): void
    {
        $this->row('ai_debit:q1', CreditAccountant::SAVED, 'debit', 2, 'document', 'x');
        $this->row('ai_debit:q2', CreditAccountant::SAVED, 'debit', 3, 'qa', (string) \Illuminate\Support\Str::uuid());
        DB::enableQueryLog();

        $data = $this->usage()->assertOk()->json('data');

        $documentQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => preg_match('/\bfrom\s+"?documents"?(\s|$)/i', $sql));
        $this->assertCount(0, $documentQueries);
        $this->assertSame([], $data['top_documents']);
        $this->assertSame([], $data['by_type']);
        $this->assertSame(0, $data['totals']['documents']);
        $this->assertSame(5, $data['totals']['charged']);
    }

    public function test_months_for_a_subscription_are_the_last_six_periods_oldest_first(): void
    {
        [$sub] = $this->subscribe(100, 40);
        foreach (range(1, 6) as $i) {
            $start = Carbon::parse('2026-10-01')->subMonthsNoOverflow($i);
            $this->period($sub, $start->toDateString(), $start->copy()->addMonthNoOverflow()->toDateString(), 100 - $i, $i * 10);
        }

        $months = $this->usage('?range=6m')->assertOk()->json('data.series');

        $this->assertSame('6m', $months['range']);
        $this->assertArrayNotHasKey('days', $months);
        $this->assertCount(6, $months['months']);
        $this->assertSame(['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'],
            array_map(fn ($m) => Carbon::parse($m['start'])->toDateString(), $months['months']));
        $this->assertSame([95, 96, 97, 98, 99, 100], array_column($months['months'], 'allowed'));
        $this->assertSame([50, 40, 30, 20, 10, 40], array_column($months['months'], 'used'));
        $this->assertSame([false, false, false, false, false, true], array_column($months['months'], 'current'));
        $this->assertSame(['start', 'end', 'allowed', 'used', 'current'], array_keys($months['months'][0]));
    }

    public function test_months_without_a_subscription_are_calendar_months_from_ledger_charges(): void
    {
        $saved = CreditAccountant::SAVED;
        $this->row('ai_debit:m1', $saved, 'debit', 5, null, null, null, '2026-10-03 09:00:00');
        $this->row('ai_debit:m2', $saved, 'debit', 7, null, null, null, '2026-08-20 09:00:00');
        $this->row('ai_debit:m3', $saved, 'debit', 2, null, null, null, '2026-08-21 09:00:00');
        $this->row('ai_debit:m4', $saved, 'debit', 99, null, null, null, '2026-03-01 09:00:00');
        $this->row('ai_release_unused:m1', $saved, 'release', 50, null, null, null, '2026-10-03 09:00:01');

        $months = $this->usage('?range=6m')->assertOk()->json('data.series.months');

        $this->assertSame(['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'],
            array_map(fn ($m) => Carbon::parse($m['start'])->toDateString(), $months));
        $this->assertSame([0, 0, 0, 9, 0, 5], array_column($months, 'used'));
        $this->assertSame([null, null, null, null, null, null], array_column($months, 'allowed'));
        $this->assertSame([false, false, false, false, false, true], array_column($months, 'current'));
    }

    public function test_questions_are_a_count_for_free_workspaces(): void
    {
        config(['ai_credits.qa.max_per_workspace_per_day' => 100, 'ai_credits.qa.free_max_per_workspace_per_day' => 5]);
        $this->qaRun(0.5);
        $this->qaRun(0.25, '2026-10-15 00:00:01');
        $this->qaRun(0.25, '2026-10-14 23:59:59');
        $this->qaRun(0.25, self::NOW, 'entities');

        $questions = $this->usage()->assertOk()->json('data.limits.questions');

        $this->assertSame(['mode' => 'count', 'used' => 2, 'limit' => 5, 'resets_at' => Carbon::parse('2026-10-16 00:00:00')->toIso8601String()], $questions);
    }

    public function test_questions_are_a_capped_percent_for_paid_workspaces_and_no_money_field_appears(): void
    {
        $this->subscribe();
        config(['ai_credits.qa.max_cost_usd_per_workspace_per_day' => 2.0, 'ai_credits.qa.plans.starter.max_cost_usd_per_day' => 2.0]);
        $this->qaRun(0.5);
        $this->qaRun(5.0, '2026-10-14 10:00:00');

        $response = $this->usage()->assertOk();
        $questions = $response->json('data.limits.questions');

        $this->assertSame(['mode' => 'percent', 'percent' => 25, 'resets_at' => Carbon::parse('2026-10-16 00:00:00')->toIso8601String()], $questions);

        $this->qaRun(9.0);
        $this->assertSame(100, $this->usage()->assertOk()->json('data.limits.questions.percent'));

        $json = $this->usage()->getContent();
        foreach (['usd', 'cost', 'model', 'quote', 'price', 'dollar', 'claude-'] as $needle) {
            $this->assertStringNotContainsString($needle, strtolower($json));
        }
        foreach ($this->keysOf($this->usage('?range=6m')->json('data')) as $key) {
            $this->assertDoesNotMatchRegularExpression('/usd|cost|model|quote|price|amount/i', $key);
        }
    }

    public function test_saved_sources_and_used(): void
    {
        $saved = CreditAccountant::SAVED;
        $document = $this->document();
        WorkspaceCredit::where('workspace_id', $this->workspace->id)->update(['ai_credits_remaining' => 42, 'documents_remaining' => 0]);
        $this->row('referral:r1', $saved, 'credit', 30);
        $this->row('admin_grant:g1', $saved, 'credit', 50);
        $this->row('trial_carryover:'.$this->workspace->id, $saved, 'credit', 9);
        $this->row('ai_debit:s1', $saved, 'debit', 5, 'document', $document->id);
        $this->row('ai_debit:s2', $saved, 'debit', 3, 'qa', 'q');
        $this->row('saved_adjust:w:a1', $saved, 'debit', 40);
        $this->row('ai_debit:s3', CreditAccountant::MONTHLY, 'debit', 99, 'document', $document->id);

        $data = $this->usage()->assertOk()->json('data');

        $this->assertSame(['balance' => 42, 'sources' => [['source' => 'referral', 'credits' => 30], ['source' => 'trial_carryover', 'credits' => 9], ['source' => 'bonus', 'credits' => 50]], 'used' => 8], $data['saved']);
        $this->assertSame(['balance' => 42], $data['limits']['saved']);
    }

    public function test_unused_last_cycle_is_the_previous_closed_period_floored_at_zero(): void
    {
        $this->assertNull($this->usage()->assertOk()->json('data.totals.unused_last_cycle'));

        [$sub] = $this->subscribe(100, 10);
        $this->assertNull($this->usage()->json('data.totals.unused_last_cycle'));

        $previous = $this->period($sub, '2026-09-01', '2026-10-01', 100, 30);
        $this->assertSame(70, $this->usage()->json('data.totals.unused_last_cycle'));

        $previous->update(['ai_credits_used' => 120]);
        $this->assertSame(0, $this->usage()->json('data.totals.unused_last_cycle'));

        $previous->update(['ai_credits_allowed' => null]);
        $this->assertNull($this->usage()->json('data.totals.unused_last_cycle'));
    }

    public function test_limits_show_monthly_held_trial_and_open_holds(): void
    {
        [, $period] = $this->subscribe(100, 30);
        $document = $this->document('Held.pdf');
        $trialBefore = (int) DB::table('credit_ledger')->where('workspace_id', $this->workspace->id)->where('unit', CreditAccountant::TRIAL)->where('direction', 'credit')->sum('amount');
        $this->row('trial:t1', CreditAccountant::TRIAL, 'credit', 20);
        WorkspaceCredit::where('workspace_id', $this->workspace->id)->update(['trial_credits_remaining' => 12, 'trial_credits_expires_at' => now()->addDays(5)]);
        $now = now();
        DB::table('billing_operations')->insert(['kind' => 'document', 'resource_id' => $document->id, 'workspace_id' => $this->workspace->id, 'status' => 'reserved',
            'attempt_number' => 1, 'amount_reserved' => 6, 'funding_bucket' => CreditAccountant::MONTHLY, 'usage_period_id' => $period->id, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('billing_operations')->insert(['kind' => 'document', 'resource_id' => '00000000-0000-0000-0000-0000000000aa', 'workspace_id' => $this->workspace->id,
            'status' => 'completed', 'attempt_number' => 1, 'amount_reserved' => 9, 'funding_bucket' => CreditAccountant::MONTHLY, 'usage_period_id' => $period->id, 'created_at' => $now, 'updated_at' => $now]);

        $data = $this->usage()->assertOk()->json('data');

        $this->assertSame(['allowed' => 100, 'used' => 30, 'held' => 6], $data['limits']['monthly']);
        $this->assertSame(['remaining' => 12, 'total' => $trialBefore + 20], array_diff_key($data['limits']['trial'], ['expires_at' => 1]));
        $this->assertSame(now()->addDays(5)->toDateString(), Carbon::parse($data['limits']['trial']['expires_at'])->toDateString());
        $this->assertSame([['document_id' => $document->id, 'document_name' => 'Held.pdf', 'credits' => 6]], $data['on_hold']);
    }

    public function test_another_workspaces_rows_never_appear(): void
    {
        $other = $this->makeWorkspace(false);
        $this->row('ai_debit:other', CreditAccountant::SAVED, 'debit', 99, null, null, $other);
        $this->row('ai_debit:mine', CreditAccountant::SAVED, 'debit', 1);

        $this->assertSame(1, $this->usage()->assertOk()->json('data.totals.charged'));
    }
}
