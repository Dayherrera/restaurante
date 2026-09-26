# Charolas Los Magueyes · POS y producción

Proyecto instalado en `C:\laragon\www\restaurante`, conectado a la base MySQL existente `restaurant_db`.

## Acceso local

1. Inicia MySQL desde Laragon.
2. Haz doble clic en `iniciar.cmd` (mantén su ventana abierta) o, desde la carpeta del proyecto, ejecuta `php artisan serve --host=127.0.0.1 --port=8000`.
3. Abre http://127.0.0.1:8000.
4. El usuario inicial es `admin@magueyes.local`. La contraseña generada está en `storage/app/setup-credentials.txt`, fuera de la carpeta pública y excluida del repositorio.
5. Cambia tu contraseña en **Usuarios y roles**, revisa el menú real y abre **Mi caja** para vender.

La aplicación incluye los recursos CSS/JS compilados. No requiere mantener Vite activo. Para modificar estilos: `npm install` y `npm run build`.

En Laragon también puedes configurar un virtual host cuyo DocumentRoot sea **C:/laragon/www/restaurante/public**. No publiques la raíz del repositorio. Consulta `docs/DESPLIEGUE.md`.

## Implementación

- Laravel 12.69.2, PHP 8.3, MySQL 8, Livewire 3.8.8, Alpine (incluido en Livewire), Tailwind 3 y Spatie Permission 6.
- Autenticación con bloqueo temporal por intentos fallidos y usuarios activos/inactivos.
- Roles y permisos editables; Administrador, Cajero, Cocina y Repartidor iniciales.
- Catálogo con filtros por categoría y estado, categorías visibles/ocultas y grupos de modificadores con máximos independientes.
- POS táctil con búsqueda, cantidades, entregas en sucursal/domicilio, fecha, hora y envío.
- Límites diarios estrictos; alertas por hora con siguiente espacio y autorización explícita por permiso.
- Anticipos, abonos, cobro combinado, saldo y protección contra repetir una misma venta o abono.
- Apertura, entradas/retiros, cierre ciego y arqueos de efectivo, transferencia y tarjeta.
- KDS con actualización de 30 segundos, secuencia de estados y asignación de repartidores.
- Cambios de notas/envío, cancelación total o por partida, registro de reembolsos y reimpresión.
- Reportes de ventas netas, cobros, reembolsos, saldos, producción por hora y arqueos.
- Cola de impresión por área, agente Node, tickets ESC/POS y seguimiento de errores.
- Auditoría de pedidos, pagos, cambios, cancelaciones y asignaciones.

## Datos y decisiones

Se crearon **30 tablas** mediante migraciones: operación, permisos y tablas de soporte de Laravel. `database/schema/mysql-schema.sql` contiene el esquema exportado sin datos; las migraciones son la fuente de verdad. No ejecutar ese SQL encima de una instalación existente.

El catálogo conserva **44 productos del menú real** y agrega un Rib Eye de prueba de $295, más 27 productos auxiliares ocultos. Hay ocho categorías y tres áreas. Se limpiaron los datos de operación conservando usuarios, permisos e impresión. Consulta `docs/MENU_REAL.md` para precios, composición y respaldo.

Se actualizó Laravel 11 del documento a Laravel 12 por avisos de seguridad detectados en la rama 11. Se conservó la arquitectura MVC/Livewire/MySQL. Spatie utiliza relaciones normalizadas de roles y permisos en lugar del `role_id` aislado del boceto SQL.

El agente consulta al servidor por HTTP/HTTPS saliente, evitando abrir un servidor local accesible desde el navegador o puertos públicos. Esta adaptación conserva la transición local/VPS propuesta. Configuración y restricciones en `agent/README.md`.

Los cobros y reembolsos son registros de caja: **no ejecutan operaciones bancarias**. La devolución debe realizarse realmente al cliente. Las modificaciones de composición/cantidad después de confirmar se gestionan cancelando partidas y registrando un nuevo pedido; las notas y el costo de envío se editan directamente. El envío no puede reducir el total por debajo de lo ya pagado.

## Pruebas

```sh
php artisan test --compact
php scripts/mysql-smoke.php
node --check agent/print-agent.mjs
npm run build
composer audit
```

PHPUnit está fijado a SQLite en memoria y no limpia `restaurant_db`. La prueba MySQL hace una venta, un pago y una cancelación dentro de una transacción que revierte al terminar; no conserva operaciones ficticias. El esquema MySQL se verificó contra la base solicitada.

Las pruebas automatizadas verifican permisos, vistas, composición, límites, precios del servidor, anticipos, idempotencia, cancelaciones, arqueos, despacho, autenticación del agente y acciones Livewire. Las impresoras físicas y el estrés con múltiples terminales táctiles requieren validación en la sucursal.

## Guías

- `docs/MEJORAS_PEDIDOS.md`: clientes, programación, categorías y grupos de opciones.
- `docs/MENU_REAL.md`: catálogo real y reglas de carga.
- `docs/OPERACION.md`: flujo de uso y reglas operativas.
- `docs/DESPLIEGUE.md`: Laragon, VPS, respaldo y configuración.
- `agent/README.md`: impresión de red, USB compartido y modo de prueba.

No ejecutar `migrate:fresh`, `migrate:reset` ni `db:wipe` sobre la base de operación. Mantener `.env`, credenciales y configuración del agente fuera del control de versiones.

