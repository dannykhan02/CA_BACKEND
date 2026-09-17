FROM dunglas/frankenphp:php8.3.33-trixie
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends \
    poppler-utils \
    clamav \
    clamav-freshclam \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/lib/clamav \
    && chmod -R a+rw /var/lib/clamav /etc/clamav
WORKDIR /app
COPY . .
RUN install-php-extensions gd zip pcntl ctype curl dom fileinfo filter hash mbstring openssl pcre pdo session tokenizer xml pdo_pgsql redis
RUN composer install --optimize-autoloader --no-dev --no-interaction
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/framework/testing storage/logs bootstrap/cache \
    && chmod -R a+rw storage bootstrap/cache
CMD ["sh", "-c", "freshclam --quiet || echo 'freshclam update failed - continuing with existing signatures if any'; php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan horizon"]
