#!/bin/sh
set -e

cd /var/www/html

# --- .env -------------------------------------------------------------
# Reuses whatever backend/.env you already have (bind-mounted in) — the
# AI provider keys, coin costs, etc. all just carry over. Only falls back
# to .env.example on a genuinely fresh clone.
if [ ! -f .env ]; then
    echo "[entrypoint] No .env found — copying .env.example"
    cp .env.example .env
fi

# --- Composer dependencies ---------------------------------------------
# vendor/ lives in a named volume (see docker-compose.yml), not the bind
# mount, so this only actually runs on first boot or after a composer.lock
# change, not on every container start.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] Installing composer dependencies..."
    composer install --no-interaction --no-progress
fi

# --- App key -------------------------------------------------------------
if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    echo "[entrypoint] Generating APP_KEY..."
    php artisan key:generate --force --ansi
fi

# --- Wait for MySQL ------------------------------------------------------
echo "[entrypoint] Waiting for database ($DB_HOST:$DB_PORT)..."
until mysqladmin ping -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" --silent 2>/dev/null; do
    sleep 2
done
echo "[entrypoint] Database is up."

# --- Migrate ---------------------------------------------------------
# --isolated uses an atomic lock so the backend AND queue containers can
# both run this at startup without racing each other on a fresh database.
php artisan migrate --force --isolated

# --- Storage symlink (public disk -> public/storage) ----------------------
php artisan storage:link 2>/dev/null || true

echo "[entrypoint] Ready — starting: $*"
exec "$@"
