<?php

namespace App\Notifications;

use App\Notifications\Concerns\RetriesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class EmailChangeVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable, RetriesMail;

    public function __construct(public string $code) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new DocIntel email address')
            ->line('Use the code below to confirm this is your new email address.')
            ->line(new HtmlString(
                '<div style="font-size: 32px; font-weight: 700; letter-spacing: 6px; text-align: center; background: #2D2618; color: #D4A04E; border-radius: 8px; padding: 18px; margin: 20px 0;">'.$this->code.'</div>'
            ))
            ->line('This code expires in 15 minutes.')
            ->line("If you didn't request this change, ignore this email or contact ".config('mail.reply_to.address').'.');
    }
}
