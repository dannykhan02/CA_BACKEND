<?php

namespace Tests\Feature;

use App\Exceptions\PaystackInitializationException;
use App\Models\CreditPurchase;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentExceptionLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_payment_logging_fixture';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => self::SECRET]);
        Http::preventStrayRequests();
    }

    private function buyer(): User
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        Sanctum::actingAs($user);

        return $user;
    }

    private function purchase(User $buyer): CreditPurchase
    {
        return $buyer->currentWorkspace->purchases()->create([
            'user_id' => $buyer->id,
            'paystack_reference' => 'credits-'.Str::uuid(),
            'documents_purchased' => 100,
            'amount_kobo_or_cents' => 200000,
            'currency' => 'KES',
            'status' => 'pending',
        ]);
    }

    private function identifiers(CreditPurchase $purchase): array
    {
        return [
            'purchase_id' => $purchase->id,
            'reference' => $purchase->paystack_reference,
            'user_id' => $purchase->user_id,
            'workspace_id' => $purchase->workspace_id,
        ];
    }

    private function assertOnlyErrorLogs(array $entries): void
    {
        // Exact contexts prohibit extra bodies, headers, customer data,
        // exception objects or traces, and the count prohibits duplicate logs.
        Log::shouldHaveReceived('error')->times(count($entries));
        foreach ($entries as [$message, $context]) {
            Log::shouldHaveReceived('error')->with($message, $context)->once();
        }
        foreach (['warning', 'critical', 'alert', 'emergency', 'notice', 'info', 'debug', 'log'] as $level) {
            Log::shouldNotHaveReceived($level);
        }
    }

    #[DataProvider('initializationFailures')]
    public function test_initialization_http_failure_logs_safe_outcome_without_retry_or_crediting(int $status, string $state, bool $rejected): void
    {
        $buyer = $this->buyer();
        Http::fake(['https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => false,
            'message' => 'Provider echoed '.self::SECRET,
            'data' => ['customer' => ['email' => 'private-payer@example.com'],
                'authorization_code' => 'AUTH_private', 'access_code' => 'private-checkout',
                'card' => ['number' => '4242424242424242', 'cvv' => '123']],
        ], $status, ['x-paystack-signature' => 'private-signature'])]);

        Log::spy();
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])
            ->assertStatus(502)->assertExactJson([
                'success' => false,
                'message' => 'Unable to initialize payment. Please try again later.',
                'errors' => [],
            ]);
        $purchase = $buyer->currentWorkspace->purchases()->sole();
        $this->assertSame($state, $purchase->status);
        $this->assertNull($purchase->paystack_response);
        $this->assertSame(0, $buyer->currentWorkspace->credits->documents_remaining);
        $this->assertSame(0, $buyer->currentWorkspace->credits->documents_purchased_total);
        Http::assertSentCount(1);
        $this->assertOnlyErrorLogs([['Paystack initialization failed.', $this->identifiers($purchase) + [
            'definitively_rejected' => $rejected,
            'exception_class' => PaystackInitializationException::class,
            'exception_message' => 'Unable to initialize payment. Please try again later.',
        ]]]);
    }

    public static function initializationFailures(): array
    {
        return [
            'definitive rejection' => [400, 'failed', true],
            'ambiguous server failure' => [503, 'pending', false],
        ];
    }

    public function test_initialization_connection_failure_logs_two_correlated_entries_without_retry(): void
    {
        $buyer = $this->buyer();
        Http::fake(['https://api.paystack.co/transaction/initialize' => Http::failedConnection(
            'cURL error 28: Operation timed out for https://api.paystack.co/transaction/initialize?access_code=private-checkout'
            .' Authorization: Bearer '.self::SECRET
            ."\nCustomer: private-payer@example.com; card_number=4242424242424242; x-paystack-signature=private-signature"
        )]);

        Log::spy();
        $this->postJson('/api/workspace/credits/purchases', ['package' => 'documents-100'])
            ->assertStatus(502)->assertExactJson([
                'success' => false,
                'message' => 'Unable to initialize payment. Please try again later.',
                'errors' => [],
            ]);
        $purchase = $buyer->currentWorkspace->purchases()->sole();
        $this->assertSame('pending', $purchase->status);
        $this->assertNull($purchase->paystack_response);
        $this->assertSame(0, $buyer->currentWorkspace->credits->documents_remaining);
        $this->assertSame(0, $buyer->currentWorkspace->credits->documents_purchased_total);
        Http::assertSentCount(1);
        $this->assertOnlyErrorLogs([
            ['Paystack initialization connection failed.', $this->identifiers($purchase) + [
                'exception_class' => ConnectionException::class,
                'exception_message' => 'cURL error 28: Operation timed out for https://api.paystack.co/[redacted] Authorization=[redacted]',
            ]],
            ['Paystack initialization failed.', $this->identifiers($purchase) + [
                'definitively_rejected' => false,
                'exception_class' => PaystackInitializationException::class,
                'exception_message' => 'Unable to initialize payment. Please try again later.',
            ]],
        ]);
    }

    #[DataProvider('verificationFailures')]
    public function test_verification_failure_logs_safe_diagnostic_without_retry_or_mutation(bool $connectionFailure): void
    {
        $buyer = $this->buyer();
        $purchase = $this->purchase($buyer);
        Http::fake(['https://api.paystack.co/transaction/verify/*' => $connectionFailure
            ? Http::failedConnection('cURL error 28: Operation timed out; x-paystack-signature: private-signature'
                .' Authorization: Bearer '.self::SECRET.'; customer: private-payer@example.com')
            : Http::response(['status' => false, 'message' => 'Private provider response',
                'data' => ['customer' => ['email' => 'private-payer@example.com'], 'access_code' => 'private-checkout']], 503)]);

        Log::spy();
        $this->postJson('/api/workspace/credits/purchases/'.$purchase->paystack_reference.'/verify')
            ->assertStatus(502)->assertExactJson([
                'success' => false,
                'message' => 'We could not verify your payment yet. Please try again shortly.',
                'errors' => [],
            ]);
        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertNull($purchase->fresh()->paystack_response);
        $this->assertSame(0, $buyer->currentWorkspace->credits->documents_remaining);
        $this->assertSame(0, $buyer->currentWorkspace->credits->documents_purchased_total);
        Http::assertSentCount(1);
        $this->assertOnlyErrorLogs([['Paystack verification unavailable.', $this->identifiers($purchase) + [
            'exception_class' => $connectionFailure ? ConnectionException::class : \RuntimeException::class,
            'exception_message' => $connectionFailure
                ? 'cURL error 28: Operation timed out; x-paystack-signature=[redacted]'
                : 'Payment verification is temporarily unavailable.',
        ]]]);
    }

    public static function verificationFailures(): array
    {
        return ['connection failure' => [true], 'provider failure' => [false]];
    }

    public function test_signed_malformed_webhook_logs_only_json_diagnostic_and_does_not_mutate_purchase(): void
    {
        $buyer = $this->buyer();
        $purchase = $this->purchase($buyer);
        $raw = '{"event":"charge.success","data":{"reference":"'.$purchase->paystack_reference
            .'","customer":{"email":"private-payer@example.com"},"authorization_code":"AUTH_private","card_number":"4242424242424242"';
        $headers = [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, self::SECRET),
        ];

        Log::spy();
        $this->call('POST', '/api/paystack/webhook', [], [], [], $headers, $raw)
            ->assertStatus(400)->assertExactJson([
                'success' => false, 'message' => 'Invalid webhook payload.', 'errors' => [],
            ]);
        // The same malformed bytes with a bad signature must never reach parsing.
        $headers['HTTP_X_PAYSTACK_SIGNATURE'] = 'invalid';
        $this->call('POST', '/api/paystack/webhook', [], [], [], $headers, $raw)
            ->assertStatus(401)->assertExactJson([
                'success' => false, 'message' => 'Invalid Paystack signature.', 'errors' => [],
            ]);
        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertNull($purchase->fresh()->paystack_response);
        $this->assertSame(0, $buyer->currentWorkspace->credits->documents_remaining);
        $this->assertSame(0, $buyer->currentWorkspace->credits->documents_purchased_total);
        Http::assertNothingSent();
        $this->assertOnlyErrorLogs([['Paystack webhook JSON parsing failed.', [
            'operation' => 'paystack.webhook.parse',
            'exception_class' => \JsonException::class,
            'exception_message' => 'Syntax error',
        ]]]);
    }
}
