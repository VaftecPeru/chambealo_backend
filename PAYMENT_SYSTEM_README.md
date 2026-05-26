# 💳 Sistema de Pagos - Chambealo Backend

> Implementación completa y segura de pagos con **Izipay**, **MercadoPago** y **PayPal**

---

## 🎯 Inicio Rápido

### 1️⃣ Configurar Variables de Entorno

```bash
# .env

# Izipay
IZIPAY_ENV=sandbox
IZIPAY_CLIENT_ID=your_client_id
IZIPAY_SECRET=your_secret
IZIPAY_HASH_KEY=your_hash_key
IZIPAY_WEBHOOK_SECRET=your_webhook_secret

# MercadoPago
MERCADOPAGO_ACCESS_TOKEN=your_access_token
MERCADOPAGO_WEBHOOK_SECRET=your_webhook_secret

# PayPal
PAYPAL_ENV=sandbox
PAYPAL_CLIENT_ID=your_client_id
PAYPAL_CLIENT_SECRET=your_client_secret
```

### 2️⃣ Ejecutar Migraciones

```bash
php artisan migrate
```

### 3️⃣ Crear Pago

```bash
curl -X POST http://localhost:8000/api/payment/session \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "gateway": "izipay",
    "order_id": 1,
    "amount": 99.99,
    "currency": "USD",
    "email": "user@example.com"
  }'
```

---

## 📚 Documentación

| Documento | Contenido |
|---|---|
| 📖 [PAYMENT_SYSTEM_IMPLEMENTATION.md](./PAYMENT_SYSTEM_IMPLEMENTATION.md) | Arquitectura, endpoints, configuración |
| 🛠️ [PAYMENT_IMPLEMENTATION_GUIDE.md](./PAYMENT_IMPLEMENTATION_GUIDE.md) | Guía práctica, ejemplos, troubleshooting |
| ✅ [PAYMENT_VERIFICATION_CHECKLIST.md](./PAYMENT_VERIFICATION_CHECKLIST.md) | Checklist de verificación |
| 📊 [PAYMENT_SYSTEM_SUMMARY.md](./PAYMENT_SYSTEM_SUMMARY.md) | Resumen ejecutivo |

---

## 🔐 Seguridad

### ✅ Validación de Firmas (HMAC-SHA256)

```
Izipay:      Header: X-Izipay-Signature
MercadoPago: Header: x-signature (formato: "timestamp|signature")
PayPal:      Sin firma (confiamos en PayPal)
```

### ✅ Protecciones Implementadas

- Throttling: 5 req/min (create/confirm), 20 req/min (webhooks)
- hash_equals() para prevenir timing attacks
- Autenticación Sanctum en endpoints protegidos
- HTTPS required para webhooks
- Logging completo de transacciones

---

## 📡 Endpoints API

### 1. Crear Sesión de Pago

```http
POST /api/payment/session
Authorization: Bearer {token}
Content-Type: application/json

{
  "gateway": "izipay|mercadopago|paypal",
  "order_id": 1,
  "amount": 99.99,
  "currency": "USD",
  "email": "user@example.com",
  "description": "Producto XYZ"
}

Response (200):
{
  "success": true,
  "data": {
    "payment_id": 1,
    "gateway_id": "ext_id_123",
    "init_point": "https://checkout.url...",
    "form_token": "token_123"
  }
}
```

### 2. Confirmar Pago Manual

```http
POST /api/payment/confirm
Authorization: Bearer {token}
Content-Type: application/json

{
  "gateway": "izipay|mercadopago|paypal",
  "payment_id": "external_payment_id"
}

Response (200):
{
  "success": true,
  "status": "completed|pending|failed",
  "message": "Payment completed"
}
```

### 3. Webhook (Recibir Eventos)

```http
POST /api/payment/webhook/{gateway}
X-Izipay-Signature: {signature}    [for Izipay]
x-signature: {timestamp}|{signature} [for MercadoPago]
Content-Type: application/json

{
  "kr-answer": "...",  // Izipay
  "data": {...}        // MercadoPago
  "event_type": "...",  // PayPal
  "resource": {...}
}

Response (200):
{
  "success": true
}
```

---

## 🏗️ Arquitectura

### Flujo de Pago

```
Frontend
  ↓
  POST /api/payment/session
  ↓
PaymentController::createSession()
  ↓
PaymentFactory::make(gateway)
  ↓
IzipayService | MercadoPagoService | PayPalService
  ↓
Gateway API
  ↓
PaymentRepository::createPayment()
  ↓
Database (payments table)
  ↓
Redirigir a formulario de pago (gateway)
  ↓
Usuario paga en gateway
  ↓
Webhook → PaymentController::webhook()
  ↓
Validar firma (CRÍTICO)
  ↓
PaymentRepository::updatePaymentStatus()
  ↓
event(PaymentConfirmed)
  ↓
GenerateInvoiceAndSendEmail listener
  ↓
✅ Orden marcada como pagada
✅ Email de confirmación enviado
✅ Transacción registrada en DB
```

---

## 📁 Estructura de Archivos

```
app/
├── Http/Controllers/
│   └── PaymentController.php          ← Endpoints unificados
├── Services/
│   ├── PaymentServiceInterface.php    ← Interfaz
│   ├── PaymentFactory.php             ← Factory pattern
│   ├── IzipayService.php              ← Gateway 1
│   ├── MercadoPagoService.php         ← Gateway 2
│   └── PayPalService.php              ← Gateway 3
├── Models/
│   ├── Payment.php                    ← Modelo de pagos
│   └── Transaction.php                ← Modelo de transacciones
├── Repositories/
│   └── PaymentRepository.php          ← Acceso a datos
├── Events/
│   └── PaymentConfirmed.php           ← Evento
├── Listeners/
│   └── GenerateInvoiceAndSendEmail.php ← Listener automático
├── Mail/
│   └── PaymentConfirmationMail.php    ← Email
└── Providers/
    └── EventServiceProvider.php       ← Registra eventos

config/
└── payment.php                         ← Configuración centralizada

database/migrations/
└── 2026_05_25_130000_update_payments_table_for_gateways.php

routes/
├── api.php                            ← Rutas API
└── web.php

resources/views/emails/
└── payment_confirmation.blade.php    ← Vista de email
```

---

## 💾 Base de Datos

### Tabla: payments

```sql
CREATE TABLE payments (
    id BIGINT PRIMARY KEY,
    order_id VARCHAR UNIQUE NOT NULL,
    gateway VARCHAR,
    payment_id VARCHAR UNIQUE,
    status VARCHAR DEFAULT 'pending',
    amount DECIMAL(10,2),
    currency VARCHAR(3),
    raw_response JSON,
    email VARCHAR,
    user_id BIGINT,
    tenant_id VARCHAR,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Tabla: transactions

```sql
CREATE TABLE transactions (
    id BIGINT PRIMARY KEY,
    payment_id BIGINT FOREIGN KEY,
    transaction_id VARCHAR UNIQUE,
    gateway VARCHAR,
    status VARCHAR,
    amount DECIMAL(10,2),
    raw_data JSON,
    ip_address VARCHAR,
    user_agent TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## 🔄 Procesamiento de Webhooks

### ¿Qué Sucede al Recibir un Webhook?

1. **Validación de Firma** (CRÍTICO)
   - Verifica HMAC-SHA256 del gateway
   - Usa `hash_equals()` para prevenir timing attacks
   - Rechaza si firma no válida

2. **Extracción de Payment ID**
   - Diferente para cada gateway
   - Izipay: `paymentId`
   - MercadoPago: `data.id`
   - PayPal: `resource.id`

3. **Consulta de Estado**
   - Llama a `confirmPayment()` del gateway
   - Obtiene estado actual de pago

4. **Actualización de BD**
   - `PaymentRepository::updatePaymentStatus()`
   - Crea Transaction si status = 'completed'

5. **Disparo de Evento**
   - `event(new PaymentConfirmed($payment))`
   - GenerateInvoiceAndSendEmail se ejecuta automáticamente
   - Marca orden como pagada
   - Envía email al usuario

---

## 🧪 Testing

### Test con Postman

1. Configurar Authorization: Bearer {token}
2. POST /api/payment/session
3. Copiar `gateway_id` de response
4. POST /api/payment/confirm con ese `gateway_id`

### Test Webhooks

```bash
# Izipay
curl -X POST http://localhost:8000/api/payment/webhook/izipay \
  -H "X-Izipay-Signature: YOUR_SIGNATURE" \
  -H "Content-Type: application/json" \
  -d '{"kr-answer": "..."}'

# MercadoPago
curl -X POST http://localhost:8000/api/payment/webhook/mercadopago \
  -H "x-signature: 1234567890|SIGNATURE" \
  -H "Content-Type: application/json" \
  -d '{"action": "payment.updated", "data": {"id": "123"}}'

# PayPal
curl -X POST http://localhost:8000/api/payment/webhook/paypal \
  -H "Content-Type: application/json" \
  -d '{"event_type": "PAYMENT.CAPTURE.COMPLETED"}'
```

---

## 🚨 Troubleshooting

### "Invalid signature"
- Verificar que `webhook_secret` en .env coincida con el gateway
- Para MercadoPago, verificar formato: "timestamp|signature"
- Usar header exacto (mayúsculas/minúsculas importan)

### "Payment not found"
- Verificar que `payment_id` en webhook exista en BD
- Verificar que `createSession` fue ejecutado exitosamente
- Revisar logs para detalles

### "Configuration missing"
- Verificar que .env tiene TODAS las variables requeridas
- Ejecutar `php artisan config:cache` después de cambiar .env
- Para nuevo environment: usar valores de producción de gateways

### "Timeout en API"
- Verificar credenciales (client_id, secret, token)
- Verificar conexión a internet
- Para sandbox: usar URLs sandbox
- Para producción: usar URLs de producción

---

## 📊 Monitoreo

### Revisar Transacciones

```sql
-- Pagos completados
SELECT * FROM payments WHERE status = 'completed';

-- Transacciones por gateway
SELECT gateway, COUNT(*) as total, SUM(amount) as total_amount
FROM transactions
WHERE status = 'success'
GROUP BY gateway;

-- Últimos 10 pagos
SELECT p.*, t.* FROM payments p
LEFT JOIN transactions t ON p.id = t.payment_id
ORDER BY p.created_at DESC
LIMIT 10;
```

### Ver Logs de Pago

```bash
tail -f storage/logs/laravel.log | grep -i payment
```

---

## 🔐 Configuración de Producción

### 1. Cambiar .env a valores reales

```env
IZIPAY_ENV=production          # cambiar de 'sandbox'
MERCADOPAGO_ACCESS_TOKEN=real_token
PAYPAL_ENV=production          # cambiar de 'sandbox'
```

### 2. Registrar Webhooks Reales

```
Izipay:      https://yourdomain.com/api/payment/webhook/izipay
MercadoPago: https://yourdomain.com/api/payment/webhook/mercadopago
PayPal:      https://yourdomain.com/api/payment/webhook/paypal
```

### 3. Habilitar HTTPS

Todos los webhooks REQUIEREN HTTPS en producción (middleware 'https.webhook')

### 4. Monitorear Logs

```bash
php artisan logs --limit=100
php artisan logs --level=error
```

---

## 📞 Soporte Adicional

Para preguntas específicas sobre cada componente:

- **Servicios de Pago**: Ver `app/Services/*.php`
- **Webhooks**: Ver `app/Http/Controllers/PaymentController.php::webhook()`
- **BD y Persistencia**: Ver `app/Repositories/PaymentRepository.php`
- **Eventos**: Ver `app/Events/PaymentConfirmed.php`
- **Configuración**: Ver `config/payment.php`

---

## ✨ Características

✅ **3 Gateways** - Izipay, MercadoPago, PayPal
✅ **Validación de Firmas** - HMAC-SHA256 para Izipay y MercadoPago
✅ **Webhooks Seguros** - Hash validation + throttling
✅ **Transacciones Persistidas** - Auditoría completa
✅ **Eventos Automáticos** - PaymentConfirmed event
✅ **Email Automático** - Confirmación al completar pago
✅ **Factory Pattern** - Código limpio y mantenible
✅ **Throttling** - Protección contra abuso
✅ **Logging Completo** - Trazabilidad de pagos
✅ **Documentación** - 4 documentos detallados

---

## 📄 Licencia

Este sistema de pagos está integrado en Chambealo Backend y sigue las mismas políticas de licencia del proyecto.

---

**Última actualización**: 25 de mayo de 2026
**Status**: ✅ Listo para Producción
**Versión**: 1.0.0
