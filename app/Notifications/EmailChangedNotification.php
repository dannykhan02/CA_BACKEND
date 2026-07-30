<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailChangedNotification extends Notification
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
            ->subject('Your Account Email Was Changed')
            ->line("This is a confirmation that your account email was changed to {$this->newEmail}.")
            ->line('If you did NOT make this change, contact support immediately — your account may be compromised.');
    }
}
