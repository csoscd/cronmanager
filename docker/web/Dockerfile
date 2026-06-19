################################################################################
# Cronmanager Web – self-contained Docker image
#
# Stages:
#   1. composer  – installs PHP dependencies with Composer
#   2. final     – runtime image (cs1711/cs_cronmanagerweb:latest)
#
# Build from the repository root:
#   docker build -f docker/web/Dockerfile -t cs1711/cronmanager-web:latest .
#
# Notes:
#   * The entrypoint runs as root (required for runtime chown of mounted
#     volumes and config generation). nginx and php-fpm drop to nobody:nogroup
#     via their configuration in the cs_cronmanagerweb base image.
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
FROM cs1711/cs_cronmanagerweb:latest

# Receive the application version from the CI build pipeline and expose it as
# an environment variable so the web UI can display it in the footer.
ARG APP_VERSION=unknown
ENV APP_VERSION=${APP_VERSION}

LABEL org.opencontainers.image.title="Cronmanager Web" \
      org.opencontainers.image.description="Cronmanager web UI – PHP-FPM + Nginx, config from environment variables" \
      org.opencontainers.image.authors="Christian Schulz <technik@meinetechnikwelt.rocks>" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.source="https://github.com/csoscd/cronmanager"

# USER root is required here only for COPY/setup steps. PHP-FPM runs as the
# unprivileged 'nobody' user defined in the pool config (www.conf), so the
# effective runtime user is not root.
# nosemgrep: dockerfile.security.last-user-is-root.last-user-is-root
USER root

# Copy Composer-installed vendor tree
COPY --from=composer-build --chown=nobody:nogroup /build/vendor /var/www/libs/vendor

# Copy web application source
COPY --chown=nobody:nogroup web/ /var/www/html/

# Download static assets that are not checked into the repository.
# Tailwind CSS (Play CDN build) and Chart.js UMD are fetched at image build
# time so the container has no external CDN dependency at runtime.
RUN mkdir -p /var/www/html/assets/js \
    && curl -fsSL -o /var/www/html/assets/js/tailwind.min.js \
         "https://cdn.tailwindcss.com/3.4.17" \
    && curl -fsSL -o /var/www/html/assets/js/chart.min.js \
         "https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js" \
    && chown nobody:nogroup \
         /var/www/html/assets/js/tailwind.min.js \
         /var/www/html/assets/js/chart.min.js \
    && echo "Static assets downloaded."

# Pre-create directories that will be used at runtime
RUN mkdir -p /var/www/conf /var/www/log \
    && chown -R nobody:nogroup /var/www/conf /var/www/log

# Copy and configure entrypoint
COPY docker/web/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Declare log volume (Docker-managed named volume by default)
VOLUME ["/var/www/log"]

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
