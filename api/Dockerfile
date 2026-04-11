# Multi-stage Dockerfile for the PHP application (API, Auth, Workers).
# Same image, different entry points per component.
# See ADR-0007 for Docker image strategy, ADR-0008 for Symfony framework context,
# ADR-0010 for FrankenPHP decision.

# ──────────────────────────────────────────────────────────────────────────────
# Stage: base — FrankenPHP runtime with all required extensions
# ──────────────────────────────────────────────────────────────────────────────
FROM dunglas/frankenphp:1.12-php8.4 AS base

# Install PHP extensions not bundled in the FrankenPHP base image.
# pdo_pgsql — PostgreSQL driver for Doctrine
# pcntl     — signal handling for Symfony Messenger workers
# intl      — internationalisation (ICU) for Symfony validators/formatters
# opcache   — bytecode cache for production performance
# redis     — phpredis for Symfony Cache/Messenger Redis transport
# zip       — ZIP archive support for Composer and file handling
RUN install-php-extensions \
    pdo_pgsql \
    pcntl \
    intl \
    opcache \
    redis \
    zip

# Copy Composer from the official image.
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# ──────────────────────────────────────────────────────────────────────────────
# Stage: deps — install Composer dependencies
# ──────────────────────────────────────────────────────────────────────────────
FROM base AS deps

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# ──────────────────────────────────────────────────────────────────────────────
# Stage: build — compile Symfony container and optimise autoloader
# ──────────────────────────────────────────────────────────────────────────────
FROM base AS build

COPY --from=deps /app/vendor /app/vendor
COPY . /app

RUN composer dump-autoload --optimize --no-dev

ARG APP_ENV=prod
ENV APP_ENV=${APP_ENV}

RUN php bin/console cache:warmup --env=prod

# ──────────────────────────────────────────────────────────────────────────────
# Stage: runtime — final production image
# ──────────────────────────────────────────────────────────────────────────────
FROM base AS runtime

# Copy the Caddyfile into the standard Caddy config location.
COPY Caddyfile /etc/caddy/Caddyfile

# Run as non-root (ADR-0007 security requirement).
# FrankenPHP can bind to unprivileged ports (>1024) without CAP_NET_BIND_SERVICE.
USER 1000:1000

COPY --from=build --chown=1000:1000 /app /app

EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
