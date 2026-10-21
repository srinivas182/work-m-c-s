# Local development image: FrankenPHP + Octane with file watching.
# Pinned extension installer: see docker/production/Dockerfile.
FROM mlocati/php-extension-installer:2.12.0 AS extension-installer

FROM dunglas/frankenphp:1-php8.4

COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions

RUN install-php-extensions pdo_mysql redis pcntl intl zip bcmath opcache \
 && apt-get update \
 && apt-get install -y --no-install-recommends git unzip nodejs \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
