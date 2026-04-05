# Multi-stage Dockerfile for the SvelteKit frontend application.
# See ADR-0005 for frontend technology choice, ADR-0007 for Docker image strategy.

# ──────────────────────────────────────────────────────────────────────────────
# Stage: build — install dependencies and build the SvelteKit application
# ──────────────────────────────────────────────────────────────────────────────
FROM node:22-alpine AS build

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

# ──────────────────────────────────────────────────────────────────────────────
# Stage: runtime — production image with only the build output
# ──────────────────────────────────────────────────────────────────────────────
FROM node:22-alpine AS runtime

WORKDIR /app

# Run as non-root (ADR-0007 security requirement).
USER 1000:1000

# Copy the adapter-node build output. This includes a self-contained
# Node.js server with all production dependencies bundled.
COPY --from=build --chown=1000:1000 /app/build /app/build
COPY --from=build --chown=1000:1000 /app/package.json /app/package.json

ENV PORT=3000
ENV HOST=0.0.0.0
EXPOSE 3000

CMD ["node", "build/index.js"]
