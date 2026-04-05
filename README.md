# skelly-saas

An open-source SaaS platform skeleton framework, licensed under MIT, owned by Top Cat Software Ltd.

## Repository Structure

```
skelly-saas/
├── api/                        # Symfony 7 PHP (API, Auth, Workers)
│   ├── src/
│   │   ├── Api/                # API controllers, middleware, resource DTOs
│   │   ├── Auth/               # OAuth endpoints, grant types, social providers
│   │   ├── Domain/             # Shared domain entities, repositories, events, services
│   │   ├── Infrastructure/     # Database, messaging, notifications, security
│   │   └── Worker/             # Message handlers, console commands
│   ├── config/                 # Symfony configuration
│   ├── composer.json
│   └── Dockerfile
├── frontend/                   # SvelteKit + Bits UI
│   ├── src/
│   │   ├── lib/                # Components, API client, stores, utilities
│   │   ├── routes/             # SvelteKit pages and server routes
│   │   └── hooks.server.ts     # Auth guard, CSP headers
│   ├── package.json
│   └── Dockerfile
├── helm/                       # Kubernetes deployment
│   └── skelly-saas/            # Umbrella Helm chart with sub-charts
│       ├── charts/             # api, frontend, workers, mercure, ingress
│       ├── values.yaml         # Global defaults
│       ├── values-dev.yaml     # Development overrides
│       └── values-prod.yaml    # Production overrides
├── docs/                       # Documentation
│   └── adr/                    # Architecture Decision Records
├── .github/                    # CI/CD workflows
│   └── workflows/
├── Makefile                    # Developer commands (up, down, test, lint, etc.)
├── Tiltfile                    # Live-reload for local development
└── k3d-config.yaml             # Local Kubernetes cluster configuration
```

## Architecture

For detailed architectural decisions, see the [ADRs](docs/adr/README.md).

- **Back-end**: Symfony 7 PHP — RESTful API, OAuth 2.0 server (League), async workers
- **Front-end**: SvelteKit with Svelte 5, Bits UI for accessible headless components
- **Data store**: PostgreSQL with Doctrine DBAL, PgBouncer for connection pooling
- **Messaging**: Redis Streams via Symfony Messenger
- **Real-time**: Mercure hub for server-sent events
- **Deployment**: Kubernetes + Helm, Traefik ingress, k3d for local development
