# Guía de Implementación - Sistema de Pagos Unificado Chambealo

## Resumen Ejecutivo

Se ha completado la **refactorización del sistema de pagos** con:
- ✅ Un único PaymentController unificado
- ✅ Todas las medidas de seguridad implementadas
- ✅ 3 Servicios de gateway completos (MercadoPago, Izipay, PayPal)
- ✅ 4 Vistas Blade funcionales
- ✅ Logging estructurado
- ✅ Rate limiting y prevención de replay attacks

---

## PASO 1: Preparación del Ambiente

### 1.1 Verificar que Laravel está instalado
```bash
php artisan --version
```

### 1.2 Crear archivo .env desde .env.example
```bash
cp .env.example .env
php artisan key:generate
```

### 1.3 Completar variables de entorno en .env

**Variables MercadoPago:**
```env
MERCADOPAGO_PUBLIC_KEY=your_public_key_here
MERCADOPAGO_ACCESS_TOKEN=your_access_token_here
MERCADOPAGO_WEBHOOK_SECRET=your_webhook_secret_here
```

**Variables Izipay:**
```env
IZIPAY_ENV=sandbox
IZIPAY_CLIENT_ID=your_client_id_here
IZIPAY_SECRET=your_secret_here
IZIPAY_HASH_KEY=your_hash_key_here
IZIPAY_WEBHOOK_SECRET=your_webhook_secret_here
```

**Variables PayPal:**
```env
PAYPAL_ENV=sandbox
PAYPAL_CLIENT_ID=your_client_id_here
PAYPAL_CLIENT_SECRET=your_client_secret_here
PAYPAL_WEBHOOK_ID=your_webhook_id_here
```

**Variables de Seguridad de Pagos:**
```env
PAYMENT_REQUIRE_HTTPS=true
PAYMENT_LOG_CHANNEL=payment
PAYMENT_REPLAY_PREVENTION=true
```

---

## PASO 2: Instalación de Dependencias

```bash
composer install
php artisan migrate
npm install
npm run build
```

---

## PASO 3: Configurar Logging

### 3.1 Crear canal de logging para pagos

Editar `config/logging.php` y asegurar que existe el canal 'payment':

```php
'payment' => [
    'driver' => 'single',
    'path' => storage_path('logs/payment.log'),
    'level' => env('LOG_LEVEL', 'debug'),
],
```

### 3.2 Crear directorio de logs
```bash
mkdir -p storage/logs
chmod -R 775 storage/logs
```

---

## PASO 4: Verificar Rutas

### 4.1 Listar rutas de pago
```bash
php artisan route:list | grep payment
```

**Rutas principales creadas:**
```
POST    /api/payment/session    (Crear sesión de pago)
POST    /api/payment/confirm    (Confirmar pago)
POST    /api/payment/webhook/{gateway}  (Recibir webhooks)
POST    /api/payment/refund     (Procesar reembolso)
GET     /api/payment/health     (Verificar estado gateways)
```

### 4.2 Rutas web para vistas
```
GET     /payment/               (Formulario de pago)
GET     /payment/success        (Pago exitoso)
GET     /payment/cancel         (Pago cancelado)
GET     /payment/webhook-debug  (Debug webhooks - solo dev)
```

---

## PASO 5: Prueba de Funcionamiento

### 5.1 Probar health check
```bash
curl -X GET http://localhost:8000/api/payment/health \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Respuesta esperada:**
```json
{
  "success": true,
  "data": {
    "mercadopago": {
      "healthy": true,
      "status": "operational"
    },
    "izipay": {
      "healthy": true,
      "status": "operational"
    },
    "paypal": {
      "healthy": true,
      "status": "operational"
    }
  },
  "message": "Todos los gateways operacionales"
}
```

### 5.2 Probar creación de sesión
```bash
curl -X POST http://localhost:8000/api/payment/session \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "gateway": "mercadopago",
    "order_id": 1,
    "amount": 100.00,
    "currency": "USD",
    "email": "user@example.com",
    "description": "Test payment"
  }'
```

### 5.3 Verificar logs
```bash
tail -f storage/logs/payment.log
```

---

## PASO 6: Configurar Webhooks

### 6.1 Obtener URL de webhook
```
https://your-domain.com/api/payment/webhook/{gateway}
```

Reemplazar `{gateway}` con: `mercadopago`, `izipay`, o `paypal`

### 6.2 En ambiente local (desarrollo)

Para probar webhooks en local con HTTPS:
```bash
# Usar ngrok o similar
ngrok http 8000
# Obtener URL como https://xxxxx.ngrok.io
```

### 6.3 Registrar webhooks en cada gateway

**MercadoPago:**
- URL: `https://your-domain.com/api/payment/webhook/mercadopago`
- Eventos: `payment.updated`

**Izipay:**
- URL: `https://your-domain.com/api/payment/webhook/izipay`
- Método: POST

**PayPal:**
- URL: `https://your-domain.com/api/payment/webhook/paypal`
- Eventos: `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.REFUNDED`

---

## PASO 7: Medidas de Seguridad Implementadas

### 7.1 HTTPS Validation
- ✅ Requerida en producción para webhooks
- ✅ Validación en `WebhookSecurityTrait`

### 7.2 Webhook Signature Verification
- ✅ HMAC-SHA256 para MercadoPago e Izipay
- ✅ API call verification para PayPal
- ✅ `hash_equals()` para prevenir timing attacks

### 7.3 Timestamp Validation
- ✅ Ventana de 5 minutos (configurable)
- ✅ Previene replay attacks antiguos

### 7.4 Replay Attack Prevention
- ✅ Cache con X-Request-Id
- ✅ Detección de duplicados dentro de 5 minutos

### 7.5 Rate Limiting
- ✅ 5 req/min para createSession y confirm
- ✅ 50 req/min para refund
- ✅ 20 req/min para webhooks
- ✅ 100 req/min por IP (webhooks)

### 7.6 VAFTEC (Validación desde Backend)
- ✅ Validación obligatoria de montos
- ✅ No confiar en datos del frontend
- ✅ Validación de rango (0.01 - 999999.99)

### 7.7 Logging Estructurado
- ✅ Canal específico para pagos
- ✅ Log de webhooks, errores y intentos fallidos
- ✅ Información de IP, user-agent, timestamp

---

## PASO 8: Estructura de Respuestas API

Todas las respuestas siguen este formato:

```json
{
  "success": true/false,
  "data": { /* datos específicos */ },
  "message": "Descripción amigable",
  "errors": { /* errores si aplica */ }
}
```

### Ejemplo: Sesión Exitosa
```json
{
  "success": true,
  "data": {
    "payment_id": 1,
    "gateway_id": "mp_12345",
    "init_point": "https://www.mercadopago.com/checkout/...",
    "redirect_url": "https://www.mercadopago.com/checkout/..."
  },
  "message": "Sesión de pago creada exitosamente"
}
```

### Ejemplo: Error
```json
{
  "success": false,
  "data": null,
  "message": "No se pudo crear la sesión de pago",
  "errors": {
    "error": "Monto inválido"
  }
}
```

---

## PASO 9: Estructura de Directorios Creados

```
app/
├── Http/
│   ├── Controllers/
│   │   └── PaymentController.php (Unificado ✨)
│   ├── Traits/
│   │   ├── WebhookSecurityTrait.php
│   │   ├── PaymentValidationTrait.php
│   │   └── PaymentLoggingTrait.php
│   ├── Requests/
│   │   ├── JobProcessRequest.php
│   │   ├── PaymentSessionRequest.php
│   │   └── RefundRequest.php
│   ├── Middleware/
│   │   └── WebhookHttpsMiddleware.php
│   └── (otros controladores)
├── Services/
│   ├── MercadoPagoService.php (Mejorado)
│   ├── IzipayService.php (Mejorado)
│   ├── PayPalService.php (Mejorado)
│   └── PaymentFactory.php
└── (resto del código)

resources/views/payments/
├── index.blade.php
├── success.blade.php
├── cancel.blade.php
└── webhook-debug.blade.php

config/
├── payment.php (Actualizado)
└── (otros configs)

routes/
├── api.php (Actualizado)
└── web.php (Actualizado)
```

---

## PASO 10: Monitoreo y Mantenimiento

### 10.1 Revisar logs regularmente
```bash
# Logs de pago
tail -f storage/logs/payment.log

# Logs de aplicación
tail -f storage/logs/laravel.log
```

### 10.2 Estadísticas de pagos
```bash
php artisan tinker
>>> \App\Models\Payment::whereDate('created_at', today())->count()
>>> \App\Models\Payment::where('status', 'completed')->sum('amount')
```

### 10.3 Debug de webhooks (desarrollo)
```
Acceso: http://localhost:8000/payment/webhook-debug
```

---

## PASO 11: Troubleshooting

### Problema: "HTTPS requerido"
**Solución:**
```env
# En desarrollo
PAYMENT_REQUIRE_HTTPS=false

# En producción
PAYMENT_REQUIRE_HTTPS=true
```

### Problema: "Firma inválida"
**Verificar:**
1. Que la webhook_secret en .env es correcta
2. Que no hay cambios en el payload
3. Revisar logs: `grep "signature verification failed" storage/logs/payment.log`

### Problema: "Rate limit excedido"
**Verificar:**
1. No hay múltiples requests simultáneos
2. Revisar IP en logs
3. Esperar 1-2 minutos y reintentar

### Problema: "Pago no encontrado"
**Verificar:**
1. Que la orden existe en BD
2. Que el payment_id es correcto
3. Revisar status del pago: `SELECT * FROM payments WHERE id = ?`

---

## PASO 12: Despliegue a Producción

### 12.1 Checklist Pre-Producción

- [ ] Variables .env configuradas correctamente
- [ ] HTTPS habilitado en servidor
- [ ] Base de datos migrada
- [ ] Logs con permisos de escritura
- [ ] Cache configurado (Redis recomendado)
- [ ] Webhooks registrados en cada gateway
- [ ] Email de notificación configurado
- [ ] Rate limiting configurado según carga esperada

### 12.2 Configuración de Producción
```env
APP_DEBUG=false
APP_ENV=production
PAYMENT_REQUIRE_HTTPS=true
PAYMENT_LOG_WEBHOOK_PAYLOADS=false
CACHE_DRIVER=redis
```

### 12.3 Comandos post-deploy
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan optimize
```

---

## PASO 13: API Endpoints Completos

### Crear Sesión de Pago
```
POST /api/payment/session
Authorization: Bearer {token}
Content-Type: application/json

{
  "gateway": "mercadopago|izipay|paypal",
  "order_id": 1,
  "amount": 100.00,
  "currency": "USD",
  "email": "user@example.com",
  "description": "Descripción del pago",
  "customer_name": "Nombre del cliente",
  "customer_phone": "Teléfono"
}
```

### Confirmar Pago
```
POST /api/payment/confirm
Authorization: Bearer {token}
Content-Type: application/json

{
  "gateway": "mercadopago|izipay|paypal",
  "payment_id": "payment_id_from_gateway"
}
```

### Procesar Reembolso
```
POST /api/payment/refund
Authorization: Bearer {token}
Content-Type: application/json

{
  "gateway": "mercadopago|izipay|paypal",
  "payment_id": "payment_id_from_gateway",
  "refund_amount": 100.00,
  "reason": "Cliente solicitó reembolso"
}
```

### Verificar Salud de Gateways
```
GET /api/payment/health
Authorization: Bearer {token}
```

### Recibir Webhook
```
POST /api/payment/webhook/{gateway}
X-{Gateway}-Signature: signature_header

Payload específico del gateway
```

---

## PASO 14: Soporte y Documentación

### Logs importantes a revisar:
- `/storage/logs/payment.log` - Transacciones de pago
- `/storage/logs/laravel.log` - Errores generales

### Contactar al desarrollador:
- Documentación completa: `PAYMENT_SYSTEM_README.md`
- API Reference: `API_ENDPOINTS.md`
- Troubleshooting: `PAYMENT_QUICK_START.md`

---

## Resumen de Archivos Creados/Modificados

### ✅ Nuevos Archivos (8)
1. `app/Http/Traits/WebhookSecurityTrait.php`
2. `app/Http/Traits/PaymentValidationTrait.php`
3. `app/Http/Traits/PaymentLoggingTrait.php`
4. `app/Http/Requests/JobProcessRequest.php`
5. `app/Http/Requests/PaymentSessionRequest.php`
6. `app/Http/Requests/RefundRequest.php`
7. `resources/views/payments/*.blade.php` (4 archivos)

### ✏️ Modificados (4)
1. `app/Http/Controllers/PaymentController.php` (Reemplazado)
2. `app/Services/MercadoPagoService.php` (Mejorado)
3. `app/Services/IzipayService.php` (Mejorado)
4. `app/Services/PayPalService.php` (Mejorado)

### 🔄 Actualizados (3)
1. `routes/api.php` (Nuevas rutas unificadas)
2. `routes/web.php` (Rutas de vistas)
3. `config/payment.php` (Opciones de seguridad)

### 📝 Archivos de Configuración (1)
1. `.env.example` (Nuevas variables)

---

## Conclusión

El sistema de pagos ha sido **completamente refactorizado** con:
- ✅ Arquitectura moderna y limpia
- ✅ Todas las medidas de seguridad requeridas
- ✅ Logging estructurado
- ✅ Manejo de errores robusto
- ✅ Documentación completa

**¡Listo para usar en producción! 🚀**
