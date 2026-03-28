################################################################################
# Cronmanager Agent – self-contained Docker image
#
# Stages:
#   1. composer  – installs PHP dependencies with Composer
#   2. final     – runtime image (cs1711/cs_cronmanageragent:latest)
#
# Build from the repository root:
#   docker build -f docker/agent/Dockerfile -t cs1711/cronmanager-agent:latest .
#
# @author  Christian Schulz <technik@meinetechnikwelt.rocks>
# @license GNU General Public License version 3 or later
################################################################################

# ── Stage 1: install Composer dependencies ────────────────────────────────────
FROM composer:2 AS composer-build

WORKDIR /build

# Copy the project-level composer.json (and lock file if present) first so the
# layer is cached as long as the dependency manifest does not change.
COPY composer.json ./
COPY composer.lock* ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --no-scripts


# ── Stage 2: runtime image ────────────────────────────────────────────────────
FROM cs1711/cs_cronmanageragent:latest

LABEL org.opencontainers.image.title="Cronmanager Agent" \
      org.opencontainers.image.description="Cronmanager host agent – manages crontabs, records execution results, exposes HMAC-secured HTTP API" \
      org.opencontainers.image.authors="Christian Schulz <technik@meinetechnikwelt.rocks>" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.source="https://github.com/csoscd/cronmanager"

# Copy Composer-installed vendor tree into the expected path
COPY --from=composer-build /build/vendor /opt/phplib/vendor

# Copy agent source code
COPY agent/ /opt/cronmanager/agent/

# Copy the SQL schema so the entrypoint can bootstrap an empty database
COPY agent/sql/schema.sql /opt/cronmanager/schema.sql

# Copy and configure entrypoint
COPY docker/agent/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Declare volumes (Docker-managed named volumes by default)
VOLUME ["/opt/cronmanager/agent/log"]

EXPOSE 8865

ENTRYPOINT ["/entrypoint.sh"]
