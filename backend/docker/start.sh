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

# Workaround Render Free: copiar sqlite a /tmp (si existe) y apuntar .env a /tmp
if [ -f "$DB_DIR/database.sqlite" ]; then
  echo "Copiando database.sqlite a /tmp para evitar readonly filesystem..."
  cp "$DB_DIR/database.sqlite" /tmp/database.sqlite || true
  chmod 664 /tmp/database.sqlite || true
  chown "$WEB_USER":"$WEB_USER" /tmp/database.sqlite || true

  if [ -f "$PROJECT_DIR/.env" ]; then
    # Reemplazar o añadir DB_DATABASE en .env
    if grep -q '^DB_DATABASE=' "$PROJECT_DIR/.env"; then
      sed -i "s|^DB_DATABASE=.*|DB_DATABASE=/tmp/database.sqlite|" "$PROJECT_DIR/.env" || true
    else
      echo "DB_DATABASE=/tmp/database.sqlite" >> "$PROJECT_DIR/.env" || true
    fi
    export DB_DATABASE=/tmp/database.sqlite
  fi
fi

# Limpiar caché de configuración y otras caches inmediatamente después de cambiar .env
echo "Limpiando caches de Laravel para que lea la nueva DB_DATABASE..."
php artisan config:clear || true
php artisan cache:clear || true
php artisan route:clear || true
php artisan view:clear || true

# Ejecutar migraciones ahora que DB_DATABASE apunta a /tmp
echo "Ejecutando migraciones (forzadas) y seed básico..."
# No suprimimos errores para que aparezcan en los logs si algo falla
php artisan migrate --force
php artisan db:seed --class=AdminUserSeeder --force || true
# Migraciones ya ejecutadas arriba

# Arrancar Apache en primer plano
exec apache2-foreground
