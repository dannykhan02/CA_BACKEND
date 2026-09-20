<?php

namespace App\Notifications;

use App\Models\TrackedItem;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TrackedDeadlineReminder extends Notification
{
    public function __construct(private TrackedItem $item) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('DocIntel deadline reminder')
            ->line($this->item->title)
            ->line('Due: '.($this->item->due_date?->toDateString() ?? 'Date not confirmed'))
            ->action('Review tracked deadlines', rtrim(config('app.frontend_url', config('app.url')), '/').'/#/deadlines');
    }
}
