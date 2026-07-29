<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
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
            ->line('If you did NOT make this change, contact support immediately — your account may be compromised.');
    }
}
