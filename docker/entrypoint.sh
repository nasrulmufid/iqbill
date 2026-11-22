#!/bin/sh
set -eu

cd /var/www/html

ROLE="${CONTAINER_ROLE:-app}"

# Ensure Laravel writable directories and PHP-FPM socket directory exist with proper permissions
mkdir -p \
    /run/php-fpm \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data /run/php-fpm storage bootstrap/cache

if [ "${APP_ENV:-production}" = "production" ] && [ -z "${APP_KEY:-}" ]; then
    echo "ERROR: APP_KEY must be set in .env when running in production." >&2
    exit 1
fi

wait_for_tcp() {
    host="$1"
    port="$2"
    label="$3"
    max_attempts="${4:-30}"
    attempt=1

    while [ "$attempt" -le "$max_attempts" ]; do
        if php -r '$c = @fsockopen($argv[1], (int) $argv[2], $e, $s, 2); if ($c) { fclose($c); exit(0); } exit(1);' -- "$host" "$port" >/dev/null 2>&1; then
            echo "[entrypoint] ${label} (${host}:${port}) is ready."
            return 0
        fi
        echo "[entrypoint] Waiting for ${label} (${host}:${port})... (${attempt}/${max_attempts})"
        attempt=$((attempt + 1))
        sleep 2
    done

    echo "ERROR: Timed out waiting for ${label} (${host}:${port})." >&2
    exit 1
}

db_conn="${DB_CONNECTION:-mysql}"
if [ "$db_conn" = "mysql" ] || [ "$db_conn" = "mariadb" ]; then
    wait_for_tcp "${DB_HOST:-db}" "${DB_PORT:-3306}" "Database" 45
fi

if [ -n "${REDIS_HOST:-}" ] && [ "${REDIS_HOST}" != "null" ]; then
    wait_for_tcp "${REDIS_HOST}" "${REDIS_PORT:-6379}" "Redis" 30
fi

if [ "$ROLE" = "app" ]; then
    if [ ! -L public/storage ]; then
        su-exec www-data:www-data php artisan storage:link --no-interaction >/dev/null 2>&1 || true
    fi

    if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
        echo "[entrypoint] Running database migrations..."
        su-exec www-data:www-data php artisan migrate --force --no-interaction
    fi

    if [ "${AUTO_SEED:-true}" = "true" ]; then
        user_count=$(su-exec www-data:www-data php -r '
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
            echo App\Models\User::query()->count();
        ' 2>/dev/null || echo "0")

        if [ "$user_count" = "0" ]; then
            echo "[entrypoint] Database has no users; running initial database seeder..."
            su-exec www-data:www-data php artisan db:seed --force --no-interaction
        fi
    fi

    if [ "${GOWA_AUTO_SYNC:-true}" = "true" ]; then
        echo "[entrypoint] Syncing Go-WhatsApp (GOWA) configuration..."
        su-exec www-data:www-data php artisan gowa:sync-config --no-interaction || true
    fi

    echo "[entrypoint] Optimizing Laravel caches..."
    su-exec www-data:www-data php artisan config:cache --no-interaction
    su-exec www-data:www-data php artisan route:cache --no-interaction
    su-exec www-data:www-data php artisan view:cache --no-interaction

    chown -R www-data:www-data /run/php-fpm storage bootstrap/cache
    echo "[entrypoint] Starting PHP-FPM server..."
    exec php-fpm -F
fi

# Worker & scheduler wait until the app container completes initial migrations
wait_for_migrations() {
    max_attempts=45
    attempt=1
    while [ "$attempt" -le "$max_attempts" ]; do
        ready=$(su-exec www-data:www-data php -r '
            require "vendor/autoload.php";
            $app = require "bootstrap/app.php";
            $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
            echo Illuminate\Support\Facades\Schema::hasTable("migrations") && Illuminate\Support\Facades\DB::table("migrations")->exists() ? "1" : "0";
        ' 2>/dev/null || echo "0")

        if [ "$ready" = "1" ]; then
            return 0
        fi
        echo "[entrypoint] Waiting for database migrations before starting ${ROLE}... (${attempt}/${max_attempts})"
        attempt=$((attempt + 1))
        sleep 2
    done

    echo "ERROR: Timed out waiting for database migrations." >&2
    exit 1
}

wait_for_migrations
su-exec www-data:www-data php artisan config:cache --no-interaction
chown -R www-data:www-data storage bootstrap/cache

if [ "$ROLE" = "queue" ]; then
    echo "[entrypoint] Starting IQ Bill queue worker..."
    exec su-exec www-data:www-data php artisan queue:work \
        --queue=provisioning,default,broadcasts \
        --sleep=3 \
        --tries=3 \
        --timeout=85 \
        --max-time=3600 \
        --backoff=10,30,60 \
        --no-interaction
elif [ "$ROLE" = "scheduler" ]; then
    echo "[entrypoint] Starting IQ Bill scheduler..."
    exec su-exec www-data:www-data php artisan schedule:work --no-interaction
else
    echo "[entrypoint] Running custom command..."
    exec su-exec www-data:www-data "$@"
fi
