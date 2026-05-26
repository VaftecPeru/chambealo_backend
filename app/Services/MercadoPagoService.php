<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MercadoPagoService
 * MercadoPago payment provider integration
 * Handles preference creation, payment confirmation, and webhook processing with signature validation
 */
class MercadoPagoService implements PaymentServiceInterface
{
    private string $accessToken;
    private string $webhookSecret;
    private string $apiUrl = 'https://api.mercadopago.com/v1';

    public function __construct()
    {
        $this->accessToken = config('payment.mercadopago.access_token', '');
        $this->webhookSecret = config('payment.mercadopago.webhook_secret', '');

        if (!$this->accessToken) {
            throw new \RuntimeException('MercadoPago configuration missing');
        }
    }

    /**
     * Create a payment preference (form token equivalent)
     * 
     * @param array $orderData
     * @return array
     * @throws \Exception
     */
    public function createPayment(array $orderData): array
    {
        $this->validateOrderData($orderData);

        try {
            $payload = [
                'items' => [[
                    'id' => $orderData['order_id'],
                    'title' => $orderData['description'] ?? 'Pago de orden #' . $orderData['order_id'],
                    'quantity' => 1,
                    'unit_price' => (float) $orderData['amount'],
                    'currency_id' => $orderData['currency'] ?? 'USD',
                ]],
                'payer' => ['email' => $orderData['email'] ?? null],
                'back_urls' => [
                    'success' => $orderData['return_url'] ?? config('app.url') . '/payment/success',
                    'failure' => $orderData['cancel_url'] ?? config('app.url') . '/payment/cancel',
                    'pending' => $orderData['cancel_url'] ?? config('app.url') . '/payment/pending',
                ],
                'notification_url' => $orderData['webhook_url'] ?? config('app.url') . '/api/v1/mercadopago/webhook',
                'auto_return' => 'approved',
                'external_reference' => (string) $orderData['order_id'],
                'metadata' => [
                    'tenant_id' => $orderData['tenant_id'] ?? null,
                    'user_id' => $orderData['user_id'] ?? null,
                ],
            ];

            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$this->apiUrl}/preferences", $payload);

            if ($response->failed()) {
                throw new \Exception('Failed to create MercadoPago preference: ' . $response->body());
            }

            $data = $response->json();
            return [
                'id' => $data['id'],
                'init_point' => $data['init_point'] ?? null,
                'sandbox_init_point' => $data['sandbox_init_point'] ?? null,
            ];

        } catch (\Exception $e) {
            Log::error('MercadoPago createPayment error', [
                'error' => $e->getMessage(),
                'order_id' => $orderData['order_id'] ?? null,
            ]);
            throw $e;
        }
    }

    /**
     * Confirm/get payment status
     * 
     * @param string $paymentId MercadoPago payment ID
     * @param array $additionalData
     * @return array
     * @throws \Exception
     */
    public function confirmPayment(string $paymentId, array $additionalData = []): array
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->get("{$this->apiUrl}/payments/{$paymentId}");

            if ($response->failed()) {
                throw new \Exception('Failed to get payment status: ' . $response->body());
            }

            $data = $response->json();

            return [
                'id' => $data['id'],
                'status' => $this->mapMercadoPagoStatus($data['status'] ?? 'unknown'),
                'status_detail' => $data['status_detail'] ?? null,
                'transaction_amount' => $data['transaction_amount'] ?? 0,
                'currency_id' => $data['currency_id'] ?? 'USD',
                'payer' => $data['payer'] ?? [],
                'raw_data' => $data,
            ];

        } catch (\Exception $e) {
            Log::error('MercadoPago confirmPayment error', [
                'error' => $e->getMessage(),
                'payment_id' => $paymentId,
            ]);
            throw $e;
        }
    }

    /**
     * Verify webhook HMAC SHA256 signature (CRITICAL FOR SECURITY)
     * MercadoPago format: "timestamp|signature"
     * 
     * @param array $payload
     * @param string $signature Format: "timestamp|signature"
     * @return bool
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        if (!$signature || !isset($payload['data'])) {
            Log::warning('MercadoPago webhook: missing signature or data payload');
            return false;
        }

        // Parse timestamp and received signature
        $parts = explode('|', $signature);
        if (count($parts) !== 2) {
            Log::warning('MercadoPago webhook: invalid signature format');
            return false;
        }

        list($timestamp, $receivedSignature) = $parts;

        // Calculate expected signature: HMAC-SHA256("timestamp\npayload_json", webhook_secret)
        $messageToSign = "{$timestamp}\n" . json_encode($payload['data']);
        $expectedSignature = hash_hmac('sha256', $messageToSign, $this->webhookSecret);

        // Use hash_equals to prevent timing attacks
        $isValid = hash_equals($expectedSignature, $receivedSignature);

        if (!$isValid) {
            Log::warning('MercadoPago webhook: signature verification failed', [
                'expected' => substr($expectedSignature, 0, 10) . '...',
                'received' => substr($receivedSignature, 0, 10) . '...',
            ]);
        }

        return $isValid;
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
        $action = $payload['action'] ?? null;
        $data = $payload['data'] ?? [];
        $dataId = $data['id'] ?? null;

        if (!$dataId) {
            throw new \Exception('Missing data.id in webhook payload');
        }

        // Get payment details to determine transaction status
        $paymentDetails = $this->confirmPayment($dataId);

        return [
            'action' => $action,
            'payment_id' => $dataId,
            'transaction_id' => $dataId,
            'status' => $paymentDetails['status'],
            'amount' => $paymentDetails['transaction_amount'] ?? 0,
            'currency' => $paymentDetails['currency_id'] ?? 'USD',
            'external_reference' => $paymentDetails['external_reference'] ?? null,
            'raw_data' => $paymentDetails['raw_data'],
        ];
    }

    /**
     * Refund a payment
     * 
     * @param string $paymentId
     * @param float|null $amount
     * @return array
     * @throws \Exception
     */
    public function refundPayment(string $paymentId, ?float $amount = null): array
    {
        try {
            $payload = [];
            if ($amount) {
                $payload['amount'] = $amount;
            }

            $response = Http::withToken($this->accessToken)
                ->post("{$this->apiUrl}/payments/{$paymentId}/refunds", $payload);

            if ($response->failed()) {
                throw new \Exception('Failed to refund: ' . $response->body());
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('MercadoPago refund error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Map MercadoPago status to standard status
     */
    private function mapMercadoPagoStatus(string $status): string
    {
        return match ($status) {
            'approved' => 'completed',
            'pending' => 'pending',
            'rejected', 'cancelled' => 'failed',
            'refunded' => 'refunded',
            'in_process' => 'pending',
            'in_mediation' => 'pending',
            default => 'unknown',
        };
    }

    /**
     * Validate required order data
     * 
     * @param array $orderData
     * @throws \Exception
     */
    private function validateOrderData(array $orderData): void
    {
        $required = ['amount', 'email', 'order_id'];
        foreach ($required as $field) {
            if (!isset($orderData[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        if ($orderData['amount'] <= 0) {
            throw new \Exception('Amount must be greater than 0');
        }

        if (!filter_var($orderData['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Invalid email address');
        }
    }
}
