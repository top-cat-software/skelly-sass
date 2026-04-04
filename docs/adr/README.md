# Architecture Decision Records

This directory contains Architecture Decision Records (ADRs) for the skelly-saas project.

## What is an ADR?

An ADR is a document that captures an important architectural decision made along with its context and consequences.

## Format

Each ADR follows this structure:

- **Title**: Short noun phrase (e.g. "Use PostgreSQL for primary data store")
- **Status**: Proposed | Accepted | Deprecated | Superseded
- **Context**: The forces at play, including technical, political, social, and project constraints
- **Decision**: The change we're proposing or have agreed to implement
- **Consequences**: What becomes easier or more difficult as a result of this decision

## Naming Convention

ADRs are numbered sequentially: `NNNN-short-title.md` (e.g. `0001-use-postgresql.md`).

## Index

| ADR | Title | Status |
|-----|-------|--------|
| [0001](0001-high-level-architecture.md) | High-Level Architecture | Accepted |
| [0002](0002-use-postgresql.md) | Use PostgreSQL for Primary Data Store | Accepted |
| [0003](0003-oauth2-server-with-php.md) | OAuth 2.0 Server with PHP | Accepted |
| [0004](0004-message-streaming-with-pulsar.md) | Message Streaming with Apache Pulsar | Accepted |
| [0005](0005-svelte-frontend.md) | Svelte with Bits UI for Frontend | Accepted |
| [0006](0006-restful-api-first.md) | RESTful API as Primary Interface | Accepted |
| [0007](0007-container-orchestration-with-kubernetes.md) | Container Orchestration with Kubernetes and Helm | Accepted |
| [0008](0008-use-symfony-framework.md) | Use Symfony 7 as the PHP Framework | Accepted |
