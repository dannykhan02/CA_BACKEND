<?php

namespace App\Support;

use Illuminate\Queue\RedisQueue;

/**
 * Read-only view of what is still waiting in the Redis queues: the dispatch tokens of
 * ready, delayed and reserved messages, and per-queue depth/age metadata. Recovery uses
 * it to tell a lost dispatch (no message) from a backed-up queue (message still there).
 */
class QueueInspector
{
    private ?array $tokens = null;

    private float $loadedAt = 0;

    private function queue(): ?RedisQueue
    {
        $connection = app('queue')->connection(config('queue.default'));

        return $connection instanceof RedisQueue ? $connection : null;
    }

    /** Raw payload pages for one queue: ready list, delayed and reserved sorted sets. */
    private function payloads(RedisQueue $queue, string $name): \Generator
    {
        $redis = $queue->getConnection();
        $key = $queue->getQueue($name);
        for ($offset = 0; ($page = $redis->lrange($key, $offset, $offset + 499)) !== []; $offset += 500) {
            yield from $page;
        }
        foreach ([':delayed', ':reserved'] as $suffix) {
            for ($offset = 0; ($page = $redis->zrange($key.$suffix, $offset, $offset + 499)) !== []; $offset += 500) {
                yield from $page;
            }
        }
    }

    /**
     * Dispatch tokens of every message still queued, or null when the queue backend cannot
     * be inspected (non-Redis driver or Redis error): callers then use age-based recovery.
     */
    public function pendingDispatchTokens(): ?array
    {
        if ($this->tokens !== null && microtime(true) - $this->loadedAt < 60) {
            return $this->tokens;
        }
        try {
            $queue = $this->queue();
            if (! $queue) {
                return null;
            }
            $tokens = [];
            foreach (QueueTopology::queues() as $name) {
                foreach ($this->payloads($queue, $name) as $payload) {
                    $command = json_decode($payload, true)['data']['command'] ?? '';
                    if (is_string($command) && preg_match('/dispatchToken";s:36:"([0-9a-f-]{36})"/', $command, $match)) {
                        $tokens[$match[1]] = true;
                    }
                }
            }
        } catch (\Throwable) {
            return null;
        }
        $this->loadedAt = microtime(true);

        return $this->tokens = $tokens;
    }

    /** Metadata only: per-queue ready/delayed/reserved depth and oldest ready job age. */
    public function depths(): array
    {
        $queue = $this->queue();
        if (! $queue) {
            return [];
        }
        $redis = $queue->getConnection();
        $rows = [];
        foreach (QueueTopology::queues() as $name) {
            $key = $queue->getQueue($name);
            // Workers LPOP from the head, so index 0 is the oldest ready message.
            $head = $redis->lindex($key, 0);
            $created = is_string($head) ? (json_decode($head, true)['createdAt'] ?? null) : null;
            $rows[$name] = ['ready' => (int) $redis->llen($key), 'delayed' => (int) $redis->zcard($key.':delayed'),
                'reserved' => (int) $redis->zcard($key.':reserved'),
                'oldest_ready_age_seconds' => $created ? max(0, time() - (int) $created) : null];
        }

        return $rows;
    }
}
