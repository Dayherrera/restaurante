# Despliegue y mantenimiento

## Instalación actual

La aplicación está instalada en `C:\laragon\www\restaurante`. MySQL apunta a la base existente `restaurant_db` con las credenciales proporcionadas en `.env`. Las migraciones están ejecutadas; no hace falta crear otra base.

Arranque de desarrollo:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Para Laragon configura un virtual host con raíz `C:/laragon/www/restaurante/public`, reescritura habilitada y PHP 8.3. Después establece `APP_URL` a ese dominio y ejecuta `php artisan config:clear`. Las rutas deben entrar siempre por `public/index.php`.

## Nuevo servidor / VPS

1. Instala PHP 8.3 con PDO MySQL, mbstring, xml, curl, zip, intl, bcmath y fileinfo; MySQL 8; Composer y un servidor web.
2. Copia el proyecto, sin `.env`, directorios de prueba, logs, credenciales de instalación ni la configuración del agente.
3. Ejecuta `composer install --no-dev --optimize-autoloader`. Copia `public/build` de esta instalación o ejecuta `npm ci && npm run build` en el proceso de compilación.
4. Crea un usuario MySQL exclusivo para la aplicación y restaura un respaldo verificado en la base de destino.
5. Configura `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tu-dominio`, zona `America/Mexico_City`, conexión MySQL y `SESSION_SECURE_COOKIE=true`. Conserva la misma `APP_KEY` si migras la instalación; para una instalación nueva usa `php artisan key:generate`.
6. Configura un token de impresión largo y aleatorio en `PRINT_AGENT_TOKEN`. Actualiza la URL/token de `agent/config.json` en la PC de la sucursal. No copies el token en archivos públicos.
7. Apunta Nginx/Apache exclusivamente a `public`, habilita HTTPS y permite escritura a `storage` y `bootstrap/cache` para el usuario del proceso PHP.
8. Ejecuta `php artisan migrate --force`, `php artisan config:cache` y `php artisan view:cache`. Para una instalación nueva sin usuarios, define una contraseña inicial fuerte en `POS_ADMIN_PASSWORD` y ejecuta el seeder una sola vez; cambia la contraseña después del primer acceso.
9. Verifica login, roles, una venta controlada, cierre de caja e impresión en cada área antes de cambiar la operación al VPS.

No se requieren Redis, WebSockets ni un worker de colas para la impresión implementada. La cola de impresión es persistente en MySQL y el agente la consulta directamente. La aplicación sí requiere conexión al servidor para operar: no existe modo de venta offline.

## Respaldo

Usa `mysqldump --single-transaction --routines --triggers -u <usuario> -p restaurant_db > respaldo.sql` y guarda el respaldo cifrado fuera del servidor. `-p` solicita la contraseña sin escribirla en el comando. Respalda también `.env` de forma privada y los archivos necesarios del agente. Verifica periódicamente una restauración en otra base.

No hagas migraciones destructivas ni restaures encima de la base operativa sin respaldo y una ventana de mantenimiento. Las migraciones incluidas tienen `down()` para desarrollo, pero revertirlas elimina tablas.

## Validación pendiente en la sucursal

- Modelos de impresora, drivers USB o IP/puertos y ancho del papel.
- Pruebas de estrés en los monitores y PCs finales; verificar tiempos y capacidad con varios cajeros.
- Precios reales, límite de inventario por día, composición de charolas y permisos de cada empleado.
- Conciliación del procedimiento de reembolsos, cierre y entregas con saldo pendiente.

## Referencias de implementación

- Livewire 3: https://livewire.laravel.com/docs/3.x/installation
- Spatie Permission: https://spatie.be/docs/laravel-permission/v6/introduction
- El documento de propuesta especifica Laravel 11; se actualizó a Laravel 12.69.2 porque la auditoría de Composer reportó vulnerabilidades sin corrección en la rama 11 seleccionada. La auditoría posterior de las dependencias instaladas no mostró avisos conocidos.
