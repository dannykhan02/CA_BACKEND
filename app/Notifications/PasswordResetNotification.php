<?php

namespace App\Notifications;

use App\Notifications\Concerns\RetriesMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable, RetriesMail;

    public function __construct(
        public string $token,
        public string $email,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
        $resetUrl = "{$frontendUrl}/#/reset?token={$this->token}&email=".urlencode($this->email);

        return (new MailMessage)
            ->subject('Reset your DocIntel password')
            ->line('You requested a password reset.')
            ->action('Reset Password', $resetUrl)
            ->line('This link expires in 60 minutes.')
            ->line("If you didn't request a password reset, no action is needed. Questions? Contact ".config('mail.reply_to.address').'.');
    }
}
