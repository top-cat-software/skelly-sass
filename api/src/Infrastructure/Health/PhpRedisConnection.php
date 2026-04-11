<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Adapter around the phpredis \Redis extension.
 *
 * Registered as a service in the container and injected into HealthChecker.
 * In tests, RedisConnectionInterface is mocked directly.
 *
 * Implements ResetInterface so the services_resetter can close the underlying
 * TCP connection between requests under FrankenPHP worker mode. Without this,
 * a single long-lived \Redis handle shared across requests could silently serve
 * stale or errored connection state after a network hiccup or Redis restart.
 *
 * The optional $redis constructor parameter exists solely for testing: it
 * allows a pre-built (or mocked) \Redis instance to be injected rather than
 * waiting for the lazy connect() call.
 */
final class PhpRedisConnection implements RedisConnectionInterface, ResetInterface
{
    private ?\Redis $redis;

    public function __construct(
        private readonly string $redisUrl,
        ?\Redis $redis = null,
    ) {
        $this->redis = $redis;
    }

    public function ping(): bool
    {
        $redis = $this->connect();

        return $redis->ping() !== false;
    }

    /**
     * Closes the underlying Redis connection and clears the cached handle.
     *
     * Called by the Symfony services_resetter between requests in worker mode.
     * Safe to call when no connection has been established yet, and idempotent
     * when called multiple times in succession.
     */
    public function reset(): void
    {
        if ($this->redis === null) {
            return;
        }

        $this->redis->close();
        $this->redis = null;
    }

    private function connect(): \Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        $parsed = parse_url($this->redisUrl);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 6379;
        // parse_url puts the password in 'pass' for both redis://user:pass@host
        // and redis://:pass@host formats.
        $password = isset($parsed['pass']) ? urldecode($parsed['pass']) : null;

        $this->redis = new \Redis();
        $this->redis->connect($host, (int) $port, 2.0);

        if ($password !== null) {
            $this->redis->auth($password);
        }

        return $this->redis;
    }
}
