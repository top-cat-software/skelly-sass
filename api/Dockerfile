# Multi-stage Dockerfile for the PHP application (API, Auth, Workers).
# Same image, different entry points per component.
# See ADR-0007 for Docker image strategy, ADR-0008 for Symfony framework context.

# ──────────────────────────────────────────────────────────────────────────────
# Stage: base — PHP runtime with all required extensions
# ──────────────────────────────────────────────────────────────────────────────
FROM php:8.4-cli-alpine AS base

# Install system dependencies and PHP extensions.
# Uses install-php-extensions for reliable Alpine builds.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
    pdo_pgsql \
    pcntl \
    opcache \
    intl \
    redis \
    zip

# Copy Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

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

# Run as non-root (ADR-0007 security requirement).
USER 1000:1000

COPY --from=build --chown=1000:1000 /app /app

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
