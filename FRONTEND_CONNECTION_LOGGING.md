# Frontend Connection Logging - Implementation Summary

## 📋 Files Created/Modified

### 1. **NEW: `app/Services/LogTransferService.php`**
- ✅ Reutiliza métodos existentes `storeLog()`, `logProcessStart()`, `logProcessEnd()` sin modificación
- ✅ Agrega nuevo método `logFrontendConnection(Request, ?Response)`
- ✅ Anonimiza JWT (primeros 8 + últimos 4 caracteres)
- ✅ NUNCA guarda passwords, tokens completos o datos sensibles
- ✅ Maneja errores silenciosamente (no interrumpe requests)
- ✅ Se activa solo si `LOG_FRONTEND_CONNECTIONS` está enabled

**Métodos internos de seguridad:**
- `extractBearerToken()` - Extrae token del header Authorization
- `anonymizeJwt()` - Enmascara JWT de forma segura
- `isSensitiveEndpoint()` - Detecta endpoints sensibles (login, password, payment, webhook, oauth)

---

### 2. **NEW: `app/Http/Middleware/LogFrontendConnection.php`**
- ✅ Middleware global que captura TODAS las conexiones frontend→backend
- ✅ Marca tiempo de inicio en `$request->attributes->set('_log_start_time', microtime(true))`
- ✅ Ejecuta siguiente middleware/controlador con `$next($request)`
- ✅ Al final, llama `logFrontendConnection($request, $response)`
- ✅ Maneja errores silenciosamente - NUNCA interrumpe requests

**Flujo:**
```
Request → LogFrontendConnection → Otros middlewares → Controlador → Response
                ↓
          Captura timestamp
                ↓
          Ejecuta next()
                ↓
          logFrontendConnection() con response
                ↓
          (Errores se manejan sin afectar response)
```

---

### 3. **MODIFIED: `app/Http/Kernel.php`**
**Línea exacta agregada en `$middleware` array (después de HandleCors):**
```php
\App\Http\Middleware\LogFrontendConnection::class,
```

**Ubicación en el stack middleware:**
```
TrustProxies
    ↓
HandleCors  ← CORS processing
    ↓
LogFrontendConnection ← 👈 AQUÍ (nuevo)
    ↓
PreventRequestsDuringMaintenance
    ...resto de middlewares
```

---

### 4. **MODIFIED: `.env`**
**Nueva variable (agregada después de LOG_LEVEL):**
```env
LOG_FRONTEND_CONNECTIONS=false
```

**Valores permitidos:**
- `false` (por defecto) - Logging deshabilitado
- `true` - Logging habilitado (usar en staging/production si se monitorea)

---

### 5. **MODIFIED: `config/logging.php`**
**Nueva configuración al inicio del array `return`:**
```php
'log_frontend_connections' => env('LOG_FRONTEND_CONNECTIONS', false),
```

---

### 6. **OPTIONAL: Migration `2026_05_27_000000_add_frontend_connection_logging.php`**
- ✅ Documenta índices ya existentes en la tabla `payment_logs`
- ✅ No modifica la estructura (índices ya están en migración original)
- ✅ Puramente informativo para futuras optimizaciones

---

## 🔒 Datos Capturados

### Cada conexión frontend registra:
```json
{
  "type": "frontend_connection",
  "method": "GET|POST|PUT|DELETE|PATCH",
  "path": "/api/endpoint",
  "ip": "192.168.1.1",
  "user_agent": "Mozilla/5.0...",
  "user_id": 123,                    // null si no autenticado
  "timestamp": "2026-05-27T13:54:34.149-05:00",
  "request_id": "req_xxxxx",
  "content_type": "application/json",
  "response_code": 200,
  "response_time_ms": 45.23,
  "token_preview": "eyJhbGc****UVCJ9...",  // Anonimizado
  "has_json_payload": true,
  "json_keys": ["key1", "key2"],     // Solo nombres de keys
}
```

### ⚠️ NUNCA se guarda:
- ❌ Passwords
- ❌ Tokens completos
- ❌ Credenciales de pago
- ❌ Datos personales completos
- ❌ Payloads de request en endpoints sensibles

---

## 🛑 Endpoints Sensibles (Sin logging de body)
- `login`
- `register`
- `password`
- `token`
- `oauth`
- `payment`
- `webhook`

---

## ⚙️ Estructura en BD

Los logs se guardan en tabla **`payment_logs`** existente:

| Campo | Valor para frontend_connection |
|-------|--------|
| `type` | `'frontend_connection'` |
| `data` | JSON con toda la información |
| `ip_address` | IP del cliente |
| `user_agent` | User Agent del navegador |
| `user_id` | ID usuario (null si no autenticado) |
| `session_id` | ID de sesión |
| `logged_at` | Timestamp de la conexión |
| `job_id` | null (no aplica) |
| `order_id` | null (no aplica) |

---

## 🔄 Configuración para Producción

Para **activar en production/staging:**

```bash
# En .env production:
LOG_FRONTEND_CONNECTIONS=true
```

**Consideraciones de rendimiento:**
- ✅ Middleware corre DESPUÉS de CORS (bajo costo)
- ✅ Logging es asíncrono cuando posible (QUEUE_CONNECTION=redis)
- ✅ Errores en logging NO interrumpen requests
- ✅ Índices en BD optimizan queries

**Rate limiting (opcional):**
- Usar throttle existente: `'api' => 'throttle:60,1'`
- O crear middleware rate-limit específico para endpoints críticos

---

## 🧪 Testing

**Verificar que funciona:**
```bash
# 1. Habilitar en .env
LOG_FRONTEND_CONNECTIONS=true

# 2. Hacer request de prueba
curl http://localhost:8000/api/test

# 3. Verificar logs en BD
SELECT * FROM payment_logs 
WHERE type = 'frontend_connection' 
ORDER BY logged_at DESC 
LIMIT 5;
```

**Verificar sintaxis PHP:**
```bash
php -l app/Services/LogTransferService.php
php -l app/Http/Middleware/LogFrontendConnection.php
php -l app/Http/Kernel.php
```

---

## ✅ Restricciones Respetadas

✔️ NO modificar `storeLog()`, `logProcessStart()`, `logProcessEnd()`  
✔️ NO cambiar nombres de columnas en BD  
✔️ NO crear nueva tabla (reutiliza `payment_logs`)  
✔️ NO alterar controladores existentes  
✔️ NO cambiar lógica de negocio actual  
✔️ ✅ ANONIMIZAR tokens JWT  
✔️ ✅ NUNCA guardar datos sensibles  
✔️ ✅ Manejar errores silenciosamente  
✔️ ✅ Impacto mínimo en rendimiento  

---

## 📦 Resumen de Cambios

```
3 Archivos NUEVOS:
  ✅ app/Services/LogTransferService.php
  ✅ app/Http/Middleware/LogFrontendConnection.php
  ✅ database/migrations/2026_05_27_000000_add_frontend_connection_logging.php

3 Archivos MODIFICADOS:
  ✅ app/Http/Kernel.php (1 línea agregada)
  ✅ .env (1 variable agregada)
  ✅ config/logging.php (1 configuración agregada)

NOTA: Tabla payment_logs NO requiere migración adicional
      (ya tiene todas las columnas necesarias)
```

---

## 🚀 Ready to use!

Todo está listo para capturar conexiones frontend→backend.  
Habilitar con `LOG_FRONTEND_CONNECTIONS=true` en el ambiente deseado.
