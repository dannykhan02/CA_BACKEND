<?php

namespace Tests\Feature;

use App\Models\TrackedItem;
use App\Models\User;
use App\Notifications\EmailChangedNotification;
use App\Notifications\EmailChangeVerificationNotification;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\TrackedDeadlineReminder;
use App\Notifications\VerificationCodeNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocIntelMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'DocIntel',
            'mail.default' => 'array',
            'mail.reply_to.address' => 'support@docintel.co.ke',
            'mail.reply_to.name' => 'DocIntel Support',
        ]);
    }

    public static function notifications(): array
    {
        return [
            'email change verification' => [new EmailChangeVerificationNotification('123456'), true],
            'email changed' => [new EmailChangedNotification('new@example.com'), true],
            'password changed' => [new PasswordChangedNotification, true],
            'password reset' => [new PasswordResetNotification('token', 'person@example.com'), true],
            'broker password reset' => [new ResetPasswordNotification('https://example.com/reset'), true],
            'verification code' => [new VerificationCodeNotification('123456'), true],
            'welcome' => [new WelcomeNotification, false],
            'deadline reminder' => [new TrackedDeadlineReminder(new TrackedItem(['title' => 'Review filing'])), false],
        ];
    }

    #[DataProvider('notifications')]
    public function test_notification_renders_with_docintel_branding($notification, bool $hasSpecificSupportLine): void
    {
        $user = new User(['email' => 'person@example.com', 'full_name' => 'Test User']);
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        $this->assertStringContainsString('DocIntel', $mail->subject);
        $this->assertStringContainsString('DocIntel', $html);
        $this->assertStringNotContainsString(' CA ', $html);
        $this->assertStringNotContainsString('Laravel', $html);
        $this->assertDoesNotMatchRegularExpression('/\{\{|\}\}|@(?:if|endif|slot)|<x-mail::/', $html);
        $this->assertStringContainsString('support@docintel.co.ke', $html);

        $lines = implode(' ', array_map('strval', [...$mail->introLines, ...$mail->outroLines]));
        if ($hasSpecificSupportLine) {
            $this->assertStringContainsString('support@docintel.co.ke', $lines);
        } else {
            $this->assertStringNotContainsString('support@docintel.co.ke', $lines);
        }
    }

    public function test_sent_notification_has_global_reply_to_header(): void
    {
        $user = new User(['email' => 'person@example.com']);
        Notification::sendNow($user, new PasswordChangedNotification);

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $replyTo = $messages->first()->getOriginalMessage()->getReplyTo();
        $this->assertCount(1, $replyTo);
        $this->assertSame('support@docintel.co.ke', $replyTo[0]->getAddress());
        $this->assertSame('DocIntel Support', $replyTo[0]->getName());
    }
}
