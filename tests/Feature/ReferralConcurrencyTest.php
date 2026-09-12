<?php

namespace Tests\Feature;

use App\Models\CreditPurchase;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\User;
use App\Models\WorkspaceCredit;
use App\Services\ReferralService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReferralConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        // Committed worker fixtures must not leak into later RefreshDatabase
        // classes, which otherwise only roll back their own transactions.
        $this->truncateTablesForAllConnections();
        parent::tearDown();
    }

    /** Run two committed workers, following WorkspaceCreditConcurrencyTest. */
    private function concurrently(callable $operation): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl for independent workers.');
        }
        $directory = sys_get_temp_dir().'/referral-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        DB::disconnect();
        $children = [];
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    touch($directory.'/ready-'.$index);
                    $deadline = microtime(true) + 10;
                    while (! file_exists($directory.'/ready-'.(1 - $index))) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Other worker did not reach the barrier.');
                        }
                        usleep(10000);
                    }
                    $operation($index);
                    exit(0);
                } catch (\Throwable $error) {
                    file_put_contents($directory.'/error-'.$index, $error->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        try {
            $success = true;
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $success = $success && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0;
            }
            $this->assertTrue($success, implode("\n", array_map('file_get_contents', glob($directory.'/error-*'))));
        } finally {
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public function test_simultaneous_first_code_requests_create_one_code(): void
    {
        $owner = User::factory()->create();
        $this->concurrently(function () use ($owner) {
            $code = app(ReferralService::class)->codeFor($owner);
            $this->assertSame($owner->id, $code->user_id);
        });
        $this->assertSame(1, ReferralCode::where('user_id', $owner->id)->count());
    }

    public function test_simultaneous_matching_signups_only_one_is_eligible_and_gets_trial(): void
    {
        $owner = User::factory()->create();
        $code = app(ReferralService::class)->codeFor($owner)->code;
        $this->concurrently(function (int $index) use ($code) {
            DB::transaction(function () use ($index, $code) {
                $user = User::factory()->create(['email' => "concurrent-$index@example.com"]);
                app(WorkspaceService::class)->createPersonalWorkspaceFor($user, '203.0.113.'.(10 + $index), 'shared-browser', $code);
            });
        });
        $this->assertDatabaseCount('referrals', 2);
        $this->assertDatabaseCount('trial_grants', 1);
        $this->assertSame(1, Referral::where('reward_eligible', true)->count());
        $this->assertSame(1, Referral::where('ineligible_reason', 'trial_abuse_signal_match')->count());
        $this->assertSame(10, (int) WorkspaceCredit::sum('documents_remaining'));
    }

    public function test_simultaneous_first_purchases_in_different_workspaces_reward_once(): void
    {
        $secret = 'sk_test_concurrent_referrals';
        config(['services.paystack.secret_key' => $secret, 'credits.referral_reward_documents' => 17]);
        $owner = User::factory()->create();
        $rewardWorkspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($owner);
        $buyer = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($buyer, '203.0.113.10', 'browser', app(ReferralService::class)->codeFor($owner)->code);
        $purchases = [];
        foreach ([0, 1] as $index) {
            $workspace = $index === 0 ? $buyer->currentWorkspace : app(WorkspaceService::class)->createPersonalWorkspaceFor($buyer);
            $purchases[] = $workspace->purchases()->create([
                'user_id' => $buyer->id, 'paystack_reference' => 'credits-'.Str::uuid(),
                'documents_purchased' => 100, 'amount_kobo_or_cents' => 200000, 'currency' => 'KES',
            ]);
        }

        $this->concurrently(function (int $index) use ($purchases, $secret) {
            $body = json_encode(['event' => 'charge.success', 'data' => [
                'reference' => $purchases[$index]->paystack_reference,
                'status' => 'success', 'amount' => 200000, 'currency' => 'KES',
            ]]);
            $this->call('POST', '/api/paystack/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $secret),
            ], $body)->assertOk();
        });

        $this->assertSame(2, CreditPurchase::where('user_id', $buyer->id)->where('status', 'completed')->count());
        $this->assertSame('rewarded', Referral::sole()->status);
        $this->assertSame(17, $rewardWorkspace->credits->documents_remaining);
        $this->assertSame(200, (int) WorkspaceCredit::sum('documents_purchased_total'));
    }
}
