#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -e

echo "=== Starting Staging Deployment for my-bab-backend ==="

# 1. Maintenance Mode
echo "[1/7] Enabling maintenance mode..."
php artisan down || true

# 2. Fetch Latest Source Code
echo "[2/7] Pulling latest changes from git repository..."
git pull origin staging || git pull origin main

# 3. Install/Update PHP Dependencies
echo "[3/7] Installing Composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# 4. Database Migration
echo "[4/7] Running database migrations..."
php artisan migrate --force

# 5. Clear and Cache Configurations, Routes, & Views
echo "[5/7] Refreshing application cache..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 6. Restart Worker Processes (Queue / Horizon)
echo "[6/7] Restarting queue workers..."
php artisan queue:restart

# 7. Disable Maintenance Mode
echo "[7/7] Disabling maintenance mode..."
php artisan up

echo "=== Staging Deployment Completed Successfully! ==="
