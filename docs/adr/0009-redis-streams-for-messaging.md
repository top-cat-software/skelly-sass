# ADR-0009: Redis Streams as Default Message Transport

**Date**: 2026-04-05
**Status**: Accepted
**Deciders**: Project Architect (IC7), Project Owner

## Context

ADR-0004 selected Apache Pulsar as the message streaming platform. After further analysis, the operational complexity of Pulsar is disproportionate to the skeleton's needs, particularly for smaller teams building prototype SaaS applications.

### Pulsar's Operational Footprint

- **Development**: standalone mode requires ~500m CPU, 1 GiB RAM, 10 GiB persistence — just for messaging.
- **Production**: minimum 7+ pods (3 ZooKeeper, 3 BookKeeper, 1+ Broker), each with its own resource requirements.
- **PHP integration**: requires a custom `TransportInterface` implementation for Symfony Messenger, using Pulsar's HTTP REST API (the PHP client ecosystem is immature).
- **Learning curve**: teams unfamiliar with Pulsar face a steep operational learning curve (ZooKeeper quorum, BookKeeper ledger management, topic/namespace administration).

### Redis Is Already in the Stack

Redis is already a required infrastructure dependency for:
- **Rate limiting counters** (ADR-0003, ADR-0006): Symfony RateLimiter needs a persistent store for multi-pod consistency.
- **Symfony cache**: application-level caching.

Adding Redis Streams as the message transport consolidates infrastructure rather than introducing a new system.

### Symfony Messenger Makes Transport Swapping Trivial

Symfony Messenger (ADR-0008) abstracts the transport layer entirely. Swapping from Redis to Pulsar is a single-line DSN change in `messenger.yaml`:

```yaml
# Redis (skeleton default)
framework:
    messenger:
        transports:
            async:
                dsn: 'redis://redis:6379/messages'

# Pulsar (upgrade path)
framework:
    messenger:
        transports:
            async:
                dsn: 'pulsar://pulsar:6650/persistent/public/default/messages'
```

No application code, message classes, handlers, middleware, or routing changes are required. Retry logic, failure transport (DLQ), serialisation, and handler dispatch are all transport-agnostic.

### Skeleton Async Use Cases

The skeleton's async workloads are all covered by Redis Streams:
- Email verification dispatch
- Password reset emails
- Tenant provisioning
- Materialised view refresh (ADR-0002)
- Multi-channel notifications (Symfony Notifier via Messenger)

None of these require Pulsar-grade features (replay, tiered storage, broker-level multi-tenancy).

## Options Considered

### Option 1: Keep Apache Pulsar (ADR-0004)
Retain Pulsar as the default transport.
- **Pros**: replayable message streams, built-in multi-tenancy, tiered storage, dual queue/topic semantics.
- **Cons**: 7+ pods in production, ~1 GiB RAM just for dev standalone mode, requires custom Symfony Messenger transport class, ZooKeeper/BookKeeper operational complexity, immature PHP ecosystem, steep learning curve for adopters.

### Option 2: Redis Streams (Selected)
Use Redis Streams via Symfony Messenger's built-in Redis transport.
- **Pros**: single process (~64 MiB RAM), already required for rate limiting and cache, zero custom transport code (first-party `symfony/redis-messenger`), consumer groups for competing workers, message acknowledgement via `XACK`, familiar technology, trivial to operate.
- **Cons**: no message replay by default, no built-in broker-level multi-tenancy, persistence is RDB/AOF-based (configurable but not replicated by default), single-node bottleneck without Sentinel/Cluster.

### Option 3: RabbitMQ
Mature message broker with excellent PHP support.
- **Pros**: very mature, excellent `php-amqplib` client, wide adoption, supports delayed messages via plugins, well-understood operational model.
- **Cons**: not already in the stack (adds a new infrastructure dependency), messages are consumed-and-gone by default (no replay), clustering can be fragile, Erlang runtime adds operational overhead compared to Redis.

## Decision

Use **Redis Streams** as the default message transport via Symfony Messenger's built-in Redis transport (`symfony/redis-messenger`).

### Rationale

- **No new infrastructure**: Redis is already required for rate limiting and cache. Using it for messaging means one fewer system to deploy, monitor, and operate.
- **No custom code**: the Redis transport is maintained by the Symfony core team. The Pulsar approach required a custom `TransportInterface` implementation — Redis requires zero custom transport code.
- **Right-sized for the skeleton**: Redis Streams covers all skeleton async use cases. Pulsar's enterprise features (replay, tiered storage, multi-tenancy) are not needed for prototype SaaS applications.
- **Familiar**: the project owner and most PHP teams are already familiar with Redis.
- **Trivial upgrade path**: when a team outgrows Redis, swapping to Pulsar (or RabbitMQ, or Amazon SQS) is a DSN change + adding the relevant transport package. No application code changes.

### Redis Streams Capabilities

Redis Streams (via `XADD`, `XREADGROUP`, `XACK`) provide:

| Capability | How |
|-----------|-----|
| Persistent message delivery | Messages stored in the stream until explicitly trimmed |
| Competing consumers | Consumer groups — multiple workers share the workload |
| Message acknowledgement | `XACK` — unacknowledged messages are re-delivered after timeout |
| Fan-out | Multiple consumer groups on the same stream |
| Delayed messages | Handled by Symfony Messenger's delay stamp (transport-agnostic) |
| Dead letter queue | Handled by Symfony Messenger's failure transport (transport-agnostic) |
| Retry with backoff | Handled by Symfony Messenger's retry configuration (transport-agnostic) |

### Pulsar as the Documented Upgrade Path

Pulsar is not removed from the project's vocabulary — it is repositioned as the upgrade path for teams that need:

- **Message replay**: audit trails, event sourcing, reprocessing after bug fixes
- **Broker-level multi-tenancy**: namespace isolation per tenant at the messaging layer
- **Tiered storage**: cost-effective long-term message retention
- **Extreme throughput**: >100k messages/second sustained

The upgrade process:
1. Install the custom Pulsar transport package (to be published as a Composer package)
2. Deploy Pulsar via its official Helm chart
3. Change the DSN in `messenger.yaml`
4. No application code changes

### Redis Deployment

**Development** (default):
- Single Redis instance via Bitnami Redis Helm chart
- Minimal resources: ~50m CPU, 64 MiB RAM, 1 GiB persistence
- Serves messaging, rate limiting, and cache from the same instance

**Production** (documented upgrade paths):
- **Redis Sentinel**: automatic failover with master/replica topology. Recommended first step for HA.
- **Redis Cluster**: horizontal scaling for high-throughput workloads.
- **Managed Redis**: AWS ElastiCache, GCP Memorystore, Azure Cache for Redis.

### Redis Configuration for Messaging

```yaml
# Redis persistence (ensure messages survive restarts)
appendonly yes
appendfsync everysec

# Stream memory management
maxmemory-policy noeviction    # Never evict stream data — let Messenger handle trimming
```

Symfony Messenger handles stream trimming — old messages are removed after successful processing. No manual `XTRIM` configuration needed.

### Redis Authentication

Redis is configured with password authentication (AUTH), injected via Kubernetes Secret. The DSN includes the password:

```
redis://:<password>@redis:6379/messages
```

For production, Redis ACLs can restrict per-component access (API can publish, workers can consume). This replaces the Pulsar namespace-level authorisation described in ADR-0001.

## Consequences

### Positive
- Drastically reduced infrastructure footprint — one Redis instance replaces Pulsar's 7+ pod production topology
- No custom transport code — `symfony/redis-messenger` is first-party, maintained by the Symfony core team
- Redis is already in the stack for rate limiting and cache — no new infrastructure dependency
- Familiar technology for most PHP teams
- Local development resource requirements reduced (~64 MiB vs ~1 GiB for Pulsar standalone)
- Trivial upgrade path to Pulsar, RabbitMQ, or SQS via DSN change

### Negative
- No message replay by default — teams needing audit trail replay must upgrade to Pulsar or implement application-level event sourcing
- No built-in broker-level multi-tenancy — tenant isolation at the messaging layer requires key prefixes (application-level), not broker namespaces
- Redis persistence is best-effort (RDB/AOF) — a crash between AOF fsyncs can lose the most recent messages (mitigated by `appendfsync everysec`, which limits loss to ~1 second of data)

### Risks
- Redis single-node bottleneck for high-volume workloads — mitigated by Sentinel/Cluster upgrade path and Pulsar escape hatch
- Redis memory pressure if streams grow unbounded — mitigated by Symfony Messenger's stream trimming and `maxmemory-policy noeviction`
- Teams may defer the Pulsar upgrade too long and hit Redis limits — mitigated by documenting clear "when to upgrade" criteria in the skeleton's production readiness guide

## Related Decisions

- [ADR-0001](0001-high-level-architecture.md) — Redis replaces Pulsar in the component architecture
- [ADR-0002](0002-use-postgresql.md) — Materialised view refresh via Redis Streams (Symfony Messenger)
- [ADR-0003](0003-oauth2-server-with-php.md) — Redis also serves rate limiting counters for auth endpoints
- [ADR-0004](0004-message-streaming-with-pulsar.md) — Superseded by this ADR
- [ADR-0007](0007-container-orchestration-with-kubernetes.md) — Bitnami Redis Helm chart replaces Pulsar chart
- [ADR-0008](0008-use-symfony-framework.md) — Symfony Messenger with built-in Redis transport
