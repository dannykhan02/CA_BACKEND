<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChangeVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $code) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm Your New Email Address')
            ->line('Use the code below to confirm this is your new email address.')
            ->line(new \Illuminate\Support\HtmlString(
                '<div style="font-size: 32px; font-weight: 700; letter-spacing: 6px; text-align: center; background: #FDF3E7; color: #854F0B; border-radius: 8px; padding: 18px; margin: 20px 0;">' . $this->code . '</div>'
            ))
            ->line('This code expires in 15 minutes.');
    }
}
