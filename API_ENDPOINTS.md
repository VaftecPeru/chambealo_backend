# 📡 API ENDPOINTS - Admin Payment Logs

## Base URL
```
http://localhost:8000/api
```

## Authentication
All endpoints require:
```
Header: Authorization: Bearer {JWT_TOKEN}
Header: Accept: application/json
```

---

## 📋 Endpoints

### 1. List Payment Logs (with Filters & Pagination)
```
GET /admin/payment-logs
```

**Query Parameters:**
| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `gateway` | string | Filter by gateway | `paypal`, `izipay`, `mercadopago` |
| `event_type` | string | Filter by event | `webhook.received`, `payment.completed` |
| `status` | string | Filter by status | `success`, `failed`, `pending` |
| `date_from` | date | Filter from date | `2026-05-20` |
| `date_to` | date | Filter to date | `2026-05-21` |
| `search` | string | Search by webhook_id or ID | `abc123` |
| `per_page` | int | Items per page | `50` (default) |
| `page` | int | Page number | `1` |

**Example Request:**
```bash
curl -X GET "http://localhost:8000/api/admin/payment-logs?gateway=paypal&status=failed&date_from=2026-05-20" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "transaction_id": null,
      "event_type": "webhook.received",
      "status": "success",
      "gateway": "paypal",
      "webhook_id": "8XZ6F7Q9",
      "signature_verified": true,
      "https_verified": true,
      "tls_version": "TLS v1.2",
      "ip_address": "192.168.1.100",
      "attempt": 1,
      "created_at": "2026-05-21T22:28:00Z",
      "updated_at": "2026-05-21T22:28:00Z"
    }
  ],
  "pagination": {
    "current_page": 1,
    "per_page": 50,
    "total": 100,
    "last_page": 2
  },
  "stats": {
    "total_today": 45,
    "failed_today": 2,
    "security_events": 1,
    "by_gateway": [
      {"gateway": "paypal", "total": 20},
      {"gateway": "izipay", "total": 15},
      {"gateway": "mercadopago", "total": 10}
    ]
  }
}
```

---

### 2. Get Single Payment Log
```
GET /admin/payment-logs/{id}
```

**Parameters:**
- `id` (required) - Payment log ID

**Example Request:**
```bash
curl -X GET "http://localhost:8000/api/admin/payment-logs/123" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

**Response:**
```json
{
  "data": {
    "id": 123,
    "transaction_id": 45,
    "event_type": "webhook.processed",
    "status": "success",
    "gateway": "paypal",
    "webhook_id": "8XZ6F7Q9",
    "request_payload": {
      "event_type": "PAYMENT.CAPTURE.COMPLETED",
      "resource": {
        "id": "8XZ6F7Q9",
        "status": "COMPLETED"
      }
    },
    "response_payload": {
      "status": "processed"
    },
    "headers": {
      "content-type": "application/json",
      "user-agent": "PayPal-IPN"
    },
    "signature_verified": true,
    "signature_method": "PKI",
    "timestamp_validated": true,
    "https_verified": true,
    "tls_version": "TLS v1.2",
    "ip_address": "192.168.1.100",
    "user_agent": "PayPal-IPN/1.0",
    "attempt": 1,
    "created_at": "2026-05-21T22:28:00Z",
    "updated_at": "2026-05-21T22:28:00Z",
    "transaction": {
      "id": 45,
      "order_id": "ORDER123",
      "amount": 99.99
    }
  }
}
```

---

### 3. Export Payment Logs
```
GET /admin/payment-logs/export/logs
```

**Query Parameters:**
| Parameter | Type | Description |
|-----------|------|-------------|
| `format` | string | `json` or `csv` |
| `gateway` | string | Optional: filter by gateway |
| `date_from` | date | Optional: from date |
| `date_to` | date | Optional: to date |

**Example Request (CSV):**
```bash
curl -X GET "http://localhost:8000/api/admin/payment-logs/export/logs?format=csv&date_from=2026-05-20" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -o payment-logs.csv
```

**CSV Output:**
```csv
ID,Transaction ID,Event Type,Status,Gateway,Webhook ID,Signature Verified,HTTPS Verified,IP Address,Error Message,Created At
1,null,webhook.received,success,paypal,8XZ6F7Q9,Yes,Yes,192.168.1.100,,2026-05-21 22:28:00
2,45,webhook.processed,success,paypal,8XZ6F7Q9,Yes,Yes,192.168.1.100,,2026-05-21 22:28:05
```

**Example Request (JSON):**
```bash
curl -X GET "http://localhost:8000/api/admin/payment-logs/export/logs?format=json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

---

### 4. Get Security Events Summary
```
GET /admin/payment-logs/security/summary
```

**Query Parameters:**
| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `gateway` | string | Optional filter | `paypal` |
| `days` | int | Optional days back | `7` |

**Example Request:**
```bash
curl -X GET "http://localhost:8000/api/admin/payment-logs/security/summary?days=7&gateway=izipay" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

**Response:**
```json
{
  "data": {
    "total_security_events": 5,
    "by_type": {
      "security.replay_attempt": 2,
      "security.signature_verification": 2,
      "security.rate_limit": 1
    },
    "by_gateway": {
      "izipay": 3,
      "mercadopago": 2
    },
    "by_status": {
      "failed": 5
    },
    "recent_events": [
      {
        "id": 234,
        "event_type": "security.replay_attempt",
        "gateway": "izipay",
        "error_message": "Duplicate webhook detected",
        "ip_address": "192.168.1.100",
        "created_at": "2026-05-21T22:15:00Z"
      }
    ]
  }
}
```

---

### 5. Get Statistics Dashboard
```
GET /admin/payment-logs/stats/dashboard
```

**Query Parameters:**
| Parameter | Type | Description | Example |
|-----------|------|-------------|---------|
| `days` | int | Optional days back | `30` (default) |

**Example Request:**
```bash
curl -X GET "http://localhost:8000/api/admin/payment-logs/stats/dashboard?days=30" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

**Response:**
```json
{
  "data": {
    "by_gateway": [
      {
        "gateway": "paypal",
        "total": 150,
        "failed": 2
      },
      {
        "gateway": "izipay",
        "total": 120,
        "failed": 1
      },
      {
        "gateway": "mercadopago",
        "total": 100,
        "failed": 3
      }
    ],
    "by_event_type": [
      {
        "event_type": "webhook.received",
        "total": 200,
        "failed": 0
      },
      {
        "event_type": "webhook.verification",
        "total": 200,
        "failed": 3
      },
      {
        "event_type": "webhook.processed",
        "total": 200,
        "failed": 3
      }
    ],
    "daily_stats": [
      {
        "date": "2026-05-21",
        "total": 45,
        "failed": 2
      },
      {
        "date": "2026-05-20",
        "total": 38,
        "failed": 1
      }
    ],
    "top_issue_ips": [
      {
        "ip_address": "192.168.1.100",
        "total": 3
      },
      {
        "ip_address": "192.168.1.101",
        "total": 2
      }
    ]
  }
}
```

---

## 🔐 Authentication & Authorization

All endpoints require:
1. **Valid JWT Token** in `Authorization: Bearer {token}` header
2. **Admin Role**: User must have `role = 'admin'` or `role = 'super_admin'`
3. **Active Status**: User must have `status = 'active'`

**Error Response (401):**
```json
{
  "error": "Unauthorized"
}
```

**Error Response (403):**
```json
{
  "error": "Acceso no autorizado. Se requieren permisos de administrador."
}
```

---

## 📊 Filtering Examples

### Find all failed PayPal webhooks in last 7 days
```bash
curl -X GET "http://localhost:8000/api/admin/payment-logs?gateway=paypal&status=failed&date_from=2026-05-14" \
  -H "Authorization: Bearer TOKEN"
```

### Find security events for specific gateway
```bash
curl -X GET "http://localhost:8000/api/admin/payment-logs?gateway=izipay&event_type=security.replay_attempt" \
  -H "Authorization: Bearer TOKEN"
```

### Find by transaction ID
```bash
curl -X GET "http://localhost:8000/api/admin/payment-logs?search=45" \
  -H "Authorization: Bearer TOKEN"
```

### Export failed transactions for audit
```bash
curl -X GET "http://localhost:8000/api/admin/payment-logs/export/logs?format=csv&status=failed&date_from=2026-05-01&date_to=2026-05-31" \
  -H "Authorization: Bearer TOKEN" \
  -o failed-transactions-audit.csv
```

---

## 🔍 Pagination

All list endpoints support pagination:
```bash
# First page (default 50 items)
GET /admin/payment-logs

# Second page with 100 items
GET /admin/payment-logs?page=2&per_page=100

# Custom page size
GET /admin/payment-logs?page=1&per_page=25
```

Response includes pagination metadata:
```json
{
  "pagination": {
    "current_page": 1,
    "per_page": 50,
    "total": 500,
    "last_page": 10
  }
}
```

---

## 📈 Event Type Reference

| Event Type | Description |
|-----------|-------------|
| `webhook.received` | Webhook received |
| `webhook.verification` | Webhook signature verified |
| `webhook.processed` | Webhook processed successfully |
| `webhook.error` | Error processing webhook |
| `payment.initiated` | Payment started |
| `payment.completed` | Payment completed |
| `payment.failed` | Payment failed |
| `security.event` | Generic security event |
| `security.replay_attempt` | Replay attack detected |
| `security.signature_verification` | Signature verification failed |

---

## 🛠️ Common Use Cases

### Monitor payment failures
```bash
GET /admin/payment-logs?status=failed&date_from=2026-05-21
```

### Check security events
```bash
GET /admin/payment-logs/security/summary?days=7
```

### Get dashboard metrics
```bash
GET /admin/payment-logs/stats/dashboard?days=30
```

### Export monthly audit
```bash
GET /admin/payment-logs/export/logs?format=csv&date_from=2026-05-01&date_to=2026-05-31
```

### Find problematic webhooks
```bash
GET /admin/payment-logs?attempt=2
```

---

**Last Updated**: 2026-05-21  
**Version**: 1.0.0
