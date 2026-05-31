FROM php:8.3-cli-alpine

# Install PostgreSQL extension and dependencies
RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json .
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy source code
COPY src/ src/

CMD ["php", "src/run.php"]
