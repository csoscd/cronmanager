################################################################################
# Cronmanager Web – self-contained Docker image
#
# Stages:
#   1. composer  – installs PHP dependencies with Composer
#   2. final     – runtime image (cs1711/cs_php-nginx-fpm:latest-alpine)
#
# Build from the repository root:
#   docker build -f docker/web/Dockerfile -t cs1711/cronmanager-web:latest .
#
# Notes:
#   * The entrypoint runs as root (required for supervisord to manage processes).
#     nginx and php-fpm drop to unprivileged users via their own config files.
#   * PHP binary on alpine is php84, not php.
#
# @author  Christian Schulz <technik@meinetechnikwelt.rocks>
# @license GNU General Public License version 3 or later
################################################################################

# ── Stage 1: install Composer dependencies ────────────────────────────────────
FROM composer:2 AS composer-build

WORKDIR /build

COPY composer.json ./
COPY composer.lock* ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --no-scripts


# ── Stage 2: runtime image ────────────────────────────────────────────────────
FROM cs1711/cs_php-nginx-fpm:latest-alpine

LABEL org.opencontainers.image.title="Cronmanager Web" \
      org.opencontainers.image.description="Cronmanager web UI – PHP-FPM + Nginx, config from environment variables" \
      org.opencontainers.image.authors="Christian Schulz <technik@meinetechnikwelt.rocks>" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.source="https://github.com/csoscd/cronmanager"

# Switch to root to install su-exec and copy files
USER root

# Copy Composer-installed vendor tree
COPY --from=composer-build --chown=nobody:nobody /build/vendor /var/www/libs/vendor

# Copy web application source
COPY --chown=nobody:nobody web/ /var/www/html/

# Pre-create directories that will be used at runtime
RUN mkdir -p /var/www/conf /var/www/log \
    && chown -R nobody:nobody /var/www/conf /var/www/log

# Copy and configure entrypoint
COPY docker/web/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Declare log volume (Docker-managed named volume by default)
VOLUME ["/var/www/log"]

EXPOSE 8080

# Run as root so the entrypoint can write the config file;
# the entrypoint then drops to nobody via su-exec before starting supervisord.
USER root

ENTRYPOINT ["/entrypoint.sh"]
