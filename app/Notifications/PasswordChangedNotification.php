<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** @see EmailChangedNotification — same queue-latency note applies (security alert). */
class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Password Was Changed')
            ->line('This is a confirmation that your password was just changed.')
            ->line('If you made this change, no action is needed.')
            ->line(new \Illuminate\Support\HtmlString(
                '<div style="background: #FBEAEA; color: #9E2A2B; border-left: 4px solid #9E2A2B; border-radius: 6px; padding: 14px 16px; margin: 16px 0; font-size: 14px;">If you did NOT make this change, contact support immediately — your account may be compromised.</div>'
            ));
    }
}
