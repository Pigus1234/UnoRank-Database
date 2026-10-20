FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libonig-dev libxml2-dev libsqlite3-dev libcurl4-openssl-dev \
    && docker-php-ext-install -j$(nproc) pdo_mysql pdo_sqlite mbstring dom xml xmlwriter curl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
