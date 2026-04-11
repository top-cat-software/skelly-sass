<?php

declare(strict_types=1);

namespace App\Tests\FrankenPhp;

use App\FrankenPhp\KernelRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests for the kernel registry that manages lazy-booted, reused kernel instances.
 *
 * Under FrankenPHP worker mode the kernel must be booted once and reused across
 * multiple requests. KernelRegistry encapsulates this responsibility so the
 * lazy-static behaviour can be tested in isolation.
 */
final class KernelRegistryTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Lazy initialisation
    // -----------------------------------------------------------------------

    #[Test]
    public function it_creates_the_api_kernel_on_first_call_to_get_api_kernel(): void
    {
        $created = false;
        $factory = static function () use (&$created): HttpKernelInterface {
            $created = true;
            return (new class implements HttpKernelInterface {
                public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
                {
                    return new \Symfony\Component\HttpFoundation\Response();
                }
            });
        };

        $registry = new KernelRegistry($factory, static fn () => throw new \LogicException('Should not be called'));

        $registry->getApiKernel();

        self::assertTrue($created, 'Expected the API kernel factory to be called on first access.');
    }

    #[Test]
    public function it_creates_the_auth_kernel_on_first_call_to_get_auth_kernel(): void
    {
        $created = false;
        $factory = static function () use (&$created): HttpKernelInterface {
            $created = true;
            return (new class implements HttpKernelInterface {
                public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
                {
                    return new \Symfony\Component\HttpFoundation\Response();
                }
            });
        };

        $registry = new KernelRegistry(static fn () => throw new \LogicException('Should not be called'), $factory);

        $registry->getAuthKernel();

        self::assertTrue($created, 'Expected the Auth kernel factory to be called on first access.');
    }

    // -----------------------------------------------------------------------
    // Kernel reuse across requests
    // -----------------------------------------------------------------------

    #[Test]
    public function it_returns_the_same_api_kernel_instance_on_subsequent_calls(): void
    {
        $callCount = 0;
        $kernel    = $this->createMock(HttpKernelInterface::class);

        $registry = new KernelRegistry(
            static function () use (&$callCount, $kernel): HttpKernelInterface {
                ++$callCount;
                return $kernel;
            },
            static fn () => throw new \LogicException('Should not be called'),
        );

        $first  = $registry->getApiKernel();
        $second = $registry->getApiKernel();
        $third  = $registry->getApiKernel();

        self::assertSame($first, $second, 'Expected the same ApiKernel instance to be returned on each call.');
        self::assertSame($first, $third);
        self::assertSame(1, $callCount, 'Expected the factory to be called exactly once regardless of how many times the kernel is retrieved.');
    }

    #[Test]
    public function it_returns_the_same_auth_kernel_instance_on_subsequent_calls(): void
    {
        $callCount = 0;
        $kernel    = $this->createMock(HttpKernelInterface::class);

        $registry = new KernelRegistry(
            static fn () => throw new \LogicException('Should not be called'),
            static function () use (&$callCount, $kernel): HttpKernelInterface {
                ++$callCount;
                return $kernel;
            },
        );

        $first  = $registry->getAuthKernel();
        $second = $registry->getAuthKernel();

        self::assertSame($first, $second, 'Expected the same AuthKernel instance to be returned on each call.');
        self::assertSame(1, $callCount, 'Expected the factory to be called exactly once.');
    }

    // -----------------------------------------------------------------------
    // Independence of the two kernel slots
    // -----------------------------------------------------------------------

    #[Test]
    public function api_kernel_and_auth_kernel_are_independent_instances(): void
    {
        $apiKernel  = $this->createMock(HttpKernelInterface::class);
        $authKernel = $this->createMock(HttpKernelInterface::class);

        $registry = new KernelRegistry(
            static fn () => $apiKernel,
            static fn () => $authKernel,
        );

        self::assertNotSame($registry->getApiKernel(), $registry->getAuthKernel());
    }

    #[Test]
    public function accessing_api_kernel_does_not_trigger_auth_kernel_factory(): void
    {
        $authFactoryCalled = false;

        $registry = new KernelRegistry(
            static fn () => (new class implements HttpKernelInterface {
                public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
                {
                    return new \Symfony\Component\HttpFoundation\Response();
                }
            }),
            static function () use (&$authFactoryCalled): HttpKernelInterface {
                $authFactoryCalled = true;
                return (new class implements HttpKernelInterface {
                    public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
                    {
                        return new \Symfony\Component\HttpFoundation\Response();
                    }
                });
            },
        );

        $registry->getApiKernel();

        self::assertFalse($authFactoryCalled, 'Expected AuthKernel factory not to be called when only ApiKernel is accessed.');
    }

    #[Test]
    public function accessing_auth_kernel_does_not_trigger_api_kernel_factory(): void
    {
        $apiFactoryCalled = false;

        $registry = new KernelRegistry(
            static function () use (&$apiFactoryCalled): HttpKernelInterface {
                $apiFactoryCalled = true;
                return (new class implements HttpKernelInterface {
                    public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
                    {
                        return new \Symfony\Component\HttpFoundation\Response();
                    }
                });
            },
            static fn () => (new class implements HttpKernelInterface {
                public function handle(\Symfony\Component\HttpFoundation\Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
                {
                    return new \Symfony\Component\HttpFoundation\Response();
                }
            }),
        );

        $registry->getAuthKernel();

        self::assertFalse($apiFactoryCalled, 'Expected ApiKernel factory not to be called when only AuthKernel is accessed.');
    }
}
