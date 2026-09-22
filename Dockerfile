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
# Reject corrupted upload artifacts before Composer or Artisan can report a
# false success by printing NUL bytes from a zero-filled PHP entrypoint.
RUN php -r '$files = ["artisan"]; foreach (["app", "bootstrap", "config", "routes", "database", "public"] as $dir) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) { if ($file->getExtension() === "php" && !str_contains($file->getPathname(), "_backup_")) $files[] = $file->getPathname(); } } foreach ($files as $file) { $bytes = file_get_contents($file); if ((!str_starts_with($bytes, "<?php") && !str_starts_with($bytes, "#!/usr/bin/env php\n<?php")) || str_contains($bytes, chr(0))) { fwrite(STDERR, "FATAL: invalid PHP source: ".$file.PHP_EOL); exit(1); } }'
RUN install-php-extensions gd zip pcntl ctype curl dom fileinfo filter hash mbstring openssl pcre pdo session tokenizer xml pdo_pgsql redis
RUN composer install --optimize-autoloader --no-dev --no-interaction
RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/framework/testing storage/logs bootstrap/cache \
    && chmod -R a+rw storage bootstrap/cache
CMD ["sh", "bin/start-production.sh", "web"]
