#!/bin/sh
set -eu

cd /var/www/backend

mkdir -p \
  storage/app/public \
  storage/app/private \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

if [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

if [ "${SKIP_CHOWN:-}" = "true" ]; then
  chmod -R a+rwX storage bootstrap/cache || true
else
  chown -R www-data:www-data storage bootstrap/cache || true
fi

wait_for_db() {
  i=0
  while [ "$i" -lt 60 ]; do
    if php -r '
      $h = getenv("DB_HOST") ?: "127.0.0.1";
      $p = getenv("DB_PORT") ?: "3306";
      $d = getenv("DB_DATABASE") ?: "launchr";
      $u = getenv("DB_USERNAME") ?: "root";
      $w = getenv("DB_PASSWORD") ?: "";
      try {
        new PDO("mysql:host=".$h.";port=".$p.";dbname=".$d, $u, $w, [PDO::ATTR_TIMEOUT => 3]);
        exit(0);
      } catch (Throwable $e) {
        exit(1);
      }
    '; then
      return 0
    fi
    i=$((i + 1))
    sleep 2
  done
  echo "database not reachable at ${DB_HOST:-unknown}" >&2
  return 1
}

if [ -n "${DB_HOST:-}" ]; then
  wait_for_db
fi

run_artisan() {
  if [ "$(id -u)" = 0 ]; then
    gosu www-data php artisan "$@"
  else
    php artisan "$@"
  fi
}

if [ "${RUN_MIGRATIONS:-}" = "true" ]; then
  php artisan storage:link --force
  run_artisan migrate --force
fi

if [ "${1:-}" = "php-fpm" ]; then
  exec docker-php-entrypoint php-fpm
fi

if [ "$(id -u)" = 0 ]; then
  exec gosu www-data "$@"
fi

exec "$@"
