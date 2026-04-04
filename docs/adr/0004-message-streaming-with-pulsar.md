# ADR-0004: Message Streaming with Apache Pulsar

**Date**: 2026-04-04
**Status**: Accepted
**Deciders**: Project Architect (IC7), Project Owner, Principal Infrastructure (IC6), Principal PHP (IC6)

## Context

The architecture (ADR-0001) specifies a worker-driven back-end where the API dispatches work asynchronously. We need a message transport that supports:

- Reliable, persistent message delivery (no message loss)
- Multiple consumer patterns (fan-out, competing consumers, delayed delivery)
- Replayability for debugging and audit trails
- Good operational tooling and observability
- Reasonable resource footprint for a skeleton project

The project owner's initial instinct is Apache Pulsar.

## Options Considered

### Option 1: Apache Pulsar
Distributed messaging and streaming platform with tiered storage, multi-tenancy, and both queuing and streaming semantics.
- **Pros**: Persistent and replayable by default, supports both pub/sub and competing consumers, built-in multi-tenancy, tiered storage for cost-effective retention, schema registry.
- **Cons**: Heavier operational footprint than simpler queues (ZooKeeper/BookKeeper dependencies), smaller community than Kafka, PHP client ecosystem is less mature (HTTP API or pulsar-client-php via FFI).

### Option 2: RabbitMQ
Mature message broker with excellent PHP support (php-amqplib).
- **Pros**: Very mature, excellent PHP client, simple to operate, wide adoption, supports delayed messages via plugins.
- **Cons**: Not designed for stream replay, messages are consumed-and-gone by default, clustering can be fragile, no built-in tiered storage.

### Option 3: Apache Kafka
Industry-standard distributed streaming platform.
- **Pros**: Massive ecosystem, persistent and replayable, proven at extreme scale, Confluent schema registry.
- **Cons**: Heavy resource requirements (JVM + ZooKeeper/KRaft), PHP client support is poor (librdkafka via FFI), overkill for a skeleton project, operational complexity comparable to Pulsar.

### Option 4: Redis Streams
Lightweight streaming built into Redis.
- **Pros**: Very simple to operate, already a common infrastructure dependency, good PHP support, low latency.
- **Cons**: Persistence is best-effort (depends on RDB/AOF), no built-in schema registry, limited consumer group features compared to dedicated streaming platforms, single-node bottleneck.

## Decision

Use **Apache Pulsar** as the message streaming platform.

### Rationale

- **Replayability**: Pulsar's persistent, log-based storage means messages can be replayed for debugging, auditing, and reprocessing — critical for a SaaS platform.
- **Dual semantics**: supports both queue-style (competing consumers for workers) and topic-style (fan-out for events) without separate systems.
- **Multi-tenancy**: aligns with SaaS multi-tenant requirements — tenants can be isolated at the namespace level.
- **Tiered storage**: long-term retention without expensive hot storage.
- **Project owner preference**: the owner has experience and confidence in Pulsar.

### PHP Integration Strategy: Symfony Messenger + Custom Pulsar Transport

Pulsar is accessed via **Symfony Messenger** (ADR-0008) with a custom Pulsar transport. This replaces the originally proposed hand-rolled `MessageProducer`/`MessageConsumer` interfaces — Symfony Messenger provides transport abstraction, routing, retry, serialisation, middleware, and handler dispatch out of the box.

The custom Pulsar transport implements Symfony's `TransportInterface` and uses Pulsar's **HTTP REST API** (no FFI dependency, works with any PSR-18 HTTP client). The FFI-based `pulsar-client-php` binary protocol is the upgrade path if throughput demands it.

**Message serialisation**: JSON with a PHP value object for each message type. Each message class implements Symfony Messenger's message interface. A `type` field in message metadata routes to the correct handler. Protobuf or Avro can be added later if throughput demands — JSON keeps the skeleton simple and debuggable.

**In-memory transport for testing**: Symfony Messenger's built-in `in-memory` transport is used for unit testing. Workers are testable without a running Pulsar instance.

### Worker Process Lifecycle

PHP is not designed for long-running processes. The skeleton's worker runner must handle:

1. **Memory limits**: track memory usage per cycle, exit when a threshold is reached. Kubernetes restarts the pod automatically.
2. **Signal handling**: handle SIGTERM (Kubernetes pod termination) gracefully — finish the current job, acknowledge it, then exit. Use `pcntl_signal()` with `pcntl_signal_dispatch()`.
3. **Connection recycling**: database connections and HTTP clients must be recycled periodically to prevent stale connections.

The Kubernetes Deployment sets `terminationGracePeriodSeconds` to allow in-flight jobs to complete (e.g. 30 seconds).

Workers process **one job at a time** per process (strictly sequential). Concurrency is achieved by scaling the number of worker pods, not by running parallel jobs within a process. This simplifies memory management and error handling.

### Deployment

Two operational profiles:

**Development / Skeleton** (default):
- Pulsar **standalone mode** — single process with embedded ZooKeeper + BookKeeper.
- Runs as a single Docker container / Kubernetes pod.
- Minimal resources: ~500m CPU, 1 GiB RAM request; 1 CPU, 2 GiB RAM limit; 10 GiB persistence.

**Production** (opt-in via Helm values):
- Full Pulsar cluster via the **official Apache Pulsar Helm chart** (`apache/pulsar-helm-chart`), pinned to a specific version.
- Minimum 3 ZooKeeper nodes, 3 BookKeeper nodes, 2+ Brokers.

The Helm chart defaults to standalone mode and requires explicit opt-in for the full cluster topology.

### Monitoring

Pulsar exposes Prometheus metrics natively. The skeleton includes:
- A Grafana dashboard JSON for Pulsar metrics (message rates, backlog, latency).
- Alerting rules for: consumer backlog > threshold, broker memory > 80%, dead letter queue messages.
- These are optional Helm chart components (disabled by default, enabled via values).

### Future Considerations

- **ZooKeeper deprecation**: Pulsar is moving towards ZooKeeper-less operation (Oxia). The skeleton should monitor this transition and update when stable.
- **Dead letter queue (DLQ)**: the skeleton should include a DLQ pattern for failed message processing. Symfony Messenger supports this via its failure transport configuration.

## Consequences

### Positive
- Persistent, replayable message streams for audit and debugging
- Single platform for both async job dispatch and event streaming
- Multi-tenancy support aligns with SaaS domain
- Symfony Messenger integration eliminates the need for a hand-rolled messaging abstraction
- In-memory transport enables unit testing without Pulsar
- Standalone mode keeps local development lightweight
- Helm chart available — no custom deployment tooling needed

### Negative
- Heavier operational footprint than RabbitMQ or Redis (mitigated by standalone mode for dev)
- HTTP API adds latency compared to binary protocol (~5-10ms vs ~1ms per message)
- Teams unfamiliar with Pulsar face a learning curve

### Risks
- Pulsar's PHP ecosystem may lag behind — mitigated by Symfony Messenger abstraction (transport is swappable)
- ZooKeeper/BookKeeper dependencies add production operational complexity — mitigated by official Helm chart with sensible defaults and future ZooKeeper-less path (Oxia)
- HTTP API throughput may be insufficient for high-volume workloads — FFI client is the upgrade path
- PHP worker memory leaks in long-running processes — mitigated by memory limit checks and Kubernetes pod restart

## Related Decisions

- [ADR-0001](0001-high-level-architecture.md) — Pulsar as the message stream component, trust boundaries (Pulsar auth required)
- [ADR-0002](0002-use-postgresql.md) — Materialised view refresh triggered via Pulsar events
- [ADR-0003](0003-oauth2-server-with-php.md) — Email verification dispatched via Pulsar
- [ADR-0008](0008-use-symfony-framework.md) — Symfony Messenger as the transport abstraction
