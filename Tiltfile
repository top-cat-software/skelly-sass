# -*- mode: Python -*-
# Tiltfile for skelly-saas local development.
# Usage: tilt up

# ─── Safety: restrict to local k3d context only ───
allow_k8s_contexts('k3d-skelly-saas')

# ─── Docker Builds with Live-Sync ───

# API image (also used by workers)
# FrankenPHP worker mode keeps PHP processes alive, so file changes synced via
# Tilt are not picked up automatically. SIGUSR1 tells FrankenPHP to restart its
# worker processes, picking up the newly synced PHP files.
docker_build(
    'skelly-saas/api',
    context='./api',
    dockerfile='./api/Dockerfile',
    live_update=[
        sync('./api/src', '/app/src'),
        sync('./api/config', '/app/config'),
        sync('./api/templates', '/app/templates'),
        run('php bin/console cache:clear', trigger=['./api/config']),
        run('kill -USR1 1', trigger=['./api/src']),
    ],
)

# Frontend image
docker_build(
    'skelly-saas/frontend',
    context='./frontend',
    dockerfile='./frontend/Dockerfile',
    live_update=[
        sync('./frontend/src', '/app/src'),
        sync('./frontend/static', '/app/static'),
    ],
)

# ─── Helm Deployment ───

k8s_yaml(
    helm(
        'helm/skelly-saas',
        name='skelly-saas',
        namespace='skelly-saas',
        values=['helm/skelly-saas/values-dev.yaml'],
    )
)

# ─── Resource Configuration ───

# Infrastructure — no dependencies, start first
k8s_resource('skelly-saas-redis-master', labels=['infrastructure'])
k8s_resource('skelly-saas-postgresql', labels=['infrastructure'])
k8s_resource('skelly-saas-traefik', labels=['infrastructure'])
k8s_resource('skelly-saas-sealed-secrets', labels=['infrastructure'])

# Backend — depends on infrastructure
k8s_resource(
    'skelly-saas-api',
    labels=['backend'],
    resource_deps=['skelly-saas-redis-master', 'skelly-saas-postgresql'],
    port_forwards=['8080:8080'],
)

k8s_resource(
    'skelly-saas-workers',
    labels=['backend'],
    resource_deps=['skelly-saas-redis-master', 'skelly-saas-postgresql'],
)

# Frontend — depends on infrastructure (for DNS)
k8s_resource(
    'skelly-saas-frontend',
    labels=['frontend'],
    port_forwards=['3000:3000'],
)
