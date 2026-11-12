FROM php:7.4-cli

# Utilizar repositorios históricos de Debian 11
RUN printf '%s\n' \
    'deb https://archive.debian.org/debian bullseye main' \
    'deb https://archive.debian.org/debian-security bullseye-security main' \
    > /etc/apt/sources.list \
    && rm -f /etc/apt/sources.list.d/*.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
    libcurl4-openssl-dev \
    ca-certificates \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*