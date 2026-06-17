# Logix ERP

ERP construido como **PWA** (responsive: escritorio para Administración, móvil para Almacén y operación en campo).

## Stack

| Capa      | Tecnología                                  |
|-----------|---------------------------------------------|
| Backend   | Laravel (PHP 8.5) · API REST · Sanctum      |
| Frontend  | React + Vite + Tailwind CSS · PWA           |
| Base de datos | MySQL 8 (`logix`)                       |

## Estructura

```
Logix.MD/
├── backend/    → API Laravel (rutas en routes/api.php)
└── frontend/   → PWA React/Vite (proxy /api → backend)
```

## Cómo arrancar (desarrollo)

Necesitas **dos terminales**:

```bash
# Terminal 1 — Backend (http://localhost:8000)
cd backend
php artisan serve

# Terminal 2 — Frontend (http://localhost:5173)
cd frontend
npm run dev
```

El frontend redirige automáticamente las llamadas `/api/*` al backend (configurado en `frontend/vite.config.js`).

Prueba de conexión: el endpoint `GET /api/ping` devuelve `{"message":"conectado","app":"Logix"}`.

## Plan de desarrollo (5 fases)

1. **Cimientos** — BD, estructura del proyecto, almacenamiento de archivos. ← *en curso*
2. **Usuarios/Roles** — Auth JWT/Sanctum, RBAC (Administrador, Almacenista, Ventas/Compras), perfil con foto.
3. **Inventario + Proveedores** — Catálogos, Kardex multi-bodega, alertas de stock mínimo.
4. **Documentación + Firma electrónica** — Repositorio PDF, estados de firma (DIAN, Colombia).
5. **Reportes/Dashboard** — Gráficas en app + exportación Excel con gráficas nativas.

### Decisiones de arquitectura

- **Multi-bodega**: el stock vive en `stock_por_bodega` (producto × bodega), no en `productos`.
- **Costeo**: costo promedio ponderado.
- **País**: Colombia (NIT) — firma electrónica vía proveedor autorizado DIAN.
