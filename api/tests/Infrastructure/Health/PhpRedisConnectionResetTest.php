<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Health;

use App\Infrastructure\Health\PhpRedisConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Tests for the kernel.reset behaviour of PhpRedisConnection.
 *
 * Under FrankenPHP worker mode, stateful services must implement ResetInterface
 * so that the services_resetter can close and clear their connections between
 * requests. Without this, a single long-lived Redis handle shared across
 * requests could silently serve stale or errored connection state.
 *
 * The implementation must accept an optional \Redis instance as a second
 * constructor argument to allow injection in tests, while still lazily
 * creating its own handle when none is injected.
 */
final class PhpRedisConnectionResetTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Contract: the class must implement ResetInterface
    // -----------------------------------------------------------------------

    #[Test]
    public function php_redis_connection_implements_reset_interface(): void
    {
        self::assertTrue(
            is_a(PhpRedisConnection::class, ResetInterface::class, true),
            'PhpRedisConnection must implement ' . ResetInterface::class . ' so it can be tagged with kernel.reset.',
        );
    }

    #[Test]
    public function reset_method_is_declared_with_void_return_type(): void
    {
        $reflection = new \ReflectionClass(PhpRedisConnection::class);

        self::assertTrue(
            $reflection->hasMethod('reset'),
            'PhpRedisConnection must declare a reset() method to satisfy ResetInterface.',
        );

        $method     = $reflection->getMethod('reset');
        $returnType = $method->getReturnType();

        self::assertNotNull($returnType, 'reset() must declare an explicit return type.');
        self::assertSame('void', (string) $returnType);
    }

    // -----------------------------------------------------------------------
    // Behaviour: reset() closes the underlying Redis handle
    // -----------------------------------------------------------------------

    #[Test]
    public function reset_calls_close_on_an_open_connection(): void
    {
        $redisMock = $this->createMock(\Redis::class);
        $redisMock->expects(self::once())->method('close');

        // The constructor accepts an optional pre-built \Redis instance so that
        // an open connection can be injected in tests. In production the
        // instance is created lazily inside connect().
        $connection = new PhpRedisConnection('redis://localhost:6379', $redisMock);
        $connection->reset();
    }

    #[Test]
    public function reset_sets_internal_redis_property_to_null(): void
    {
        $redisMock = $this->createMock(\Redis::class);
        $redisMock->method('close');

        $connection = new PhpRedisConnection('redis://localhost:6379', $redisMock);
        $connection->reset();

        $reflection = new \ReflectionClass($connection);
        $property   = $reflection->getProperty('redis');
        $property->setAccessible(true);

        self::assertNull(
            $property->getValue($connection),
            'After reset(), the $redis property must be null so the next connection attempt reconnects.',
        );
    }

    #[Test]
    public function reset_is_safe_to_call_when_no_connection_has_been_established(): void
    {
        // The services_resetter may call reset() before any request has been
        // handled; the object must tolerate this gracefully without throwing.
        $connection = new PhpRedisConnection('redis://localhost:6379');

        $this->expectNotToPerformAssertions();

        $connection->reset();
    }

    #[Test]
    public function reset_is_idempotent(): void
    {
        // Calling reset() twice in succession must not throw. This can happen
        // when the services_resetter fires twice (e.g., two idle worker cycles).
        $connection = new PhpRedisConnection('redis://localhost:6379');

        $this->expectNotToPerformAssertions();

        $connection->reset();
        $connection->reset();
    }

    // -----------------------------------------------------------------------
    // Internal state assertions
    // -----------------------------------------------------------------------

    #[Test]
    public function redis_property_is_null_before_first_connection(): void
    {
        $connection = new PhpRedisConnection('redis://localhost:6379');

        $reflection = new \ReflectionClass($connection);
        $property   = $reflection->getProperty('redis');
        $property->setAccessible(true);

        self::assertNull(
            $property->getValue($connection),
            '$redis must be null before any connection is established.',
        );
    }

    #[Test]
    public function redis_property_holds_injected_instance_when_provided_via_constructor(): void
    {
        $redisMock  = $this->createMock(\Redis::class);
        $connection = new PhpRedisConnection('redis://localhost:6379', $redisMock);

        $reflection = new \ReflectionClass($connection);
        $property   = $reflection->getProperty('redis');
        $property->setAccessible(true);

        self::assertSame(
            $redisMock,
            $property->getValue($connection),
            'When a \Redis instance is injected via the constructor, $redis must hold that instance.',
        );
    }
}
