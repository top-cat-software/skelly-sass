<?php

declare(strict_types=1);

/**
 * FrankenPHP Worker-Mode Front Controller.
 *
 * This is the sole entry point for HTTP requests under FrankenPHP worker mode.
 * The worker loop (managed by the FrankenPHP runtime) calls this closure once
 * per request, with the Symfony kernel kept in memory between requests. This
 * eliminates per-request bootstrap overhead.
 *
 * When APP_RUNTIME is not set (e.g., during tests or when using PHP's built-in
 * server), the runtime/frankenphp-symfony package falls back to the standard
 * Symfony Runtime behaviour, so this file is backward-compatible.
 *
 * Multi-kernel architecture:
 *   /api/*  → ApiKernel  (separate DI container, cache in var/cache/api/)
 *   /auth/* → AuthKernel (separate DI container, cache in var/cache/auth/)
 *
 * Each kernel has its own services_resetter, so kernel.reset only resets the
 * services belonging to the kernel that handled the request. Shared services
 * from Domain\ and Infrastructure\ namespaces get separate instances per
 * kernel. Any shared service with mutable state MUST implement ResetInterface.
 *
 * @see docs/adr/0010-use-frankenphp.md
 */

use App\Api\ApiKernel;
use App\Auth\AuthKernel;
use App\FrankenPhp\FrontControllerRouter;
use App\FrankenPhp\KernelRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

return function (Request $request, array $context): Response {
    static $router = null;

    if ($router === null) {
        $env   = $context['APP_ENV'];
        $debug = (bool) $context['APP_DEBUG'];

        $registry = new KernelRegistry(
            apiKernelFactory: static fn (): ApiKernel => new ApiKernel($env, $debug),
            authKernelFactory: static fn (): AuthKernel => new AuthKernel($env, $debug),
        );

        $router = new FrontControllerRouter(
            $registry->getApiKernel(),
            $registry->getAuthKernel(),
        );
    }

    return $router->dispatch($request);
};
