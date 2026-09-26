# Impresión local ESC/POS

Requiere Node.js 20 o posterior y una impresora compatible con ESC/POS en modo RAW. No requiere paquetes npm adicionales.

## Configuración

1. Copia `config.example.json` a `config.json`.
2. En `server` configura la URL de la aplicación con `/` al final.
3. Copia el valor de `PRINT_AGENT_TOKEN` de `.env` al campo `token`. No lo publiques ni lo uses en el navegador.
4. Consulta los IDs de áreas en Centro de impresión y configura una impresora por área.
5. Ejecuta desde la raíz: `node agent/print-agent.mjs`.

El agente hace solicitudes salientes cada dos segundos. Para VPS utiliza HTTPS con certificado válido; no hay que abrir un puerto entrante en la sucursal. Solo ejecuta una instancia por archivo `config.json` y directorio del agente.

## Transportes

**Red:** `{"type":"tcp","host":"192.168.1.201","port":9100}`. Debe ser la IP real y fija de la impresora. El puerto 9100 es de la impresora, no del servidor web.

**USB compartido de Windows:** `{"type":"device","path":"\\\\localhost\\TicketCaja"}`. Instala el controlador de la impresora y configura una cola compartida que acepte RAW. El nombre del recurso y la cuenta que ejecuta Node necesitan acceso. El envío directo al recurso depende del controlador/spooler; debe probarse con el modelo físico. Alternativa: una impresora de red ESC/POS o un puerto de dispositivo RAW disponible.

**Prueba sin papel:** `{"type":"file","directory":"spool"}`. Guarda bytes ESC/POS en `agent/spool/<id>.bin`. En este modo el sistema marca el trabajo como enviado aunque solo se haya guardado un archivo. No usarlo como configuración operativa.

## Fiabilidad

El servidor entrega un trabajo por solicitud con un token de reserva. La confirmación exige ese token y el token del agente. El agente conserva un registro en `ledger.json` antes y después de enviar. Los tickets se transliteran a ASCII para compatibilidad con distintas tablas de caracteres; no incluyen logo ni acentos.

Un error o una confirmación perdida requiere revisión manual desde Centro de impresión. No existe garantía de impresión física exactamente una vez: un corte de red después de enviar puede dejar un resultado incierto. Los reintentos explícitos pueden imprimir una copia.

El agente no está instalado como servicio de Windows. Para operación permanente puedes ejecutarlo mediante una tarea al iniciar sesión o un administrador de servicios, con la cuenta que tiene acceso a las impresoras. Conserva su directorio de trabajo y el archivo `ledger.json`.

## Verificación

`node --check agent/print-agent.mjs` verifica sintaxis. `node --test agent/print-agent.test.mjs` verifica el flujo del agente con servidor y transporte de prueba. Antes de operar: prueba cada área, corte de papel, caracteres, desconexión/reconexión, comprobante de anticipo, saldo, modificación y cancelación con la impresora real.

## Configuración de pruebas instalada

Cocina, Barra y Caja usan la misma impresora **EC-PM-5890X**, instalada en **USB001** y compartida como **EC-PM-5890X**. El agente envía RAW a `\\localhost\EC-PM-5890X`, utilizando la cola existente de Windows.

- Ejecuta `iniciar-impresoras.cmd` en la raíz y mantén esa ventana abierta durante las ventas. El servidor web también debe estar activo. Abre una sola instancia del agente.
- Ejecuta `probar-impresoras.cmd` para imprimir tres tickets identificados por área, sin crear pedidos ni cobros.
- Los datos privados están en `agent/config.json`, excluido del repositorio. Para separar impresoras posteriormente, cambia el destino de cada área en ese archivo.
- Si Windows acepta el envío pero no hay papel, revisa la cola y el estado físico antes de repetir para evitar copias pendientes.
