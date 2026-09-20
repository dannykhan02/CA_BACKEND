<?php

namespace App\Jobs;

use App\Models\TrackedItem;
use App\Notifications\TrackedDeadlineReminder;
use App\Services\IntelligenceAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SendTrackedDeadlineReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $itemId) {}

    public function handle(IntelligenceAccess $access): void
    {
        DB::transaction(function () use ($access) {
            $item = TrackedItem::whereKey($this->itemId)->lockForUpdate()->first();
            if (! $item || $item->status !== 'open' || ! $item->remind_at || $item->remind_at->isFuture() || $item->reminded_at) {
                return;
            }
            $user = $item->creator;
            if (! $user || ! $user->active || ! $user->email_verified_at || $user->current_workspace_id !== $item->workspace_id) {
                return;
            }
            try {
                $access->document($user, $item->document_id);
            } catch (HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException|AuthorizationException $e) {
                return;
            }
            $user->notify(new TrackedDeadlineReminder($item));
            $item->update(['reminded_at' => now()]);
        });
    }
}
