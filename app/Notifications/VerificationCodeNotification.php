<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerificationCodeNotification extends Notification
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
                '<div style="font-size: 32px; font-weight: 700; letter-spacing: 6px; text-align: center; background: #eff6ff; color: #2563eb; border-radius: 8px; padding: 18px; margin: 20px 0;">' . $this->code . '</div>'
            ))
            ->line('This code will expire shortly.')
            ->line('If you did not request this, no further action is required.');
    }
}
