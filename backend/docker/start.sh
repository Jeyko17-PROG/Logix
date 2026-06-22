#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="/var/www/html"
DB_DIR="$PROJECT_DIR/database"
WEB_USER="www-data"

# Asegurar directorios y permisos
mkdir -p "$DB_DIR"
chown -R "$WEB_USER":"$WEB_USER" "$DB_DIR" || true
chmod -R 777 "$DB_DIR" || true

# Permisos para storage y cache
chown -R "$WEB_USER":"$WEB_USER" "$PROJECT_DIR/storage" || true
chmod -R 775 "$PROJECT_DIR/storage" || true
chown -R "$WEB_USER":"$WEB_USER" "$PROJECT_DIR/bootstrap/cache" || true
chmod -R 775 "$PROJECT_DIR/bootstrap/cache" || true

# Backup rápido del sqlite si existe
if [ -f "$DB_DIR/database.sqlite" ]; then
  mkdir -p "$PROJECT_DIR/backups"
  cp "$DB_DIR/database.sqlite" "$PROJECT_DIR/backups/database.sqlite.$(date +%F_%H%M%S)" || true
fi

# Ejecutar migraciones y sembrado (forzado en producción)
php artisan config:clear || true
php artisan migrate --force || true
php artisan db:seed --class=AdminUserSeeder --force || true

# Arrancar Apache en primer plano
exec apache2-foreground
