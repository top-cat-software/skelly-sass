<?php

declare(strict_types=1);

namespace App\FrankenPhp;

use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Manages the lifecycle of lazily-booted, long-lived kernel instances.
 *
 * Under FrankenPHP worker mode the process is kept alive between requests,
 * so kernels must be booted once and reused. This registry encapsulates that
 * responsibility: each kernel slot is initialised on first access via its
 * factory callable and the same instance is returned on all subsequent calls.
 *
 * The two kernel slots are entirely independent — accessing one will never
 * trigger the other's factory, which keeps boot time proportional to the
 * traffic mix rather than always paying for both kernels upfront.
 */
final class KernelRegistry
{
    private ?HttpKernelInterface $apiKernel = null;
    private ?HttpKernelInterface $authKernel = null;

    /**
     * @param callable(): HttpKernelInterface $apiKernelFactory
     * @param callable(): HttpKernelInterface $authKernelFactory
     */
    public function __construct(
        private readonly mixed $apiKernelFactory,
        private readonly mixed $authKernelFactory,
    ) {}

    /**
     * Returns the API kernel, creating it on first call via the factory.
     */
    public function getApiKernel(): HttpKernelInterface
    {
        if ($this->apiKernel === null) {
            $this->apiKernel = ($this->apiKernelFactory)();
        }

        return $this->apiKernel;
    }

    /**
     * Returns the Auth kernel, creating it on first call via the factory.
     */
    public function getAuthKernel(): HttpKernelInterface
    {
        if ($this->authKernel === null) {
            $this->authKernel = ($this->authKernelFactory)();
        }

        return $this->authKernel;
    }
}
