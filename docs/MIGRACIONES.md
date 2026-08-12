# Migraciones de Logix (Fénix)

Guía de referencia rápida para navegar `backend/database/migrations/` (90 archivos a la fecha) sin tener que abrirlos uno por uno. Agrupadas por módulo, en el orden en que se construyeron.

**Regla de oro: nunca se borran, renombran ni fusionan migraciones que ya corrieron en producción.** Laravel guarda en la tabla `migrations` cuáles ya se aplicaron, por nombre de archivo — tocar una ya ejecutada rompe el rastreo (Laravel cree que falta por correr, o pierde el registro de que existió). Si un cambio queda obsoleto, se agrega una migración *nueva* que lo corrija hacia adelante (así se hizo con `tipo_operario`, ver más abajo). Esto no es desorden: es la forma correcta de versionar un esquema que ya tiene datos reales.

## 1. Cimientos (jun 9–10, 2026)

`0001_01_01_*`, `2026_06_09_*`, `2026_06_10_*`

Tablas base de Laravel (users, cache, jobs), roles/permisos, y el núcleo del ERP: bodegas, categorías, proveedores, productos, inventario, órdenes de compra, documentos/firmas, clientes, servicios, citas, agenda (horarios/bloqueos), facturas, notificaciones, notas.

## 2. Multi-inquilino y planes SaaS (jun 11–22, 2026)

`add_multi_tenant_owner`, `create_plans_table`, `add_saas_fields_to_users_table`, `create_control_funcionalidades`, `add_bodega_principal_and_reservas_slug`, `add_impuesto_to_factura_detalle`, `add_firma_to_facturas`, `create_adjuntos_table`, `add_funcionalidades_to_plans`, `add_currency_to_facturas`, `add_workspace_fields_to_users_table`, `add_bodega_to_facturas_and_auditorias`, `create_payment_transactions_table`, `create_credit_packages/user_credits/credit_transactions_table`

Aísla los datos por dueño (`owner_id`), agrega planes/licencias, control de funcionalidades por usuario, y el sistema de pagos/créditos (Wompi).

## 3. Taller / POS híbrido (jun 24 – jul 23, 2026)

`add_service_and_commission_to_productos_table`, `create_operables_employees_table`, `create_assets_vehicles_table`, `create_service_orders_table`, `create_service_order_details_table`, `create_asset_history_table`, `create_commission_liquidations_table`, `add_billing_mode_and_mechanic_links`, `create_caja_sesiones_and_gastos_tables`, `add_secando_a_estado_service_orders`, `create_planes_lavado_table`, `add_vehiculo_and_plan_to_citas_table`, `add_lavador_a_tipo_operario_operables_employees`, `add_plan_lavado_to_service_orders_table`, `add_barbero_a_tipo_operario_operables_employees`, `add_servicio_to_service_orders_table`, `add_operables_employee_a_citas`

Empleados operables (mecánicos → lavadores → barberos, agregados incrementalmente), vehículos/activos, órdenes de servicio con comisiones, caja y turnos.

> ⚠️ `add_lavador_a_tipo_operario...` y `add_barbero_a_tipo_operario...` ampliaban un ENUM de MySQL. Quedaron **superadas** por `2026_08_03_100000_tipo_operario_a_texto_libre.php` (sección 6), que convierte esa columna a texto libre. Se dejan intactas en el historial porque ya corrieron; no hace falta tocarlas.

## 4. Multiempresa real (jul 9, 2026)

`create_multiempresa_tables`, `add_empresa_id_to_tenant_tables`, `backfill_empresas`

De "un usuario = un negocio" a "una empresa (`empresas`) puede tener varios usuarios". Patrón de 3 pasos que es la forma correcta de hacerlo: crear tabla → agregar columna FK a todo lo demás → migrar los datos existentes (`backfill`) para que nada quede huérfano.

## 5. Facturación y negocio por tipo (jul 14–22, 2026)

`add_flujos_por_negocio`, `facturas_numero_unico_por_empresa`, `create_modulo_restaurante_y_checklist`, `add_email_facturacion_a_empresas`, `add_telefono_ciudad_a_bodegas`, `add_categoria_imagen_a_servicios`, `create_bodega_servicio_table`, `add_bodega_a_citas`, `add_bodega_a_horarios_y_bloqueos`, `add_icono_a_servicios`, `add_logo_emoji_a_empresas`, `create_cita_servicio_table`, `add_limite_citas_a_planes_empresas_users`

Multisucursal (bodegas con teléfono/ciudad, servicios y horarios por bodega), catálogo de servicios con foto/emoji, módulo de restaurante (mesas/comandas), límites de citas por plan.

## 6. Barbería, tatuajes y catálogo simple (jul 29 – ago 3, 2026)

`ampliar_imagen_servicios_a_text`, `add_activacion_y_prueba_a_users_empresas`, `add_disponible_a_productos`, `create_factura_pagos_table`, `create_negocios_vinculados_table`, `create_galeria_imagenes_table`, `add_imagen_referencia_a_citas`, `add_unidad_medida_a_productos`, `tipo_operario_a_texto_libre`, `add_especialidad_a_operables_employees`, `add_zona_cuerpo_y_tamano_a_citas`, `add_politicas_y_redes_sociales_a_empresas`

Activación por código (con período de prueba), pagos parciales de facturas, "Mis negocios" (varias cuentas vinculadas a la misma persona), galería de fotos genérica (productos/servicios/artistas), catálogo visual simple para barbería/tatuajes, y el rubro de tatuajes completo (artistas con especialidad, zona/tamaño en citas).

`tipo_operario_a_texto_libre` es la que **reemplaza** el patrón ENUM de la sección 3 — a partir de aquí, agregar un oficio nuevo (ej. otro tipo de negocio) no necesita ninguna migración.

## 7. Motor de base de datos

El código soporta MySQL y PostgreSQL sin cambios (ver `config/database.php`). Producción corrió sobre PostgreSQL en Render; el trabajo en la rama `vps-mysql-monolito` migra a MySQL sobre un VPS propio. Solo 3 restricciones `RESTRICT` existen en todo el esquema (documentadas en `UsuarioAdminController::eliminarPermanente()`), el resto son `CASCADE`/`SET NULL` estándar de Laravel — portable entre ambos motores sin SQL específico de un solo proveedor, salvo las migraciones de la sección 3 marcadas arriba (que ya traen su rama `if (DB::getDriverName() === ...)`).
