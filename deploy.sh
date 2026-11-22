#!/usr/bin/env bash
set -e

echo "==> [1/5] Pulling latest code from GitHub..."
git pull origin main

echo "==> [2/5] Installing PHP Composer dependencies..."
cd apps/api
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> [3/5] Running PostgreSQL migrations & seeders..."
php artisan migrate --seed --force

echo "==> [4/5] Optimizing Laravel cache..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> [5/5] Fixing storage permissions for aaPanel (www:www)..."
chown -R www:www storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache

echo "==> DEPLOYMENT COMPLETE!"
