<?php

declare(strict_types=1);

namespace App\FrankenPhp;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes incoming requests to the appropriate kernel based on URI prefix.
 *
 * Under FrankenPHP worker mode, a single process handles all requests.
 * This router acts as a dispatcher, delegating to the correct kernel
 * without re-bootstrapping on every request.
 *
 * Routing rules:
 *   /api/*  → ApiKernel
 *   /auth/* → AuthKernel
 *   anything else → RFC 7807 404
 *
 * Note: bare /api and /auth (without trailing slash) are intentionally
 * treated as 404s — only paths with a further segment are valid.
 */
final class FrontControllerRouter
{
    public function __construct(
        private readonly KernelRegistry $kernelRegistry,
    ) {}

    public function dispatch(Request $request): Response
    {
        $path = $request->getPathInfo();

        if (str_starts_with($path, '/api/')) {
            return $this->kernelRegistry->getApiKernel()->handle($request);
        }

        if (str_starts_with($path, '/auth/')) {
            return $this->kernelRegistry->getAuthKernel()->handle($request);
        }

        return $this->buildNotFoundResponse($path);
    }

    /**
     * Builds an RFC 7807 Problem Details response for unmatched paths.
     *
     * Uses "about:blank" as the type URI per RFC 7807 §4.2, which is the
     * conventional value when no dedicated problem type documentation exists.
     */
    private function buildNotFoundResponse(string $path): JsonResponse
    {
        // The path is user-supplied. Reflected as-is; acceptable for a JSON
        // problem response (not HTML), but avoid in any HTML error page.
        return new JsonResponse(
            data: [
                'type'   => 'about:blank',
                'title'  => 'Not Found',
                'status' => Response::HTTP_NOT_FOUND,
                'detail' => sprintf(
                    'No application handles the path "%s". API routes start with /api/, auth routes start with /auth/.',
                    $path,
                ),
            ],
            status: Response::HTTP_NOT_FOUND,
            headers: ['Content-Type' => 'application/problem+json'],
        );
    }
}
