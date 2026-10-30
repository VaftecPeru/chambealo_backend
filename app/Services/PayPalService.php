<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPalService
 * PayPal payment provider integration
 * Handles order creation, payment capture, and webhook processing
 */
class PayPalService implements PaymentServiceInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $mode;
    private string $apiUrl;
    private string $webhookId;

    public function __construct()
    {
        $this->clientId = config('payment.paypal.client_id', '');
        $this->clientSecret = config('payment.paypal.client_secret', '');
        $this->mode = config('payment.paypal.environment', 'sandbox');
        $this->webhookId = config('payment.paypal.webhook_id', '');

        $this->apiUrl = $this->mode === 'production'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';

        if (!$this->clientId || !$this->clientSecret) {
            throw new \RuntimeException('PayPal configuration missing');
        }
    }

    /**
     * Create a PayPal order
     * 
     * @param array $orderData
     * @return array
     * @throws \Exception
     */
public function createPayment(array $orderData): array
{
    $this->validateOrderData($orderData);

    try {
        $reservationId = $orderData['reservation_id'] ?? null;

        if (!is_int($reservationId) || $reservationId <= 0) {
            throw new \InvalidArgumentException(
                'Falta el identificador de reserva PayPal.'
            );
        }

        // Identificador estable para evitar crear
        // varias ordenes por la misma reserva.
        $requestId = hash(
            'sha256',
            'chambealo-paypal-create-' . $reservationId
        );

        $accessToken = $this->getAccessToken();

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'PayPal-Request-Id' => $requestId,
            ])
            ->post("{$this->apiUrl}/v2/checkout/orders", [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $orderData['order_id'],
                    'amount' => [
                        'currency_code' => $orderData['currency'] ?? 'USD',
                        'value' => number_format(
                            $orderData['amount'],
                            2,
                            '.',
                            ''
                        ),
                    ],
                    'custom_id' => $orderData['tenant_id'] ?? null,
                ]],
                'application_context' => [
                    'return_url' => $orderData['return_url']
                        ?? config('app.url') . '/payment/success',
                    'cancel_url' => $orderData['cancel_url']
                        ?? config('app.url') . '/payment/cancel',
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'No se pudo crear la orden de PayPal.'
            );
        }

        $result = $response->json();

        $approveLink = collect($result['links'] ?? [])
            ->first(fn ($link) => in_array(
                $link['rel'] ?? '',
                ['approve', 'payer-action'],
                true
            ));

        $result['approve_url'] = $approveLink['href'] ?? null;

        return $result;

    } catch (\Exception $e) {
        Log::error('PayPal createPayment error', [
            'error' => $e->getMessage(),
            'order_id' => $orderData['order_id'] ?? null,
        ]);

        throw $e;
    }
}
    /**
     * Capture a PayPal order
     * 
     * @param string $orderId PayPal order ID
     * @param array $additionalData
     * @return array
     * @throws \Exception
     */
    /**
     * Consultar una orden de PayPal sin realizar cobros.
     */
    public function getOrder(string $orderId): array
    {
        $accessToken = $this->getAccessToken();

        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->get("{$this->apiUrl}/v2/checkout/orders/{$orderId}");

        if ($response->failed()) {
            throw new \RuntimeException(
                'No se pudo consultar la orden de PayPal.'
            );
        }

        return $response->json();
    }

    /**
     * Capturar una orden aprobada, evitando capturas repetidas.
     */
    public function confirmPayment(
        string $orderId,
        array $additionalData = []
    ): array {
        $order = $this->getOrder($orderId);

        if (($order['id'] ?? null) !== $orderId) {
            throw new \RuntimeException(
                'El identificador de PayPal no coincide.'
            );
        }

        $status = $order['status'] ?? null;

        // Si ya se capturo, devolver los datos existentes.
        if ($status === 'COMPLETED') {
            return $order;
        }

        // No capturar ordenes sin aprobacion del comprador.
        if ($status !== 'APPROVED') {
            throw new \RuntimeException(
                'La orden de PayPal aun no esta aprobada.'
            );
        }

        $accessToken = $this->getAccessToken();

        // Identificador estable para reintentos de esta captura.
        $requestId = hash(
            'sha256',
            'chambealo-paypal-capture-' . $orderId
        );

        $response = Http::withToken($accessToken)
            ->withHeaders([
                'PayPal-Request-Id' => $requestId,
            ])
            ->timeout(20)
            ->post(
                "{$this->apiUrl}/v2/checkout/orders/{$orderId}/capture",
                []
            );

        if ($response->failed()) {
            throw new \RuntimeException(
                'No se pudo completar la captura de PayPal.'
            );
        }
        return $response->json();
    }
    /**
     * Verify webhook signature
     * 
     * @param array $payload
     * @param string $signature
     * @return bool
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        // La interfaz antigua no recibe los encabezados obligatorios de PayPal.
        // Fallar cerrado: verificar usando verifyWebhookRequest().
        return false;
    }

    /** Verificar el webhook con la API oficial de PayPal antes de procesarlo. */
    public function verifyWebhookRequest(\Illuminate\Http\Request $request): bool
    {
        $required = [
            'transmission_id' => $request->header('PayPal-Transmission-Id'),
            'transmission_time' => $request->header('PayPal-Transmission-Time'),
            'cert_url' => $request->header('PayPal-Cert-Url'),
            'auth_algo' => $request->header('PayPal-Auth-Algo'),
            'transmission_sig' => $request->header('PayPal-Transmission-Sig'),
            'webhook_id' => $this->webhookId,
        ];

        if (in_array(null, $required, true) || in_array('', $required, true)) {
            Log::warning('PayPal webhook: falta ID o encabezados de verificación');
            return false;
        }

        try {
            $response = Http::withToken($this->getAccessToken())
                ->timeout(10)
                ->post("{$this->apiUrl}/v1/notifications/verify-webhook-signature", [
                    ...$required,
                    'webhook_event' => $request->all(),
                ]);

            return $response->successful()
                && $response->json('verification_status') === 'SUCCESS';
        } catch (\Throwable $e) {
            Log::warning('PayPal webhook: no se pudo validar la firma', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Process webhook payload
     * 
     * @param array $payload
     * @return array
     * @throws \Exception
     */
    public function processWebhookPayload(array $payload): array
    {
        $eventType = $payload['event_type'] ?? null;

        return match ($eventType) {
            'CHECKOUT.ORDER.APPROVED' => $this->processOrderApproved($payload),
            'PAYMENT.CAPTURE.COMPLETED' => $this->processCaptureCompleted($payload),
            'PAYMENT.CAPTURE.DENIED' => $this->processCaptureDenied($payload),
            'PAYMENT.CAPTURE.REFUNDED' => $this->processCaptureRefunded($payload),
            default => throw new \Exception("Unknown event type: {$eventType}"),
        };
    }

    /**
     * Refund a PayPal payment
     * 
     * @param string $captureId
     * @param float|null $amount
     * @return array
     * @throws \Exception
     */
    public function refundPayment(string $captureId, ?float $amount = null): array
    {
        try {
            $accessToken = $this->getAccessToken();

            $data = $amount ? ['amount' => ['currency_code' => 'USD', 'value' => number_format($amount, 2, '.', '')]] : [];

            $response = Http::withToken($accessToken)
                ->post("{$this->apiUrl}/v2/payments/captures/{$captureId}/refund", $data);

            if ($response->failed()) {
                throw new \Exception('Failed to refund: ' . $response->body());
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('PayPal refund error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get PayPal access token
     */
    private function getAccessToken(): string
    {
        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post("{$this->apiUrl}/v1/oauth2/token", [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to get PayPal access token');
        }

        return $response->json()['access_token'];
    }

    /**
     * Validate required order data
     */
    private function validateOrderData(array $orderData): void
    {
        $required = ['amount', 'order_id'];
        foreach ($required as $field) {
            if (!isset($orderData[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        if ($orderData['amount'] <= 0) {
            throw new \Exception('Amount must be greater than 0');
        }
    }

    /**
     * Process CHECKOUT.ORDER.APPROVED event
     */
    private function processOrderApproved(array $payload): array
    {
        $resource = $payload['resource'];
        return [
            'order_id' => $resource['id'],
            'status' => 'approved',
            'payer_email' => $resource['payer']['email_address'] ?? null,
            'amount' => $resource['purchase_units'][0]['amount']['value'] ?? 0,
        ];
    }

    /**
     * Process PAYMENT.CAPTURE.COMPLETED event
     */
    private function processCaptureCompleted(array $payload): array
    {
        $resource = $payload['resource'];
        return [
            'transaction_id' => $resource['id'],
            'status' => 'paid',
            'amount' => $resource['amount']['value'] ?? 0,
            'payer_email' => $resource['supplementary_data']['related_ids']['order_id'] ?? null,
        ];
    }

    /**
     * Process PAYMENT.CAPTURE.DENIED event
     */
    private function processCaptureDenied(array $payload): array
    {
        $resource = $payload['resource'];
        return [
            'transaction_id' => $resource['id'],
            'status' => 'failed',
            'error' => $resource['status_details']['reason'] ?? 'Payment denied',
        ];
    }

    /**
     * Process PAYMENT.CAPTURE.REFUNDED event
     */
    private function processCaptureRefunded(array $payload): array
    {
        $resource = $payload['resource'];
        return [
            'transaction_id' => $resource['id'],
            'status' => 'refunded',
            'amount' => $resource['amount']['value'] ?? 0,
        ];
    }
}
