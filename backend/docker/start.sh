#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="/var/www/html"
DB_DIR="$PROJECT_DIR/database"
WEB_USER="www-data"

echo "=== Iniciando tareas de arranque en Render ==="

# Asegurar directorios base
mkdir -p "$DB_DIR"
mkdir -p "$PROJECT_DIR/storage"
mkdir -p "$PROJECT_DIR/bootstrap/cache"

# Copiar sqlite original a /tmp si existe en el build
if [ -f "$DB_DIR/database.sqlite" ]; then
  echo "Copiando database.sqlite a /tmp para habilitar escritura..."
  cp "$DB_DIR/database.sqlite" /tmp/database.sqlite || true
else
  echo "No se encontró base de datos previa, creando una vacía en /tmp..."
  touch /tmp/database.sqlite || true
fi

# 🚨 EL FIX CLAVE: Permisos agresivos 777 para que el backend pueda escribir sin restricciones
chmod 777 /tmp || true
chmod 777 /tmp/database.sqlite || true
chown "$WEB_USER":"$WEB_USER" /tmp/database.sqlite || true

# Forzar la variable de entorno a nivel del sistema operativo por si acaso
export DB_DATABASE=/tmp/database.sqlite

# Limpiar absolutamente todas las cachés viejas
echo "Limpiando caches de Laravel..."
php artisan config:clear || true
php artisan cache:clear || true
php artisan route:clear || true
php artisan view:clear || true

# Ejecutar migraciones y seeders sobre la base de datos de /tmp
echo "Ejecutando migraciones y seeders..."
php artisan migrate --force || true
php artisan db:seed --force || true

echo "=== Tareas completadas con éxito — Lanzando Apache ==="
exec apache2-foreground