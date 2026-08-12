# Fénix (Logix)

POS/ERP multi-negocio en la nube (SaaS): talleres de motos/carros, lavaderos, barberías, estudios de tatuajes, restaurantes, tiendas y spas. Cada negocio es una cuenta aislada (multi-inquilino) con su propio plan, módulos y datos.

## Arquitectura: dos aplicaciones independientes, a propósito

Este repositorio contiene **dos proyectos independientes**, cada uno con su propio despliegue — `frontend/` vive anidado dentro de `backend/` solo por organización visual del repositorio, pero **no** están integrados: no es un monolito Laravel con frontend embebido, es una API + una SPA desacopladas (lo que permite tener la PWA instalable y la futura app de Capacitor).

```
Logix.MD/
└── backend/            → API REST en Laravel (PHP). Se despliega en Render.
    ├── app/, routes/, database/...  → todo el código de la API
    └── frontend/       → SPA en React + Vite + Tailwind (PWA). Se despliega en Vercel
                           (por separado — Vercel apunta su "Root Directory" aquí).
```

| Carpeta              | Qué es                          | Dónde vive en producción                  |
|-----------------------|----------------------------------|--------------------------------------------|
| `backend/`            | API Laravel + Sanctum, PostgreSQL | Render (`logix-backend-wla9.onrender.com`) |
| `backend/frontend/`   | React + Vite, PWA                 | Vercel (`logix-delta.vercel.app`)           |

El frontend nunca sirve archivos PHP ni el backend sirve la SPA — se comunican solo por HTTP (`backend/frontend/src/api/client.js` llama a `/api/*` en el backend). El build de Render ignora `backend/frontend/` por completo (ver `backend/.dockerignore`). `backend/resources/` es algo aparte: solo las plantillas Blade que usa Laravel internamente (ej. el PDF de facturas) — no es "donde va el frontend".

## Cómo arrancar en local

Dos terminales:

```bash
# Terminal 1 — Backend (http://localhost:8000)
cd backend
php artisan serve

# Terminal 2 — Frontend (http://localhost:5173)
cd backend/frontend
npm run dev
```

El frontend redirige `/api/*` al backend (configurado en `backend/frontend/vite.config.js`). Prueba de conexión: `GET /api/tipos-negocio`.

Base de datos local: MySQL (ver `backend/.env`, `DB_CONNECTION=mysql`). Producción corre sobre PostgreSQL en Render.

### Decisiones de arquitectura

- **Multi-inquilino**: cada negocio (`empresas`) es dueño exclusivo de sus datos vía `owner_id`/`empresa_id`; el super-admin es el único que ve todos los negocios.
- **Multi-bodega**: el stock vive en `stock_por_bodega` (producto × bodega), no en `productos`.
- **Costeo**: costo promedio ponderado.
- **País**: Colombia (NIT) — facturación electrónica.
