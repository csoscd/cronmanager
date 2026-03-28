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

USER root

# Copy Composer-installed vendor tree
COPY --from=composer-build --chown=nobody:nobody /build/vendor /var/www/libs/vendor

# Copy web application source
COPY --chown=nobody:nobody web/ /var/www/html/

# Pre-create directories that will be used at runtime
RUN mkdir -p /var/www/conf /var/www/log \
    && chown -R nobody:nobody /var/www/conf /var/www/log

# Set php-fpm pool user/group.
# The base image was designed to run with the master process already as nobody,
# so the pool config has no user= line.  When supervisord runs as root it must
# be told which user to drop to or php-fpm refuses to start.
# We locate www.conf dynamically to stay compatible if the base image PHP
# version changes, then append the directives (last value wins in php-fpm).
RUN PHP_FPM_POOL=$(find /etc/php* -name "www.conf" 2>/dev/null | head -1) \
    && if [ -n "$PHP_FPM_POOL" ]; then \
         printf '\n; Added by cronmanager-web Dockerfile\n' >> "$PHP_FPM_POOL"; \
         printf 'user = nobody\ngroup = nobody\n' >> "$PHP_FPM_POOL"; \
         printf '; Socket ownership must match nginx worker user.\n' >> "$PHP_FPM_POOL"; \
         printf '; php-fpm master runs as root (via supervisord) so the socket\n' >> "$PHP_FPM_POOL"; \
         printf '; is created by root unless listen.owner/group are set explicitly.\n' >> "$PHP_FPM_POOL"; \
         printf 'listen.owner = nobody\nlisten.group = nobody\nlisten.mode = 0660\n' >> "$PHP_FPM_POOL"; \
         echo "php-fpm pool user/listen set to nobody in ${PHP_FPM_POOL}"; \
       else \
         echo "WARNING: php-fpm www.conf not found – pool user not set"; \
       fi

# Copy and configure entrypoint
COPY docker/web/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Declare log volume (Docker-managed named volume by default)
VOLUME ["/var/www/log"]

EXPOSE 8080

USER root

ENTRYPOINT ["/entrypoint.sh"]
