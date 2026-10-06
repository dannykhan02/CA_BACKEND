<?php

namespace App\Services\AI\ProviderGate;

use Illuminate\Support\Facades\Redis;

/**
 * Atomic lease semaphore in shared Redis. One Lua script decides each admission, using
 * the Redis server clock (no replica clock skew). Leases are sorted-set members
 * "token|document" scored by expiry; expired leases are purged on every acquire.
 */
class RedisGateStore
{
    /*
     * KEYS: 1 leases zset, 2 waiting-documents zset, 3 counters hash, 4 priority-waiters zset
     * ARGV: 1 token, 2 document ('' = none), 3 lease ttl ms, 4 max, 5 per-document max,
     *       6 reserve last permit for others (0/1), 7 waiter ttl ms, 8 priority (0/1), 9 waiter id
     * Priority callers (synthesis, interactive web) that were denied are owed the next free
     * permits: bulk callers cannot take a permit while priority waiters need it.
     * Returns {granted 0/1, reason, active after decision}.
     */
    private const ACQUIRE = <<<'LUA'
redis.replicate_commands()
local t = redis.call('TIME')
local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
local expired = redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now)
if expired > 0 then redis.call('HINCRBY', KEYS[3], 'lease_expired', expired) end
redis.call('ZREMRANGEBYSCORE', KEYS[2], '-inf', now)
redis.call('ZREMRANGEBYSCORE', KEYS[4], '-inf', now)
local active = redis.call('ZCARD', KEYS[1])
local max = tonumber(ARGV[4])
local doc = ARGV[2]
local priority = tonumber(ARGV[8]) == 1
local function deny(reason)
  if doc ~= '' then
    redis.call('ZADD', KEYS[2], now + tonumber(ARGV[7]), doc)
    redis.call('PEXPIRE', KEYS[2], tonumber(ARGV[7]) * 2)
  end
  if priority then
    redis.call('ZADD', KEYS[4], now + tonumber(ARGV[7]), ARGV[9])
    redis.call('PEXPIRE', KEYS[4], tonumber(ARGV[7]) * 2)
  end
  redis.call('HINCRBY', KEYS[3], 'denied_' .. reason, 1)
  return {0, reason, active}
end
if active >= max then return deny('global') end
if not priority then
  local owed = redis.call('ZCARD', KEYS[4])
  if owed > 0 and (max - active) <= owed then return deny('priority') end
end
if doc ~= '' then
  local holders = {}
  for _, member in ipairs(redis.call('ZRANGE', KEYS[1], 0, -1)) do
    local d = string.match(member, '|(.*)$')
    if d and d ~= '' then holders[d] = (holders[d] or 0) + 1 end
  end
  local held = holders[doc] or 0
  if held >= tonumber(ARGV[5]) then return deny('document') end
  if held >= 1 then
    local starving = tonumber(ARGV[6])
    local waiters = 0
    for _, w in ipairs(redis.call('ZRANGE', KEYS[2], 0, -1)) do
      if w ~= doc and not holders[w] then waiters = waiters + 1 end
    end
    if waiters > starving then starving = waiters end
    if (max - active) <= starving then return deny('fairness') end
  end
  redis.call('ZREM', KEYS[2], doc)
end
if priority then redis.call('ZREM', KEYS[4], ARGV[9]) end
redis.call('ZADD', KEYS[1], now + tonumber(ARGV[3]), ARGV[1] .. '|' .. doc)
redis.call('PEXPIRE', KEYS[1], tonumber(ARGV[3]) * 2)
redis.call('HINCRBY', KEYS[3], 'acquired', 1)
return {1, 'ok', active + 1}
LUA;

    public function __construct(private string $connection, private string $prefix) {}

    private function key(string $name): string
    {
        return $this->prefix.':'.$name;
    }

    public function acquire(string $token, string $document, int $ttlMs, int $max, int $perDocument, bool $reserveForOthers, int $waiterMs,
        bool $priority = false, string $waiter = ''): array
    {
        [$granted, $reason, $active] = Redis::connection($this->connection)->eval(self::ACQUIRE, 4,
            $this->key('leases'), $this->key('waiting'), $this->key('counters'), $this->key('priority'),
            $token, $document, $ttlMs, $max, $perDocument, $reserveForOthers ? 1 : 0, $waiterMs, $priority ? 1 : 0, $waiter ?: $token);

        return [(int) $granted === 1, (string) $reason, (int) $active, $token.'|'.$document];
    }

    public function withdraw(string $waiter): void
    {
        Redis::connection($this->connection)->zrem($this->key('priority'), $waiter);
    }

    public function release(string $member): void
    {
        Redis::connection($this->connection)->zrem($this->key('leases'), $member);
    }

    public function stats(): array
    {
        $redis = Redis::connection($this->connection);
        $counters = $redis->hgetall($this->key('counters')) ?: [];

        return ['active' => (int) $redis->zcard($this->key('leases')), 'waiting_documents' => (int) $redis->zcard($this->key('waiting')),
            'priority_waiters' => (int) $redis->zcard($this->key('priority')),
            'counters' => array_map('intval', $counters)];
    }
}
