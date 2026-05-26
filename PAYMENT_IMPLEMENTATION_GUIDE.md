# 📚 Guía Práctica - Sistema de Pagos Completo

## 1. Flujo de Pago Completo

### Cliente Frontend
```javascript
// 1. Crear sesión de pago
const response = await fetch('/api/payment/session', {
    method: 'POST',
    headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        gateway: 'izipay', // o 'mercadopago', 'paypal'
        order_id: 1,
        amount: 99.99,
        currency: 'USD',
        email: 'user@example.com'
    })
});

const data = await response.json();

// 2. Redirigir a formulario de pago (según gateway)
if (data.data.form_token) {
    // Izipay - mostrar formulario
    showIzipayForm(data.data.form_token);
} else if (data.data.init_point) {
    // MercadoPago - redirigir a checkout
    window.location.href = data.data.init_point;
} else if (data.data.approve_url) {
    // PayPal - redirigir a aprobación
    window.location.href = data.data.approve_url;
}

// 3. Confirmar pago después de que el usuario regresa
const confirmResponse = await fetch('/api/payment/confirm', {
    method: 'POST',
    headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        gateway: 'izipay',
        payment_id: data.data.gateway_id
    })
});

const status = await confirmResponse.json();
console.log('Status:', status.status); // 'completed', 'pending', 'failed'
```

## 2. Configurar Webhooks en Gateways

### Izipay
```
Webhook URL: https://yourdomain.com/api/payment/webhook/izipay
Headers esperado: X-Izipay-Signature

Ejemplo de evento:
POST /api/payment/webhook/izipay
Header: X-Izipay-Signature: <hmac-sha256>
Body: {
    "kr-answer": "json_encoded_payment_data",
    "krSignature": "signature_value"
}
```

### MercadoPago
```
Webhook URL: https://yourdomain.com/api/payment/webhook/mercadopago
Headers esperado: x-signature

Ejemplo de evento:
POST /api/payment/webhook/mercadopago
Header: x-signature: timestamp|signature
Body: {
    "action": "payment.updated",
    "data": {
        "id": "123456789"
    }
}
```

### PayPal
```
Webhook URL: https://yourdomain.com/api/payment/webhook/paypal

Ejemplo de evento:
POST /api/payment/webhook/paypal
Body: {
    "event_type": "PAYMENT.CAPTURE.COMPLETED",
    "resource": {
        "id": "capture_id",
        "status": "COMPLETED",
        "amount": {
            "value": "99.99",
            "currency_code": "USD"
        }
    }
}
```

## 3. Clase de Pagos para Usar en la Aplicación

```php
<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class PaymentProcessor
{
    protected $paymentFactory;
    protected $paymentRepository;

    public function __construct()
    {
        $this->paymentFactory = new PaymentFactory();
        $this->paymentRepository = app('App\Repositories\PaymentRepository');
    }

    /**
     * Procesar pago para una orden
     */
    public function processPayment(Order $order, string $gateway, array $data)
    {
        try {
            $gatewayService = PaymentFactory::make($gateway);
            
            $result = $gatewayService->createPayment([
                'order_id' => $order->id,
                'amount' => $order->total,
                'currency' => $order->currency ?? 'USD',
                'email' => $data['email'],
                'description' => "Orden #{$order->order_id}",
                'user_id' => auth()->id(),
                'tenant_id' => $order->tenant_id,
            ]);

            $payment = $this->paymentRepository->createPayment([
                'order_id' => $order->id,
                'gateway' => $gateway,
                'payment_id' => $result['id'] ?? $result['payment_id'] ?? null,
                'amount' => $order->total,
                'currency' => 'USD',
                'email' => $data['email'],
                'user_id' => auth()->id(),
                'raw_response' => $result,
            ]);

            return ['success' => true, 'payment' => $payment, 'redirect' => $result];

        } catch (\Exception $e) {
            Log::error('Payment processing failed', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
                'gateway' => $gateway,
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Confirmar pago manualmente
     */
    public function confirmPayment(string $paymentId, string $gateway)
    {
        try {
            $gatewayService = PaymentFactory::make($gateway);
            $result = $gatewayService->confirmPayment($paymentId);
            
            $payment = $this->paymentRepository->updatePaymentStatus(
                $paymentId,
                strtolower($result['status']),
                $result
            );

            return ['success' => true, 'payment' => $payment];

        } catch (\Exception $e) {
            Log::error('Payment confirmation failed', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId,
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
```

## 4. Verificar Estado de Pago

```php
use App\Repositories\PaymentRepository;

$paymentRepo = new PaymentRepository();

// Obtener pago por ID externo
$payment = $paymentRepo->getPaymentByPaymentId('external_payment_id');

// Obtener transacciones para un pago
$transactions = $paymentRepo->getTransactions($payment->id);

// Verificar si fue completado
if ($payment->status === 'completed') {
    echo "¡Pago confirmado!";
    foreach ($transactions as $transaction) {
        echo "Transacción: {$transaction->transaction_id}";
    }
}
```

## 5. Monitorear Webhooks

Revisar tabla `transactions` para auditoría:

```sql
-- Pagos completados por gateway
SELECT gateway, COUNT(*) as total, SUM(amount) as monto_total
FROM transactions
WHERE status = 'success'
GROUP BY gateway;

-- Pagos fallidos
SELECT * FROM transactions WHERE status = 'failed' ORDER BY created_at DESC;

-- Última transacción por usuario
SELECT u.email, t.amount, t.gateway, t.created_at
FROM transactions t
JOIN payments p ON p.id = t.payment_id
JOIN users u ON u.user_id = p.user_id
WHERE t.status = 'success'
ORDER BY t.created_at DESC
LIMIT 10;
```

## 6. Testing de Webhooks Localmente

### Usando Postman

1. **Crear Environment Variable**: `{{token}}` = webhook_secret

2. **Test Izipay**:
```
POST http://localhost:8000/api/payment/webhook/izipay
Header: X-Izipay-Signature = {{signature}}
Body (raw JSON):
{
    "kr-answer": "{\"orderStatus\": \"PAID\", \"amount\": 9999, \"currency\": \"USD\", \"transactions\": [{\"uuid\": \"123\"}]}"
}
```

3. **Test MercadoPago**:
```
POST http://localhost:8000/api/payment/webhook/mercadopago
Header: x-signature = 1234567890|abcdef123456
Body (raw JSON):
{
    "action": "payment.updated",
    "data": {
        "id": "123456789"
    }
}
```

### Usando Laravel Tinker

```php
php artisan tinker

$gateway = \App\Services\PaymentFactory::make('izipay');
$payload = ['kr-answer' => '...'];
$signature = 'test_signature';

$isValid = $gateway->verifyWebhookSignature($payload, $signature);
dd($isValid);
```

## 7. Eventos Personalizados

### Escuchar Evento de Pago Confirmado

```php
// En app/Listeners/

class YourCustomListener
{
    public function handle(PaymentConfirmed $event)
    {
        $payment = $event->payment;
        $order = $payment->order;
        
        // Tu lógica personalizada
        Log::info("Payment confirmed for order {$order->id}");
    }
}

// Registrar en EventServiceProvider
protected $listen = [
    PaymentConfirmed::class => [
        GenerateInvoiceAndSendEmail::class,
        YourCustomListener::class,
    ],
];
```

## 8. Refundar Pagos

```php
$gateway = \App\Services\PaymentFactory::make('izipay');

$result = $gateway->refundPayment('payment_id', 50.00); // Reembolso parcial

if ($result['success']) {
    Log::info("Refund successful: {$result['refund_id']}");
}
```

## 9. Errores Comunes

### "Invalid signature"
- Verificar que webhook_secret coincida
- Verificar formato de signature (especialmente MercadoPago: "timestamp|signature")
- Verificar que se envíe la signature completa

### "Payment not found"
- Asegurar que payment_id en webhook coincida con database
- Verificar rutas y extraer correctamente payment_id del payload

### "Configuration missing"
- Verificar que .env tiene todas las variables requeridas
- Usar `php artisan config:cache` después de cambiar .env

### "Connection timeout"
- Verificar credentials del gateway
- Asegurar HTTPS en producción
- Verificar firewall/VPN permite conexión externa

## 10. Debugging

Habilitar logs de payment:

```php
// En .env
LOG_CHANNEL=single
LOG_LEVEL=debug

// En logs se verá:
[2026-05-25 13:22:00] local.DEBUG: Payment session created {"payment_id":1,"gateway":"izipay"...}
[2026-05-25 13:23:00] local.INFO: Payment confirmed {"payment_id":"xxx","status":"completed"...}
[2026-05-25 13:24:00] local.WARNING: Webhook signature validation failed {"gateway":"izipay"...}
```

Revisar logs:
```bash
tail -f storage/logs/laravel.log | grep -i payment
```
