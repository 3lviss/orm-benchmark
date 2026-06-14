FROM php:8.3-cli

# Install PostgreSQL extension and dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json .
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy source code and entrypoint
COPY src/ src/
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

# Proxy dir must exist — classes are generated at container startup
RUN mkdir -p /var/tmp/doctrine-proxies

ENTRYPOINT ["/docker-entrypoint.sh"]
CMD []
