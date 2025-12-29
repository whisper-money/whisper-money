#!/bin/bash
set -e

echo "=== Whisper Money Container Startup ==="

# Ensure storage directories exist (volume may be empty on first deploy)
echo "Ensuring storage directories exist..."
mkdir -p /app/storage/app/public
mkdir -p /app/storage/framework/cache/data
mkdir -p /app/storage/framework/sessions
mkdir -p /app/storage/framework/views
mkdir -p /app/storage/logs
chown -R www-data:www-data /app/storage
chmod -R 775 /app/storage

# Auto-generate APP_KEY if not set
if [ -z "$APP_KEY" ]; then
    echo "No APP_KEY found, generating one..."
    APP_KEY=$(php artisan key:generate --show)
    export APP_KEY
    echo "Generated APP_KEY: $APP_KEY"
    echo ""
    echo "=================================================="
    echo "IMPORTANT: Save this key in your environment!"
    echo "APP_KEY=$APP_KEY"
    echo "=================================================="
    echo ""
fi

# Wait for MySQL to be ready
echo "Waiting for MySQL..."
max_attempts=30
attempt=0
until php artisan db:monitor --databases=mysql > /dev/null 2>&1 || [ $attempt -ge $max_attempts ]; do
    attempt=$((attempt + 1))
    echo "MySQL not ready (attempt $attempt/$max_attempts)..."
    sleep 2
done

if [ $attempt -ge $max_attempts ]; then
    echo "Warning: MySQL may not be ready, proceeding anyway..."
fi

# Run migrations
echo "Running migrations..."
php artisan migrate --force

# Cache configuration
echo "Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Create storage link (harmless if already exists)
echo "Creating storage link..."
php artisan storage:link 2>/dev/null || true

echo "=== Startup complete, launching services ==="

# Start supervisor
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
