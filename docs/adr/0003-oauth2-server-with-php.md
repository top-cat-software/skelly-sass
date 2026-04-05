# ADR-0003: OAuth 2.0 Server with PHP

**Date**: 2026-04-04
**Status**: Accepted
**Deciders**: Project Architect (IC7), Project Owner, Security Design Expert (IC6), Principal PHP (IC6)

## Context

The SaaS skeleton requires a full authentication and authorisation system supporting:

- User registration with email verification
- Login with email/password
- Password reset flow
- Two-factor authentication (TOTP-based)
- Social login via OAuth 2.0 providers (Google, GitHub initially)
- JWT-based access tokens for API authentication
- Refresh token rotation

This is security-critical infrastructure. The team's primary expertise is PHP.

## Options Considered

### Option 1: PHP Library — League OAuth2 Server
Build on `thephpleague/oauth2-server`, a well-tested, spec-compliant OAuth 2.0 library for PHP.
- **Pros**: Single language across the back-end, full control over auth flows, co-deployable with API, no external service dependency.
- **Cons**: We own the full security surface area, must implement 2FA/social login/registration ourselves on top of the library.

### Option 2: Standalone Identity Provider — Keycloak
Use Keycloak as a dedicated identity provider, running as a separate service.
- **Pros**: Feature-complete (SSO, SAML, SCIM, social login, 2FA out of the box), battle-tested at enterprise scale.
- **Cons**: JVM-based (heavy resource usage), complex configuration, opinionated UI that is hard to customise, adds a large operational dependency to the skeleton.

### Option 3: Go-Based Identity Stack — Ory Hydra + Kratos
Use Ory's identity stack with a Go-based auth server.
- **Pros**: Modern, cloud-native design, good API-first approach, lightweight.
- **Cons**: Introduces a second language and ecosystem, team has less Go expertise, two services to operate (Hydra + Kratos), less mature than Keycloak for enterprise features.

## Decision

Use **Option 1: League OAuth2 Server** as the foundation, integrated into the Symfony 7 Auth application (ADR-0008) via Symfony Security authenticators.

### Rationale

- **Single language**: keeps the auth server in PHP, matching the team's primary expertise and reducing operational burden.
- **Library, not framework**: League OAuth2 Server is spec-compliant and well-tested. We wrap it — it does not dictate our architecture.
- **Co-deployable**: initially deployed as part of the same container image as the API, with a separate Symfony kernel entry point. Can be extracted into its own service later.
- **Full control**: 2FA, social login, and registration are application-level features built on top of the OAuth 2.0 token machinery.
- **Symfony integration**: League OAuth2 Server integrates via a custom Symfony Security authenticator, leveraging Symfony's firewall and access control configuration.

### Primary Grant Type: Authorization Code + PKCE

The **Authorization Code grant with PKCE** (Proof Key for Code Exchange) is the primary browser flow. The password grant is **disabled by default** — it is deprecated in the OAuth 2.0 Security Best Current Practice (RFC 6819) and draft OAuth 2.1 specification.

The password grant may be enabled as an opt-in for first-party machine-to-machine clients only, gated by client type configuration.

### Auth Flow Architecture

```
Browser                SvelteKit Server         Auth Server (Symfony)     PostgreSQL
  │                         │                         │                      │
  │── GET /login ──────────▶│                         │                      │
  │                         │── Generate PKCE ────────│                      │
  │◀── 302 /auth/authorize ─│  (code_challenge)       │                      │
  │                         │                         │                      │
  │── GET /auth/authorize ──────────────────────────▶│                      │
  │                         │                         │── Validate client ──▶│
  │◀── Login form / 302 to provider (social) ────────│                      │
  │                         │                         │                      │
  │── POST /auth/login (credentials) ───────────────▶│                      │
  │                         │                         │── Validate creds ───▶│
  │                         │                         │── Check 2FA ────────▶│
  │                         │                         │                      │
  │  [If 2FA enabled]       │                         │                      │
  │◀── 2FA challenge (challenge_token) ──────────────│                      │
  │── POST /auth/2fa/verify (challenge_token + TOTP) ▶│                      │
  │                         │                         │                      │
  │◀── 302 callback?code=xxx ────────────────────────│                      │
  │                         │                         │                      │
  │── GET /callback?code=xxx ▶│                         │                      │
  │                         │── POST /auth/token ────▶│                      │
  │                         │  (code + code_verifier) │── Issue tokens ─────▶│
  │                         │◀── { access, refresh } ─│                      │
  │                         │── Set httpOnly cookie ──│                      │
  │◀── 200 (session set) ───│                         │                      │
```

### Token Policy

| Token | Lifetime | Storage | Rotation |
|-------|----------|---------|----------|
| Access token (JWT) | 15 minutes | Not stored server-side (stateless, validated by signature) | Not rotated — short-lived by design |
| Refresh token | 7 days (sliding) | Stored hashed (SHA-256) in PostgreSQL | Rotated on every use (one-time use, token family revocation on replay) |
| Authorization code | 60 seconds | Stored hashed in PostgreSQL | Single use |
| 2FA challenge token | 5 minutes | Stored hashed in PostgreSQL with user ID + client binding | Single use |

**Refresh token family revocation**: if a refresh token is used twice (replay detected), ALL tokens in that token family are revoked. This detects token theft — a legitimate client and an attacker cannot both successfully use the same refresh token lineage.

### JWT Key Management

- **Algorithm**: RS256 (RSA-SHA256). The API validates tokens with only the public key; the private key stays on the Auth server.
- **Key size**: 4096-bit RSA.
- **Private key storage**: Kubernetes Secret, mounted as a file (not an environment variable — avoids accidental logging).
- **Key rotation**: support two active key pairs simultaneously (current and previous). The JWT `kid` (Key ID) header identifies which key signed the token. Rotate keys every 90 days.
- **JWKS endpoint**: publish public keys at `/.well-known/jwks.json` for service-to-service token validation without shared secrets.

### Password Hashing

- **Algorithm**: Argon2id via PHP's `password_hash()` with `PASSWORD_ARGON2ID`.
- **Parameters**: memory cost 65536 KiB (64 MB), time cost 4 iterations, parallelism 1.
- **Rehashing**: call `password_needs_rehash()` on every successful login to auto-upgrade parameters if the configuration changes.
- **No manual implementation**: use PHP's native functions only.

### Rate Limiting

Implemented via Symfony RateLimiter component at the application middleware level (not Traefik — per-account limits require application context).

| Endpoint | Limit | Window | Key | Action on Exceed |
|----------|-------|--------|-----|-----------------|
| `POST /auth/token` | 5 attempts | 15 minutes | Per-IP + per-account (dual key) | 429 + `Retry-After` header |
| `POST /auth/register` | 3 attempts | 1 hour | Per-IP | 429 |
| `POST /auth/password-reset` | 3 attempts | 1 hour | Per-IP | Always return 200 (prevent email enumeration) |
| `POST /auth/2fa/verify` | 3 attempts | 5 minutes | Per-challenge-token | Invalidate challenge, require re-authentication |

- Use **sliding window** algorithm (not fixed window) to prevent burst attacks at window boundaries.

### CSRF Protection

- **Cookie-authenticated endpoints** (via SvelteKit BFF): protected by SvelteKit's built-in CSRF protection (Origin/Referer header validation + CSRF token).
- **JWT bearer-authenticated endpoints**: CSRF is not applicable — bearer tokens are not auto-attached by browsers.
- The SvelteKit BFF pattern (ADR-0005) means most browser flows go through SvelteKit server routes, which mitigates CSRF. This is documented explicitly so adopters understand the security model.

### 2FA Strategy

- **TOTP-based** (RFC 6238) using `robthree/twofactorauth`.
- If 2FA is enabled, the authorization flow returns a **2FA challenge** instead of completing the grant:
  - The challenge response includes a `challenge_token` (opaque, cryptographically random, 256-bit).
  - The challenge token is stored hashed in PostgreSQL with a 5-minute TTL, the user ID, and a client binding (IP + User-Agent hash) to prevent relay attacks.
  - The client submits `challenge_token` + TOTP code to `/auth/2fa/verify`.
  - On success, the authorization flow completes and issues the authorization code.
  - On failure (3 attempts), the challenge token is invalidated and the user must re-authenticate from scratch.

### Social Login Strategy

- Use `league/oauth2-client` with provider packages for Google and GitHub.
- Social login creates or links a local user account.
- After social auth, issue our own JWT — the front-end never holds third-party tokens.
- Third-party access tokens are used **only** for the initial profile fetch (email, name, avatar) and are never stored persistently. If social profile refresh is needed, require re-authentication with the provider.
- Third-party token values are never logged.

Social login is implemented as a **strategy pattern**:
```php
interface SocialLoginProvider {
    public function getAuthorizationUrl(string $state): string;
    public function handleCallback(string $code): SocialUser;
}
```
Ship Google and GitHub implementations. Adopters add providers by implementing the interface and registering in configuration.

### Client Credentials Grant

The skeleton supports the **Client Credentials** grant for service-to-service authentication (e.g. external integrations, internal microservices). This is separate from the browser flow and uses client ID + secret, not user credentials.

## Consequences

### Positive
- Team works in one language for the entire back-end
- Full control over registration, 2FA, and social login flows
- No external identity provider to operate, upgrade, or configure
- Tokens are our own JWTs — no dependency on external token introspection
- Auth Code + PKCE follows current OAuth 2.0 security best practices
- JWKS endpoint enables future service-to-service auth without shared secrets
- Symfony Security integration provides firewall, access control, and authenticator infrastructure

### Negative
- We own the security surface area — must invest in thorough security review
- Password hashing, token storage, and key rotation are our responsibility
- More initial development effort than adopting a turnkey solution
- Auth Code + PKCE is more complex to implement than the password grant

### Risks
- Security vulnerabilities in our auth implementation could be severe — mitigated by mandatory Security Design Expert review before implementation and structured security testing requirements
- If requirements grow to enterprise SSO (SAML, SCIM), this decision may need revisiting
- JWT key rotation process failure could lock out all users — mitigated by dual-key support (current + previous)
- Argon2id memory cost (64 MB per hash) could be used for DoS via concurrent login attempts — mitigated by rate limiting

## Related Decisions

- [ADR-0001](0001-high-level-architecture.md) — OAuth server as a component in the architecture
- [ADR-0009](0009-redis-streams-for-messaging.md) — Email verification dispatched via message transport (Redis Streams)
- [ADR-0005](0005-svelte-frontend.md) — SvelteKit BFF handles token exchange and httpOnly cookie management
- [ADR-0008](0008-use-symfony-framework.md) — Symfony 7 framework, Security component integration
