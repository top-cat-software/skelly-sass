# ADR-0001: High-Level Architecture

**Date**: 2026-04-04
**Status**: Accepted
**Deciders**: Project Architect (IC7), Project Owner

## Context

We are building **skelly-saas**, an open-source SaaS skeleton framework licensed under MIT, owned by Top Cat Software Ltd. The goal is a batteries-included starting point for spinning up new SaaS services, covering authentication, API, async processing, front-end, and deployment infrastructure.

The system must be:
- **Modular**: each concern (auth, API, workers, front-end, infrastructure) should be independently deployable and replaceable.
- **Production-ready**: ships with Helm charts, Docker images, CI/CD patterns, and observability hooks.
- **Opinionated but extensible**: sensible defaults that can be overridden by teams adopting the skeleton.

The team has strong PHP expertise. The front-end will be handled by a dedicated specialist. Data and security specialists are available for consultation.

## Options Considered

### Option 1: Monolithic Application
A single deployable unit containing API, auth, workers, and server-rendered front-end.
- **Pros**: Simple deployment, no inter-service communication overhead, fast initial development.
- **Cons**: Cannot scale components independently, harder to replace individual parts, couples front-end to back-end deployment lifecycle.

### Option 2: Modular Monolith with Separate Front-End
A single back-end deployable with clearly separated internal modules (auth, API, workers), plus an independently deployed SPA front-end.
- **Pros**: Simpler than full microservices, clear boundaries without network overhead, front-end deploys independently.
- **Cons**: Workers share process with API (scaling constraints), harder to extract modules later if boundaries erode.

### Option 3: Component-Based Architecture (Selected)
Separate deployable components — API gateway, OAuth server (co-deployed initially), async workers, front-end SPA — communicating via REST and message streams, orchestrated with Kubernetes.
- **Pros**: Independent scaling, clear contracts between components, workers isolated from request path, each component can evolve independently.
- **Cons**: More infrastructure to manage, operational complexity, requires Kubernetes expertise.

## Decision

Adopt **Option 3: Component-Based Architecture**. The skeleton is designed to be a production starting point, not a prototype. Teams adopting it will need independent scaling and clear component boundaries from day one.

### Component Map

```
┌─────────────────────────────────────────────────────────┐
│                     Ingress (Traefik)                    │
│                                                         │
│   ┌──────────┐   ┌──────────────┐   ┌───────────────┐  │
│   │ Frontend  │   │   API        │   │  OAuth 2.0    │  │
│   │ (Svelte)  │──▶│   Gateway    │──▶│  Server       │  │
│   │           │   │   (PHP)      │   │  (PHP)        │  │
│   └──────────┘   └──────┬───────┘   └───────────────┘  │
│                         │                               │
│                         ▼                               │
│                  ┌──────────────┐                        │
│                  │   Redis      │                        │
│                  │   (Streams)  │                        │
│                  └──────┬───────┘                        │
│                         │                               │
│                         ▼                               │
│                  ┌──────────────┐   ┌───────────────┐   │
│                  │   Workers    │──▶│  PostgreSQL    │   │
│                  │   (PHP)      │   │               │   │
│                  └──────────────┘   └───────────────┘   │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

### Component Responsibilities

| Component | Technology | Responsibility |
|-----------|-----------|----------------|
| **Frontend** | Svelte + Bits UI | User-facing SPA — auth flows, dashboard shell |
| **API Gateway** | Symfony 7 (PHP) | RESTful API, request validation, auth middleware, command dispatch |
| **OAuth 2.0 Server** | Symfony 7 (PHP) | User registration, login, password reset, 2FA, social login (Google, GitHub) |
| **Message Streams** | Redis Streams | Async command/event transport between API and workers via Symfony Messenger |
| **Workers** | Symfony 7 (PHP) | Process async jobs via Symfony Messenger — email, provisioning, background tasks |
| **Mercure Hub** | Mercure (Go) | Real-time push to browsers via SSE — notifications, inbox updates |
| **Data Store** | PostgreSQL + PgBouncer | Primary OLTP store, materialised views for read-heavy queries, connection pooling |
| **Ingress** | Traefik | TLS termination, routing |
| **Orchestration** | Kubernetes + Helm | Deployment, scaling, service discovery |

### Co-Deployment Model

The API and OAuth Server are **separate Symfony applications sharing the same container image**, each with its own entry point and kernel bootstrap. Traefik routes `/auth/*` to the Auth kernel and `/api/*` to the API kernel within the same pod. This makes future extraction trivial (deploy the same image with a different entry point) without adding overhead now.

Both applications share a common **Domain** and **Infrastructure** library layer (same Composer package, no network calls). Workers also share this domain library — they operate on the database directly using the same domain/service layer code as the API, avoiding duplication while keeping deployment independent.

### Communication Patterns

1. **Frontend (SvelteKit server) → API**: HTTPS/REST with JWT bearer tokens. Browser sessions managed via httpOnly cookies — the browser never directly handles JWTs.
2. **Frontend (browser) → OAuth Server**: Redirect-based flows for login, registration, and social auth (Authorization Code + PKCE). Token exchange handled by SvelteKit server routes.
3. **API → OAuth Server**: Shared PHP interface within the same pod (not internal HTTP). The interface is designed so that swapping to HTTP later requires only a new implementation, not changes to calling code.
4. **API → Workers**: Redis Streams via Symfony Messenger's built-in Redis transport (fire-and-forget commands, request-reply where needed).
5. **Workers → Data Store**: PostgreSQL connection via PgBouncer (transaction mode). Workers acquire a connection at job start and release it at job end.
6. **API → Data Store**: PostgreSQL connection via PgBouncer for synchronous reads.

### Service Discovery

Components discover each other via **Kubernetes cluster DNS** (e.g. `api.default.svc.cluster.local`). Service names are defined in Helm values and injected as environment variables. No external service registry is required.

### Trust Boundaries

```
┌─────────────────────────────────────────────────────────────────┐
│ UNTRUSTED — Public Internet                                     │
│                                                                 │
│   Browsers, third-party API consumers, OAuth provider callbacks │
└────────────────────────┬────────────────────────────────────────┘
                         │ TLS termination
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ BOUNDARY 1 — Ingress (Traefik)                                  │
│                                                                 │
│   All inbound traffic is TLS-terminated and validated here.     │
│   Traefik handles TLS termination and routing only.             │
│   Rate limiting is the API's responsibility (Symfony            │
│   RateLimiter), not Traefik's — per-account limits require      │
│   application context.                                          │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ SEMI-TRUSTED — Cluster Internal Network                         │
│                                                                 │
│   Component-to-component communication within the Kubernetes    │
│   cluster. Assumed trusted for the initial skeleton — no mTLS   │
│   between services. This is an explicit trade-off: mTLS adds    │
│   operational complexity disproportionate to the skeleton's     │
│   threat model. Noted as a production hardening enhancement.    │
│                                                                 │
│   Redis is configured with password authentication (AUTH)       │
│   and optionally ACLs to restrict per-component access          │
│   (API can publish, workers can consume). See ADR-0009.         │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│ TRUSTED — Data Layer                                            │
│                                                                 │
│   PostgreSQL and PgBouncer. Connection authenticated via        │
│   credentials with least-privilege separation:                  │
│   - API/Auth: DML-only credentials                              │
│   - Workers: DML-only credentials                               │
│   - Migration runner: DDL + DML credentials                     │
│   Credentials stored as Kubernetes Secrets.                     │
└─────────────────────────────────────────────────────────────────┘
```

### Network Communication Matrix

| Source | Destination | Protocol | Auth | Allowed? |
|--------|------------|----------|------|----------|
| Browser | Traefik (Ingress) | HTTPS | TLS | Yes |
| Traefik | Frontend (SvelteKit) | HTTP | None (cluster-internal) | Yes |
| Traefik | API / Auth | HTTP | None (cluster-internal) | Yes |
| Frontend | API / Auth | HTTP | None (cluster-internal) | Yes |
| API | Redis | TCP | Redis AUTH password | Yes |
| API | PostgreSQL (via PgBouncer) | TCP | DB credentials | Yes |
| Workers | Redis | TCP | Redis AUTH password | Yes |
| Workers | PostgreSQL (via PgBouncer) | TCP | DB credentials | Yes |
| API | Mercure Hub | HTTP | JWT publisher token | Yes |
| Frontend | Mercure Hub | SSE | JWT subscriber token | Yes |
| Frontend | PostgreSQL | — | — | **No** |
| Frontend | Redis | — | — | **No** |
| Workers | Traefik | — | — | **No** |

**NetworkPolicies**: the skeleton should document intended network restrictions (the "No" rows above) even if Kubernetes NetworkPolicies are not enforced in the initial release. Adopters deploying to production should implement NetworkPolicies to enforce this matrix.

### Secrets Management

All component secrets (database credentials, JWT signing keys, Redis password, social login API keys) are managed via **Kubernetes Secrets**, injected as environment variables or mounted as files.

- **JWT signing keys** are mounted as files (not environment variables) to avoid accidental logging. See ADR-0003 for key management details.
- **Database credentials** are separated per component with least-privilege access. See ADR-0002.
- **Redis password** is shared across components that access Redis (API, workers). See ADR-0009.
- Secret management strategy (Sealed Secrets, SOPS, or External Secrets Operator) is defined in ADR-0007.

### Local Development Resource Requirements

Running the full stack locally (API, Auth, Workers, Redis, PostgreSQL, PgBouncer, Traefik, Frontend, Mercure Hub) requires:

- **Minimum**: 4 GB RAM allocated to Docker, 2 CPU cores
- **Recommended**: 8 GB RAM, 4 CPU cores

A **minimal profile** is provided via Helm values that runs only essential components (API, Auth, PostgreSQL, Redis, Frontend) without workers, for frontend-focused development or machines with limited resources.

## Consequences

### Positive
- New SaaS projects get auth, async processing, and deployment out of the box
- Each component can be scaled independently (e.g. more workers without more API pods)
- PHP ecosystem expertise can be leveraged across API, OAuth, and workers
- Clear contracts make it possible to swap components (e.g. replace PHP workers with Go)

### Negative
- Multiple deployment units increase initial infrastructure setup time
- Teams unfamiliar with Kubernetes face a steeper learning curve
- More moving parts to monitor and debug

### Risks
- Over-engineering for small teams who may not need independent scaling initially
- Redis single-node bottleneck under extreme load (mitigated by Sentinel/Cluster upgrade path and Pulsar escape hatch — see ADR-0009)
- PHP worker processes need careful lifecycle management (memory leaks, signal handling)

## Related Decisions

- [ADR-0002](0002-use-postgresql.md) — PostgreSQL as primary data store
- [ADR-0003](0003-oauth2-server-with-php.md) — OAuth 2.0 server implementation
- [ADR-0004](0004-message-streaming-with-pulsar.md) — Original Pulsar decision (superseded by ADR-0009)
- [ADR-0005](0005-svelte-frontend.md) — Front-end technology choice
- [ADR-0006](0006-restful-api-first.md) — API style decision
- [ADR-0007](0007-container-orchestration-with-kubernetes.md) — Deployment infrastructure
- [ADR-0008](0008-use-symfony-framework.md) — Symfony 7 as the PHP framework
- [ADR-0009](0009-redis-streams-for-messaging.md) — Redis Streams as default message transport
