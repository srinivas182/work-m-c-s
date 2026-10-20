# Local development image: FrankenPHP + Octane with file watching.
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_mysql redis pcntl intl zip bcmath opcache \
 && apt-get update \
 && apt-get install -y --no-install-recommends git unzip nodejs \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
