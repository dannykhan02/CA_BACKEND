<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

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
            ->subject('Your DocIntel password was changed')
            ->line('This is a confirmation that your password was just changed.')
            ->line('If you made this change, no action is needed.')
            ->line(new HtmlString(
                '<div style="background: #FBEDEA; color: #8C2F20; border-left: 4px solid #B4412F; border-radius: 6px; padding: 14px 16px; margin: 16px 0; font-size: 14px;">If you didn\'t change your password, reply to this email or contact '.e(config('mail.reply_to.address')).' immediately.</div>'
            ));
    }
}
