<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VerificationCode;
use App\Notifications\VerificationCodeNotification;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Resend\Client;
use Resend\Contracts\Client as ClientContract;
use Resend\Exceptions\ErrorException;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

class VerificationEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        config([
            'auth.developer.expose_verification_code' => false,
            'queue.default' => 'redis',
            'mail.default' => 'resend',
            'mail.from.address' => 'verification@example.com',
            'mail.from.name' => 'CA Document Intelligence',
        ]);

        // Capture the asynchronous boundary only. Tests execute the serialized
        // job through the real notification channel, mailer and Resend SDK.
        // MockHandler replaces HTTP at the socket boundary; no email is sent.
        Queue::fake();
        $this->resendResponses(new Response(200, ['Content-Type' => 'application/json'], '{"id":"email-fixture"}'));
    }

    public function test_signup_renders_and_submits_the_code_through_the_resend_transport(): void
    {
        $this->signup();
        $job = $this->verificationJob();
        $this->assertSame(['mail'], $job->channels);
        $this->assertCount(0, $this->requests); // Signup has not called the provider.

        $user = User::where('email', 'new-user@example.com')->sole();
        $code = VerificationCode::where('user_id', $user->id)->sole();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $job->notification->code);
        $this->assertTrue(Hash::check($job->notification->code, $code->code));
        $this->assertTrue($code->expires_at->equalTo(now()->addMinutes(15)));

        // A worker resolves its own mail configuration when consuming the job.
        // Updating only the web service's sender cannot fix a stale worker.
        config(['mail.from.address' => 'worker@example.com']);
        $this->deliver($job);

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.resend.com/emails', (string) $request->getUri());
        $email = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $sender = Address::create($email['from']);
        $this->assertSame('worker@example.com', $sender->getAddress());
        $this->assertSame('CA Document Intelligence', $sender->getName());
        $this->assertSame(['new-user@example.com'], $email['to']);
        $this->assertSame('Verify Your Email', $email['subject']);
        foreach (['html', 'text'] as $format) {
            $this->assertStringContainsString($job->notification->code, $email[$format]);
            $this->assertStringContainsString('Use the code below to verify your email address.', $email[$format]);
            $this->assertStringContainsString('This code will expire shortly.', $email[$format]);
        }
    }

    #[DataProvider('senderRejections')]
    public function test_provider_rejection_surfaces_in_the_worker_after_signup_succeeds(string $sender, string $reason): void
    {
        config(['mail.from.address' => $sender]);
        $this->resendResponses(new Response(403, ['Content-Type' => 'application/json'], json_encode([
            'statusCode' => 403, 'name' => 'validation_error', 'message' => $reason,
        ], JSON_THROW_ON_ERROR)));

        $this->signup();
        $this->assertCount(0, $this->requests);

        try {
            $this->deliver($this->verificationJob());
            $this->fail('Resend rejection must escape the notification job for the worker to record failure.');
        } catch (TransportException $exception) {
            $this->assertSame('Request to the Resend API failed. Reason: '.$reason, $exception->getMessage());
            $this->assertInstanceOf(ErrorException::class, $exception->getPrevious());
            $this->assertSame(403, $exception->getPrevious()->getErrorCode());
        }

        $this->assertCount(1, $this->requests);
        $this->assertDatabaseHas('users', ['email' => 'new-user@example.com', 'email_verified_at' => null]);
        $this->assertDatabaseCount('verification_codes', 1);
    }

    public static function senderRejections(): array
    {
        return [
            'restricted testing sender' => ['onboarding@resend.dev',
                'You can only send testing emails to your own email address (owner@example.com). To send emails to other recipients, please verify a domain at resend.com/domains, and change the `from` address to an email using this domain.'],
            'unverified placeholder domain' => ['noreply@yourdomain.com',
                'The yourdomain.com domain is not verified. Please, add and verify your domain on https://resend.com/domains'],
        ];
    }

    public function test_resend_submits_a_replacement_code_through_the_same_transport(): void
    {
        $this->resendResponses(
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"first-fixture"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"second-fixture"}'),
        );
        $this->signup();
        $oldRecordId = VerificationCode::sole()->id;
        $this->deliver($this->verificationJob());

        $this->postJson('/api/auth/resend-verification', ['email' => 'new-user@example.com'])
            ->assertOk()->assertJsonMissingPath('data.verification_code');
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        $replacement = $this->verificationJob();
        $this->assertCount(1, $this->requests); // Resend also waits for a worker.
        $this->assertDatabaseMissing('verification_codes', ['id' => $oldRecordId]);
        $this->assertTrue(Hash::check($replacement->notification->code, VerificationCode::sole()->code));

        $this->deliver($replacement);
        $this->assertCount(2, $this->requests);
        $email = json_decode((string) $this->requests[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['new-user@example.com'], $email['to']);
        $this->assertStringContainsString($replacement->notification->code, $email['html']);
        $this->assertDatabaseCount('verification_codes', 1);
    }

    private function signup(): void
    {
        $this->postJson('/api/auth/signup', [
            'full_name' => 'New User',
            'email' => 'New-User@Example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ])->assertCreated()->assertJsonPath('message', 'Account created successfully.')
            ->assertJsonMissingPath('data.verification_code');
    }

    private function verificationJob(): SendQueuedNotifications
    {
        $job = Queue::pushed(SendQueuedNotifications::class)->last();
        $this->assertInstanceOf(SendQueuedNotifications::class, $job);
        $this->assertInstanceOf(VerificationCodeNotification::class, $job->notification);

        return $job;
    }

    private function deliver(SendQueuedNotifications $job): void
    {
        unserialize(serialize($job))->handle(app(ChannelManager::class));
    }

    private function resendResponses(Response ...$responses): void
    {
        $handler = HandlerStack::create(new MockHandler($responses));
        $handler->push(Middleware::history($this->requests));
        $client = new Client(new HttpTransporter(
            new HttpClient(['handler' => $handler]),
            BaseUri::from('api.resend.com'),
            Headers::withAuthorization(ApiKey::from('re_test_fixture')),
        ));
        $this->app->instance(ClientContract::class, $client);
        Mail::purge('resend');
    }
}
