<?php

namespace Tests\Feature;

use App\Models\ComparisonIdentityLink;
use App\Models\DocumentComparison;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\Comparisons\ComparisonIdentityService;
use App\Services\DocumentComparisonService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsIdentityPair;
use Tests\TestCase;

class ComparisonIdentityConcurrencyTest extends TestCase
{
    use BuildsIdentityPair;
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE users, workspaces RESTART IDENTITY CASCADE');
        parent::tearDown();
    }

    private function race(callable $work): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'Identity races require pcntl.');
        DB::disconnect();
        $children = [];
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    $work($index);
                    DB::disconnect();
                    exit(0);
                } catch (\Throwable $error) {
                    fwrite(STDERR, $error::class.': '.$error->getMessage()."\n");
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
    }

    public function test_two_authorized_users_confirm_concurrently_and_only_one_version_wins(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $user->currentWorkspace->update(['type' => 'Organization']);
        $other = User::factory()->create(['role' => 'Reviewer', 'current_workspace_id' => $user->current_workspace_id]);
        WorkspaceMember::create(['workspace_id' => $user->current_workspace_id, 'user_id' => $other->id, 'role' => 'Reviewer', 'joined_at' => now()]);
        $key = $comparison->metadata['identity_context']['candidates'][0]['key'];
        $this->race(function ($index) use ($user, $other, $comparison, $key) {
            try {
                app(ComparisonIdentityService::class)->decide(User::findOrFail($index ? $other->id : $user->id), $comparison, $key, 'confirmed', 0, 'Reviewed both original provisions.');
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }
            }
        });
        $this->assertSame(1, ComparisonIdentityLink::count());
        $this->assertSame(1, ComparisonIdentityLink::sole()->version);
        $this->assertSame(1, DB::table('comparison_identity_events')->count());
    }

    public function test_concurrent_confirmation_and_rejection_do_not_overwrite_each_other(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $key = $comparison->metadata['identity_context']['candidates'][0]['key'];
        $this->race(function ($index) use ($user, $comparison, $key) {
            try {
                app(ComparisonIdentityService::class)->decide($user->fresh(), $comparison, $key, $index ? 'rejected' : 'confirmed', 0, 'Source review decision.');
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }
            }
        });
        $this->assertSame(1, ComparisonIdentityLink::sole()->version);
        $this->assertSame(1, DB::table('comparison_identity_events')->count());
    }

    public function test_concurrent_confirmation_and_revocation_use_expected_version(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $key = $comparison->metadata['identity_context']['candidates'][0]['key'];
        app(ComparisonIdentityService::class)->decide($user, $comparison, $key, 'confirmed', 0, 'Initial reviewed correspondence.');
        $this->race(function ($index) use ($user, $comparison, $key) {
            try {
                app(ComparisonIdentityService::class)->decide($user->fresh(), $comparison, $key, $index ? 'revoked' : 'confirmed', 1, 'Updated source review decision.');
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }
            }
        });
        $this->assertSame(2, ComparisonIdentityLink::sole()->version);
        $this->assertSame(2, DB::table('comparison_identity_events')->count());
    }

    public function test_two_matching_comparison_requests_share_one_immutable_context_and_fingerprint(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $key = $comparison->metadata['identity_context']['candidates'][0]['key'];
        app(ComparisonIdentityService::class)->decide($user, $comparison, $key, 'confirmed', 0, 'Reviewed source correspondence.');
        $this->race(fn () => app(DocumentComparisonService::class)->create($user->fresh(), $a->id, $b->id));
        $this->assertSame(2, DocumentComparison::count());
        $this->assertSame(1, DocumentComparison::where('id', '!=', $comparison->id)->count());
    }
}
