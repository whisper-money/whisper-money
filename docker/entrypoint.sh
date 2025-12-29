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

# Wait for MySQL to be ready using PHP PDO (more reliable than artisan commands)
echo "Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
max_attempts=30
attempt=0

wait_for_mysql() {
    php -r "
        \$host = getenv('DB_HOST') ?: 'mysql';
        \$port = getenv('DB_PORT') ?: '3306';
        \$user = getenv('DB_USERNAME') ?: 'root';
        \$pass = getenv('DB_PASSWORD') ?: '';
        \$db = getenv('DB_DATABASE') ?: 'whisper_money';
        try {
            new PDO(\"mysql:host=\$host;port=\$port;dbname=\$db\", \$user, \$pass, [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            exit(0);
        } catch (Exception \$e) {
            exit(1);
        }
    " 2>/dev/null
}

until wait_for_mysql || [ $attempt -ge $max_attempts ]; do
    attempt=$((attempt + 1))
    echo "MySQL not ready (attempt $attempt/$max_attempts)..."
    sleep 2
done

if [ $attempt -ge $max_attempts ]; then
    echo "ERROR: Could not connect to MySQL after $max_attempts attempts"
    echo "DB_HOST=${DB_HOST:-mysql}, DB_PORT=${DB_PORT:-3306}, DB_DATABASE=${DB_DATABASE:-whisper_money}"
    exit 1
fi

echo "MySQL is ready!"

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
