<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Jobs\AnalyzeEmbeddedVisualsJob;
use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateEmbeddingsJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\ScanUploadedFileJob;
use App\Models\AuditLog;
use App\Models\CreditPurchase;
use App\Models\Document;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\VerificationCodeNotification;
use App\Services\AnthropicClient;
use App\Services\DocumentTextExtractor;
use App\Services\Ocr\OcrEngineResolver;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonalWorkspaceJourneyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_journey_fixture';

    private const TEXT = 'Example Ltd earned revenue of 100. File the annual report by September 30.';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('documents');
        Http::preventStrayRequests();
        Http::fake(['https://api.paystack.co/transaction/initialize' => fn (ClientRequest $request) => Http::response([
            'status' => true, 'data' => ['reference' => $request['reference'],
                'authorization_url' => 'https://checkout.paystack.com/journey', 'access_code' => 'fixture'],
        ])]);
        config([
            'queue.default' => 'sync',
            'services.paystack.secret_key' => self::SECRET,
            'credits.referral_reward_documents' => 10,
        ]);

        // Pause at the scanner boundary to inspect the accepted upload. The
        // extraction, four-job batch, summary callback and insights then run
        // through Laravel's real sync queue, including chain/batch bookkeeping.
        // Malware scanning and enrichment after Ready are outside this journey.
        Queue::fake([ScanUploadedFileJob::class, AnalyzeEmbeddedVisualsJob::class, GenerateEmbeddingsJob::class]);
    }

    public function test_signup_document_report_purchase_and_referral_form_one_scoped_journey(): void
    {
        $owner = $this->signup('owner@example.com', '203.0.113.10', 'owner-browser');
        $this->assertSame(WorkspaceType::Personal, $owner->currentWorkspace->type);
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $owner->current_workspace_id, 'user_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('trial_grants', [
            'email' => $owner->email, 'ip_address' => '203.0.113.10', 'fingerprint' => 'owner-browser',
        ]);
        $this->assertBalance($owner, 10, 0);

        $this->mockExtraction(self::TEXT);
        $this->mockIntelligence();
        $document = $this->upload($owner);
        $this->assertSame('Processing', $document->status);
        // Personal skips the security-classification input/review gate; AI
        // document-type classification remains part of the four-job batch.
        $this->assertSame('Internal', $document->classification);
        Storage::disk('documents')->assertExists($document->file_path);
        $this->assertBalance($owner, 10, 0); // Acceptance is not consumption.

        $this->continueUploadChain($document);
        $document->refresh();
        $this->assertSame(self::TEXT, $document->extracted_text);
        $this->assertSame('Ready', $document->status);
        $this->assertSame(100, $document->progress);
        $this->assertNotNull($document->credit_accounted_at);
        $this->assertBalance($owner, 9, 0);
        $this->assertNoReview($document);

        $batchId = DB::table('job_batches')->where('name', 'document-intelligence:'.$document->id)->sole()->id;
        $batch = Bus::findBatch($batchId);
        $this->assertSame(4, $batch->totalJobs);
        $this->assertSame(0, $batch->pendingJobs);
        $this->assertSame(0, $batch->failedJobs);
        $this->assertTrue($batch->finished());
        foreach (['extract', 'document_type', 'entities', 'risks', 'deadlines', 'document_summary', 'ai_analysis'] as $stage) {
            $this->assertDatabaseHas('processing_jobs', [
                'document_id' => $document->id, 'stage' => $stage, 'status' => 'completed',
            ]);
        }
        $this->assertSame('financial_report', $document->documentTypeClassification->document_type);
        $this->assertSame('Example Ltd', $document->entities()->sole()->value);
        $this->assertSame('Late filing', $document->risks()->sole()->title);
        $this->assertSame('2026-09-30', $document->deadlines()->sole()->due_date->toDateString());
        $this->assertDatabaseHas('document_kpis', ['document_id' => $document->id, 'label' => 'Revenue', 'value' => '100']);
        $this->assertDatabaseHas('document_chart_points', ['workspace_id' => $owner->current_workspace_id, 'label' => 'Q1', 'value' => 100]);

        // One delivery replay is enough here; exhaustive reprocessing and
        // webhook races remain in the focused credit/purchase test classes.
        (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        $this->assertBalance($owner, 9, 0);
        $this->getJson('/api/documents/'.$document->id)->assertOk()->assertJsonPath('data.status', 'Ready');
        $this->postJson('/api/documents/'.$document->id.'/report-generated', ['note' => 'Downloaded Word report'])
            ->assertOk()->assertJsonPath('message', 'Report generation recorded.');
        $audit = AuditLog::where('action', 'document.report_generated')->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame($owner->current_workspace_id, $audit->workspace_id);
        $this->assertSame(Document::class, $audit->auditable_type);
        $this->assertSame($document->id, $audit->auditable_id);
        $this->assertSame(['note' => 'Downloaded Word report'], $audit->metadata);
        $this->assertBalance($owner, 9, 0);

        $ownerPurchase = $this->initializePurchase($owner);
        $this->assertBalance($owner, 9, 0);
        $this->completePurchase($ownerPurchase);
        $this->assertBalance($owner, 109, 100);

        $code = $this->getJson('/api/referrals/my-code')->assertOk()->json('data.code');
        $friend = $this->signup('friend@example.com', '203.0.113.11', 'friend-browser', $code);
        $referral = Referral::where('referred_user_id', $friend->id)->sole();
        $this->assertSame('pending', $referral->status);
        $this->assertTrue($referral->reward_eligible);
        $this->assertBalance($friend, 10, 0, $owner->current_workspace_id);
        $this->assertBalance($owner, 109, 100, $friend->current_workspace_id);

        $friendPurchase = $this->initializePurchase($friend);
        $this->assertSame('pending', $referral->fresh()->status);
        $this->assertBalance($friend, 10, 0, $owner->current_workspace_id);
        $this->assertBalance($owner, 109, 100, $friend->current_workspace_id);
        $this->completePurchase($friendPurchase);
        $this->completePurchase($friendPurchase); // Duplicate provider delivery.
        $this->assertSame('rewarded', $referral->fresh()->status);
        $this->assertSame(10, $referral->fresh()->reward_documents);
        $this->assertSame($owner->current_workspace_id, $referral->fresh()->rewarded_workspace_id);
        $this->assertBalance($friend, 110, 100, $owner->current_workspace_id);
        $this->assertBalance($owner, 119, 100, $friend->current_workspace_id);
        $this->getJson('/api/referrals/my-code')->assertOk()->assertJsonPath('data.summary', [
            'signups' => 1, 'rewarded' => 1, 'credits_earned' => 10,
        ]);
        Http::assertSentCount(2); // Only the two mocked checkout initializations.
    }

    public function test_reused_trial_signal_leads_to_zero_balance_and_upload_payment_gate(): void
    {
        $owner = $this->signup('owner@example.com', '203.0.113.10', 'shared-browser');
        $second = $this->signup('second@example.com', '203.0.113.11', 'shared-browser');
        // One representative signal links signup to upload billing. The full
        // email/IP/fingerprint matrix belongs to WorkspaceCreditsTest.
        $this->assertDatabaseCount('trial_grants', 1);
        $this->assertBalance($owner, 10, 0, $second->current_workspace_id);
        $this->assertBalance($second, 0, 0, $owner->current_workspace_id);
        $this->postJson('/api/documents', ['file' => UploadedFile::fake()->create('blocked.pdf', 1, 'application/pdf')])
            ->assertStatus(402);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('documents')->allFiles());
        Queue::assertNothingPushed();
        $this->assertBalance($second, 0, 0);
    }

    public static function pipelineFailures(): array
    {
        return ['OCR unavailable' => ['ocr'], 'AI analysis failed' => ['analysis']];
    }

    #[DataProvider('pipelineFailures')]
    public function test_failed_personal_upload_preserves_trial_credits_without_entering_review(string $failure): void
    {
        $owner = $this->signup('owner@example.com', '203.0.113.10', 'owner-browser');
        $this->mockExtraction($failure === 'ocr' ? '' : self::TEXT);
        if ($failure === 'ocr') {
            $this->mock(OcrEngineResolver::class, fn ($mock) => $mock->shouldReceive('resolve')
                ->once()->andThrow(new \RuntimeException('OCR provider unavailable')));
            $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldNotReceive('extractDocumentInsights'));
        } else {
            $this->mockIntelligence(analysisFails: true);
        }
        $document = $this->upload($owner);
        $this->assertBalance($owner, 10, 0);
        $this->continueUploadChain($document);
        $this->assertSame('Failed', $document->fresh()->status);
        $this->assertNull($document->fresh()->credit_accounted_at);
        $this->assertDatabaseHas('processing_jobs', [
            'document_id' => $document->id, 'stage' => $failure === 'ocr' ? 'ocr_check' : 'ai_analysis',
            'status' => 'failed',
        ]);
        $this->assertNoReview($document);
        $this->assertBalance($owner, 10, 0);
        Http::assertNothingSent();
    }

    private function signup(string $email, string $ip, string $fingerprint, ?string $referralCode = null): User
    {
        $this->app['auth']->forgetGuards();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/signup', [
            'email' => $email, 'full_name' => 'Journey User',
            'password' => 'StrongPassword1!', 'password_confirmation' => 'StrongPassword1!',
            'fingerprint' => $fingerprint, 'referral_code' => $referralCode,
        ])->assertCreated()->assertJsonStructure(['data' => ['user', 'token']]);
        $user = User::where('email', $email)->sole();
        $notification = Notification::sent($user, VerificationCodeNotification::class)->sole();
        $this->postJson('/api/auth/verify-email', ['email' => $email, 'code' => $notification->code])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);

        return $user->fresh();
    }

    private function assertBalance(User $user, int $remaining, int $purchased, ?string $foreignWorkspaceId = null): void
    {
        Sanctum::actingAs($user->fresh());
        $url = '/api/workspace/credits'.($foreignWorkspaceId ? '?workspace_id='.$foreignWorkspaceId : '');
        $this->getJson($url)->assertOk()->assertJsonPath('data.documents_remaining', $remaining)
            ->assertJsonPath('data.documents_purchased_total', $purchased);
    }

    private function upload(User $owner): Document
    {
        Sanctum::actingAs($owner->fresh());
        $response = $this->postJson('/api/documents', [
            'file' => UploadedFile::fake()->create('journey.pdf', 1, 'application/pdf'),
        ])->assertStatus(202);

        return Document::findOrFail($response->json('data.id'));
    }

    private function continueUploadChain(Document $document): void
    {
        $scan = Queue::pushed(ScanUploadedFileJob::class, fn ($job) => $job->documentId === $document->id)->sole();
        $scan->assertHasChain([
            ExtractDocumentTextJob::class, GenerateInsightsJob::class,
            AnalyzeEmbeddedVisualsJob::class, GenerateEmbeddingsJob::class,
        ]);
        $scan->dispatchNextJobInChain();
    }

    private function assertNoReview(Document $document): void
    {
        $this->assertDatabaseMissing('audit_logs', [
            'auditable_id' => $document->id, 'action' => 'document.needs_review',
        ]);
    }

    private function mockExtraction(string $text): void
    {
        $this->mock(DocumentTextExtractor::class, function ($mock) use ($text) {
            $mock->shouldReceive('extractPdfText')->once()->andReturn($text);
            $mock->shouldReceive('countPdfPages')->once()->andReturn(1);
        });
    }

    private function mockIntelligence(bool $analysisFails = false): void
    {
        $this->mock(AnthropicClient::class, function ($mock) use ($analysisFails) {
            $mock->shouldReceive('classifyDocumentType')->once()->andReturn([
                'document_type' => 'financial_report', 'confidence' => 0.9,
                'reasoning' => 'Contains revenue.', 'prompt_version' => 1,
            ]);
            $mock->shouldReceive('extractDocumentEntities')->once()->andReturn([
                'entities' => [['entity_type' => 'organization', 'value' => 'Example Ltd', 'confidence' => 0.9]],
                'prompt_version' => 1,
            ]);
            $mock->shouldReceive('detectDocumentRisks')->once()->andReturn([
                'risks' => [['title' => 'Late filing', 'description' => 'Filing deadline.',
                    'severity' => 'low', 'confidence' => 0.9, 'evidence' => 'File the annual report.']],
                'prompt_version' => 1,
            ]);
            $mock->shouldReceive('detectDocumentDeadlines')->once()->andReturn([
                'deadlines' => [['title' => 'Annual filing', 'description' => 'File the report.',
                    'due_date' => '2026-09-30', 'date_type' => 'explicit',
                    'confidence' => 0.9, 'evidence' => 'By September 30.']],
                'prompt_version' => 1,
            ]);
            $mock->shouldReceive('generateDocumentSummary')->once()->withArgs(function ($json) {
                $data = json_decode($json, true);

                // The real batch's finally callback must see all four persisted results.
                return $data['document_type'] === 'financial_report'
                    && $data['entities'][0]['value'] === 'Example Ltd'
                    && $data['risks'][0]['title'] === 'Late filing'
                    && $data['deadlines'][0]['due_date'] === '2026-09-30';
            })->andReturn([
                'executive_summary' => 'Annual revenue and filing obligations.', 'key_findings' => ['Revenue is 100.'],
                'critical_risks' => [], 'upcoming_deadlines' => [], 'important_entities' => [],
                'recommended_attention' => [], 'prompt_version' => 1,
            ]);
            $insights = $mock->shouldReceive('extractDocumentInsights')->once();
            if ($analysisFails) {
                $insights->andThrow(new \RuntimeException('AI analysis unavailable'));
            } else {
                $insights->andReturn([
                    'kpis' => [['label' => 'Revenue', 'value' => '100']],
                    'charts' => [['type' => 'bar', 'title' => 'Revenue', 'description' => 'Q1 revenue.',
                        'data' => [['label' => 'Q1', 'value' => 100]]]],
                    'insights' => ['Revenue is 100.'],
                ]);
            }
        });
    }

    private function initializePurchase(User $buyer): CreditPurchase
    {
        Sanctum::actingAs($buyer->fresh());
        $response = $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])
            ->assertCreated()->assertJsonPath('data.authorization_url', 'https://checkout.paystack.com/journey');
        $purchase = CreditPurchase::where('paystack_reference', $response->json('data.reference'))->sole();
        $this->assertSame($buyer->id, $purchase->user_id);
        $this->assertSame($buyer->current_workspace_id, $purchase->workspace_id);
        $this->assertSame('pending', $purchase->status);

        return $purchase;
    }

    private function completePurchase(CreditPurchase $purchase): void
    {
        $body = json_encode(['event' => 'charge.success', 'data' => [
            'reference' => $purchase->paystack_reference, 'status' => 'success',
            'amount' => $purchase->amount_kobo_or_cents, 'currency' => $purchase->currency,
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->app['auth']->forgetGuards(); // Provider webhook carries no user session.
        $this->call('POST', '/api/paystack/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET),
        ], $body)->assertOk();
        $this->assertSame('completed', $purchase->fresh()->status);
    }
}
