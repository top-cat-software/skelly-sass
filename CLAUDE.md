# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**skelly-sass** is an open-source SaaS platform skeleton framework, licensed under MIT, owned by Top Cat Software Ltd.

## Architecture

- **Component-based architecture**: API (PHP), OAuth server (PHP, co-deployed with API), Workers (PHP), Frontend (SvelteKit + Bits UI), Ingress (Traefik)
- **Data store**: PostgreSQL (with materialised views, JSONB, PgBouncer)
- **Messaging**: Apache Pulsar for async job dispatch and event streaming
- **Auth**: League OAuth2 Server with JWT, 2FA (TOTP), social login (Google, GitHub)
- **API**: RESTful, JSON, versioned via URI (`/v1/`), RFC 7807 errors, OpenAPI 3.1
- **Deployment**: Kubernetes + Helm (umbrella chart with sub-charts per component)
- **ADRs**: all architectural decisions documented in `docs/adr/`

## Development Environment

- IDE: PhpStorm (JetBrains) with PHP quality tools configured (PHP_CodeSniffer, PHP-CS-Fixer, PHPStan, Psalm, PHP Mess Detector)
- Languages: PHP (back-end), TypeScript/Svelte (front-end)
- Local Kubernetes: k3d recommended
- Container runtime: Docker

## Code Style

- Avoid nested if statements as much as possible; prefer early returns and guard clauses.
- Use British English in documentation and comments.
