# Operación del sistema

## Primer turno

1. Accede con la cuenta inicial y cambia la contraseña en Usuarios y roles.
2. Revisa el catálogo real de la imagen cargada el 13/09/2026; sus precios y presentaciones están en MENU_REAL.md.
3. Crea las cuentas del personal, revisa permisos y registra repartidores.
4. Configura las tres áreas en el agente de impresión.
5. En Mi caja, registra el fondo de efectivo y abre el turno. Cada usuario trabaja con su propia caja.

## Venta y anticipo

Agrega productos desde Punto de venta. En una charola compuesta captura el número de porciones de cada botana hasta completar las elecciones exactas; la composición se aplica a cada unidad de esa partida. Si necesitas dos charolas diferentes, agrégalas en partidas separadas.

Completa cliente, teléfono, fecha, hora y forma de entrega. En domicilio se necesita dirección y costo de envío. El sistema consulta los precios vigentes al guardar; no acepta precios enviados por el navegador.

Captura lo realmente aplicado al pedido en efectivo, transferencia y/o tarjeta. El importe aplicado no puede superar el total; el cambio se entrega aparte. Cero en todos los métodos permite reservar sin anticipo. Para abonar después, entra al detalle del pedido; puedes registrar varios abonos con métodos distintos, incluso desde un turno posterior.

El comprobante muestra total, pagos, reembolsos y saldo. No se cobra a bancos ni se conecta con terminales de tarjeta: se registra el pago realizado por el operador.

## Capacidad de producción

Los límites son por producto y hora del reloj (14:00–14:59), además de un máximo por fecha de entrega. Cero significa sin límite. Partidas repetidas se suman al validar. Los pedidos cancelados no consumen capacidad.

Si la hora está llena, el sistema busca un espacio que admita todos los productos del carrito dentro de los siguientes siete días. La búsqueda considera las 24 horas; no hay un calendario de horarios comerciales configurado. Cambia fecha/hora para usar la sugerencia, o marca la autorización de sobrecarga si tu perfil tiene ese permiso. El máximo diario es estricto.

## Producción y reparto

La pantalla Cocina y despacho se actualiza cada 30 segundos; puedes pausar la actualización mientras trabajas. Secuencia:

- Sucursal: pendiente → en preparación → listo → entregado.
- Domicilio: pendiente → en preparación → listo → en ruta → entregado.

Para salir a ruta se requiere repartidor. Para entregar, el saldo debe estar liquidado. Registra el cobro antes de confirmar la entrega. Los perfiles iniciales Cocina y Repartidor comparten acceso al monitor; el sistema trabaja en una sola sucursal y no restringe el monitor a pedidos de un repartidor particular.

## Cambios y cancelaciones

Un usuario con permiso puede modificar notas de preparación y costo de envío mientras el pedido no esté entregado/cancelado. Esto emite una comanda de modificación. Para cambiar composición o cantidades, cancela la partida completa y registra la sustitución en un nuevo pedido.

Cancela todo el pedido o una partida desde el detalle, con motivo obligatorio. Si el importe pagado excede el nuevo total, se registra una devolución por los métodos originales en la caja abierta del operador. **Realiza también la devolución física/bancaria:** la aplicación solo deja el registro contable. En una cancelación parcial el envío permanece; al cancelar todo el total y el envío a cobrar quedan en cero. El valor histórico del envío permanece como referencia en el pedido.

Las devoluciones están en el detalle y en los reportes; las cancelaciones emiten tickets por las áreas afectadas.

## Caja

Registra entradas/retiros con motivo. No se admite retirar más efectivo del disponible según los registros. Al cierre captura el efectivo, transferencia y tarjeta realmente conciliados. El arqueo muestra esperado, contado y diferencia después de cerrar. Las diferencias positivas son sobrantes y las negativas faltantes. El cierre es definitivo; abre un nuevo turno para nuevas operaciones.

## Impresión

El sistema registra trabajos aunque el agente esté apagado. El centro de impresión muestra pendiente, procesando, impreso o fallido. Un envío TCP exitoso significa que el transporte aceptó los bytes; no confirma que exista papel ni que el ticket saliera correctamente.

Si falta confirmación por más de dos minutos, el siguiente sondeo del agente deja el trabajo en fallido para revisión manual. Antes de reintentar revisa si el ticket ya salió; evita duplicar comandas.


## Tickets y folios (25/09/2026)

Los pedidos nuevos usan un folio numérico consecutivo global, sin reinicio diario. Los folios antiguos se conservan para mantener las referencias de comprobantes ya entregados. La secuencia se asigna dentro de la transacción: un error no consume el número y reenviar la misma venta devuelve el mismo pedido. Cancelar un pedido no reutiliza su folio.

Cocina, caja y el monitor de despacho muestran cantidad y nombre del producto, elecciones guardadas y notas de preparación. La descripción fija del menú no aparece en estas salidas; sigue conservada en el detalle del pedido.

Caja utiliza un recibo de 32 columnas para papel de 58 mm: encabezado centrado, folio, entrega, precios por partida, total, pagos por método, reembolsos y saldo. Los movimientos parciales identifican expresamente que los totales corresponden al pedido completo. No se añaden cargos ni impuestos inventados.

Los trabajos de impresión ya generados conservan su contenido original. Las nuevas comandas y reimpresiones usan el formato nuevo. Para aplicar en otra instalación existente: respaldar y ejecutar las migraciones; `scripts/apply-ticket-folios.php` automatiza respaldo y migración para restaurant_db.
