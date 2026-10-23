# syntax=docker/dockerfile:1.7
#
# WURK production image — cloud-agnostic.
#   Runtime:  FrankenPHP + Laravel Octane, non-root, OPcache tuned
#   Build:    docker build -f docker/production/Dockerfile -t wurk:latest .
#   Run:      see docs/deployment/docker.md

ARG PHP_VERSION=8.4
# Build tooling is pinned like any other dependency (at least two weeks old when
# chosen), so an upstream release can never change or break our images silently.
ARG EXTENSION_INSTALLER_VERSION=2.12.0
ARG FRANKENPHP_VERSION=1.13.0
# Node runs the server-side renderer for wurk.co.za (pinned like other tooling).
ARG NODE_VERSION=22.23.3

# ---------------------------------------------------------------------------
# base: PHP runtime with the extensions WURK needs
# ---------------------------------------------------------------------------
FROM mlocati/php-extension-installer:${EXTENSION_INSTALLER_VERSION} AS extension-installer

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-trixie AS base

COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions

RUN install-php-extensions pdo_mysql redis pcntl intl zip bcmath opcache gd exif

# Use PHP's recommended production settings when the base image ships them;
# our own overrides (zz-wurk.ini) apply either way.
RUN if [ -f "$PHP_INI_DIR/php.ini-production" ]; then \
      cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    fi \
 && php --ini

COPY docker/production/php.ini "$PHP_INI_DIR/conf.d/zz-wurk.ini"

WORKDIR /app

# ---------------------------------------------------------------------------
# vendor: production Composer dependencies (no dev packages)
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
      --no-scripts --no-autoloader

# ---------------------------------------------------------------------------
# assets: production frontend build
# ---------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /app
RUN corepack enable
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY . .
RUN pnpm build

# ---------------------------------------------------------------------------
# runtime
# ---------------------------------------------------------------------------
# The Node binary only (glibc build; the SSR bundle carries its own dependencies).
FROM node:${NODE_VERSION}-bookworm-slim AS node-runtime

FROM base AS runtime

ARG APP_USER=wurk
ARG APP_UID=10001

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    OCTANE_SERVER=frankenphp \
    OCTANE_WORKERS=auto \
    OCTANE_MAX_REQUESTS=500 \
    XDG_CONFIG_HOME=/app/storage/caddy/config \
    XDG_DATA_HOME=/app/storage/caddy/data

RUN useradd --uid "${APP_UID}" --create-home --shell /usr/sbin/nologin "${APP_USER}"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --chown=${APP_USER}:${APP_USER} . .
COPY --chown=${APP_USER}:${APP_USER} --from=vendor /app/vendor ./vendor
COPY --chown=${APP_USER}:${APP_USER} --from=assets /app/public/build ./public/build
COPY --chown=${APP_USER}:${APP_USER} --from=assets /app/bootstrap/ssr ./bootstrap/ssr
COPY --from=node-runtime /usr/local/bin/node /usr/local/bin/node

# Optimised autoloader + package discovery, then drop build-only tooling.
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
 && rm /usr/bin/composer \
 && mkdir -p storage/caddy/config storage/caddy/data storage/framework/cache/data \
      storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R ${APP_USER}:${APP_USER} storage bootstrap/cache \
 && chmod +x docker/production/entrypoint.sh

USER ${APP_USER}

EXPOSE 8000

# Container liveness. Orchestrators should use GET /health/ready for readiness.
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
  CMD ["php", "-r", "exit(@file_get_contents('http://127.0.0.1:8000/health/live') === false ? 1 : 0);"]

ENTRYPOINT ["docker/production/entrypoint.sh"]
CMD ["serve"]
