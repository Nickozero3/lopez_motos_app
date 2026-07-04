# Lopez Motos · Gestión de taller

Sistema web para administrar clientes, motos, órdenes de trabajo, presupuestos, repuestos, movimientos de stock, seguimiento público y notificaciones.

## Acceso inicial

```text
Usuario: fabricio
Contraseña: 123456
```

Cambiá la contraseña inicial antes de publicar el sistema en Internet.

## Iniciar con Docker

Desde la carpeta del proyecto:

```bash
docker compose down
docker compose up --build
```

- Aplicación: `http://localhost:8080`
- phpMyAdmin: `http://localhost:8081`
- MySQL externo: puerto `3308`

Para reiniciar también la base de datos:

```bash
docker compose down -v
docker compose up --build
```

## Funcionalidades principales

### Clientes y motos

- Alta, lectura, edición y archivado de clientes.
- Una o varias motos por cliente.
- Alta, edición, archivado y restauración de motos.
- Historial de órdenes por cliente.
- Patente, número de motor y número de chasis obligatorios para motos nuevas.
- Los tres identificadores se normalizan en mayúsculas y sin espacios en el navegador y nuevamente en el servidor.
- Control de duplicados por patente, motor o chasis.

### Órdenes de trabajo

- Alta usando un cliente/moto existente o creando registros nuevos.
- Estados, prioridad, diagnóstico, fecha estimada y total final.
- Historial interno y mensajes visibles para el cliente.
- Edición de los datos del cliente y de la moto desde la propia orden.
- Enlace público individual para consultar el avance.

### Stock

- CRUD completo de repuestos.
- Foto, SKU, categoría, proveedor, precios, stock actual y stock mínimo.
- Archivado/restauración sin borrar el historial.
- Entradas y salidas manuales.
- Historial auditable con fecha, usuario, stock anterior y stock resultante.
- Filtros por estado, stock bajo y sin stock.
- Al agregar un repuesto a una reparación se descuenta automáticamente.
- Al reducir la cantidad o borrar el ítem, la diferencia vuelve al stock.

## Variables de entorno

Creá o editá `.env` junto a `docker-compose.yml`:

```env
APP_TIMEZONE=America/Argentina/Cordoba
PUBLIC_BASE_URL=http://localhost:8080
WORKSHOP_WHATSAPP=5493510000000
OCR_SPACE_API_KEY=

NOTIFY_CHANNEL=webhook
NOTIFY_WEBHOOK_URL=

WHATSAPP_CLOUD_TOKEN=
WHATSAPP_PHONE_NUMBER_ID=
```

También se incluye `.env.example` como referencia.

## OCR de repuestos

Con `OCR_SPACE_API_KEY` configurada, en Stock podés subir una foto y usar **Completar con IA/OCR**. El resultado es una sugerencia: revisalo antes de guardar.

## Notificaciones automáticas

Al actualizar una orden, la app registra el intento en `notification_queue`.

### Webhook

```env
NOTIFY_CHANNEL=webhook
NOTIFY_WEBHOOK_URL=https://tu-servicio.com/webhook
```

Es útil para n8n, Make, Zapier, correo transaccional o un proveedor de WhatsApp.

### WhatsApp Cloud API

```env
NOTIFY_CHANNEL=whatsapp_cloud
WHATSAPP_CLOUD_TOKEN=tu_token
WHATSAPP_PHONE_NUMBER_ID=tu_phone_number_id
```

Requiere una cuenta configurada en WhatsApp Business Platform de Meta.

## Bases existentes

`ensure_schema()` agrega las columnas y tablas nuevas necesarias al abrir la aplicación. El archivo `db/init.sql` contiene el esquema completo para instalaciones limpias.

## Seguridad incluida

- Consultas preparadas con PDO.
- Tokens CSRF en formularios de escritura.
- Escape de salida HTML.
- Sesión regenerada al iniciar sesión.
- Validaciones duplicadas en cliente y servidor.
- Archivado lógico para preservar trazabilidad.

## Railway

Este paquete usa el Dockerfile de la raíz. En Railway no configures un Start Command manual.

Variables mínimas del servicio web:

- `DB_HOST=${{MySQL.MYSQLHOST}}`
- `DB_NAME=${{MySQL.MYSQLDATABASE}}`
- `DB_USER=${{MySQL.MYSQLUSER}}`
- `DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}`
- `APP_NAME=Lopez Motos`
- `APP_TIMEZONE=America/Argentina/Cordoba`
- `PUBLIC_BASE_URL=https://${{RAILWAY_PUBLIC_DOMAIN}}`

El puerto se toma automáticamente de `PORT`.

## Inicialización automática en Railway

En Docker Compose, MySQL ejecuta `db/init.sql` mediante `/docker-entrypoint-initdb.d`.
Railway no realiza ese paso automáticamente porque la base de datos se ejecuta como un
servicio separado. El contenedor web incluye `railway-db-init.php`, que espera la conexión
a MySQL y ejecuta el esquema de forma idempotente antes de iniciar Apache.

En los logs de un despliegue correcto debe aparecer:

```text
[db-init] Esquema verificado correctamente en la base railway.
Syntax OK
```
