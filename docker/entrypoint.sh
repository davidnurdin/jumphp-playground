#!/bin/sh
# Before the web server / the worker starts: wait for the database, migrations (web only), the shared JumPHP
# engines (app:runtime:install, web only).
set -e
case "$*" in
    frankenphp*) role=web ;;
    *messenger:consume*) role=worker ;;
    *) role=other ;;
esac
if [ "$role" != other ] && [ -f bin/console ]; then
    for i in $(seq 1 30); do php bin/console dbal:run-sql -q "SELECT 1" >/dev/null 2>&1 && break; sleep 2; done
    if [ "$role" = web ]; then
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
        php bin/console app:runtime:install || echo "[!] app:runtime:install failed: the games will not start"
    fi
fi
exec "$@"
