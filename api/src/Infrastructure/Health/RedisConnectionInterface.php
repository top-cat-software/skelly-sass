<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

/**
 * Wraps the phpredis extension's Redis class for testability.
 * The phpredis extension is only available inside the Docker container,
 * so we use this interface for mocking in tests.
 */
interface RedisConnectionInterface
{
    /**
     * @return bool True if Redis responds to PING.
     *
     * @throws \RuntimeException If Redis is unreachable.
     */
    public function ping(): bool;
}
