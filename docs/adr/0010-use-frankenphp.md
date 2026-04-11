# ADR-0010: Use FrankenPHP as PHP Application Server

**Date**: 2026-04-11
**Status**: Accepted
**Deciders**: Project Architect (IC7), Project Owner

## Context

[ADR-0001](0001-high-level-architecture.md) establishes a component-based architecture in which the API and OAuth Server are PHP applications served over HTTP inside Kubernetes pods. The traditional approach for this is a two-process model: Nginx handling HTTP and PHP-FPM handling PHP execution, communicating via FastCGI.

The walking skeleton currently uses `php:8.4-cli-alpine` with PHP's built-in development server — a placeholder with no production viability. A production-grade application server must be selected before the skeleton can be considered shippable.

[ADR-0001](0001-high-level-architecture.md) also lists a standalone **Mercure Hub** (Go process) as a dedicated component for real-time SSE push to browsers. This adds a ninth component type to the topology, with its own Helm sub-chart, Kubernetes deployment, health checks, and scaling configuration.

The following forces drive this decision:

- **Process count per pod**: Nginx + FPM requires two processes in every PHP pod, complicating Dockerfile construction, health check routing, and resource allocation.
- **Per-request bootstrap cost**: PHP-FPM terminates the process after each request (or recycles workers under load). For Symfony, this means bootstrapping the dependency injection container on every request — a measurable overhead.
- **Topology complexity**: The project already manages nine component types. Reducing distinct processes and containers reduces operational surface area for adopters.
- **Real-time push**: A standalone Mercure Hub adds deployment and operational cost. A solution that co-locates Mercure handling with the API is desirable.

## Options Considered

### Option 1: Nginx + PHP-FPM (Traditional)

Two-process model: Nginx serves static assets and proxies PHP requests via FastCGI to a PHP-FPM pool. Battle-tested and universally understood.

- **Pros**: Extensive production history across millions of deployments; comprehensive documentation and community knowledge; well-understood operational model; Alpine-based images available; no worker-mode memory concerns.
- **Cons**: Two processes per pod (Nginx + FPM) — complicates Dockerfile, resource limits, and liveness/readiness probe routing; requires FastCGI socket or TCP configuration between Nginx and FPM; separate Mercure deployment still required; per-request kernel bootstrap cost remains; more complex health check setup (Nginx stub status endpoint needed separately from application health).

### Option 2: FrankenPHP (Selected)

A modern PHP application server built on top of the Caddy web server, distributed as a single binary. Embeds the PHP interpreter directly and supports a long-lived worker mode where the Symfony kernel is bootstrapped once and reused across requests.

- **Pros**: Single binary replaces both Nginx and FPM; worker mode eliminates per-request Symfony kernel bootstrap; native Mercure hub embedding eliminates the standalone Mercure deployment; HTTP/3 and Early Hints (103) support out of the box; first-class Symfony support via `runtime/frankenphp-symfony`; simpler Dockerfile and Kubernetes pod configuration; liveness and readiness probes hit the application HTTP server directly — no stub endpoint needed.
- **Cons**: Younger project than PHP-FPM (less production battle-testing); worker mode requires careful memory management and service reset discipline; built on Caddy, which has TLS and routing capabilities that overlap with Traefik — Caddyfile must be configured to avoid conflicts; official Docker images are Debian-based rather than Alpine, resulting in larger image sizes.

### Option 3: RoadRunner or Swoole

Alternative worker-mode PHP runtimes: RoadRunner (Go-based application server with a PHP plugin) or Swoole (PECL extension that turns PHP into an event-driven runtime).

- **Pros**: Both offer worker-mode performance benefits; more mature than FrankenPHP in the long-lived PHP process space; RoadRunner is used in production at scale by several notable projects.
- **Cons**: Neither embeds a Mercure hub — the standalone Mercure deployment remains; less tight Symfony integration than FrankenPHP (no equivalent to `runtime/frankenphp-symfony`); RoadRunner requires a Go binary and a PHP plugin architecture, adding a separate dependency chain; Swoole requires a PECL extension with known compatibility constraints for certain PHP extensions and Symfony components.

## Decision

Adopt **Option 2: FrankenPHP** as the PHP application server for the API and OAuth Server components.

### Primary Motivation: Topology Simplification

The strongest driver is reducing operational complexity. FrankenPHP replaces the two-process Nginx + FPM model with a single binary per pod. More significantly, its embedded Mercure hub eliminates the standalone Mercure deployment entirely:

- The `mercure/` Helm sub-chart is removed from the umbrella chart.
- The Mercure Hub row is removed from the network communication matrix in ADR-0001.
- JWT publisher and subscriber token verification is handled by the embedded hub, co-located with the application that generates those tokens.
- The API pod serves SSE endpoints (`/hub`) directly, routed there by Traefik alongside `/api/*` and `/auth/*`.

The revised component topology removes the standalone Mercure Hub as a separate deployment unit:

```
┌─────────────────────────────────────────────────────────┐
│                     Ingress (Traefik)                    │
│                                                         │
│   ┌──────────┐   ┌──────────────────────────────────┐  │
│   │ Frontend  │   │   API / OAuth Server Pod         │  │
│   │ (Svelte)  │──▶│   ┌──────────────────────────┐  │  │
│   │           │   │   │  FrankenPHP              │  │  │
│   └──────────┘   │   │  ├─ API Kernel            │  │  │
│                  │   │  ├─ Auth Kernel            │  │  │
│                  │   │  └─ Mercure Hub (embedded) │  │  │
│                  │   └──────────────────────────┘  │  │
│                  └──────────────┬───────────────────┘  │
│                                 │                       │
│                         ┌───────▼──────┐                │
│                         │   Redis      │                │
│                         │   (Streams)  │                │
│                         └──────┬───────┘                │
│                                │                        │
│                         ┌──────▼──────────┐ ┌────────┐  │
│                         │   Workers (PHP) │▶│  PG    │  │
│                         └─────────────────┘ └────────┘  │
└─────────────────────────────────────────────────────────┘
```

### Secondary Motivation: Worker Mode Performance

FrankenPHP's worker mode keeps the Symfony kernel bootstrapped in memory across requests. For Symfony applications with deep service graphs, this eliminates the most expensive part of request processing. Lower per-request CPU cost enables lower pod counts for the same throughput, reducing hosting cost for skeleton adopters.

### Escape Hatch

Symfony's Runtime component (`symfony/runtime`) abstracts the application server from application code. The application entry point (`public/index.php`) calls `$app->run()` against the runtime — it has no knowledge of whether the underlying server is FrankenPHP, Nginx + FPM, or RoadRunner. Reverting to Nginx + FPM requires only:

1. Swapping the Dockerfile base image from `dunglas/frankenphp` to `php:8.4-fpm-alpine` with an Nginx sidecar.
2. Updating Helm values to reflect the new pod configuration.

Zero application code changes are required. This is a low-risk decision to reverse.

### Scope

Symfony Messenger workers (`bin/console messenger:consume`) are **unaffected** by this decision. They are CLI processes, not HTTP-serving processes. They continue to run in separate Kubernetes deployments, consuming from Redis Streams, as defined in ADR-0001.

## Consequences

### Positive

- Single container per PHP pod simplifies Kubernetes resource limits, liveness probes, readiness probes, and Dockerfile construction.
- Mercure hub embedded in the API pod eliminates the standalone Mercure deployment, its Helm sub-chart, its health checks, and its independent scaling configuration.
- Worker mode reduces CPU and memory churn per request, enabling lower pod counts for equivalent throughput.
- Simpler Dockerfile: a single `FROM dunglas/frankenphp` base replaces multi-process supervisor configuration or sidecar patterns.
- Kubernetes liveness and readiness probes target the application HTTP server directly — no Nginx stub status endpoint is needed.
- HTTP/3 and Early Hints (103) are available natively. Symfony's `WebLink` component integrates with Early Hints out of the box, enabling resource hinting without additional infrastructure.
- Mercure scaling is now coupled to API pod scaling, which matches the typical relationship between SSE event publishing and API load.

### Negative

- FrankenPHP's official Docker images are Debian-based, not Alpine. This increases base image size compared to `php:8.4-fpm-alpine`. A static FrankenPHP build on Alpine is possible but less tested and not the recommended path.
- Caddy (the underlying web server in FrankenPHP) has TLS management and routing capabilities that overlap with Traefik. The Caddyfile must explicitly disable automatic HTTPS and configure FrankenPHP to listen only on the pod's internal port, deferring all TLS termination and routing to Traefik.
- If adopters require independent Mercure scaling (separate from API pod scaling), they must extract the embedded hub into a standalone FrankenPHP or Mercure deployment. This is a documented upgrade path, not a blocker.
- Debugging in worker mode is more complex than stateless FPM. State persists across requests; Xdebug requires additional configuration to attach to worker processes rather than individual request forks.

### Risks

- **Worker mode memory leaks**: Long-lived PHP processes accumulate state if services hold references between requests. Mitigated by Symfony's `kernel.reset` mechanism — all services with mutable state must implement `ResetInterface` or be tagged appropriately. Both `ApiKernel` and `AuthKernel` must be audited for clean resets during implementation. This is a standard Symfony worker-mode concern with established patterns.
- **Extension compatibility**: Not all PHP extensions behave correctly in a long-lived worker process. The standard extension set (`redis`, `pdo_pgsql`, `pcntl`, `intl`, `opcache`, `zip`) is expected to be compatible, but this must be validated in CI against the FrankenPHP base image.
- **Maturity**: FrankenPHP is younger than PHP-FPM. This risk is mitigated by two factors: the Symfony Runtime escape hatch means any reversal requires only infrastructure changes; and FrankenPHP is used by Symfony's own hosting platform (Platform.sh / Upsun), providing a meaningful production reference.
- **Worker-unsafe Symfony bundles**: Not all community bundles are safe to use in worker mode. Bundles that hold mutable static state or do not implement `ResetInterface` can cause cross-request contamination. Bundles must be validated for worker compatibility during implementation and documented in the project's operations guide.

## Related Decisions

- [ADR-0001](0001-high-level-architecture.md) — component map topology changes: Mercure Hub removed as a standalone component; network communication matrix updated
- [ADR-0007](0007-container-orchestration-with-kubernetes.md) — Helm chart strategy: `mercure/` sub-chart removed from umbrella chart; Docker image base changed from FPM to FrankenPHP
- [ADR-0008](0008-use-symfony-framework.md) — runtime entry point change to `runtime/frankenphp-symfony`; MercureBundle integration with embedded hub
