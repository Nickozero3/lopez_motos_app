# Lopez Motos

Sistema base profesional para taller de motos.

## Acceso

Usuario inicial:

```text
fabricio
123456
```

## Iniciar en local

```bash
docker compose down
docker compose up --build
```

App:

```text
http://localhost:8082
```

phpMyAdmin:

```text
http://localhost:8083
```

## OCR.Space

Para completar productos desde foto, creá un archivo `.env` en la misma carpeta donde está `docker-compose.yml`:

```env
OCR_SPACE_API_KEY=tu_key_de_ocr_space
```

Reiniciá Docker después de cambiar el `.env`.

## Stock conectado a reparaciones

En cada orden de trabajo ahora existe la sección **Agregar repuesto desde stock**.

Cuando agregás un producto desde stock:

- se descuenta automáticamente del inventario;
- se agrega como ítem de reparación/presupuesto;
- se guarda un movimiento de stock;
- si borrás el ítem de la reparación, el stock vuelve a sumarse;
- si cambiás la cantidad, el stock se ajusta por diferencia.

La mano de obra quedó separada para no mezclar servicios con repuestos.

## Notificaciones automáticas

La app ya no depende de abrir WhatsApp desde tu teléfono.

Cuando actualizás una orden y dejás marcado **Enviar notificación automática**, el sistema intenta enviar el mensaje usando el canal configurado y también guarda el resultado en `notification_queue`.

### Opción simple: Webhook

Ideal para conectar con n8n, Make, Zapier, un bot propio, email transaccional o WhatsApp Business de terceros.

```env
PUBLIC_BASE_URL=http://localhost:8082
NOTIFY_CHANNEL=webhook
NOTIFY_WEBHOOK_URL=https://tu-webhook.com/lopez-motos
```

El webhook recibe JSON con:

```json
{
  "app": "Lopez Motos",
  "order_code": "LM-260702-ABC123",
  "client_name": "Cliente",
  "phone": "351...",
  "email": "cliente@email.com",
  "subject": "Lopez Motos - Actualización de tu moto",
  "message": "Mensaje visible para cliente",
  "tracking_url": "http://localhost:8082/track.php?t=..."
}
```

### Opción WhatsApp Cloud API

Para enviar WhatsApp real sin usar tu celular, necesitás WhatsApp Business Platform de Meta.

```env
PUBLIC_BASE_URL=https://tu-dominio.com
NOTIFY_CHANNEL=whatsapp_cloud
WHATSAPP_CLOUD_TOKEN=tu_token
WHATSAPP_PHONE_NUMBER_ID=tu_phone_number_id
```

En local puede quedar registrado como fallido si no configurás credenciales reales. Eso es normal.

## Nota importante para bases existentes

Si ya tenías datos cargados, la app intenta actualizar la base automáticamente al entrar.
Si querés una instalación limpia, podés borrar el volumen:

```bash
docker compose down -v
docker compose up --build
```
