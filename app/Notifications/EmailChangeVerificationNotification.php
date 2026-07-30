<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChangeVerificationNotification extends Notification
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
                '<div style="font-size: 28px; font-weight: bold; letter-spacing: 4px; text-align: center;">' . $this->code . '</div>'
            ))
            ->line('This code expires in 15 minutes.');
    }
}
