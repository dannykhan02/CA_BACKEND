<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Additional hardening (found on re-review, not in original audit): every
 * notification class in this app `use Queueable` but none previously
 * `implements ShouldQueue`. The trait alone does nothing — it only supplies
 * queue-configuration methods (onQueue(), delay(), etc.); without the
 * interface, Laravel dispatches the notification's mail SYNCHRONOUSLY
 * inside the current request/job. That means signup(), resendVerification(),
 * forgotPassword(), etc. all block on an outbound SMTP/API call to the mail
 * provider before returning a response — and a slow or down mail provider
 * degrades or fails an otherwise-successful auth action.
 */
class VerificationCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $code)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify Your Email')
            ->greeting('Hello!')
            ->line('Use the code below to verify your email address.')
            ->line(new \Illuminate\Support\HtmlString(
                '<div style="font-size: 32px; font-weight: 700; letter-spacing: 6px; text-align: center; background: #FDF3E7; color: #854F0B; border-radius: 8px; padding: 18px; margin: 20px 0;">' . $this->code . '</div>'
            ))
            ->line('This code will expire shortly.')
            ->line('If you did not request this, no further action is required.');
    }
}
