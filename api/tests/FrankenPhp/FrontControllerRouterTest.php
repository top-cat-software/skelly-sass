<?php

declare(strict_types=1);

namespace App\Tests\FrankenPhp;

use App\FrankenPhp\FrontControllerRouter;
use App\FrankenPhp\KernelRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests for the FrankenPHP worker-mode front controller routing logic.
 *
 * The router is responsible for:
 * - Dispatching /api/* requests to ApiKernel
 * - Dispatching /auth/* requests to AuthKernel
 * - Returning an RFC 7807 JSON 404 for any other path
 */
final class FrontControllerRouterTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Routing — happy paths
    // -----------------------------------------------------------------------

    #[Test]
    public function it_dispatches_api_root_request_to_api_kernel(): void
    {
        $apiKernel  = $this->createMock(HttpKernelInterface::class);
        $authKernel = $this->createMock(HttpKernelInterface::class);

        $request = Request::create('/api/v1/health');

        $apiKernel
            ->expects(self::once())
            ->method('handle')
            ->with($request)
            ->willReturn(new Response('ok', 200));

        $authKernel
            ->expects(self::never())
            ->method('handle');

        $registry = new KernelRegistry(
            static fn () => $apiKernel,
            static fn () => $authKernel,
        );
        $router = new FrontControllerRouter($registry);
        $router->dispatch($request);
    }

    #[Test]
    public function it_dispatches_api_nested_path_to_api_kernel(): void
    {
        $apiKernel  = $this->createMock(HttpKernelInterface::class);
        $authKernel = $this->createMock(HttpKernelInterface::class);

        $request = Request::create('/api/v1/users/42/profile');

        $apiKernel
            ->expects(self::once())
            ->method('handle')
            ->with($request)
            ->willReturn(new Response('ok', 200));

        $authKernel->expects(self::never())->method('handle');

        $registry = new KernelRegistry(
            static fn () => $apiKernel,
            static fn () => $authKernel,
        );
        $router = new FrontControllerRouter($registry);
        $router->dispatch($request);
    }

    #[Test]
    public function it_dispatches_auth_root_request_to_auth_kernel(): void
    {
        $apiKernel  = $this->createMock(HttpKernelInterface::class);
        $authKernel = $this->createMock(HttpKernelInterface::class);

        $request = Request::create('/auth/token');

        $authKernel
            ->expects(self::once())
            ->method('handle')
            ->with($request)
            ->willReturn(new Response('ok', 200));

        $apiKernel->expects(self::never())->method('handle');

        $registry = new KernelRegistry(
            static fn () => $apiKernel,
            static fn () => $authKernel,
        );
        $router = new FrontControllerRouter($registry);
        $router->dispatch($request);
    }

    #[Test]
    public function it_dispatches_auth_nested_path_to_auth_kernel(): void
    {
        $apiKernel  = $this->createMock(HttpKernelInterface::class);
        $authKernel = $this->createMock(HttpKernelInterface::class);

        $request = Request::create('/auth/oauth/v2/token');

        $authKernel
            ->expects(self::once())
            ->method('handle')
            ->with($request)
            ->willReturn(new Response('ok', 200));

        $apiKernel->expects(self::never())->method('handle');

        $registry = new KernelRegistry(
            static fn () => $apiKernel,
            static fn () => $authKernel,
        );
        $router = new FrontControllerRouter($registry);
        $router->dispatch($request);
    }

    #[Test]
    public function it_returns_the_kernel_response_unmodified_for_api_requests(): void
    {
        $apiKernel  = $this->createMock(HttpKernelInterface::class);
        $authKernel = $this->createMock(HttpKernelInterface::class);

        $expectedResponse = new Response('{"data":"value"}', 200, ['Content-Type' => 'application/json']);
        $apiKernel->method('handle')->willReturn($expectedResponse);

        $registry = new KernelRegistry(
            static fn () => $apiKernel,
            static fn () => $authKernel,
        );
        $router = new FrontControllerRouter($registry);
        $response = $router->dispatch(Request::create('/api/v1/resource'));

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function it_returns_the_kernel_response_unmodified_for_auth_requests(): void
    {
        $apiKernel  = $this->createMock(HttpKernelInterface::class);
        $authKernel = $this->createMock(HttpKernelInterface::class);

        $expectedResponse = new Response('{"access_token":"tok"}', 200, ['Content-Type' => 'application/json']);
        $authKernel->method('handle')->willReturn($expectedResponse);

        $registry = new KernelRegistry(
            static fn () => $apiKernel,
            static fn () => $authKernel,
        );
        $router = new FrontControllerRouter($registry);
        $response = $router->dispatch(Request::create('/auth/token'));

        self::assertSame($expectedResponse, $response);
    }

    // -----------------------------------------------------------------------
    // Routing — unmatched paths return RFC 7807 404
    // -----------------------------------------------------------------------

    #[Test]
    #[DataProvider('unmatchedPaths')]
    public function it_returns_404_for_unmatched_path(string $path): void
    {
        $apiKernel  = $this->createMock(HttpKernelInterface::class);
        $authKernel = $this->createMock(HttpKernelInterface::class);

        $apiKernel->expects(self::never())->method('handle');
        $authKernel->expects(self::never())->method('handle');

        $registry = new KernelRegistry(
            static fn () => $apiKernel,
            static fn () => $authKernel,
        );
        $router = new FrontControllerRouter($registry);
        $response = $router->dispatch(Request::create($path));

        self::assertSame(404, $response->getStatusCode());
    }

    /** @return list<array{0: string}> */
    public static function unmatchedPaths(): array
    {
        return [
            'root path'                => ['/'],
            'unknown prefix'           => ['/unknown/resource'],
            'partial api prefix'       => ['/apiv1'],
            'partial auth prefix'      => ['/authentication'],
            'empty-looking path'       => ['/v1/resource'],
            'admin path'               => ['/admin'],
            'traversal attempt'        => ['/../etc/passwd'],
        ];
    }

    // -----------------------------------------------------------------------
    // RFC 7807 error response structure
    // -----------------------------------------------------------------------

    #[Test]
    public function unmatched_path_response_has_application_problem_json_content_type(): void
    {
        $router   = $this->buildRouterWithStubKernels();
        $response = $router->dispatch(Request::create('/not-found'));

        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function unmatched_path_response_body_contains_required_rfc7807_fields(): void
    {
        $router   = $this->buildRouterWithStubKernels();
        $response = $router->dispatch(Request::create('/not-found'));
        $body     = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('type', $body);
        self::assertArrayHasKey('title', $body);
        self::assertArrayHasKey('status', $body);
        self::assertArrayHasKey('detail', $body);
    }

    #[Test]
    public function unmatched_path_response_body_status_field_matches_http_status(): void
    {
        $router   = $this->buildRouterWithStubKernels();
        $response = $router->dispatch(Request::create('/not-found'));
        $body     = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(404, $body['status']);
    }

    #[Test]
    public function unmatched_path_response_body_title_is_not_found(): void
    {
        $router   = $this->buildRouterWithStubKernels();
        $response = $router->dispatch(Request::create('/not-found'));
        $body     = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Not Found', $body['title']);
    }

    #[Test]
    public function unmatched_path_response_body_type_is_a_uri(): void
    {
        $router   = $this->buildRouterWithStubKernels();
        $response = $router->dispatch(Request::create('/not-found'));
        $body     = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // RFC 7807 requires type to be a URI reference; "about:blank" is the
        // conventional value when no problem type documentation exists.
        self::assertNotEmpty($body['type']);
        self::assertMatchesRegularExpression('#^(https?://|about:blank)#', $body['type']);
    }

    #[Test]
    public function unmatched_path_response_body_detail_is_non_empty_string(): void
    {
        $router   = $this->buildRouterWithStubKernels();
        $response = $router->dispatch(Request::create('/not-found'));
        $body     = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsString($body['detail']);
        self::assertNotEmpty($body['detail']);
    }

    // -----------------------------------------------------------------------
    // Edge cases — path prefix matching must be exact
    // -----------------------------------------------------------------------

    #[Test]
    public function it_does_not_dispatch_to_api_kernel_when_path_is_exactly_api_with_no_trailing_slash(): void
    {
        // /api with no trailing slash is not a valid sub-path for this application;
        // routing must require /api/ prefix to prevent ambiguity.
        $apiKernel  = $this->createMock(HttpKernelInterface::class);
        $authKernel = $this->createMock(HttpKernelInterface::class);

        $apiKernel->expects(self::never())->method('handle');
        $authKernel->expects(self::never())->method('handle');

        $registry = new KernelRegistry(
            static fn () => $apiKernel,
            static fn () => $authKernel,
        );
        $router = new FrontControllerRouter($registry);
        $response = $router->dispatch(Request::create('/api'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_does_not_dispatch_to_auth_kernel_when_path_is_exactly_auth_with_no_trailing_slash(): void
    {
        $apiKernel  = $this->createMock(HttpKernelInterface::class);
        $authKernel = $this->createMock(HttpKernelInterface::class);

        $apiKernel->expects(self::never())->method('handle');
        $authKernel->expects(self::never())->method('handle');

        $registry = new KernelRegistry(
            static fn () => $apiKernel,
            static fn () => $authKernel,
        );
        $router = new FrontControllerRouter($registry);
        $response = $router->dispatch(Request::create('/auth'));

        self::assertSame(404, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Builds a router pre-wired with no-op stub kernels.
     *
     * Used by tests that exercise the router's own 404 logic and do not need
     * to assert anything about kernel invocation.
     */
    private function buildRouterWithStubKernels(): FrontControllerRouter
    {
        $registry = new KernelRegistry(
            static fn () => new class implements HttpKernelInterface {
                public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
                {
                    return new Response();
                }
            },
            static fn () => new class implements HttpKernelInterface {
                public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
                {
                    return new Response();
                }
            },
        );

        return new FrontControllerRouter($registry);
    }
}
