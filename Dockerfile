FROM dunglas/frankenphp:php8.3.33-trixie

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
    poppler-utils \
    ghostscript \
    imagemagick \
    libzip-dev \
    unzip \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /etc/ImageMagick-6 \
    && echo '<?xml version="1.0" encoding="UTF-8"?>' > /etc/ImageMagick-6/policy.xml \
    && echo '<policymap>' >> /etc/ImageMagick-6/policy.xml \
    && echo '  <policy domain="coder" rights="read|write" pattern="PDF" />' >> /etc/ImageMagick-6/policy.xml \
    && echo '  <policy domain="coder" rights="read" pattern="PNG" />' >> /etc/ImageMagick-6/policy.xml \
    && echo '  <policy domain="coder" rights="read" pattern="JPEG" />' >> /etc/ImageMagick-6/policy.xml \
    && echo '</policymap>' >> /etc/ImageMagick-6/policy.xml

WORKDIR /app
COPY . .

RUN install-php-extensions gd zip pcntl ctype curl dom fileinfo filter hash mbstring openssl pcre pdo session tokenizer xml pdo_pgsql redis

RUN composer install --optimize-autoloader --no-dev --no-interaction

RUN mkdir -p storage/framework/sessions \
    storage/framework/views \
    storage/framework/cache \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache \
    && chmod -R a+rw storage \
    && chmod -R a+rw bootstrap/cache

RUN php artisan config:cache && php artisan route:cache && php artisan view:cache

HEALTHCHECK --interval=30s --timeout=3s --start-period=30s --retries=3 \
    CMD php artisan horizon:status || exit 1

CMD ["php", "artisan", "horizon"]
