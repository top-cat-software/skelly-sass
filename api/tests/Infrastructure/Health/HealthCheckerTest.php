<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Health;

use App\Infrastructure\Health\HealthChecker;
use App\Infrastructure\Health\RedisConnectionInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HealthCheckerTest extends TestCase
{
    #[Test]
    public function it_returns_healthy_when_database_is_reachable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createMock(Result::class));

        $redis = $this->createMock(RedisConnectionInterface::class);
        $redis->method('ping')->willReturn(true);

        $checker = new HealthChecker($connection, $redis);
        $result = $checker->check();

        self::assertSame('healthy', $result['status']);
        self::assertSame('healthy', $result['checks']['database']['status']);
        self::assertIsFloat($result['checks']['database']['response_time_ms']);
        self::assertArrayNotHasKey('error', $result['checks']['database']);
    }

    #[Test]
    public function it_returns_healthy_when_redis_is_reachable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createMock(Result::class));

        $redis = $this->createMock(RedisConnectionInterface::class);
        $redis->method('ping')->willReturn(true);

        $checker = new HealthChecker($connection, $redis);
        $result = $checker->check();

        self::assertSame('healthy', $result['checks']['redis']['status']);
        self::assertIsFloat($result['checks']['redis']['response_time_ms']);
        self::assertArrayNotHasKey('error', $result['checks']['redis']);
    }

    #[Test]
    public function it_returns_unhealthy_when_database_is_unreachable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willThrowException(
            new \RuntimeException('Connection refused'),
        );

        $redis = $this->createMock(RedisConnectionInterface::class);
        $redis->method('ping')->willReturn(true);

        $checker = new HealthChecker($connection, $redis);
        $result = $checker->check();

        self::assertSame('unhealthy', $result['status']);
        self::assertSame('unhealthy', $result['checks']['database']['status']);
        self::assertNull($result['checks']['database']['response_time_ms']);
        self::assertArrayHasKey('error', $result['checks']['database']);
    }

    #[Test]
    public function it_returns_unhealthy_when_redis_is_unreachable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createMock(Result::class));

        $redis = $this->createMock(RedisConnectionInterface::class);
        $redis->method('ping')->willThrowException(new \RuntimeException('Connection refused'));

        $checker = new HealthChecker($connection, $redis);
        $result = $checker->check();

        self::assertSame('unhealthy', $result['status']);
        self::assertSame('unhealthy', $result['checks']['redis']['status']);
        self::assertNull($result['checks']['redis']['response_time_ms']);
        self::assertArrayHasKey('error', $result['checks']['redis']);
    }

    #[Test]
    public function it_includes_timestamp_in_iso8601_format(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($this->createMock(Result::class));

        $redis = $this->createMock(RedisConnectionInterface::class);
        $redis->method('ping')->willReturn(true);

        $checker = new HealthChecker($connection, $redis);
        $result = $checker->check();

        self::assertArrayHasKey('timestamp', $result);
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $result['timestamp']);
        self::assertInstanceOf(\DateTimeImmutable::class, $parsed);
    }

    #[Test]
    public function it_reports_overall_unhealthy_if_any_check_fails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willThrowException(
            new \RuntimeException('Connection refused'),
        );

        $redis = $this->createMock(RedisConnectionInterface::class);
        $redis->method('ping')->willReturn(true);

        $checker = new HealthChecker($connection, $redis);
        $result = $checker->check();

        self::assertSame('unhealthy', $result['status']);
        self::assertSame('healthy', $result['checks']['redis']['status']);
        self::assertSame('unhealthy', $result['checks']['database']['status']);
    }

    #[Test]
    public function error_messages_are_sanitised(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willThrowException(
            new \RuntimeException('SQLSTATE[08006] connection to server at "10.0.0.5" port 5432 failed: password authentication failed for user "skelly"'),
        );

        $redis = $this->createMock(RedisConnectionInterface::class);
        $redis->method('ping')->willReturn(true);

        $checker = new HealthChecker($connection, $redis);
        $result = $checker->check();

        // Must not contain IP addresses or usernames from raw exception messages.
        self::assertStringNotContainsString('10.0.0.5', $result['checks']['database']['error']);
        self::assertStringNotContainsString('skelly', $result['checks']['database']['error']);
    }
}
