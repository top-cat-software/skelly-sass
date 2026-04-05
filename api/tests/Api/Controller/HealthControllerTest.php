<?php

declare(strict_types=1);

namespace App\Tests\Api\Controller;

use App\Infrastructure\Health\HealthChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use App\Api\Controller\HealthController;

final class HealthControllerTest extends TestCase
{
    #[Test]
    public function it_returns_200_when_all_checks_healthy(): void
    {
        $healthChecker = $this->createMock(HealthChecker::class);
        $healthChecker->method('check')->willReturn([
            'status' => 'healthy',
            'timestamp' => '2026-04-05T20:00:00+00:00',
            'checks' => [
                'database' => ['status' => 'healthy', 'response_time_ms' => 3.0],
                'redis' => ['status' => 'healthy', 'response_time_ms' => 1.0],
            ],
        ]);

        $controller = new HealthController($healthChecker);
        $response = $controller->__invoke();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('healthy', $body['status']);
        self::assertArrayHasKey('checks', $body);
    }

    #[Test]
    public function it_returns_503_when_any_check_unhealthy(): void
    {
        $healthChecker = $this->createMock(HealthChecker::class);
        $healthChecker->method('check')->willReturn([
            'status' => 'unhealthy',
            'timestamp' => '2026-04-05T20:00:00+00:00',
            'checks' => [
                'database' => ['status' => 'unhealthy', 'response_time_ms' => null, 'error' => 'Connection refused'],
                'redis' => ['status' => 'healthy', 'response_time_ms' => 1.0],
            ],
        ]);

        $controller = new HealthController($healthChecker);
        $response = $controller->__invoke();

        self::assertSame(503, $response->getStatusCode());

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('unhealthy', $body['status']);
    }

    #[Test]
    public function response_contains_cache_control_no_store(): void
    {
        $healthChecker = $this->createMock(HealthChecker::class);
        $healthChecker->method('check')->willReturn([
            'status' => 'healthy',
            'timestamp' => '2026-04-05T20:00:00+00:00',
            'checks' => [],
        ]);

        $controller = new HealthController($healthChecker);
        $response = $controller->__invoke();

        self::assertSame('no-store', $response->headers->get('Cache-Control'));
    }
}
