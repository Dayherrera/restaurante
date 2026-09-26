# Clientes y captura de pedidos

1. En Punto de venta, busca al cliente por nombre o teléfono. Seleccionar una coincidencia rellena sus datos. Para uno nuevo, solo se toma el teléfono de la búsqueda; ciudad y estado tienen los valores predeterminados COMITÁN DE DOMÍNGUEZ y CHIAPAS.
2. Guarda el cliente. El teléfono normalizado identifica un solo registro. El catálogo Clientes permite buscar y actualizar los datos.
3. Confirma fecha, hora y entrega en sucursal o a domicilio. Para domicilio se requieren calle, número (puede ser S/N), colonia, ciudad y estado.
4. Agrega productos. La capacidad se revisa al agregar, aumentar cantidades, cambiar el horario y confirmar el pedido. El máximo diario no admite sobrecarga; el horario requiere permiso y autorización explícita.
5. Selecciona opciones con botones. Cada grupo tiene su máximo; Forzar captura exige exactamente ese número. Los opcionales permiten de cero al máximo. Puedes repetir opciones cuando el máximo es mayor a uno. Las selecciones nunca suman precio: los extras cobrados se agregan como productos independientes.
6. Captura anticipo o pago combinado. En domicilio puedes asignar repartidor con permiso de despacho. Confirma con caja abierta.

## Configuración del catálogo

Filtra productos por nombre, categoría y estado. Al crear o editar un producto puedes crear su categoría sin abandonar el formulario. El catálogo Categorías controla Visible en punto de venta; las categorías ocultas permanecen disponibles para configurar modificadores.

Para un producto compuesto agrega grupos en el orden deseado, define máximo y Forzar captura, y marca sus productos simples activos. El filtro de categoría facilita encontrar las opciones y conserva las selecciones al cambiar de filtro.

Las dos charolas especiales conservan sus precios ($800 y $1,600), con un grupo obligatorio de 12 elecciones entre 18 botanas sin mariscos. Los auxiliares copian el precio de su orden extra cuando existe. Bolitas de plátano no tiene precio individual en la imagen y se registra como auxiliar a $0. Los 44 productos originales mantienen sus precios.

Rib Eye (prueba), $295: Término obligatorio (máximo 1, cuatro opciones) y Guarniciones opcionales (máximo 2, cinco opciones). Puedes editarlo o desactivarlo en Catálogo.

## Actualización y verificación

La actualización aplicada hizo un respaldo SQL verificado antes de migrar. No eliminó ventas, usuarios ni configuración de impresión. `scripts/apply-order-ux.php` aplica migraciones y conversión con respaldo en restaurant_db. No utilices el antiguo script de limpieza para actualizar.

En una instalación nueva, `php artisan migrate --seed` incluye menú real y grupos. `MenuUxSeeder` es repetible sin cambiar precios existentes. El esquema de referencia es `database/schema/mysql-schema.sql`; no se importa sobre una base existente.

Verificación: `php artisan test` usa SQLite en memoria; `php scripts/mysql-smoke.php` comprueba MySQL con rollback, sin conservar ventas de prueba.
