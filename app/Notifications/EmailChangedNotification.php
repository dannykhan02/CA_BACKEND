<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Note: this is a security alert (unexpected-change notification), so queue
 * worker health/latency directly affects how fast the account owner finds
 * out about a change they didn't make. If this app doesn't already monitor
 * queue depth/latency, consider putting security-alert notifications like
 * this one on a dedicated, closely-watched queue rather than the default.
 */
class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $newEmail) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your DocIntel email address was changed')
            ->line("This is a confirmation that your DocIntel account email was changed to {$this->newEmail}.")
            ->line(new HtmlString(
                '<div style="background: #FBEDEA; color: #8C2F20; border-left: 4px solid #B4412F; border-radius: 6px; padding: 14px 16px; margin: 16px 0; font-size: 14px;">Wasn\'t you? Reply to this email right away at '.e(config('mail.reply_to.address')).'.</div>'
            ));
    }
}
