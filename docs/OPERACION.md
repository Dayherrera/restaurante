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

## Valores iniciales y seguimiento de saldos (26/09/2026)

Los pedidos nuevos proponen la fecha actual de la sucursal y entrega a domicilio. La hora debe capturarse; para domicilio sigue siendo obligatoria la dirección completa. Editar los datos del cliente conserva la fecha y el tipo de entrega elegidos.

Los abonos desde Ver pedido generan comprobante exclusivamente para Caja. Las comandas iniciales y las cancelaciones siguen enviándose a las áreas correspondientes. En Pedidos, las filas con saldo pendiente aparecen con fondo ámbar, importe destacado y la etiqueta Saldo pendiente; al liquidar desaparece ese resaltado.

En Pedidos, el filtro usa un rango inclusivo de fechas de entrega (Desde/Hasta). Al entrar muestra hoy; el botón Hoy restablece el rango al día actual. La búsqueda por folio o cliente se combina con el rango seleccionado.

## Configuración general de la empresa

En Administración > Configuración general se capturan nombre comercial (obligatorio), razón social, RFC, dirección, teléfono/WhatsApp, correo, sitio web y mensaje al pie del ticket. Solo los usuarios con permiso users.manage pueden consultar o modificar estos datos. Los campos opcionales vacíos se omiten al imprimir.

Los datos se imprimen en el comprobante de Caja para el cliente, incluidas nuevas reimpresiones y abonos. Cocina y Barra conservan su encabezado operativo. Los trabajos ya generados mantienen el texto original; reintentar un trabajo no cambia sus datos. No se precargan datos fiscales ni de contacto sin confirmar.

La migración crea company_settings y conserva los datos de operación. El script scripts/apply-company-settings.php aplica las migraciones con respaldo previo en restaurant_db.

## Agenda y liberación manual a cocina (27/09/2026)

Cocina y Despacho muestra por defecto todos los pedidos activos con entrega hoy. Atrasados muestra los pendientes de días anteriores; el tablero avisa cuántos requieren revisión. Anticipados autorizados reúne pedidos futuros cuya preparación fue autorizada. Agenda futura abre en Pedidos la lista de programados sin liberar; no habilita preparación por consultar la agenda.

Un pedido nuevo imprime solo el comprobante de Caja. El personal con permiso de despacho debe pulsar Liberar a cocina para generar las comandas operativas; después puede Comenzar preparación. La liberación es única y queda auditada. Los abonos siguen imprimiendo solo en Caja. Modificaciones, cancelaciones y reimpresiones no envían a cocina/barra antes de la liberación.

Un pedido futuro únicamente puede liberarlo un usuario con rol Administrador y permiso de despacho, desde Ver pedido, mediante Autorizar preparación anticipada: requiere motivo y confirmación. La validación se ejecuta en servidor, incluyendo cambios de estado y reintentos de impresión. El pedido aparecerá en Anticipados autorizados, con la fecha destacada.

Programado es una clasificación por fecha y ausencia de liberación, no un nuevo estado almacenado. Al llegar su día aparece automáticamente al consultar o actualizar el tablero sin tarea programada. La consulta no imprime ni cambia estados.

Actualización: scripts/apply-production-release.php hace respaldo y migración. Los pedidos ya en preparación/listos/en ruta se conservan liberados para no interrumpir el trabajo; los pendientes requieren liberación. Las comandas antiguas aún en cola para pendientes se retienen como fallidas. El papel ya impreso o enviado a la impresora no puede retirarse automáticamente: debe revisarse al comenzar a usar este flujo.

Al entrar al Punto de venta, el sistema exige una caja abierta del usuario actual; si no existe, redirige a Mi caja para abrir turno. El folio se destaca en tamaño y negrita en Pedidos, detalle del pedido y Cocina y Despacho.


## Calendario de entregas

El menú Calendario abre /calendario con el permiso orders.view. Muestra una cuadrícula mensual de lunes a domingo, navegación anterior/siguiente y Hoy. En pantallas pequeñas comienza en Agenda; los botones Mes y Agenda permiten cambiar la vista. Se usa FullCalendar 6.1.21 con Vite y Livewire; los recursos se sirven localmente, sin CDN.

Cada día indica únicamente el número de pedidos no cancelados, sin importes. Se muestran hasta cuatro tarjetas y un enlace para consultar el resto. Pulsar el contador o una fecha abre todos los pedidos de ese día por hora; pulsar una tarjeta abre su detalle. Mostrar cancelados los incorpora a la consulta, pero nunca al conteo vigente.

El panel muestra folio, cliente, entrega, estado, productos, elecciones, notas, repartidor, total, pagado neto y saldo. Consultar no cambia estados ni imprime. Reimprimir requiere orders.reprint y respeta la liberación a cocina. Asignar repartidor requiere dispatch.manage y un repartidor activo; no se permite en cancelados o entregados. Ver pedido / acciones lleva al detalle existente para edición o autorizaciones.

Actualizar vuelve a consultar el intervalo visible. Esta primera versión no cambia fecha/hora, no permite arrastrar pedidos y no incluye fechas especiales. El importe no aparece como indicador diario. Los colores se acompañan de etiquetas para distinguir estados, saldo pendiente y liberación.

El calendario incluye botones Mes, Semana, Día y Agenda. Semana y Día organizan los pedidos por hora de entrega (formato de 24 horas), con conteo diario y acceso al mismo panel. Las tarjetas no representan duración de preparación. Anterior/Siguiente avanzan según la vista seleccionada.

Semana y Día ahora usan listas cronológicas: una fila por pedido, hora a la izquierda y sin intervalos vacíos. Mes y Agenda conservan su presentación. El detalle del calendario incluye el precio unitario guardado en la venta y el importe (cantidad por precio unitario) de cada partida, incluso si el precio del catálogo cambia después.

Ajuste de presentación: Semana usa siete columnas (lunes a domingo) con filas compactas por pedido y sin cuadrícula horaria. Día conserva su lista y muestra la fecha completa en el título.

El botón ☰ de la barra superior permite mostrar u ocultar el menú izquierdo. Punto de venta abre con el menú colapsado. En pantallas pequeñas el menú aparece sobre el contenido y se cierra con ×, Escape o pulsando fuera del panel.

En Nuevo pedido, la búsqueda de clientes permite recorrer los resultados con ↑/↓ y elegir el resaltado con Enter. La lista se cierra al seleccionar o pulsar Escape; al escribir una nueva búsqueda vuelve a mostrarse.

Al confirmar un pedido ya no se genera automáticamente ningún ticket, incluso con anticipo o pago completo inicial. En Ver pedido, el botón Imprimir comprobantes permite imprimir manualmente; conserva las reglas de liberación de cocina. Los abonos posteriores y la liberación manual siguen generando sus comprobantes correspondientes.

### Énfasis en comandas de cocina y barra
Las nuevas comandas destacan fecha/hora de entrega y productos en negritas y doble altura, conservando 32 columnas para papel de 58 mm. El domicilio se imprime en un bloque en negritas. Los modificadores y notas mantienen tamaño normal. Los comprobantes de caja conservan su diseño. El formato se guarda junto al trabajo de impresión; los trabajos anteriores mantienen su formato original.


### Repartidor obligatorio al registrar entregas
Los pedidos nuevos a domicilio requieren un repartidor activo antes de confirmar. La asignación inicial está disponible para quien registra la venta, incluido Cajero. Los pedidos para recoger en sucursal no requieren repartidor. Cocina y Despacho muestra únicamente el nombre asignado, sin selector ni botón de asignación. Los pedidos existentes se conservan y pueden gestionarse desde el detalle del pedido con los permisos actuales.


### Captura en mayúsculas
Al guardar desde los formularios se convierten a mayúsculas los nombres de clientes, los componentes de su dirección y referencias, nombres y descripciones de productos, categorías, grupos de modificadores y nombres de repartidores y usuarios. Se conservan acentos y Ñ. Correos, contraseñas, enlaces y valores internos no se convierten. Los registros anteriores se normalizan cuando se editan y guardan; no se modifica el historial de pedidos ni los tickets ya generados.

