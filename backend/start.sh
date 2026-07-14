#!/usr/bin/env bash
# Arranque de Logix en Render (Start Command: bash start.sh)
#  1. Migra la BD y siembra los catálogos (idempotente).
#  2. Lanza el worker de la cola en segundo plano (correos de facturas/recibos).
#  3. Arranca el servidor web.
set -e

php artisan migrate --force
php artisan db:seed --force

# Worker de correos: si se cae, se relanza solo.
(
  while true; do
    php artisan queue:work --sleep=3 --tries=3 --timeout=60 || true
    echo "queue:work terminó; reiniciando en 5s..."
    sleep 5
  done
) &

exec php artisan serve --host 0.0.0.0 --port "${PORT:-8000}"
