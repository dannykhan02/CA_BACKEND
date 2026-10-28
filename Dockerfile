FROM dunglas/frankenphp:php8.3.36-trixie

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# tesseract is geometry only: it locates words on JPG/PNG sources so a
# citation can be highlighted. Text extraction stays on claude_vision (see
# docs/IMAGE_TEXT_LOCATION.md). eng + swa cover Kenyan documents. This image
# serves both CA_BACKEND and ca-horizon-worker, which differ only by the role
# argument to bin/start-production.sh, so installing it here covers the worker
# that actually runs the stage.
RUN apt-get -o Acquire::Retries=5 update && apt-get -o Acquire::Retries=5 install -y --no-install-recommends \
    poppler-utils \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-swa \
    clamav \
    clamav-freshclam \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /var/lib/clamav \
    && chmod -R a+rw /var/lib/clamav /etc/clamav

WORKDIR /app

COPY . .

# Reject corrupted upload artifact before Composer or Artisan can report a
# false success by printing NUL bytes from a zero-filled PHP entrypoint.
RUN php -r '$files = ["artisan"]; foreach (["app", "bootstrap", "config", "routes", "database", "public"] as $dir) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) { if ($file->getExtension() === "php" && !str_contains($file->getPathname(), "_backup_")) $files[] = $file->getPathname(); } } foreach ($files as $file) { $bytes = file_get_contents($file); if ((!str_starts_with($bytes, "<?php") && !str_starts_with($bytes, "#!/usr/bin/env php\n<?php")) || str_contains($bytes, chr(0))) { fwrite(STDERR, "FATAL: invalid PHP source: ".$file.PHP_EOL); exit(1); } }'

# exif: ImageOrienter reads the Orientation tag so word boxes are measured
# against the image as a browser renders it, not as the sensor stored it.
RUN install-php-extensions gd exif zip pcntl ctype curl dom fileinfo filter hash mbstring openssl pcre pdo session tokenizer xml pdo_pgsql

# redis is built separately because pecl.php.net has returned HTTP 500 and
# failed the whole build. Same pinned version both ways: PECL first (4 tries,
# 15 s apart), then the phpredis GitHub tag of that version. The build fails
# only if both fail or the extension did not load.
RUN set -e; \
    ok=0; \
    for attempt in 1 2 3 4; do \
        if install-php-extensions redis-6.3.0; then ok=1; break; fi; \
        echo "PECL redis-6.3.0 failed (attempt $attempt of 4)" >&2; \
        if [ "$attempt" -lt 4 ]; then sleep 15; fi; \
    done; \
    if [ "$ok" -ne 1 ]; then \
        echo "PECL unavailable, building phpredis 6.3.0 from GitHub" >&2; \
        install-php-extensions phpredis/phpredis@6.3.0; \
    fi; \
    if ! php -m | grep -qx redis; then \
        echo "FATAL: the redis PHP extension is not loaded after install" >&2; \
        exit 1; \
    fi

# Match PHP's upload and memory limits with DocIntel's processing needs.
RUN printf "upload_max_filesize=20M\npost_max_size=25M\nmemory_limit=512M\n" \
    > /usr/local/etc/php/conf.d/uploads.ini

# Packagist can fail transiently too: retry before failing the build.
RUN set -e; \
    for attempt in 1 2 3; do \
        if composer install --optimize-autoloader --no-dev --no-interaction; then exit 0; fi; \
        echo "composer install failed (attempt $attempt of 3)" >&2; \
        if [ "$attempt" -lt 3 ]; then sleep 10; fi; \
    done; \
    exit 1

RUN mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/framework/testing storage/logs bootstrap/cache \
    && chmod -R a+rw storage bootstrap/cache

CMD ["sh", "bin/start-production.sh", "web"]