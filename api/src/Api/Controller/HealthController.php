<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Infrastructure\Health\HealthCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API health endpoint — returns database, Redis, and Messenger status.
 */
#[Route('/api/v1/health', name: 'api_health', methods: ['GET'])]
final class HealthController
{
    public function __construct(
        private readonly HealthCheckerInterface $healthChecker,
    ) {}

    public function __invoke(): JsonResponse
    {
        $result = $this->healthChecker->check();

        $statusCode = $result['status'] === 'healthy'
            ? Response::HTTP_OK
            : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse($result, $statusCode, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
