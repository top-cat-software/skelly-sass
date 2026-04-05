# ADR-0007: Container Orchestration with Kubernetes and Helm

**Date**: 2026-04-04
**Status**: Accepted
**Deciders**: Project Architect (IC7), Project Owner, Principal Infrastructure (IC6), Security Design Expert (IC6)

## Context

The SaaS skeleton is designed as a set of independently deployable components (ADR-0001). We need:

- Container orchestration for all components (API, workers, front-end, Redis, PostgreSQL, Mercure)
- Declarative, versioned deployment configuration
- Service discovery and internal networking
- Scaling policies (particularly for workers)
- A local development story that mirrors production topology
- Infrastructure security baseline (network policies, RBAC, container hardening)

The project owner has specified Helm charts and custom Docker images as requirements.

## Options Considered

### Option 1: Kubernetes + Helm
Industry-standard container orchestration with templated deployment packages.
- **Pros**: De facto standard, massive ecosystem, supports any cloud provider, Helm charts provide versioned, parameterised deployments, good support for CRDs and operators.
- **Cons**: Complex to learn and operate, heavy for small deployments, YAML verbosity.

### Option 2: Docker Compose (Development) + Kubernetes (Production)
Compose for local development, Kubernetes for staging/production.
- **Pros**: Simpler local development experience, Docker Compose is widely known.
- **Cons**: Two deployment paradigms to maintain, drift between local and production environments, Compose lacks scaling and health-check sophistication.

### Option 3: Nomad + Consul
HashiCorp's orchestration stack.
- **Pros**: Simpler than Kubernetes, good service discovery via Consul, supports non-container workloads.
- **Cons**: Smaller ecosystem, fewer managed offerings, less community momentum, Helm-equivalent tooling (Levant/Nomad Pack) is less mature.

## Decision

Use **Kubernetes with Helm** for all environments, including local development via k3d.

### Rationale

- **Single paradigm**: one deployment model from development through production eliminates environment drift.
- **Helm charts**: provide a clean packaging model — adopters `helm install` and get a working stack.
- **Ecosystem**: Redis, PostgreSQL (Bitnami), Traefik, and Mercure all have official or well-maintained Helm charts.
- **Cloud portability**: Kubernetes runs on every major cloud provider and on-premises.

### Helm Chart Structure

```
helm/
├── skelly-saas/                    # Umbrella chart
│   ├── Chart.yaml                  # Dependencies: api, frontend, workers, ingress, mercure, redis
│   ├── values.yaml                 # Global defaults + per-component overrides
│   ├── values-dev.yaml             # Development overrides (resource reductions, debug flags)
│   ├── values-prod.yaml            # Production overrides (replicas, resource limits)
│   └── charts/
│       ├── api/                    # API + OAuth (co-deployed, separate Symfony kernels)
│       │   ├── Chart.yaml
│       │   ├── values.yaml
│       │   └── templates/
│       │       ├── deployment.yaml
│       │       ├── service.yaml
│       │       ├── hpa.yaml        # Horizontal Pod Autoscaler
│       │       ├── configmap.yaml
│       │       ├── serviceaccount.yaml
│       │       └── networkpolicy.yaml
│       ├── frontend/               # SvelteKit app
│       ├── workers/                # PHP worker fleet
│       ├── mercure/                # Mercure hub (SSE push)
│       └── ingress/                # Traefik IngressRoute CRDs
├── redis/                          # Referenced via Chart.yaml dependency (Bitnami)
└── postgresql/                     # Referenced via Chart.yaml dependency (Bitnami)
```

Separate `values-dev.yaml` and `values-prod.yaml` files for environment-specific configuration. The base `values.yaml` provides sensible defaults for all environments.

### Ingress: Traefik with IngressRoute CRDs

- **Traefik** as the ingress controller — lightweight, Kubernetes-native, supports automatic TLS via Let's Encrypt.
- Use **Traefik IngressRoute CRDs** (not standard Ingress resources) for Traefik-specific features (middleware chains, weighted routing) without annotations.
- Routing: `app.example.com` → frontend, `api.example.com` → API/Auth, configurable via Helm values.

### Local Development: k3d + Tilt

**k3d** (k3s in Docker) for the local Kubernetes cluster:

```yaml
# k3d-config.yaml
apiVersion: k3d.io/v1alpha5
kind: Simple
name: skelly-saas
servers: 1
agents: 1
ports:
  - port: 8080:80
    nodeFilters: [loadbalancer]
  - port: 8443:443
    nodeFilters: [loadbalancer]
options:
  k3s:
    extraArgs:
      - arg: --disable=traefik
        nodeFilters: [server:*]
```

k3d's bundled Traefik is disabled — the skeleton deploys its own Traefik via Helm for version control and configuration consistency.

**Tilt** for live-reload during development:
- Live-sync PHP source files to API/Auth containers (no rebuild needed).
- Live-sync SvelteKit source files to frontend container.
- Tilt's browser UI provides real-time logs, build status, and resource health.

### Resource Defaults

| Component | CPU Request | CPU Limit | Memory Request | Memory Limit | Replicas (dev) | Replicas (prod) |
|-----------|-----------|-----------|---------------|-------------|---------------|----------------|
| API | 250m | 500m | 256Mi | 512Mi | 1 | 2+ (HPA) |
| Frontend | 100m | 250m | 128Mi | 256Mi | 1 | 2+ (HPA) |
| Workers | 250m | 500m | 256Mi | 512Mi | 1 | 3+ (HPA) |
| Mercure Hub | 100m | 250m | 64Mi | 128Mi | 1 | 2 |
| Traefik | 100m | 250m | 128Mi | 256Mi | 1 | 2 |
| Redis | 50m | 100m | 64Mi | 128Mi | 1 | 1 (Sentinel/Cluster) |
| PostgreSQL | 250m | 500m | 256Mi | 512Mi | 1 | 1 (managed) |
| PgBouncer | 50m | 100m | 32Mi | 64Mi | 1 | 2 |

### Secret Management: Sealed Secrets

Use **Sealed Secrets** for the skeleton:
- Encrypt secrets client-side with `kubeseal`, commit the sealed secret to git, the Sealed Secrets controller decrypts in-cluster.
- No external dependencies (unlike External Secrets Operator which needs a cloud secret store).
- **Critical**: the Sealed Secrets controller's private key is the master secret. Document: "Back up the controller key. If lost, all sealed secrets become undecryptable."
- Include a `make seal-secret` target and a `make rotate-sealed-secrets-key` target.
- **Never** store secrets in Helm values files (even `values-dev.yaml`). Use Sealed Secrets for all environments.
- Include `.gitignore` rules to prevent accidental commit of unsealed secret YAML.

For production, recommend **External Secrets Operator** (AWS Secrets Manager / GCP Secret Manager) as an upgrade path. Document this migration in the skeleton's production readiness guide.

### CI/CD: GitHub Actions

1. **PR checks**: lint, unit tests, OpenAPI spec validation, Docker build (no push), `npm audit`.
2. **Main merge**: Docker build + push to GitHub Container Registry (`ghcr.io`), Helm chart package + push to OCI registry.
3. **Release**: semantic versioning, create GitHub release, push tagged images.

Workflow files included in `.github/workflows/` as part of the skeleton.

### Docker Image Strategy

Multi-stage builds for all images. All images:
- Use **Alpine-based** base images for minimal attack surface.
- Run as **non-root** users (`USER` directive in Dockerfile).
- Pin base image digests (not just tags) for reproducibility.
- Set `readOnlyRootFilesystem: true` in pod security contexts where possible.

**PHP (API/Auth/Workers):**
```dockerfile
FROM php:8.4-cli-alpine@sha256:<digest> AS base
# Install extensions: pdo_pgsql, pcntl, opcache, intl
FROM base AS deps
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts
FROM base AS runtime
USER 1000:1000
COPY --from=deps /app/vendor /app/vendor
COPY src/ /app/src/
COPY config/ /app/config/
```

**Frontend (SvelteKit):**
```dockerfile
FROM node:22-alpine@sha256:<digest> AS build
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build
FROM node:22-alpine@sha256:<digest> AS runtime
USER 1000:1000
COPY --from=build /app/build /app/build
CMD ["node", "build/index.js"]
```

### Network Policies

Default-deny NetworkPolicies with explicit allow rules, **enabled by default** in the Helm chart:

| Source | Destination | Allowed |
|--------|------------|---------|
| Traefik (ingress) | API, Frontend, Mercure | ✅ |
| API | PostgreSQL (PgBouncer), Redis, Mercure | ✅ |
| Workers | PostgreSQL (PgBouncer), Redis | ✅ |
| Frontend | API (via Traefik) | ✅ |
| Frontend | PostgreSQL, Redis | ❌ |
| Workers | Traefik | ❌ |

This enforces the network communication matrix defined in ADR-0001.

### RBAC and Service Accounts

Each component runs with its own Kubernetes **ServiceAccount** with minimal RBAC permissions:

| Component | ServiceAccount | Kubernetes API Access |
|-----------|---------------|----------------------|
| API | `skelly-api` | None (deny) |
| Workers | `skelly-workers` | Read ConfigMaps (own namespace only) |
| Frontend | `skelly-frontend` | None (deny) |
| Mercure | `skelly-mercure` | None (deny) |

RBAC manifests are included in Helm templates.

### Pod Security Standards

The namespace applies the **`restricted`** Pod Security Standard:

```yaml
apiVersion: v1
kind: Namespace
metadata:
  labels:
    pod-security.kubernetes.io/enforce: restricted
```

All pods run as non-root, with no privilege escalation, no host networking, and `readOnlyRootFilesystem: true` where supported. Workers may mount a writable `/tmp` via `emptyDir`.

### Image Security

- Include a `make scan-images` target that runs **Trivy** for vulnerability scanning.
- **Cosign** image signing and **Syft** SBOM generation are documented as recommended production enhancements (not enabled by default in the skeleton).

### Monitoring (Optional)

Included as an optional Helm chart dependency (disabled by default, enabled via `monitoring.enabled: true`):

- **Prometheus** (via kube-prometheus-stack) for metrics
- **Grafana** for dashboards — includes pre-built dashboards for API latency, worker throughput, Redis metrics
- **Loki** for log aggregation

### Developer Experience: Makefile

```makefile
up:                   ## Start local cluster (k3d + Tilt)
down:                 ## Tear down local cluster
logs:                 ## Stream all component logs
seed:                 ## Seed database with development data
test:                 ## Run all tests
lint:                 ## Run all linters
openapi:              ## Generate OpenAPI spec
db-migrate:           ## Run database migrations
db-reset:             ## Reset database (drop + recreate + migrate + seed)
seal-secret:          ## Seal a Kubernetes secret
rotate-sealed-key:    ## Rotate Sealed Secrets controller key
scan-images:          ## Scan Docker images for vulnerabilities
```

## Consequences

### Positive
- Single deployment model across all environments
- Adopters get a production-grade, security-hardened deployment from day one
- NetworkPolicies, RBAC, and Pod Security Standards enforce defence in depth
- Umbrella chart allows full-stack or per-component deployment
- Tilt provides fast live-reload for development
- Sealed Secrets provide git-based secret management without external dependencies
- External dependencies (Redis, PostgreSQL) managed via official Bitnami charts

### Negative
- Kubernetes learning curve is steep for teams without prior experience
- Local development requires running a Kubernetes cluster (~4 GB RAM minimum)
- Helm template debugging can be frustrating
- NetworkPolicies and RBAC add Helm template complexity

### Risks
- Over-specifying infrastructure for teams who just want a simple deployment — mitigated by good defaults and a clear "quick start" guide
- k3d differences from production Kubernetes could mask issues — integration tests should run on a realistic cluster
- Sealed Secrets controller key loss renders all secrets undecryptable — mitigated by backup documentation and `make rotate-sealed-key` target
- Helm chart maintenance burden as upstream dependencies release new versions

## Related Decisions

- [ADR-0001](0001-high-level-architecture.md) — Component topology, trust boundaries, network communication matrix
- [ADR-0009](0009-redis-streams-for-messaging.md) — Redis Helm chart as a dependency (replaces Pulsar)
- [ADR-0005](0005-svelte-frontend.md) — Frontend Docker image (adapter-node, multi-stage build)
- [ADR-0008](0008-use-symfony-framework.md) — Mercure hub added to chart, Symfony container builds
