# ADR-0008: Use Symfony 7 as the PHP Framework

**Date**: 2026-04-04
**Status**: Accepted
**Deciders**: Principal PHP (IC6), Project Owner, Project Architect (IC7)

## Context

The SaaS skeleton requires a PHP framework for both the API and Auth applications. The skeleton is explicitly designed to be batteries-included — adopters get a production-ready starting point, not a minimal scaffold they must build up.

The confirmed feature scope includes:

- RESTful API with OpenAPI documentation (ADR-0006)
- OAuth 2.0 server with registration, login, 2FA, social login (ADR-0003)
- Async worker processing via Redis Streams (ADR-0009)
- In-app inbox / messaging system
- WebSocket / real-time push notifications
- Multi-channel notifications (email, in-app, push)
- Additional features not yet scoped

The team's primary expertise is PHP. The project owner has strong Symfony familiarity expectations.

## Options Considered

### Option 1: Slim Framework 4
Lightweight PSR-7/PSR-15 micro-framework with routing and middleware.
- **Pros**: Minimal footprint, no framework opinions to fight, simple request lifecycle, fast bootstrap, PSR-compliant throughout.
- **Cons**: Provides only routing and middleware — every other capability (DI container, console commands, messaging, validation, serialisation, rate limiting, notifications, real-time push) must be sourced and integrated manually. For a batteries-included skeleton, this means building and maintaining a custom framework from Slim + dozens of standalone packages.

### Option 2: Laravel 11
Full-featured framework with extensive ecosystem (Horizon, Echo, Reverb, Sanctum).
- **Pros**: Large community, excellent developer experience, built-in WebSocket support (Reverb), queue system, notification system, extensive first-party packages.
- **Cons**: Heavier convention-over-configuration approach, Eloquent ORM is tightly coupled (conflicts with our Doctrine DBAL decision in ADR-0002), facade pattern obscures dependencies, less PSR-compliant, harder to extract components independently. Reverb is Laravel-specific — not portable.

### Option 3: Symfony 7 (Selected)
Component-based full-stack framework with 50+ decoupled components.
- **Pros**: Components are independently usable and PSR-compliant, excellent DI with autowiring, Messenger component provides transport abstraction (built-in Redis transport, Pulsar available as upgrade), native Mercure integration for real-time push, Notifier for multi-channel notifications, RateLimiter component, Serializer, Validator, Console, Mailer — all battle-tested. Doctrine DBAL/Migrations integrate natively. Strong typing and explicit configuration.
- **Cons**: Steeper initial learning curve than Slim, heavier bootstrap than a micro-framework, configuration can be verbose, compiled container adds a build step.

## Decision

Use **Symfony 7** as the PHP framework for both the API and Auth applications.

### Rationale

The deciding factors, in order of weight:

1. **Messenger + built-in Redis transport**: Symfony Messenger provides transport abstraction with a first-party Redis Streams transport (`symfony/redis-messenger`). No custom transport code needed. Routing, retry, serialisation, middleware, and handler dispatch come free. Upgrading to Pulsar later is a DSN change + adding a custom transport class — no application code changes. See ADR-0009.

2. **Mercure for real-time push**: The inbox and push notification requirements need server-to-browser real-time delivery. Mercure is a purpose-built protocol with a lightweight Go hub server. Symfony has native integration — publishing an update is a single service call. This is simpler and more scalable than raw WebSockets for notification/inbox use cases, works behind load balancers without sticky sessions, and doesn't require a separate PHP long-running process.

3. **Notifier for multi-channel notifications**: Symfony Notifier provides a unified API for email, SMS, chat, browser push, and custom channels. The inbox becomes a custom notification channel. Adopters can add channels without changing application code.

4. **RateLimiter component**: The security review (ADR-0003, issue #3) requires per-endpoint, per-account rate limiting with sliding window. Symfony's RateLimiter component provides exactly this — token bucket and sliding window algorithms, configurable per-route. No need to build custom middleware.

5. **Doctrine integration**: Symfony integrates natively with Doctrine DBAL and Migrations (ADR-0002). No adapter layer needed.

6. **Future feature headroom**: The "features not yet scoped" factor. Symfony's component library means most future needs (workflow engine, lock/semaphore, HTTP client, cache, scheduler) have a tested, maintained implementation. The skeleton doesn't need to ship them all — but adopters can pull them in without fighting the framework.

### Application Structure

Following the Principal PHP recommendation from issue #1, the API and Auth applications share a container image with separate entry points:

```
src/
├── Api/                    # API application (Symfony kernel)
│   ├── Controller/         # API controllers
│   ├── Middleware/          # PSR-15 middleware (CORS, auth, etc.)
│   └── Resource/           # API resource DTOs
├── Auth/                   # Auth application (Symfony kernel)
│   ├── Controller/         # Auth endpoints (token, register, 2FA)
│   ├── Grant/              # OAuth2 grant types
│   └── Provider/           # Social login providers
├── Domain/                 # Shared domain logic
│   ├── Entity/             # Domain entities
│   ├── Repository/         # Repository interfaces
│   ├── Event/              # Domain events
│   └── Service/            # Domain services
├── Infrastructure/         # Shared infrastructure
│   ├── Database/           # Doctrine DBAL repositories, migrations
│   ├── Messaging/          # Redis transport config, message handlers
│   ├── Notification/       # Notifier channels (inbox, push)
│   └── Security/           # JWT validation, password hashing
└── Worker/                 # Worker entry point
    ├── Handler/            # Messenger message handlers
    └── Console/            # Console commands
```

Traefik routes `/auth/*` to the Auth kernel and `/api/*` to the API kernel. Both share the Domain and Infrastructure layers as a library dependency within the same codebase.

### Key Symfony Components in Use

| Component | Purpose | Replaces |
|-----------|---------|----------|
| **HttpKernel** | Request handling, controller dispatch | Slim router |
| **DependencyInjection** | Autowired, compiled DI container | Manual PHP-DI setup |
| **Messenger** | Async message dispatch + Redis transport (built-in) | Custom MessageProducer/Consumer interfaces |
| **Mercure** | Real-time push to browsers (SSE) | Custom WebSocket server |
| **Notifier** | Multi-channel notifications | Custom notification system |
| **RateLimiter** | Per-endpoint, per-account throttling | Custom rate limiting middleware |
| **Serializer** | JSON serialisation/deserialisation | Manual DTO mapping |
| **Validator** | Request/DTO validation via attributes | Manual validation |
| **Console** | CLI commands for workers, migrations, admin tasks | Standalone symfony/console |
| **Mailer** | Email dispatch (async via Messenger) | Standalone mailer package |
| **Security** | Authenticators, firewalls, token handling | Manual JWT middleware |

### What We Do NOT Use

- **Doctrine ORM** — we use DBAL directly (ADR-0002). No entity manager, no annotations-driven schema.
- **Twig** — the front-end is SvelteKit (ADR-0005). No server-rendered templates except for transactional emails (where Twig is acceptable).
- **Symfony Forms** — API-only, no HTML form rendering. Validation via Validator component on DTOs.
- **Asset Mapper / Webpack Encore** — front-end build is SvelteKit's concern.

## Consequences

### Positive
- Batteries-included: inbox, push notifications, async messaging, rate limiting, validation all have framework-level support
- Messenger + built-in Redis transport eliminates the need for any custom messaging code
- Mercure gives real-time push without a custom WebSocket server or PHP long-running process
- Doctrine DBAL integrates natively — no adapter layer
- Adopters can add Symfony components incrementally as their requirements grow
- Large community, extensive documentation, long-term support (Symfony 7 LTS)
- Symfony's explicit, configuration-driven approach makes the skeleton easy to understand and modify

### Negative
- Steeper learning curve than Slim for developers unfamiliar with Symfony
- Heavier bootstrap and memory footprint than a micro-framework
- Compiled DI container requires a cache warmup step (adds to container build time)
- Symfony's conventions (bundles, config files, service definitions) add boilerplate

### Risks
- Symfony version upgrades can require significant migration effort — mitigated by targeting an LTS release and following Symfony's deprecation policy
- Mercure hub is a separate Go process to deploy — adds one more component to the Helm chart. Mitigated by Mercure's tiny footprint (~10MB binary, ~30MB RAM)
- Over-reliance on Symfony-specific patterns could make the skeleton harder to adopt for teams committed to other frameworks — mitigated by keeping domain logic framework-agnostic (Domain/ and Infrastructure/ layers have no Symfony imports)

## Related Decisions

- [ADR-0001](0001-high-level-architecture.md) — component architecture that this framework serves
- [ADR-0002](0002-use-postgresql.md) — Doctrine DBAL integration
- [ADR-0003](0003-oauth2-server-with-php.md) — League OAuth2 Server integrated via Symfony Security authenticators
- [ADR-0009](0009-redis-streams-for-messaging.md) — Redis Streams via Symfony Messenger built-in transport
- [ADR-0006](0006-restful-api-first.md) — API structure built on Symfony HttpKernel + controllers
- [ADR-0007](0007-container-orchestration-with-kubernetes.md) — Mercure hub added to Helm chart
- Supersedes the Slim Framework recommendation made in the Principal PHP review on issue #3
