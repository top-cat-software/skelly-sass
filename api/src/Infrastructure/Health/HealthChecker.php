<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

use Doctrine\DBAL\Connection;

/**
 * Checks the health of infrastructure dependencies (database, Redis).
 * Used by both the API and Auth health endpoints.
 */
final class HealthChecker implements HealthCheckerInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RedisConnectionInterface $redis,
    ) {}

    /**
     * Run all health checks and return the aggregated result.
     *
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
    public function check(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        $overallHealthy = true;
        foreach ($checks as $check) {
            if ($check['status'] === 'unhealthy') {
                $overallHealthy = false;
                break;
            }
        }

        return [
            'status' => $overallHealthy ? 'healthy' : 'unhealthy',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{status: 'healthy'|'unhealthy', response_time_ms: float|null, error?: string}
     */
    private function checkDatabase(): array
    {
        $start = hrtime(true);

        try {
            $this->connection->executeQuery('SELECT 1');
            $elapsed = (hrtime(true) - $start) / 1_000_000;

            return [
                'status' => 'healthy',
                'response_time_ms' => round($elapsed, 2),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'response_time_ms' => null,
                'error' => self::sanitiseError($e),
            ];
        }
    }

    /**
     * @return array{status: 'healthy'|'unhealthy', response_time_ms: float|null, error?: string}
     */
    private function checkRedis(): array
    {
        $start = hrtime(true);

        try {
            $this->redis->ping();
            $elapsed = (hrtime(true) - $start) / 1_000_000;

            return [
                'status' => 'healthy',
                'response_time_ms' => round($elapsed, 2),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'response_time_ms' => null,
                'error' => self::sanitiseError($e),
            ];
        }
    }

    /**
     * Sanitise exception messages to prevent leaking credentials,
     * hostnames, IP addresses, or stack traces.
     */
    private static function sanitiseError(\Throwable $e): string
    {
        $className = (new \ReflectionClass($e))->getShortName();

        return match (true) {
            str_contains($e->getMessage(), 'Connection refused') => 'Connection refused',
            str_contains($e->getMessage(), 'Connection timed out') => 'Connection timed out',
            str_contains($e->getMessage(), 'Authentication') ||
            str_contains($e->getMessage(), 'password') => 'Authentication failed',
            str_contains($e->getMessage(), 'No such host') ||
            str_contains($e->getMessage(), 'Name or service not known') => 'Host not found',
            default => $className,
        };
    }
}
