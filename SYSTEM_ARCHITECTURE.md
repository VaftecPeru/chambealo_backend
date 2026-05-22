# 🏗️ Arquitectura del Sistema de Logs de Pagos

## Flujo General del Sistema

```
┌─────────────────────────────────────────────────────────────────┐
│                     PAYMENT GATEWAYS                            │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │   PayPal     │  │   Izipay     │  │Mercado Pago  │          │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘          │
│         │                 │                  │                  │
│         └─────────────────┼──────────────────┘                  │
│                           ▼                                      │
│            ┌──────────────────────────┐                         │
│            │   Webhook Received       │                         │
│            │   HTTPS Verified ✅      │                         │
│            │   TLS Version Checked    │                         │
│            └───────────┬──────────────┘                         │
│                        ▼                                         │
│            ┌──────────────────────────┐                         │
│            │  Signature Verification  │                         │
│            │  ✓ HMAC SHA-256          │                         │
│            │  ✓ RSA                   │                         │
│            └───────────┬──────────────┘                         │
└─────────────────────────┼──────────────────────────────────────┘
                          │
                          ▼
        ┌─────────────────────────────────────┐
        │   LogsPaymentEvents Trait           │
        │  ┌────────────────────────────────┐ │
        │  │ logWebhookReceived()           │ │
        │  │ logWebhookVerification()       │ │
        │  │ logSecurityEvent()             │ │
        │  │ logPaymentEvent()              │ │
        │  │ logError()                     │ │
        │  │ logSignatureVerification()     │ │
        │  └────────────────────────────────┘ │
        └──────────────┬──────────────────────┘
                       │
                       ▼
        ┌──────────────────────────────────┐
        │    Database (payment_logs)       │
        │                                  │
        │  • event_type                    │
        │  • status                        │
        │  • gateway                       │
        │  • signature_verified            │
        │  • https_verified                │
        │  • request_payload               │
        │  • response_payload              │
        │  • headers                       │
        │  • error_message                 │
        │  • ip_address                    │
        │  • user_agent                    │
        │  • timestamp_validated           │
        │  • tls_version                   │
        │  • webhook_id                    │
        │  • replay_prevention_id          │
        └────────┬─────────────────────────┘
                 │
        ┌────────┴──────────────┬──────────────┐
        │                       │              │
        ▼                       ▼              ▼
   ┌─────────────┐     ┌───────────────┐  ┌──────────┐
   │  API Routes │     │  Web Routes   │  │ Reports  │
   │  (JSON)     │     │  (Blade)      │  │ (Export) │
   │             │     │               │  │          │
   │ /api/admin/ │     │ /admin/       │  │ CSV/PDF  │
   │ payment-logs│     │ payment-logs  │  │ Emails   │
   └──────┬──────┘     └────────┬──────┘  └──────────┘
          │                     │
          │                     ▼
          │            ┌─────────────────────┐
          │            │  Blade Templates    │
          │            │  ┌─────────────────┐│
          │            │  │ • Layout master ││
          │            │  │ • Index (list)  ││
          │            │  │ • Show (detail) ││
          │            │  │ • Bootstrap 5   ││
          │            │  │ • Responsive    ││
          │            │  └─────────────────┘│
          │            └─────────────────────┘
          │                     │
          │                     ▼
          │            ┌─────────────────────┐
          │            │  Admin Dashboard    │
          │            │  ┌─────────────────┐│
          │            │  │ Filters         ││
          │            │  │ Statistics      ││
          │            │  │ Table           ││
          │            │  │ Pagination      ││
          │            │  │ Detail View     ││
          │            │  └─────────────────┘│
          │            └─────────────────────┘
          │
          └────────────────────────────────────►
                    Admin API Consumers
```

---

## Estructura de Carpetas

```
chambealo_backend/
│
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   │   ├── PaymentLogController.php          ✅ API
│   │   │   │   └── PaymentLogViewController.php     ✅ Web/Blade
│   │   │   └── ...
│   │   │
│   │   └── Middleware/
│   │       ├── EnforceHttpsForWebhooks.php          ✅ HTTPS Check
│   │       ├── AdminMiddleware.php                  ✅ Role Check
│   │       └── ...
│   │
│   ├── Models/
│   │   ├── PaymentLog.php                           ✅ Model
│   │   └── ...
│   │
│   └── Traits/
│       ├── LogsPaymentEvents.php                    ✅ Logging
│       └── ...
│
├── database/
│   ├── migrations/
│   │   ├── 2026_05_21_113254_create_payment_logs_table.php     ✅
│   │   ├── 2026_05_21_114500_add_security_fields.php           ✅
│   │   ├── 2026_05_21_115000_add_https_fields.php              ✅
│   │   └── ...
│
├── resources/
│   └── views/
│       ├── admin/
│       │   ├── layouts/
│       │   │   └── app.blade.php                    ✅ Master Layout
│       │   │
│       │   └── payment-logs/
│       │       ├── index.blade.php                  ✅ List View
│       │       └── show.blade.php                   ✅ Detail View
│       │
│       └── ...
│
├── routes/
│   ├── api.php                                      ✅ API Routes
│   ├── web.php                                      ✅ Web Routes
│   └── ...
│
└── Documentation/
    ├── FINAL_SUMMARY.md
    ├── IMPLEMENTATION_COMPLETE.md
    ├── IMPLEMENTATION_CHECKLIST.md
    ├── IMPLEMENTATION_FINAL.md
    ├── BLADE_TEMPLATES_SUMMARY.md
    ├── QUICK_START_ADMIN_PANEL.md
    ├── QUICK_START.md
    ├── API_ENDPOINTS.md
    ├── PAYMENT_LOGS_README.md
    └── MANIFEST_CHANGES.md
```

---

## Flujo de Autenticación

```
┌──────────────────┐
│   User Request   │
└────────┬─────────┘
         │
         ▼
┌──────────────────────┐
│ Check: Authenticated?│
└────────┬─────────────┘
         │
    ┌────┴────┐
    │          │
   NO        YES
    │          │
    │          ▼
    │    ┌─────────────────────┐
    │    │ Check: Is Admin?    │
    │    └────────┬────────────┘
    │             │
    │        ┌────┴────┐
    │        │          │
    │       NO        YES
    │        │          │
    │        │          ▼
    │        │    ┌──────────────────────┐
    │        │    │ Grant Access ✅      │
    │        │    │ Load Logs Data       │
    │        │    │ Render View/JSON     │
    │        │    └──────────────────────┘
    │        │
    │        ▼
    │    ┌──────────────────────┐
    └───►│ Deny Access ❌       │
         │ Return 403 Forbidden │
         └──────────────────────┘
         OR
         ┌──────────────────────┐
         │ Redirect to Login    │
         └──────────────────────┘
```

---

## Ciclo de Vida de un Log

```
┌────────────────┐
│  1. Webhook    │
│     Received   │
└────────┬───────┘
         │
         ▼
┌────────────────────────┐
│  2. HTTPS Verified     │
│     • Check SSL/TLS    │
│     • Capture version  │
└────────┬───────────────┘
         │
         ▼
┌────────────────────────┐
│  3. Signature Check    │
│     • Verify sig       │
│     • Record result    │
└────────┬───────────────┘
         │
         ▼
┌────────────────────────┐
│  4. Timestamp Check    │
│     • Validate time    │
│     • Prevent replay   │
└────────┬───────────────┘
         │
         ▼
┌────────────────────────┐
│  5. Process Payment    │
│     • Update balance   │
│     • Create record    │
└────────┬───────────────┘
         │
         ▼
┌────────────────────────┐
│  6. Log Event          │
│     • Save to DB       │
│     • Record details   │
└────────┬───────────────┘
         │
         ▼
┌────────────────────────┐
│  7. Admin Can View     │
│     • In Dashboard     │
│     • See Full Details │
│     • Filter & Search  │
└────────────────────────┘
```

---

## Tabla payment_logs - Estructura

```
┌─────────────────────────────────────────────────────────────────┐
│                    payment_logs Table                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  PRIMARY KEY:                                                   │
│  • id (PK)                                                      │
│                                                                 │
│  FOREIGN KEY:                                                   │
│  • transaction_id (FK → transactions.id)                        │
│                                                                 │
│  EVENT INFORMATION:                                             │
│  • event_type (webhook.*, payment.*, security.*)               │
│  • status (success, failed, pending, processing, retry)        │
│  • gateway (paypal, izipay, mercadopago)                       │
│  • webhook_id (UNIQUE - prevent duplicates)                    │
│                                                                 │
│  SECURITY:                                                      │
│  • signature_verified (boolean)                                │
│  • signature_method (hmac_sha256, rsa, x_signature)            │
│  • signature_details (JSON)                                    │
│  • timestamp_validated (boolean)                               │
│  • https_verified (boolean)                                    │
│  • tls_version (TLS 1.2, TLS 1.3, etc)                        │
│  • replay_prevention_id (UNIQUE)                               │
│                                                                 │
│  PAYLOAD DATA:                                                  │
│  • request_payload (JSON)                                      │
│  • response_payload (JSON)                                     │
│  • headers (JSON)                                              │
│                                                                 │
│  METADATA:                                                      │
│  • ip_address                                                  │
│  • user_agent                                                  │
│  • error_message (if failed)                                   │
│  • attempt (retry count)                                       │
│  • created_at, updated_at                                      │
│                                                                 │
│  INDEXES (13 total):                                            │
│  ✓ PRIMARY (id)                                                │
│  ✓ transaction_id                                              │
│  ✓ event_type                                                  │
│  ✓ webhook_id                                                  │
│  ✓ gateway                                                     │
│  ✓ status                                                      │
│  ✓ created_at                                                  │
│  ✓ signature_verified                                          │
│  ✓ https_verified                                              │
│  ✓ UNIQUE (webhook_id)                                         │
│  ✓ UNIQUE (replay_prevention_id)                               │
│  ✓ COMPOSITE (gateway, event_type, created_at)                │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Vista Admin - Componentes

```
┌─────────────────────────────────────────────────────────┐
│             ADMIN DASHBOARD - PAYMENT LOGS              │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  ┌─ NAVBAR ─────────────────────────────────────────┐ │
│  │ 🏢 Admin Panel  |  Logs  |  Logout              │ │
│  └───────────────────────────────────────────────────┘ │
│                                                         │
│  ┌─ HEADER ─────────────────────────────────────────┐ │
│  │ 📊 Logs de Pagos y Webhooks  [Actualizar]       │ │
│  └───────────────────────────────────────────────────┘ │
│                                                         │
│  ┌─ STATISTICS ──────────────────────────────────────┐ │
│  │ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐  │ │
│  │ │ Total  │ │Fallidos│ │Seguridad│ │PayPal │  │ │
│  │ │  Hoy   │ │  Hoy   │ │  Hoy    │ │  123  │  │ │
│  │ │  456   │ │  12    │ │   45    │ │       │  │ │
│  │ └────────┘ └────────┘ └────────┘ └────────┘  │ │
│  └───────────────────────────────────────────────────┘ │
│                                                         │
│  ┌─ FILTERS ────────────────────────────────────────┐ │
│  │ Gateway: [▼]  Event: [▼]  Status: [▼]          │ │
│  │ From: [  ]  To: [  ]  Search: [  ]  [Filter]  │ │
│  └───────────────────────────────────────────────────┘ │
│                                                         │
│  ┌─ LOGS TABLE ─────────────────────────────────────┐ │
│  │ ID │ Trans │ Fecha │ Gateway │ Evento │ Est...│ │
│  │────┼───────┼───────┼─────────┼────────┼──────│ │
│  │ 1  │ 123   │ 10:25 │ PayPal  │ webhook│ ✅  │ │
│  │ 2  │ 124   │ 10:24 │ Izipay  │payment │ ❌  │ │
│  │ 3  │ 125   │ 10:23 │ MP      │security│ ⚠️  │ │
│  │ 4  │ 126   │ 10:22 │ PayPal  │webhook │ ✅  │ │
│  │ 5  │ null  │ 10:21 │ Izipay  │webhook │ ⏳  │ │
│  │ ... más registros ...                         │ │
│  └───────────────────────────────────────────────────┘ │
│                                                         │
│  ┌─ PAGINATION ─────────────────────────────────────┐ │
│  │  [< Anterior]  [1] [2] [3] ... [20]  [Próximo >] │ │
│  │  Mostrando 1-50 de 2,543                        │ │
│  └───────────────────────────────────────────────────┘ │
│                                                         │
└─────────────────────────────────────────────────────────┘
```

---

## Vista Detalle - Componentes

```
┌──────────────────────────────────────────────────┐
│          DETALLE DEL LOG #123                    │
│  [← Volver]                                      │
├──────────────────────────────────────────────────┤
│                                                  │
│  ┌─ GENERAL INFORMATION ──────────────────────┐ │
│  │ ID:            123                         │ │
│  │ Transaction:   456                         │ │
│  │ Gateway:       [PayPal]                    │ │
│  │ Event:         webhook.processed           │ │
│  │ Status:        ✅ Success                  │ │
│  │ Date:          2024-01-15 10:25:30        │ │
│  │ Webhook ID:    wh_12345678...             │ │
│  └────────────────────────────────────────────┘ │
│                                                  │
│  ┌─ SECURITY INFORMATION ─────────────────────┐ │
│  │ HTTPS Verified:  ✅ Yes (TLS 1.3)         │ │
│  │ Signature:       ✅ Verified (HMAC-SHA256)│ │
│  │ Timestamp:       ✅ Validated             │ │
│  │ IP Address:      192.168.1.1              │ │
│  │ User Agent:      curl/7.68.0              │ │
│  └────────────────────────────────────────────┘ │
│                                                  │
│  ┌─ REQUEST PAYLOAD ──────────────────────────┐ │
│  │ {                                          │ │
│  │   "id": "456",                            │ │
│  │   "status": "completed",                  │ │
│  │   "amount": 1000,                         │ │
│  │   "currency": "USD"                       │ │
│  │ }                                          │ │
│  └────────────────────────────────────────────┘ │
│                                                  │
│  ┌─ RESPONSE PAYLOAD ─────────────────────────┐ │
│  │ {                                          │ │
│  │   "success": true,                        │ │
│  │   "message": "Payment processed"          │ │
│  │ }                                          │ │
│  └────────────────────────────────────────────┘ │
│                                                  │
└──────────────────────────────────────────────────┘
```

---

## Relaciones de Datos

```
┌──────────────────┐
│  transactions    │
├──────────────────┤
│ id (PK)          │
│ amount           │
│ status           │
│ ...              │
└────────┬─────────┘
         │ 1
         │
         │ Many
         │
         ▼
┌──────────────────┐
│ payment_logs     │
├──────────────────┤
│ id (PK)          │
│ transaction_id   │
│ event_type       │
│ status           │
│ gateway          │
│ ...              │
└──────────────────┘
```

---

## Eventos Registrados

```
Tipo: webhook.*
  ├─ webhook.received        → Webhook recibido
  ├─ webhook.verification    → Se verificó la firma
  └─ webhook.processed       → Se procesó completamente

Tipo: payment.*
  ├─ payment.initiated       → Pago iniciado
  ├─ payment.completed       → Pago completado
  ├─ payment.failed          → Pago fallido
  ├─ payment.refunded        → Reembolso
  └─ payment.expired         → Pago expirado

Tipo: security.*
  ├─ security.signature_verification → Fallo de firma
  └─ security.replay_attempt         → Intento de replay
```

---

## Estados Posibles

```
✅ success       → Operación exitosa
❌ failed        → Error en la operación
⏳ pending       → Esperando procesamiento
🔄 processing    → En proceso
↩️ retry         → Reintentando
```

---

**Última actualización**: 2024  
**Versión**: 1.0
