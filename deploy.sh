#!/usr/bin/env bash
set -e

PHP_BIN="/www/server/php/83/bin/php"
if [ ! -x "$PHP_BIN" ]; then
  PHP_BIN="php"
fi

COMPOSER_BIN="/usr/bin/composer"
if [ ! -x "$COMPOSER_BIN" ]; then
  COMPOSER_BIN="composer"
fi

echo "==> [1/5] Pulling latest code from GitHub (main)..."
git fetch origin main
git reset --hard origin/main

echo "==> [2/5] Installing PHP Composer dependencies..."
cd apps/api
$PHP_BIN $COMPOSER_BIN install --no-dev --optimize-autoloader --no-interaction

echo "==> [3/5] Running PostgreSQL database migrations..."
$PHP_BIN artisan migrate --force

echo "==> [4/5] Refreshing Laravel production cache..."
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache

echo "==> [5/5] Fixing storage permissions for aaPanel (www:www)..."
chown -R www:www storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache

echo "==> DEPLOYMENT COMPLETE!"
