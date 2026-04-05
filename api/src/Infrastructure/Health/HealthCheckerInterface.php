<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

interface HealthCheckerInterface
{
    /**
     * @return array{
     *     status: 'healthy'|'unhealthy',
     *     timestamp: string,
     *     checks: array<string, array{
     *         status: 'healthy'|'unhealthy',
     *         response_time_ms: float|null,
     *         error?: string,
     *     }>,
     * }
     */
    public function check(): array;
}
