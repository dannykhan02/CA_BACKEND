<?php

namespace App\Notifications;

use App\Notifications\Concerns\RetriesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable, RetriesMail;

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to DocIntel')
            ->greeting('Welcome, '.($notifiable->full_name ?? 'there').'!')
            ->line('Your account is ready to go.')
            ->line('Need help getting started? Just reply to this email.');
    }
}
