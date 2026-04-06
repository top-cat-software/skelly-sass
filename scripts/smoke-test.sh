#!/usr/bin/env bash
# smoke-test.sh — End-to-end smoke test for the skelly-saas walking skeleton.
#
# Verifies all pods are running, health endpoints return 200,
# the frontend renders, and the database migration has been applied.
#
# Usage: ./scripts/smoke-test.sh
#   or:  make smoke-test
#
# Environment variables:
#   NAMESPACE        — Kubernetes namespace (default: skelly-saas)
#   TIMEOUT          — Pod readiness timeout in seconds (default: 120)
#   API_HOST         — API hostname (default: api.localhost:8080)
#   APP_HOST         — App hostname (default: app.localhost:8080)

set -euo pipefail

# Check required tools
for tool in curl jq kubectl; do
    command -v "$tool" >/dev/null 2>&1 || {
        printf '\033[1;31m[ERROR]\033[0m %s is required but not installed.\n' "$tool"
        exit 1
    }
done

NAMESPACE="${NAMESPACE:-skelly-saas}"
TIMEOUT="${TIMEOUT:-120}"
API_HOST="${API_HOST:-api.localhost:8080}"
APP_HOST="${APP_HOST:-app.localhost:8080}"

pass=0
fail=0
errors=()

# ─── Helpers ─────────────────────────────────────────────────────────────────

info()  { printf '\033[1;34m[INFO]\033[0m  %s\n' "$*"; }
ok()    { printf '\033[1;32m[PASS]\033[0m  %s\n' "$*"; pass=$((pass + 1)); }
fail()  { printf '\033[1;31m[FAIL]\033[0m  %s\n' "$*"; fail=$((fail + 1)); errors+=("$*"); }

check_http() {
    local label="$1" url="$2" expected_status="${3:-200}"
    local status
    status=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$url" 2>/dev/null) || true
    if [ "$status" = "$expected_status" ]; then
        ok "$label — HTTP $status"
    else
        fail "$label — expected HTTP $expected_status, got ${status:-timeout}"
    fi
}

check_json_field() {
    local label="$1" url="$2" jq_expr="$3" expected="$4"
    local body value
    body=$(curl -s --max-time 10 "$url" 2>/dev/null) || true
    value=$(echo "$body" | jq -r "$jq_expr" 2>/dev/null) || true
    if [ "$value" = "$expected" ]; then
        ok "$label — $jq_expr = $expected"
    else
        fail "$label — expected $jq_expr = $expected, got ${value:-null}"
    fi
}

check_html_contains() {
    local label="$1" url="$2" needle="$3"
    local body
    body=$(curl -s --max-time 10 "$url" 2>/dev/null) || true
    if echo "$body" | grep -qi "$needle"; then
        ok "$label — contains '$needle'"
    else
        fail "$label — missing '$needle'"
    fi
}

# ─── 1. Pod Readiness ───────────────────────────────────────────────────────

info "Waiting for pods to be ready (timeout: ${TIMEOUT}s)..."
if kubectl wait --for=condition=Ready pod \
    -l app.kubernetes.io/part-of=skelly-saas \
    -n "$NAMESPACE" \
    --timeout="${TIMEOUT}s" >/dev/null 2>&1; then
    ok "All pods are Ready"
else
    fail "Pods did not reach Ready state within ${TIMEOUT}s"
fi

info "Pod status:"
kubectl get pods -n "$NAMESPACE" -o wide 2>/dev/null || true
echo ""

# ─── 2. API Health Endpoint ─────────────────────────────────────────────────

info "Checking API health endpoint..."
check_http "API health HTTP status" "http://${API_HOST}/api/v1/health"
check_json_field "API health overall" "http://${API_HOST}/api/v1/health" '.status' 'healthy'
check_json_field "API health database" "http://${API_HOST}/api/v1/health" '.checks.database.status' 'healthy'
check_json_field "API health Redis" "http://${API_HOST}/api/v1/health" '.checks.redis.status' 'healthy'
check_json_field "API health Messenger" "http://${API_HOST}/api/v1/health" '.checks.messenger.status' 'healthy'

# ─── 3. Auth Health Endpoint ────────────────────────────────────────────────

info "Checking Auth health endpoint..."
check_http "Auth health HTTP status" "http://${API_HOST}/auth/health"
check_json_field "Auth health overall" "http://${API_HOST}/auth/health" '.status' 'healthy'

# ─── 4. Frontend ────────────────────────────────────────────────────────────

info "Checking frontend..."
check_http "Frontend HTTP status" "http://${APP_HOST}/"
check_html_contains "Frontend renders" "http://${APP_HOST}/" "skelly-saas"

# ─── 5. Database Migration ──────────────────────────────────────────────────

info "Checking database migration..."
API_POD=$(kubectl get pod -n "$NAMESPACE" -l app.kubernetes.io/name=api \
    -o jsonpath='{.items[0].metadata.name}' 2>/dev/null) || true

if [ -n "$API_POD" ]; then
    migration_output=$(kubectl exec -n "$NAMESPACE" "$API_POD" -- \
        php bin/console doctrine:migrations:status --no-interaction 2>/dev/null) || true
    if echo "$migration_output" | grep -q "Already at latest version\|New Migrations.*0"; then
        ok "Database migrations are up to date"
    else
        fail "Database migrations may not be applied"
    fi
else
    fail "Could not find API pod for migration check"
fi

# ─── Summary ────────────────────────────────────────────────────────────────

echo ""
echo "════════════════════════════════════════"
printf "  \033[1;32mPassed: %d\033[0m   \033[1;31mFailed: %d\033[0m\n" "$pass" "$fail"
echo "════════════════════════════════════════"

if [ "$fail" -gt 0 ]; then
    echo ""
    echo "Failures:"
    for e in "${errors[@]}"; do
        printf "  • %s\n" "$e"
    done
    echo ""
    exit 1
fi

exit 0
