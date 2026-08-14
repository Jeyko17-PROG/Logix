#!/usr/bin/env bash
set -euo pipefail

# Deploy de Logix.MD (Docker Compose): clona/actualiza el repo, arma el
# certificado TLS la primera vez, levanta el stack (nginx/app/db/certbot) y
# corre las migraciones. Pensado para Ubuntu 22.04 con Docker Engine +
# Docker Compose plugin ya instalados.
#
# Uso:
#   REPO_URL=https://github.com/otro-usuario/otro-repo.git BRANCH=otra-rama ./deploy.sh
# (o edita REPO_URL/BRANCH abajo). Reintentar el script es seguro: no repite
# pasos que ya quedaron hechos (certificado, .env, etc).

REPO_URL="${REPO_URL:-https://github.com/Jeyko17-PROG/Logix.git}"
APP_DIR="${APP_DIR:-$HOME/Logix.MD}"
BRANCH="${BRANCH:-vps-mysql-monolito}"

echo "==> Repo: $REPO_URL -> $APP_DIR (rama: $BRANCH)"
if [ -d "$APP_DIR/.git" ]; then
  echo "==> Ya existe, actualizando..."
  git -C "$APP_DIR" fetch origin "$BRANCH"
  git -C "$APP_DIR" checkout "$BRANCH"
  git -C "$APP_DIR" pull origin "$BRANCH"
else
  echo "==> Clonando..."
  git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"

# ---- .env ----
if [ ! -f .env ]; then
  cp .env.example .env
  # APP_KEY real (32 bytes al azar en base64) sin depender de un contenedor corriendo.
  KEY="base64:$(openssl rand -base64 32)"
  sed -i "s|^APP_KEY=.*|APP_KEY=${KEY}|" .env
  echo
  echo "!! Se creó .env desde .env.example (con un APP_KEY nuevo)."
  echo "!! Edítalo ahora: DOMAIN, CERTBOT_EMAIL, DB_PASSWORD, MYSQL_ROOT_PASSWORD,"
  echo "!! MAIL_*, CLOUDINARY_URL, WOMPI_*. Luego vuelve a correr este script."
  exit 0
fi

DOMAIN=$(grep -E '^DOMAIN=' .env | cut -d'=' -f2-)
CERTBOT_EMAIL=$(grep -E '^CERTBOT_EMAIL=' .env | cut -d'=' -f2-)
if [ -z "$DOMAIN" ] || [ "$DOMAIN" = "tu-dominio.com" ] || [ -z "$CERTBOT_EMAIL" ]; then
  echo "DOMAIN/CERTBOT_EMAIL faltan o siguen con el valor de ejemplo en .env — edítalos primero."
  exit 1
fi

# ---- Config de nginx a partir de la plantilla ----
sed "s/__DOMAIN__/${DOMAIN}/g" docker/nginx/app.conf.template > docker/nginx/app.conf

# ---- Certificado TLS (solo si todavía no existe uno para $DOMAIN) ----
CERT_EXISTS=$(docker compose run --rm --entrypoint sh certbot -c \
  "[ -f /etc/letsencrypt/live/${DOMAIN}/fullchain.pem ] && echo yes || echo no" 2>/dev/null | tail -1 || echo no)

if [ "$CERT_EXISTS" != "yes" ]; then
  echo "==> No hay certificado para ${DOMAIN}. Generando uno temporal para poder arrancar nginx..."
  docker compose run --rm --entrypoint sh certbot -c "
    apk add --no-cache openssl >/dev/null 2>&1 || true
    mkdir -p /etc/letsencrypt/live/${DOMAIN}
    openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
      -keyout /etc/letsencrypt/live/${DOMAIN}/privkey.pem \
      -out /etc/letsencrypt/live/${DOMAIN}/fullchain.pem \
      -subj '/CN=${DOMAIN}'
  "

  echo "==> Levantando db, app y nginx con el certificado temporal..."
  docker compose up -d --build db app nginx
  echo "==> Esperando a que nginx quede arriba..."
  sleep 8

  echo "==> Pidiendo el certificado real a Let's Encrypt (requiere que ${DOMAIN} ya apunte a este VPS)..."
  docker compose run --rm --entrypoint sh certbot -c \
    "rm -rf /etc/letsencrypt/live/${DOMAIN} /etc/letsencrypt/archive/${DOMAIN} /etc/letsencrypt/renewal/${DOMAIN}.conf"
  docker compose run --rm certbot certonly --webroot -w /var/www/certbot \
    -d "${DOMAIN}" --email "${CERTBOT_EMAIL}" --agree-tos --no-eff-email

  echo "==> Recargando nginx con el certificado real..."
  docker compose restart nginx
else
  echo "==> Certificado ya existe para ${DOMAIN}, no se vuelve a pedir (evita el límite de Let's Encrypt)."
fi

# ---- Stack completo ----
echo "==> Construyendo y levantando todos los contenedores..."
docker compose up -d --build

# start.sh ya corre "migrate --force" solo al arrancar el contenedor app; esto
# es la confirmación explícita en el log de este deploy.
echo "==> Corriendo migraciones..."
docker compose exec -T app php artisan migrate --force

echo
echo "==> Listo: https://${DOMAIN}"
echo "    Logs app:    docker compose logs -f app"
echo "    Logs nginx:  docker compose logs -f nginx"
echo "    Renovación TLS: automática cada 12h vía el contenedor certbot"
