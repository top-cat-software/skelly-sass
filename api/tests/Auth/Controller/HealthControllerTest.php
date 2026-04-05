<?php

declare(strict_types=1);

namespace App\Tests\Auth\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Auth\Controller\HealthController;

final class HealthControllerTest extends TestCase
{
    #[Test]
    public function it_returns_200_with_healthy_status(): void
    {
        $controller = new HealthController();
        $response = $controller->__invoke();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('healthy', $body['status']);
        self::assertArrayHasKey('timestamp', $body);
        self::assertArrayHasKey('checks', $body);
        self::assertSame('healthy', $body['checks']['application']['status']);
    }

    #[Test]
    public function it_includes_iso8601_timestamp(): void
    {
        $controller = new HealthController();
        $response = $controller->__invoke();

        $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $body['timestamp']);
        self::assertInstanceOf(\DateTimeImmutable::class, $parsed);
    }

    #[Test]
    public function response_contains_cache_control_no_store(): void
    {
        $controller = new HealthController();
        $response = $controller->__invoke();

        self::assertSame('no-store', $response->headers->get('Cache-Control'));
    }
}
