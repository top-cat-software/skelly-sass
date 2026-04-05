<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

/**
 * Adapter around the phpredis \Redis extension.
 *
 * Registered as a service in the container and injected into HealthChecker.
 * In tests, RedisConnectionInterface is mocked directly.
 */
final class PhpRedisConnection implements RedisConnectionInterface
{
    private ?\Redis $redis = null;

    public function __construct(
        private readonly string $redisUrl,
    ) {}

    public function ping(): bool
    {
        $redis = $this->connect();

        return $redis->ping() !== false;
    }

    private function connect(): \Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        $parsed = parse_url($this->redisUrl);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 6379;
        $password = isset($parsed['pass']) ? urldecode($parsed['pass']) : null;

        // Handle redis://:password@host format (empty user, password after colon).
        if ($password === null && isset($parsed['user']) && $parsed['user'] === '') {
            $password = isset($parsed['pass']) ? urldecode($parsed['pass']) : null;
        }

        $this->redis = new \Redis();
        $this->redis->connect($host, (int) $port, 2.0);

        if ($password !== null) {
            $this->redis->auth($password);
        }

        return $this->redis;
    }
}
