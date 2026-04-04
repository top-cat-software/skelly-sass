# ADR-0006: RESTful API as Primary Interface

**Date**: 2026-04-04
**Status**: Accepted
**Deciders**: Project Architect (IC7), Project Owner, Principal PHP (IC6), Principal JS (IC6), Security Design Expert (IC6)

## Context

The SaaS skeleton needs an API layer that the front-end and potentially third-party integrations consume. The API must:

- Expose CRUD operations for core resources (users, tenants, configuration)
- Support authentication via JWT bearer tokens
- Be well-documented and easy for adopters to extend
- Handle both synchronous reads and async command dispatch (via Pulsar)

The project owner is open to either REST or GraphQL.

## Options Considered

### Option 1: RESTful API (JSON:API or similar convention)
Standard resource-oriented HTTP API.
- **Pros**: Universally understood, excellent tooling (OpenAPI/Swagger), simple caching via HTTP semantics, mature PHP frameworks, easy to debug with standard HTTP tools.
- **Cons**: Over-fetching/under-fetching for complex UIs, multiple round-trips for related resources, versioning can be awkward.

### Option 2: GraphQL
Query language allowing clients to request exactly the data they need.
- **Pros**: Eliminates over-fetching, single endpoint, strong typing via schema, good for complex nested data.
- **Cons**: Caching is harder (no HTTP-level caching), N+1 query problems require DataLoader patterns, more complex server implementation, harder to rate-limit, PHP GraphQL libraries are less mature than REST tooling.

### Option 3: REST + GraphQL (Hybrid)
REST for simple CRUD, GraphQL for complex query patterns.
- **Pros**: Best of both worlds for different use cases.
- **Cons**: Two API paradigms to maintain, document, and test — significant overhead for a skeleton project.

## Decision

Use **RESTful API** as the primary and sole API interface, built on Symfony 7 (ADR-0008).

### Rationale

- **Simplicity**: REST is the simpler choice for a skeleton project. Adopters can add GraphQL later if their specific use case demands it — the clean service layer (ADR-0008) means a GraphQL resolver can call the same domain services.
- **Tooling**: OpenAPI/Swagger generation, Postman, curl — the REST ecosystem is unmatched for developer experience.
- **Caching**: HTTP caching semantics (ETags, Cache-Control) work natively with REST.
- **PHP ecosystem**: Symfony's HttpKernel, routing, and controller system are extremely mature for REST APIs.
- **SaaS skeleton scope**: the skeleton's API is relatively simple (auth, tenant CRUD, config). GraphQL's strengths emerge with complex, deeply nested data — not the initial use case.

### API Architecture: Symfony Controllers + Domain Services

Built on Symfony 7 (ADR-0008), the API uses Symfony controllers calling domain services:

```
Request → Middleware Pipeline → Controller → Domain Service → Response
```

The middleware pipeline executes in this order (outer to inner):
1. **Error Handler** — catches exceptions, formats RFC 7807 responses
2. **HTTP Security Headers** — adds security response headers
3. **CORS** — handles preflight and response headers
4. **Rate Limiter** — per-IP, per-user, and per-write throttling (Symfony RateLimiter)
5. **Auth** — validates JWT, populates request with user context (default-deny)
6. **Validation** — validates request body/query against schema (Symfony Validator)
7. **Controller** — endpoint logic

### Authentication Matrix

The auth middleware defaults to **deny** — endpoints must be explicitly marked as public. This prevents accidentally exposing new endpoints without auth.

| Endpoint Pattern | Auth Required | Notes |
|-----------------|---------------|-------|
| `POST /auth/*` | No (public) | Registration, login, password reset, social login |
| `GET /.well-known/jwks.json` | No (public) | JWT public key discovery |
| `GET /openapi.json` | No (public) | API documentation |
| `GET /health` | No (public) | Kubernetes health check |
| `GET /v1/*` | Yes (JWT) | All read endpoints |
| `POST/PUT/DELETE /v1/*` | Yes (JWT) | All write endpoints |

### API Design Conventions

- **JSON responses** with consistent envelope: `{ "data": ..., "meta": ..., "errors": ... }`
- **Versioning**: URI-based (`/v1/`) — "The skeleton ships with `/v1/`. When `/v2/` is needed, create a new route group — do not modify `/v1/` endpoints."
- **Sparse fieldsets**: list endpoints support `?fields=name,email` to reduce over-fetching without GraphQL complexity.
- **Pagination**: cursor-based with opaque base64-encoded cursors. Responses include full URLs for next/prev:
  ```json
  {
    "data": [...],
    "meta": {
      "links": {
        "next": "/v1/users?cursor=eyJpZCI6MTAwfQ==&per_page=25",
        "prev": "/v1/users?cursor=eyJpZCI6NTF9&per_page=25"
      },
      "per_page": 25
    }
  }
  ```
- **Error format**: RFC 7807 Problem Details with `violations` extension for validation errors:
  ```json
  {
    "type": "https://docs.skelly-saas.dev/errors/validation-failed",
    "title": "Validation Failed",
    "status": 422,
    "detail": "The request body contains invalid data.",
    "violations": [
      { "field": "email", "message": "Must be a valid email address." }
    ]
  }
  ```
  The `type` URI points to documentation, never exposing internal error codes.
- **Documentation**: auto-generated OpenAPI 3.1 spec from PHP 8 attributes via `nelmio/api-doc-bundle` (Symfony integration). Served at `/openapi.json` with Swagger UI at `/docs` (development only).

### OpenAPI and TypeScript Client

- The OpenAPI spec is generated in CI and committed to the repository for versioning.
- TypeScript types are auto-generated from the OpenAPI spec via `openapi-typescript` and published as part of the frontend package, keeping frontend types in sync with the API contract.

### Input Validation and Security

- **All** request input (body, query params, path params, headers) validated via Symfony Validator with strict type coercion.
- **Maximum request body size**: 1 MB default (configurable). Prevents DoS via large payloads.
- **JSON parsing**: reject duplicate keys to prevent value-override exploitation.
- **Parameterised queries only**: enforced via Doctrine DBAL (ADR-0002). Raw SQL string interpolation is prohibited.

### Rate Limiting

Implemented via Symfony RateLimiter component with a persistent store (Redis or PostgreSQL) for multi-pod consistency:

| Scope | Limit | Window | Notes |
|-------|-------|--------|-------|
| Global per-IP | 1000 requests | 1 minute | Broad DDoS protection |
| Authenticated per-user | 100 requests | 1 minute | Abuse prevention |
| Write endpoints per-user | 20 requests | 1 minute | Prevents bulk data modification |

Returns `429 Too Many Requests` with `Retry-After` header. Auth-specific rate limits are defined in ADR-0003.

### CORS

Configured via Symfony middleware:
- Allowed origin: specific frontend domain (configurable via environment variable). **Never** `*` with credentials, never reflect the `Origin` header.
- Allowed headers: `Authorization`, `Content-Type`.
- `Access-Control-Allow-Credentials: true` for cookie-based auth.
- Preflight cache: `Access-Control-Max-Age: 86400`.
- Development: allow `http://localhost:*` origins.

### HTTP Security Headers

Added via middleware on all responses:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Strict-Transport-Security: max-age=31536000; includeSubDomains
Cache-Control: no-store                  (for authenticated responses)
```

Response headers must NOT include `X-Powered-By`, server version, or framework identification.

### Information Disclosure Prevention

- RFC 7807 `detail` field contains user-friendly messages, never stack traces or SQL errors.
- In production: `display_errors = Off`, custom error handler returns RFC 7807.
- The `type` URI points to documentation, not internal error codes.

## Consequences

### Positive
- Universally understood by developers — low barrier to adoption
- Excellent tooling for documentation, testing, and client generation
- HTTP caching works natively
- Sparse fieldsets address the most common over-fetching scenarios
- Auto-generated TypeScript types keep frontend and API in sync
- Default-deny auth and comprehensive rate limiting provide a secure baseline
- RFC 7807 errors are structured and machine-parseable

### Negative
- Over-fetching on complex screens may still require multiple API calls (sparse fieldsets help but don't eliminate)
- URI versioning can lead to duplication if not governed
- OpenAPI attributes add verbosity to controller classes

### Risks
- If adopters need complex nested queries, they may outgrow REST — mitigated by clean service layer boundaries that could support a GraphQL resolver layer later
- URI versioning could proliferate if not governed — skeleton ships with clear versioning guidelines
- Rate limit counter store (Redis/PostgreSQL) adds a dependency — in-memory counters don't work with multiple API pods

## Related Decisions

- [ADR-0001](0001-high-level-architecture.md) — API gateway as a component, trust boundaries
- [ADR-0003](0003-oauth2-server-with-php.md) — Auth endpoints, auth-specific rate limiting
- [ADR-0005](0005-svelte-frontend.md) — Front-end consuming this API via SvelteKit server routes
- [ADR-0008](0008-use-symfony-framework.md) — Symfony 7 as the API framework, RateLimiter, Validator, Security components
