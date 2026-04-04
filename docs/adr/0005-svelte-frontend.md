# ADR-0005: Svelte with Bits UI for Frontend

**Date**: 2026-04-04
**Status**: Accepted
**Deciders**: Project Architect (IC7), Project Owner, Principal JS (IC6), Security Design Expert (IC6)

## Context

The SaaS skeleton needs a front-end application that provides:

- User authentication flows (login, register, password reset, 2FA)
- A dashboard shell that adopters can extend
- Integration with the RESTful API and OAuth 2.0 server
- Accessible, responsive UI components

The project owner has specified Svelte with Bits UI. A front-end specialist will handle implementation details.

## Options Considered

### Option 1: Svelte (SvelteKit) + Bits UI
Modern, compiler-based framework with a headless component library.
- **Pros**: Small bundle sizes (compiler-based, no virtual DOM runtime), simple mental model, Bits UI provides accessible headless components built on Melt UI primitives, SvelteKit handles routing and SSR.
- **Cons**: Smaller ecosystem than React/Vue, fewer third-party component libraries, harder to hire for.

### Option 2: React + Radix UI
Industry-standard framework with a mature headless component library.
- **Pros**: Massive ecosystem, easy to hire for, Radix is battle-tested, extensive tooling (Next.js, etc.).
- **Cons**: Larger bundle sizes, more boilerplate, virtual DOM overhead, project owner is not predisposed to React.

### Option 3: Vue + Headless UI
Progressive framework with Tailwind Labs' component library.
- **Pros**: Gentle learning curve, good documentation, Headless UI is well-maintained.
- **Cons**: Smaller ecosystem than React, Headless UI has fewer components than Bits UI/Radix, less momentum than Svelte for new projects.

## Decision

Use **Svelte 5 (via SvelteKit) with Bits UI v1.x** for the front-end application.

### Svelte Version: Svelte 5 with Runes

Target **Svelte 5** from the start. This is a clean-slate skeleton — no migration burden.

- Use `$state`, `$derived`, `$effect` instead of Svelte 4's reactive declarations.
- Component props use `$props()` instead of `export let`.
- Bits UI v1.x supports Svelte 5 natively.

### Project Structure

```
src/
├── lib/
│   ├── components/       # Reusable UI components (wrapping Bits UI)
│   │   ├── ui/           # Base components (Button, Input, Dialog, etc.)
│   │   └── auth/         # Auth-specific components (LoginForm, RegisterForm)
│   ├── api/              # API client functions (typed fetch wrappers)
│   ├── stores/           # Svelte 5 rune-based state contexts
│   └── utils/            # Helpers (date formatting, validation, etc.)
├── routes/
│   ├── (auth)/           # Auth route group — public layout
│   │   ├── login/
│   │   ├── register/
│   │   ├── password-reset/
│   │   └── 2fa/
│   ├── (app)/            # Authenticated route group — dashboard layout
│   │   ├── dashboard/
│   │   └── settings/
│   └── api/              # SvelteKit server routes (token proxy, CSRF)
├── hooks.server.ts       # Auth guard, token validation, CSP headers
└── app.html
```

Route groups `(auth)` and `(app)` apply different layouts (public vs authenticated).

### Styling: Tailwind CSS v4

Bits UI components are unstyled — **Tailwind CSS v4** provides the styling layer:
- CSS-native configuration (no `tailwind.config.js`), smaller output, faster builds.
- Most Bits UI examples and community patterns assume Tailwind.
- The skeleton includes a consistent design token set (colours, spacing, typography) via Tailwind's theme.

### Complementary Libraries

Bits UI has gaps that are filled by targeted libraries:

| Gap | Library | Notes |
|-----|---------|-------|
| Form validation | `sveltekit-superforms` + Zod | Form-level validation, field errors, progressive enhancement |
| Toast notifications | `svelte-sonner` | Svelte 5 compatible toast library |
| Data tables | `@tanstack/svelte-table` | If needed for dashboard — optional |
| Sidebar/navigation | Built from Bits UI primitives | Sheet + Accordion composited into a dashboard sidebar |

### Token Management: httpOnly Cookie Proxy

JWTs are stored in httpOnly cookies via SvelteKit server routes. The browser never directly handles JWTs.

1. SvelteKit server route `POST /api/auth/token` proxies to the PHP Auth server.
2. On success, sets cookies with full security flags:
   ```
   Set-Cookie: access_token=<jwt>; HttpOnly; Secure; SameSite=Lax; Path=/api; Max-Age=900
   Set-Cookie: refresh_token=<token>; HttpOnly; Secure; SameSite=Lax; Path=/api/auth/refresh; Max-Age=604800
   ```
3. `Path` scoping ensures the refresh token cookie is only sent to the refresh endpoint.
4. `hooks.server.ts` middleware reads the cookie and validates the JWT on every request.
5. If the access token is expired but the refresh token is valid, auto-refresh transparently.

### State Management

With Svelte 5 runes, the built-in reactivity system is sufficient. No separate state management library needed.

- **Auth state**: managed in `hooks.server.ts`, passed to pages via `event.locals` → `load` functions. No client-side auth store.
- **UI state** (sidebar open, theme preference): Svelte 5 `$state` in a context module.
- **Server state** (API data): SvelteKit's `load` functions + `invalidate()` for re-fetching.

### Content Security Policy

A strict default CSP is configured in `hooks.server.ts`:

```
Content-Security-Policy:
  default-src 'self';
  script-src 'self';
  style-src 'self' 'unsafe-inline';
  img-src 'self' data: https:;
  connect-src 'self' <api-origin> <mercure-origin>;
  frame-ancestors 'none';
  base-uri 'self';
  form-action 'self';
```

- `frame-ancestors 'none'` prevents clickjacking.
- `'unsafe-inline'` for styles is required for Svelte's scoped styles and Tailwind — this trade-off is documented.
- Origins are configurable via environment variables.

### Security Controls

- **`{@html}` prohibition**: the `{@html}` directive is prohibited unless content has been sanitised with DOMPurify. Code review must flag any usage.
- **Dependency pinning**: all npm dependencies pinned to exact versions (not ranges) in `package.json`.
- **CI security**: `npm ci` (not `npm install`) in CI/CD and Docker builds. `npm audit` in CI pipeline.
- **Social login redirects**: `redirect_uri` validated against a whitelist. `state` parameter is cryptographically random and verified on callback. SvelteKit server routes handle the callback — the browser never sees the authorization code.

### Testing

- **Vitest**: unit tests for components, stores, and utility functions. Native SvelteKit integration.
- **@testing-library/svelte**: component testing with accessible queries (user-centric, not implementation-centric).
- **Playwright**: E2E tests for auth flows (login, register, 2FA). SvelteKit has first-class Playwright support.

### Build and Deployment

- SvelteKit uses Vite natively — code splitting enabled by default.
- Use `adapter-node` for Docker deployment (generates a Node.js server).
- Multi-stage Dockerfile: `node:22-alpine` for build, `node:22-alpine` for runtime.

## Consequences

### Positive
- Small bundle sizes improve initial load performance
- Svelte 5 runes provide a clean, modern reactivity model with no migration debt
- Bits UI provides accessibility out of the box (ARIA, keyboard navigation)
- httpOnly cookie proxy keeps JWTs out of JavaScript entirely — eliminates XSS-based token theft
- Tailwind v4 provides consistent styling with minimal CSS output
- Strict CSP and security controls demonstrate production-grade frontend security

### Negative
- Smaller talent pool compared to React
- `'unsafe-inline'` for styles weakens CSP (required by Svelte + Tailwind)
- Bits UI is less mature than Radix — may encounter edge cases or missing components
- Multiple complementary libraries (superforms, sonner, tanstack) add dependency surface

### Risks
- Svelte 5 runes ecosystem is still maturing — some community packages may lag behind
- Bits UI gaps may require custom component development for features not covered
- SvelteKit server routes as a BFF add a network hop — mitigated by co-location in the same cluster

## Related Decisions

- [ADR-0001](0001-high-level-architecture.md) — Frontend as an independent component
- [ADR-0003](0003-oauth2-server-with-php.md) — OAuth Auth Code + PKCE flow, token proxy integration
- [ADR-0006](0006-restful-api-first.md) — API that the front-end consumes
- [ADR-0008](0008-use-symfony-framework.md) — Mercure hub for real-time push to the frontend
