# Webhook Batch - Documentación de Debug

## Descripción
El webhook `webhook_batch.php` recibe notificaciones POST de la API EasyOCR cuando un lote de documentos ha sido procesado.

## Ubicación
```
/custom/easyocr/webhook_batch.php
```

## URL de Acceso
La URL completa incluye el parámetro de seguridad `instance_id`:
```
https://tu-dominio.com/custom/easyocr/webhook_batch.php?instance_id={unique_id}
```

El `instance_id` se genera automáticamente desde `$dolibarr_main_instance_unique_id` (conf.php).

## Seguridad

### 1. Validación de Instance ID
El webhook valida que el `instance_id` recibido coincida con el configurado en Dolibarr:
- ✅ **Válido**: Procesa la petición
- ❌ **Inválido**: Retorna HTTP 403 Forbidden
- ⚠️ **No configurado**: Muestra advertencia en debug pero continúa (modo desarrollo)

### 2. Verificación de Método HTTP
Solo acepta peticiones POST. Cualquier otro método retorna HTTP 405.

### 3. Webhook Secret (Opcional)
Si configurado en Dolibarr (`EASYOCR_WEBHOOK_SECRET`), valida el header `X-Webhook-Secret`.

## Archivos de Debug

### Ubicación de Logs
```
documents/easyocr/webhook_debug/  → Archivos JSON individuales por petición
documents/easyocr/webhook_logs/   → Logs diarios agregados
```

### 1. Debug JSON (Completo)
**Ruta**: `documents/easyocr/webhook_debug/webhook_YYYY-MM-DD_HHmmss_uniqueid.json`

**Contenido**:
```json
{
  "timestamp": "2026-02-17 10:30:45",
  "method": "POST",
  "remote_ip": "192.168.1.100",
  "request_uri": "/custom/easyocr/webhook_batch.php?instance_id=abc123",
  "query_string": "instance_id=abc123",
  "GET": {
    "instance_id": "abc123"
  },
  "POST": {},
  "headers": {
    "content_type": "application/json",
    "content_length": "1234",
    "user_agent": "EasyOCR-API/1.0",
    "webhook_secret": "***present***",
    "authorization": "not-set"
  },
  "raw_body": "{\"event\":\"batch.document.completed\",\"batch_id\":\"uuid-123\", ...}",
  "raw_body_length": 1234,
  "parsed_json": {
    "event": "batch.document.completed",
    "batch_id": "uuid-123",
    "document": { ... }
  },
  "json_parse_error": null
}
```

### 2. Logs Diarios (Agregados)
**Ruta**: `documents/easyocr/webhook_logs/webhook_YYYY-MM-DD.log`

**Formato**: Una línea JSON por evento
```
10:30:45 | {"received_at":"2026-02-17 10:30:45","remote_ip":"192.168.1.100","instance_id":"abc123","payload":{...}}
```

## Depuración de Problemas

### 1. Webhook no recibe peticiones
**Verificar**:
- ✅ URL correcta en batch.php (debe estar en raíz: `/easyocr/webhook_batch.php`)
- ✅ `instance_id` incluido en la URL
- ✅ Servidor accesible desde la API externa
- ✅ Firewall permite conexiones entrantes

**Comprobar**:
```bash
# Ver si hay archivos de debug
ls documents/easyocr/webhook_debug/

# Si no hay archivos, el webhook no está recibiendo peticiones
```

### 2. Error HTTP 403 (Forbidden)
**Causa**: `instance_id` no válido

**Verificar**:
1. Archivo de debug JSON → campo `GET.instance_id`
2. Comparar con `conf/conf.php` → `$dolibarr_main_instance_unique_id`
3. Si no coinciden:
   ```php
   // batch.php debe generar la URL correctamente
   global $dolibarr_main_instance_unique_id;
   $webhookUrl .= '?instance_id=' . urlencode($dolibarr_main_instance_unique_id);
   ```

### 3. Error HTTP 400 (Bad Request)
**Causas posibles**:
- Cuerpo vacío
- JSON inválido
- Parámetro `instance_id` faltante

**Revisar**: `webhook_debug/*.json` → campos `raw_body` y `json_parse_error`

### 4. Error HTTP 405 (Method Not Allowed)
**Causa**: Petición GET en lugar de POST

**Revisar**: `webhook_debug/*.json` → campo `method`

### 5. Base de datos no actualizada
**Verificar**:
```sql
SELECT COUNT(*) FROM llx_easyocr_webhook_log;
```

Si logs de archivo existen pero tabla está vacía:
- ✅ Tabla `llx_easyocr_webhook_log` creada
- ✅ Permisos de escritura en BD
- ✅ Revisar logs → buscar "DB insert failed"

## Limpieza de Archivos de Debug

### Manual
```bash
# Eliminar archivos de debug antiguos (> 7 días)
find documents/easyocr/webhook_debug/ -name "webhook_*.json" -mtime +7 -delete

# Eliminar logs antiguos (> 30 días)
find documents/easyocr/webhook_logs/ -name "webhook_*.log" -mtime +30 -delete
```

### Automática (Cron recomendado)
```bash
# Añadir en crontab del servidor
0 2 * * * find /ruta/documents/easyocr/webhook_debug/ -name "*.json" -mtime +7 -delete
0 3 * * 0 find /ruta/documents/easyocr/webhook_logs/ -name "*.log" -mtime +30 -delete
```

## Ejemplo de Payload Recibido
```json
{
  "event": "batch.document.completed",
  "batch_id": "550e8400-e29b-41d4-a716-446655440000",
  "document": {
    "document_id": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
    "filename": "factura_001.pdf",
    "status": "completed",
    "pages": 2,
    "structured_data": {
      "document_number": "INV-2026-001",
      "issue_date": "2026-02-17",
      "supplier": {
        "name": "ACME Corp",
        "tax_id": "B12345678"
      },
      "totals": {
        "net_subtotal": 100.00,
        "tax_total": 21.00,
        "total": 121.00
      }
    }
  },
  "batch": {
    "batch_id": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Facturas Febrero",
    "status": "processing",
    "total_documents": 10,
    "completed_documents": 5,
    "failed_documents": 0,
    "progress": 50
  },
  "timestamp": "2026-02-17T10:30:45Z"
}
```

## Respuesta del Webhook
El webhook siempre responde con código HTTP 200 (incluso si hay error de BD, para evitar reintentos):

```json
{
  "status": "ok",
  "message": "Webhook received",
  "batch_id": "550e8400-e29b-41d4-a716-446655440000",
  "event": "batch.document.completed",
  "instance_id_validated": true
}
```

## Migración desde Versión Anterior
Si actualizas desde una versión que tenía el webhook en `ajax/webhook_batch.php`:

1. **La nueva URL es automática**: batch.php genera la URL correcta con `instance_id`
2. **Mantén el archivo viejo** (opcional): Por compatibilidad con webhooks antiguos aún en cola
3. **Actualiza webhooks activos**: Re-crear lotes para usar la nueva URL
4. **Elimina el archivo antiguo** (después de verificar): `ajax/webhook_batch.php`

## Soporte
Para reportar problemas o consultas:
- Email: info@easysoft.es
- Incluye en el reporte:
  - Archivo `webhook_debug/*.json` de la petición problemática
  - Línea correspondiente de `webhook_logs/*.log`
  - Versión del módulo EasyOcr
