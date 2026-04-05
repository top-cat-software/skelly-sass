# Quick Start

Get the skelly-saas local development environment running on macOS.

## Prerequisites

You need **Docker Desktop** installed and running. All application code (PHP, Node) runs inside containers — you do not need PHP, Composer, Node, or npm installed locally.

### Required Tools

Install via Homebrew:

```bash
brew install helm tilt-dev/tap/tilt k3d kubeseal
```

| Tool | Purpose |
|------|---------|
| **Docker Desktop** | Container runtime (you already have this) |
| **k3d** | Local Kubernetes cluster (k3s in Docker) |
| **Helm** | Kubernetes package manager for deploying charts |
| **Tilt** | Live-reload and development workflow orchestration |
| **kubeseal** | Client-side encryption for Sealed Secrets |
| **kubectl** | Kubernetes CLI (bundled with Docker Desktop when Kubernetes is enabled) |

### Verify Installation

```bash
docker version
k3d version
helm version
tilt version
kubectl version --client
kubeseal --version
```

All commands should return version information without errors.

## Why k3d Instead of Docker Desktop Kubernetes?

Docker Desktop includes a built-in Kubernetes cluster, but skelly-saas uses k3d instead. Both can coexist — they run as separate Kubernetes contexts and do not conflict.

| Concern | Docker Desktop K8s | k3d |
|---------|-------------------|-----|
| **Disposability** | Persistent, hard to reset cleanly | Create and destroy clusters in seconds |
| **Multi-node** | Single node only | 1 server + 1 agent (closer to production) |
| **Port mapping** | Manual port-forward or NodePort | Explicit host port mapping in checked-in config |
| **Reproducibility** | No config file to share | `k3d-config.yaml` checked into the repo |
| **Traefik control** | Not included | Bundled Traefik disabled; our own deployed via Helm |

The Tiltfile is locked to the `k3d-skelly-saas` context to prevent accidental deployments to other clusters.

## Getting Started

Once all prerequisites are installed:

```bash
git clone git@github.com:top-cat-software/skelly-sass.git
cd skelly-sass
make up
```

This creates the k3d cluster, builds Docker images, and deploys the full stack via Helm. The first run takes a few minutes; subsequent runs are faster.

### Accessing the Applications

| Application | URL |
|-------------|-----|
| Frontend | http://app.localhost:8080 |
| API | http://api.localhost:8080/api/v1/health |
| Auth | http://api.localhost:8080/auth/health |
| Tilt UI | http://localhost:10350 |

### Common Commands

```bash
make up          # Start the local development environment
make down        # Tear down the cluster
make logs        # Stream logs from all pods
make logs-api    # Stream logs from the API pods
make status      # Show pod and node status
make shell-api   # Open a shell in the API pod
make db-migrate  # Run database migrations
make db-reset    # Drop, recreate, migrate, and seed the database
make scan-images # Run Trivy vulnerability scan on Docker images
make help        # List all available targets
```

### Live-Reload

Tilt watches for file changes and live-syncs them into running containers:

- **PHP changes** (`api/src/`, `api/config/`) sync to the API and worker pods within seconds — no image rebuild needed.
- **SvelteKit changes** (`frontend/src/`) sync to the frontend pod with Vite HMR for instant browser updates.

The Tilt UI at http://localhost:10350 shows build status, sync activity, and real-time logs for all components.

## Resource Requirements

| Profile | RAM | Notes |
|---------|-----|-------|
| Minimum | ~4 GB | All components running with development resource limits |
| Recommended | ~8 GB | Comfortable headroom for IDE, browser, and the cluster |

## Troubleshooting

### Port 8080 already in use

Another process is using port 8080. Either stop it or edit `k3d-config.yaml` to use a different host port.

### Pods stuck in CrashLoopBackOff

Check pod logs for the failing component:

```bash
make logs-api      # or logs-workers, logs-frontend
kubectl get events -n skelly-saas --sort-by='.lastTimestamp'
```

### k3d cluster won't start

Ensure Docker Desktop is running and has enough resources allocated (at least 4 GB RAM assigned to Docker).

### kubectl points to the wrong cluster

Switch to the k3d context:

```bash
kubectl config use-context k3d-skelly-saas
```

### Fresh start

Tear everything down and start over:

```bash
make down
make up
```
