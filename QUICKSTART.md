# Quick Start

Get the skelly-saas local development environment running.

## Prerequisites

You need **Docker** installed and running. All application code (PHP, Node) runs inside containers — you do not need PHP, Composer, Node, or npm installed locally.

### Required Tools

| Tool | Purpose | Install |
|------|---------|---------|
| **Docker** | Container runtime | [docs.docker.com/get-docker](https://docs.docker.com/get-docker/) |
| **k3d** | Local Kubernetes cluster (k3s in Docker) | [k3d.io](https://k3d.io/) |
| **Helm** | Kubernetes package manager | [helm.sh/docs/intro/install](https://helm.sh/docs/intro/install/) |
| **Tilt** | Live-reload and dev workflow orchestration | [docs.tilt.dev/install](https://docs.tilt.dev/install.html) |
| **kubectl** | Kubernetes CLI | [kubernetes.io/docs/tasks/tools](https://kubernetes.io/docs/tasks/tools/) |
| **kubeseal** | Client-side encryption for Sealed Secrets | [github.com/bitnami-labs/sealed-secrets](https://github.com/bitnami-labs/sealed-secrets#kubeseal) |

#### macOS (Homebrew)

```bash
brew install k3d helm tilt-dev/tap/tilt kubeseal
```

kubectl is bundled with Docker Desktop. If you don't have it:

```bash
brew install kubectl
```

#### Linux

```bash
# k3d
curl -s https://raw.githubusercontent.com/k3d-io/k3d/main/install.sh | bash

# Helm
curl https://raw.githubusercontent.com/helm/helm/main/scripts/get-helm-3 | bash

# Tilt
curl -fsSL https://raw.githubusercontent.com/tilt-dev/tilt/master/scripts/install.sh | bash

# kubectl
curl -LO "https://dl.k8s.io/release/$(curl -L -s https://dl.k8s.io/release/stable.txt)/bin/linux/amd64/kubectl"
chmod +x kubectl && sudo mv kubectl /usr/local/bin/

# kubeseal
KUBESEAL_VERSION=$(curl -s https://api.github.com/repos/bitnami-labs/sealed-secrets/releases/latest | grep '"tag_name"' | sed -E 's/.*"v([^"]+)".*/\1/')
curl -OL "https://github.com/bitnami-labs/sealed-secrets/releases/download/v${KUBESEAL_VERSION}/kubeseal-${KUBESEAL_VERSION}-linux-amd64.tar.gz"
tar -xvzf kubeseal-*.tar.gz kubeseal && sudo mv kubeseal /usr/local/bin/
```

#### Windows (WSL2)

skelly-saas runs on Windows via WSL2 with Docker Desktop. Install Docker Desktop with the WSL2 backend enabled, then follow the Linux instructions above inside your WSL2 distribution.

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

## Why k3d?

You may already have Kubernetes available through Docker Desktop or another tool. skelly-saas uses k3d because it provides a disposable, reproducible cluster from a checked-in config file.

| Concern | Docker Desktop K8s | k3d |
|---------|-------------------|-----|
| **Disposability** | Persistent, hard to reset cleanly | Create and destroy clusters in seconds |
| **Multi-node** | Single node only | 1 server + 1 agent (closer to production) |
| **Port mapping** | Manual port-forward or NodePort | Explicit host port mapping in checked-in config |
| **Reproducibility** | No config file to share | `k3d-config.yaml` checked into the repo |
| **Traefik control** | Not included | Bundled Traefik disabled; our own deployed via Helm |

k3d runs alongside any existing Kubernetes setup without conflict. It creates its own context (`k3d-skelly-saas`) and the Tiltfile is locked to that context to prevent accidental deployments elsewhere.

## Getting Started

```bash
git clone https://github.com/top-cat-software/skelly-sass.git
cd skelly-sass
make up
```

This creates the k3d cluster, builds Docker images, and deploys the full stack via Helm. The first run takes a few minutes while images are built and dependencies are pulled; subsequent runs are faster.

`make up` runs a prerequisites check and will tell you if any tools are missing.

### Accessing the Applications

| Application | URL |
|-------------|-----|
| Frontend | http://app.localhost:8080 |
| API | http://api.localhost:8080/api/v1/health |
| Auth | http://api.localhost:8080/auth/health |
| Tilt UI | http://localhost:10350 |

### Common Commands

```bash
make help          # List all available targets
make up            # Start the local development environment
make down          # Tear down the cluster
make restart       # Tear down and start fresh
make logs          # Stream logs from all pods
make logs-api      # Stream logs from the API pods
make logs-frontend # Stream logs from the frontend pods
make logs-workers  # Stream logs from the worker pods
make status        # Show pod and node status
make shell-api     # Open a shell in the API pod
make shell-frontend# Open a shell in the frontend pod
make db-migrate    # Run database migrations
make db-reset      # Drop, recreate, migrate, and seed the database
make scan-images   # Run Trivy vulnerability scan on Docker images
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

Ensure Docker has at least 4 GB of RAM allocated. On Docker Desktop, check **Settings → Resources → Memory**.

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

Ensure Docker is running and has enough resources allocated (at least 4 GB RAM).

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
