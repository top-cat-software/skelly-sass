<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Auth health endpoint — confirms the Auth kernel is running.
 */
#[Route('/auth/health', name: 'auth_health', methods: ['GET'])]
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'healthy',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checks' => [
                'application' => [
                    'status' => 'healthy',
                    'response_time_ms' => 0.0,
                ],
            ],
        ], Response::HTTP_OK, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
