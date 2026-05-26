# ⚡ Quick Start - Sistema de Pagos en 5 Minutos

> **Para**: Desarrolladores que necesitan empezar ya  
> **Tiempo**: ~5 minutos para setup  
> **Requisito**: Laravel 10, Composer, PHP 8.1+

---

## 📦 Paso 1: Configurar Variables de Entorno (1 min)

Edita `.env`:

```env
# IZIPAY
IZIPAY_ENV=sandbox
IZIPAY_CLIENT_ID=your_client_id
IZIPAY_SECRET=your_secret
IZIPAY_HASH_KEY=your_hash_key
IZIPAY_WEBHOOK_SECRET=your_webhook_secret

# MERCADOPAGO
MERCADOPAGO_ACCESS_TOKEN=your_access_token
MERCADOPAGO_WEBHOOK_SECRET=your_webhook_secret

# PAYPAL
PAYPAL_ENV=sandbox
PAYPAL_CLIENT_ID=your_client_id
PAYPAL_CLIENT_SECRET=your_client_secret
```

> **Nota**: Reemplaza con valores reales de tus gateways

---

## 🗄️ Paso 2: Ejecutar Migraciones (1 min)

```bash
php artisan migrate
```

✅ Esto crea las tablas `payments` y `transactions`

---

## 🔌 Paso 3: Registrar Webhooks (1 min)

Accede a los paneles de cada gateway y registra estas URLs:

```
Izipay:      https://yourdomain.com/api/payment/webhook/izipay
MercadoPago: https://yourdomain.com/api/payment/webhook/mercadopago
PayPal:      https://yourdomain.com/api/payment/webhook/paypal
```

---

## 🧪 Paso 4: Probar con cURL (2 min)

### 1️⃣ Obtener Token de Autenticación

```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "password"
  }'
```

Respuesta:
```json
{
  "token": "YOUR_TOKEN_HERE"
}
```

### 2️⃣ Crear Sesión de Pago

```bash
curl -X POST http://localhost:8000/api/payment/session \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "gateway": "izipay",
    "order_id": 1,
    "amount": 99.99,
    "currency": "USD",
    "email": "customer@example.com"
  }'
```

Respuesta:
```json
{
  "success": true,
  "data": {
    "token": "FORM_TOKEN_HERE",
    "payment_id": "PAYMENT_ID_HERE",
    "gateway": "izipay"
  }
}
```

### 3️⃣ Confirmar Pago Manualmente

```bash
curl -X POST http://localhost:8000/api/payment/confirm \
  -H "Authorization: Bearer YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "gateway": "izipay",
    "payment_id": "PAYMENT_ID_HERE"
  }'
```

Respuesta:
```json
{
  "success": true,
  "status": "COMPLETED"
}
```

---

## 📋 Gateways Soportados

| Gateway | Validación | Status |
|---------|-----------|--------|
| **Izipay** | HMAC-SHA256 | ✅ Listo |
| **MercadoPago** | HMAC-SHA256 | ✅ Listo |
| **PayPal** | N/A | ✅ Listo |

---

## 🔐 Validaciones Implementadas

✅ **Firma HMAC-SHA256** para Izipay y MercadoPago  
✅ **Rate Limiting**: 5 req/min (crear/confirmar), 20 req/min (webhooks)  
✅ **Autenticación Sanctum** en endpoints protegidos  
✅ **HTTPS required** para webhooks en producción  

---

## 📊 Response Codes

| Code | Significado |
|------|-------------|
| **200** | Éxito |
| **400** | Validación fallida |
| **401** | No autorizado / Firma inválida |
| **404** | Recurso no encontrado |
| **429** | Rate limit excedido |
| **500** | Error del servidor |

---

## 📚 Documentación Completa

Para más detalles, consulta:
- **README**: [PAYMENT_SYSTEM_README.md](./PAYMENT_SYSTEM_README.md)
- **Guía Técnica**: [PAYMENT_SYSTEM_IMPLEMENTATION.md](./PAYMENT_SYSTEM_IMPLEMENTATION.md)
- **Ejemplos**: [PAYMENT_IMPLEMENTATION_GUIDE.md](./PAYMENT_IMPLEMENTATION_GUIDE.md)
- **Deploy**: [PAYMENT_DEPLOYMENT_GUIDE.md](./PAYMENT_DEPLOYMENT_GUIDE.md)

---

## 🐛 Troubleshooting Rápido

### "Method not allowed" en webhook
→ Verificar que la ruta esté en routes/api.php

### "Invalid signature"
→ Verificar IZIPAY_WEBHOOK_SECRET o MERCADOPAGO_WEBHOOK_SECRET en .env

### "Too many requests"
→ Esperando 60 segundos (throttle: 5/min)

### "Payment not found"
→ Verificar que payment_id existe en BD

### Email no se envía
→ Verificar MAIL_MAILER y cola en .env

---

## ✅ Checklist de Setup

- [ ] Variables .env configuradas
- [ ] Migraciones ejecutadas
- [ ] Webhooks registrados en gateways
- [ ] Token de autenticación obtenido
- [ ] Endpoint /api/payment/session probado
- [ ] Endpoint /api/payment/confirm probado
- [ ] Email de confirmación recibido
- [ ] Transacción guardada en BD

---

## 🚀 Listo para Producción

Una vez completado el checklist anterior:

```bash
# 1. Cambiar a producción
sed -i 's/sandbox/production/g' .env

# 2. Limpiar cache
php artisan config:cache
php artisan route:cache

# 3. Verificar logs
tail -f storage/logs/laravel.log

# 4. ¡Aceptar pagos! 🎉
```

---

## 📞 URLs de Gateways

```
Izipay Sandbox:      https://sandbox-api.izipay.com
Izipay Production:   https://api.izipay.com

MercadoPago Sandbox: https://api.mercadopago.com (sandbox mode)
MercadoPago Prod:    https://api.mercadopago.com (production mode)

PayPal Sandbox:      https://api-m.sandbox.paypal.com
PayPal Production:   https://api-m.paypal.com
```

---

## 💡 Tips Útiles

**Tip 1**: Usa [Postman](https://postman.com) para probar endpoints  
**Tip 2**: Monitorea `tail -f storage/logs/laravel.log` durante desarrollo  
**Tip 3**: Usa webhooks.site para debuggear webhooks  
**Tip 4**: Configura eventos como cron: `* * * * * cd /path && php artisan schedule:run`

---

## 🎯 Próximos Pasos

1. ✅ Completar los 4 pasos de setup
2. ✅ Ejecutar los tests de cURL
3. ✅ Registrar webhooks en gateways
4. 📚 Leer [PAYMENT_SYSTEM_README.md](./PAYMENT_SYSTEM_README.md)
5. 🚀 Deploy a producción

---

**¡Tu sistema de pagos está listo! Comienza en 5 minutos 🚀**

Para ayuda completa: [PAYMENT_DOCUMENTATION_INDEX.md](./PAYMENT_DOCUMENTATION_INDEX.md)
