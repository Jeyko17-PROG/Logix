# Entornos de Logix

## Localhost

Objetivo: probar cambios sin tocar datos de internet.

Backend:

```bash
cd backend
copy .env.local.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Frontend:

```bash
cd frontend
copy .env.local.example .env.local
npm run dev
```

El frontend local usa el proxy de Vite:

- `http://localhost:5173/api/*` -> `http://localhost:8000/api/*`
- `http://localhost:5173/storage/*` -> `http://localhost:8000/storage/*`

## Produccion

No usar SQLite dentro del contenedor en Render. El contenedor se reconstruye y los datos locales pueden perderse.

Configurar el backend en Render con variables persistentes:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://TU-BACKEND.onrender.com
FRONTEND_URL=https://TU-FRONTEND.vercel.app

DB_CONNECTION=pgsql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
DB_SSLMODE=require
```

Despues de crear la base de datos, ejecutar migraciones en Render:

```bash
php artisan migrate --force --seed
```

## Base de datos recomendada

Para trabajar profesionalmente con Render, usar PostgreSQL administrado. El plan Free de Render Postgres aparece con limite de 30 dias; sirve para pruebas, no para datos de clientes reales.

Opciones:

- Render Postgres `Basic-256mb`: recomendado si quieres mantener backend y base de datos dentro de Render.
- Proveedor externo Postgres gestionado: buena opcion si quieres capa gratuita mas flexible, pero agrega otra cuenta/proveedor y mas configuracion.

Decision recomendada para Logix: Render Postgres `Basic-256mb` para produccion real y MySQL/SQLite local para desarrollo.
