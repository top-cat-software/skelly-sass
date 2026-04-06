# skelly-saas — Local Development Makefile
# Run `make` or `make help` to see all available targets.

SHELL := /bin/bash
.DEFAULT_GOAL := help

# ─── Configuration ───────────────────────────────────────────────────────────

CLUSTER_NAME    := skelly-saas
NAMESPACE       := skelly-saas
HELM_RELEASE    := skelly-saas
HELM_CHART      := helm/skelly-saas
HELM_DEV_VALUES := helm/skelly-saas/values-dev.yaml
K3D_CONFIG      := k3d-config.yaml
API_IMAGE       := skelly-saas/api:dev
FRONTEND_IMAGE  := skelly-saas/frontend:dev
LABEL_SELECTOR  := app.kubernetes.io/part-of=skelly-saas

# ─── Colour output (respects NO_COLOR — see https://no-color.org) ───────────

ifdef NO_COLOR
  INFO  := [INFO]
  WARN  := [WARN]
  ERROR := [ERROR]
  OK    := [OK]
else
  INFO  := \033[1;34m[INFO]\033[0m
  WARN  := \033[1;33m[WARN]\033[0m
  ERROR := \033[1;31m[ERROR]\033[0m
  OK    := \033[1;32m[OK]\033[0m
endif

# ─── Phony targets ──────────────────────────────────────────────────────────

.PHONY: help up down restart check-deps \
        logs logs-api logs-workers logs-frontend \
        status shell-api shell-frontend \
        db-migrate db-reset seed \
        seal-secret rotate-sealed-key \
        smoke-test \
        scan-images

# ─── Help ────────────────────────────────────────────────────────────────────

help: ## Show this help message
	@echo ""
	@echo "skelly-saas — Local Development Targets"
	@echo "════════════════════════════════════════"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'
	@echo ""

# ─── Prerequisites ───────────────────────────────────────────────────────────

define check_tool
	@command -v $(1) >/dev/null 2>&1 || { \
		printf "$(ERROR) $(1) is not installed.\n"; \
		printf "       Install: $(2)\n"; \
		exit 1; \
	}
endef

check-deps: ## Verify all required tools are installed
	$(call check_tool,docker,https://docs.docker.com/get-docker/)
	@docker info >/dev/null 2>&1 || { printf "$(ERROR) Docker daemon is not running.\n"; exit 1; }
	$(call check_tool,k3d,https://k3d.io/#installation)
	$(call check_tool,tilt,https://docs.tilt.dev/install.html)
	$(call check_tool,helm,https://helm.sh/docs/intro/install/)
	$(call check_tool,kubectl,https://kubernetes.io/docs/tasks/tools/)
	$(call check_tool,kubeseal,https://github.com/bitnami-labs/sealed-secrets#kubeseal)
	@printf "$(OK) All dependencies are installed.\n"

# ─── Core Lifecycle ──────────────────────────────────────────────────────────

up: check-deps ## Start the local development environment
	@printf "$(INFO) Starting skelly-saas local environment...\n"
	@k3d cluster list -o json 2>/dev/null | grep -q '"name": "$(CLUSTER_NAME)"' \
		|| k3d cluster create --config $(K3D_CONFIG)
	@printf "$(OK) k3d cluster '$(CLUSTER_NAME)' is running.\n"
	@helm dependency build $(HELM_CHART) 2>/dev/null
	@printf "$(INFO) Starting Tilt...\n"
	@tilt up

down: ## Tear down the local environment and delete the cluster
	@printf "$(INFO) Stopping Tilt...\n"
	-@tilt down 2>/dev/null
	@printf "$(INFO) Deleting k3d cluster '$(CLUSTER_NAME)'...\n"
	-@k3d cluster delete $(CLUSTER_NAME) 2>/dev/null
	@printf "$(OK) Environment torn down.\n"

restart: ## Restart the local environment (down + up)
	@$(MAKE) down
	@$(MAKE) up

# ─── Observability ───────────────────────────────────────────────────────────

logs: ## Stream logs from all application pods
	kubectl logs -f -n $(NAMESPACE) -l $(LABEL_SELECTOR) --all-containers --max-log-requests=10

logs-api: ## Stream logs from API pods
	kubectl logs -f -n $(NAMESPACE) -l app.kubernetes.io/name=api --all-containers

logs-workers: ## Stream logs from worker pods
	kubectl logs -f -n $(NAMESPACE) -l app.kubernetes.io/name=workers --all-containers

logs-frontend: ## Stream logs from frontend pods
	kubectl logs -f -n $(NAMESPACE) -l app.kubernetes.io/name=frontend --all-containers

# ─── Status ──────────────────────────────────────────────────────────────────

status: ## Show pod status, node status, and resource usage
	@printf "$(INFO) Cluster nodes:\n"
	@kubectl get nodes -o wide 2>/dev/null || printf "$(WARN) Cluster not running.\n"
	@echo ""
	@printf "$(INFO) Pods:\n"
	@kubectl get pods -n $(NAMESPACE) -o wide 2>/dev/null || printf "$(WARN) Namespace '$(NAMESPACE)' not found.\n"
	@echo ""
	@printf "$(INFO) Services:\n"
	@kubectl get svc -n $(NAMESPACE) 2>/dev/null || true

# ─── Shell Access ────────────────────────────────────────────────────────────

shell-api: ## Open a shell in the API pod
	kubectl exec -it -n $(NAMESPACE) deploy/$(HELM_RELEASE)-api -- /bin/sh

shell-frontend: ## Open a shell in the frontend pod
	kubectl exec -it -n $(NAMESPACE) deploy/$(HELM_RELEASE)-frontend -- /bin/sh

# ─── Database ────────────────────────────────────────────────────────────────

API_POD = $$(kubectl get pod -n $(NAMESPACE) -l app.kubernetes.io/name=api -o jsonpath='{.items[0].metadata.name}')

db-migrate: ## Run database migrations in the API pod
	@printf "$(INFO) Running database migrations...\n"
	kubectl exec -n $(NAMESPACE) $(API_POD) -- php bin/console doctrine:migrations:migrate --no-interaction
	@printf "$(OK) Migrations complete.\n"

db-reset: ## Drop and recreate the database, then run migrations
	@printf "$(WARN) This will destroy all data in the database.\n"
	kubectl exec -n $(NAMESPACE) $(API_POD) -- php bin/console doctrine:database:drop --force --if-exists
	kubectl exec -n $(NAMESPACE) $(API_POD) -- php bin/console doctrine:database:create
	kubectl exec -n $(NAMESPACE) $(API_POD) -- php bin/console doctrine:migrations:migrate --no-interaction
	@printf "$(OK) Database reset and migrations complete.\n"

seed: ## Seed development data (placeholder)
	@printf "$(WARN) Not yet implemented. Will run: php bin/console app:seed\n"

# ─── Secret Management ──────────────────────────────────────────────────────

seal-secret: ## Seal a Kubernetes secret with Sealed Secrets
	@printf "$(INFO) Seal a secret for the $(NAMESPACE) namespace.\n"
	@printf "Usage:\n"
	@printf "  kubectl create secret generic <name> --dry-run=client -o yaml \\\\\n"
	@printf "    --from-literal=<key>=<value> \\\\\n"
	@printf "    -n $(NAMESPACE) | kubeseal --format yaml \\\\\n"
	@printf "    --controller-name=$(HELM_RELEASE)-sealed-secrets \\\\\n"
	@printf "    --controller-namespace=$(NAMESPACE) \\\\\n"
	@printf "    > helm/skelly-saas/templates/sealed-<name>.yaml\n"

rotate-sealed-key: ## Rotate the Sealed Secrets controller master key
	@printf "$(INFO) Rotating Sealed Secrets master key...\n"
	kubectl annotate sealedsecret --all -n $(NAMESPACE) \
		sealedsecrets.bitnami.com/managed="true" --overwrite
	kubectl delete secret -n $(NAMESPACE) -l sealedsecrets.bitnami.com/sealed-secrets-key
	kubectl rollout restart deployment/$(HELM_RELEASE)-sealed-secrets -n $(NAMESPACE)
	@printf "$(OK) Key rotated. Re-seal all existing secrets.\n"

# ─── Security ────────────────────────────────────────────────────────────────

scan-images: ## Run Trivy vulnerability scan on Docker images
	$(call check_tool,trivy,https://aquasecurity.github.io/trivy/latest/getting-started/installation/)
	@printf "$(INFO) Scanning $(API_IMAGE)...\n"
	trivy image --severity HIGH,CRITICAL $(API_IMAGE)
	@echo ""
	@printf "$(INFO) Scanning $(FRONTEND_IMAGE)...\n"
	trivy image --severity HIGH,CRITICAL $(FRONTEND_IMAGE)

# ─── Smoke Test ─────────────────────────────────────────────────────────────

smoke-test: ## Run end-to-end smoke tests against the running cluster
	@printf "$(INFO) Running smoke tests...\n"
	NAMESPACE=$(NAMESPACE) ./scripts/smoke-test.sh
