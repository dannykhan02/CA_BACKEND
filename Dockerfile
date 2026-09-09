FROM dunglas/frankenphp:php8.3.33-trixie

RUN apt-get update && apt-get install -y --no-install-recommends \
    poppler-utils dnsutils iputils-ping netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

CMD sh -c "getent hosts redis.railway.internal; nc -zv redis.railway.internal 6379; sleep 3600"
