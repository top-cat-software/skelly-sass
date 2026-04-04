# ADR-0002: Use PostgreSQL for Primary Data Store

**Date**: 2026-04-04
**Status**: Accepted
**Deciders**: Project Architect (IC7), Project Owner, Data Engineer (IC5), Principal PHP (IC6), Security Design Expert (IC6)

## Context

The SaaS skeleton needs a primary data store for user accounts, OAuth tokens, tenant data, and application state. Requirements:

- ACID transactions for auth and billing-critical operations
- Mature ecosystem with good PHP driver support
- Read-optimised query patterns for dashboards without a separate OLAP system
- Flexible schema support for tenant-specific configuration
- Strong community and managed-service availability (AWS RDS, GCP Cloud SQL, etc.)

The project owner has experience with both MySQL/PostgreSQL (OLTP) and ClickHouse (OLAP).

## Options Considered

### Option 1: PostgreSQL
Full-featured RDBMS with materialised views, JSONB, and an excellent extension ecosystem (PostGIS, pg_partman, pgvector, etc.).
- **Pros**: Materialised views for read-heavy patterns, JSONB for flexible config storage, strong extension ecosystem, excellent managed-service support.
- **Cons**: Connection handling is heavier than MySQL — PgBouncer needed at scale, materialised views are not incrementally refreshed by default.

### Option 2: MySQL
Widely used RDBMS with simpler replication and broad hosting support.
- **Pros**: Very widely known, simpler replication setup, lighter connection model.
- **Cons**: Weaker materialised view support (no native materialised views), less powerful JSON querying, fewer advanced features (e.g. no partial indexes).

### Option 3: PostgreSQL + ClickHouse
PostgreSQL for OLTP, ClickHouse for analytics and reporting.
- **Pros**: Best of both worlds — OLTP and OLAP optimised separately.
- **Cons**: Significant operational complexity for a skeleton project, data synchronisation between stores, overkill for initial scope.

## Decision

Use **PostgreSQL** as the sole primary data store.

ClickHouse may be introduced later as a dedicated analytics layer if the skeleton grows to include reporting features, but is out of scope for the initial architecture. The project owner's ClickHouse experience means this path is available when needed.

### Database Access Layer

Use **Doctrine DBAL** (without the full ORM) as the database access layer:
- Provides a solid abstraction over PDO with query builder, schema introspection, and type mapping.
- Avoids the overhead and complexity of a full ORM (Doctrine ORM, Eloquent) which is opinionated and may not suit all adopters.
- Adopters who want a full ORM can layer it on top.
- Integrates natively with Symfony 7 (ADR-0008) and Doctrine Migrations.

### Schema Migrations

Use **Doctrine Migrations** for versioned schema management:
- Natural pairing with Doctrine DBAL — shares the same connection configuration and schema abstraction.
- PHP-based migrations (not just SQL) — can reference DBAL's schema builder for portable DDL.
- **Forward-only in production**: no down migrations. Down migrations are error-prone with data loss risk. For development, provide a `make db:reset` target that drops and recreates from scratch.
- CI must verify that migrations run cleanly against a fresh database.

### Materialised View Refresh Strategy

Use **event-driven refresh triggered via Pulsar** (Symfony Messenger), not scheduled refresh:
- When a write operation completes, the API publishes a domain event to Pulsar (e.g. `tenant.usage.updated`).
- A dedicated worker subscribes to these events and calls `REFRESH MATERIALIZED VIEW CONCURRENTLY <view>`.
- `CONCURRENTLY` is required — it allows reads during refresh (requires a unique index on the view).
- **Debounced refresh**: the worker batches events and refreshes at most once per configurable interval (e.g. 30 seconds) to avoid excessive refresh under high write loads.
- **Fallback**: a scheduled refresh (cron job) as a safety net for missed events.

### Connection Pooling

PgBouncer in **transaction mode** in front of PostgreSQL:

| Component | Pool Size | Notes |
|-----------|-----------|-------|
| API pods | 20 per pod | Short-lived request connections |
| Worker pods | 5 per pod | One job at a time, may run parallel within pod |
| Migration runner | 1 | DDL operations, run separately |

**PostgreSQL `max_connections`** formula: `(API_pods × 20) + (Worker_pods × 5) + 20 (admin/migration headroom)`. For a skeleton default of 2 API + 2 worker pods: `2×20 + 2×5 + 20 = 70`. This formula is documented in the Helm values.

**Worker connection pattern**: workers acquire a connection at job start and release it at job end. Disable emulated prepares in PDO or use DBAL's built-in parameter binding (compatible with PgBouncer transaction mode). Add a health check that detects connection leaks (connections held longer than expected job duration).

### Partitioning Strategy

High-volume tables use PostgreSQL's native **declarative partitioning** from day one:

| Table | Partition Key | Strategy | Notes |
|-------|--------------|----------|-------|
| `audit_logs` | `created_at` | Range, monthly | Append-only, high volume |
| `events` / `messages` | `created_at` | Range, weekly | Stream replay support |
| `sessions` / `tokens` | `expires_at` | Range, daily | Enables cheap expiry via `DROP PARTITION` |

Include `pg_partman` as an optional extension for automated partition maintenance. The skeleton ships with partitioned table definitions for audit logs and tokens.

### JSONB Governance

JSONB columns are permitted for **tenant-extensible configuration** and **audit metadata** only. Three-tier governance:

1. **Schema validation**: every JSONB column must have a corresponding PHP value object that validates structure on write. No raw JSON inserts.
2. **Indexing rule**: if a JSONB path is queried more than once in application code, extract it to a GIN index or a generated column. This is a code review checklist item.
3. **Promotion rule**: if a JSONB field is used in a WHERE clause across multiple endpoints, it must be promoted to a normalised column in the next migration cycle.
4. **Sensitive data prohibition**: JSONB columns MUST NOT contain passwords, tokens, API keys, or unencrypted PII. This is a code review checklist item for any migration adding or modifying a JSONB column.

### Credential Separation

Separate PostgreSQL credentials per component, following the principle of least privilege:

| Component | Permissions | Notes |
|-----------|------------|-------|
| API / Auth | DML only (SELECT, INSERT, UPDATE, DELETE) | Cannot modify schema |
| Workers | DML only | Same permission level as API |
| Migration runner | DDL + DML | Schema modifications, run separately |

Credentials stored as Kubernetes Secrets, injected as environment variables. See ADR-0001 trust boundaries.

### Security Controls

- **Parameterised queries only**: all database queries must use Doctrine DBAL's query builder or prepared statements. Raw SQL string interpolation is prohibited and enforced by static analysis (PHPStan/Psalm rule).
- **Connection string sanitisation**: PHP's PDO exception handler must sanitise database connection details from error messages. PgBouncer logs must not include authentication details.
- **Encryption at rest**: handled at the storage layer (encrypted volumes in cloud providers, LUKS on-premises). Application-level column encryption is out of scope for the initial skeleton but noted as a recommended enhancement for production deployments containing PII.

## Consequences

### Positive
- Single data store simplifies operations, backups, and monitoring
- Materialised views with event-driven refresh give near-real-time reads without a separate OLAP store
- JSONB provides flexibility with clear governance to prevent sprawl
- Doctrine DBAL integrates natively with Symfony 7 and Doctrine Migrations
- Credential separation enforces least privilege from day one
- Partitioning strategy prevents unbounded table growth

### Negative
- PgBouncer adds an operational dependency
- Forward-only migrations mean mistakes require corrective migrations, not rollbacks
- Teams more familiar with MySQL will need to adjust
- JSONB governance rules add friction to development (by design)

### Risks
- Materialised view debounced refresh could still lag under extreme write loads — monitor refresh duration and backlog
- Connection pool sizing formula needs adjustment as the fleet scales — document the formula in Helm values so adopters can tune it
- JSONB governance relies on code review discipline — consider automated static analysis rules as a future enhancement

## Related Decisions

- [ADR-0001](0001-high-level-architecture.md) — High-level architecture (PostgreSQL as data store component, trust boundaries)
- [ADR-0004](0004-message-streaming-with-pulsar.md) — Materialised view refresh triggered via Pulsar events (Symfony Messenger)
- [ADR-0008](0008-use-symfony-framework.md) — Symfony 7 with Doctrine DBAL integration
