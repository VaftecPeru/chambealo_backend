<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * IzipayService
 * Izipay payment provider integration
 * Handles form token generation, payment confirmation, and webhook processing
 */
class IzipayService implements PaymentServiceInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $hashKey;
    private string $apiUrl;
    private string $publicKey;

    public function __construct()
    {
        $this->clientId = config('izipay.client_id', '');
        $this->clientSecret = config('izipay.secret', '');
        $this->hashKey = config('izipay.hash_key', '');
        $this->apiUrl = config('izipay.url', 'https://api.izipay.pe');
        $this->publicKey = config('izipay.public_key', '');

        if (!$this->clientId || !$this->clientSecret) {
            throw new \RuntimeException('Izipay configuration missing');
        }
    }

    /**
     * Create a payment session (form token)
     * 
     * @param array $orderData
     * @return array
     * @throws \Exception
     */
    public function createPayment(array $orderData): array
    {
        $this->validateOrderData($orderData);

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->post("{$this->apiUrl}/api-payment/V4/Charge/CreatePayment", [
                    'amount' => (int)($orderData['amount'] * 100), // Convert to cents
                    'currency' => $orderData['currency'] ?? 'PEN',
                    'orderId' => $orderData['order_id'],
                    'customer' => [
                        'email' => $orderData['email'],
                        'reference' => $orderData['user_id'] ?? null,
                    ],
                    'metadata' => [
                        'tenant_id' => $orderData['tenant_id'] ?? null,
                    ],
                ]);

            if ($response->failed()) {
                throw new \Exception('Failed to create Izipay payment: ' . $response->body());
            }

            $data = $response->json();
            return $data['answer'] ?? $data;

        } catch (\Exception $e) {
            Log::error('Izipay createPayment error', [
                'error' => $e->getMessage(),
                'order_id' => $orderData['order_id'] ?? null,
            ]);
            throw $e;
        }
    }

    /**
     * Confirm payment (not typically needed for Izipay form token flow)
     * 
     * @param string $transactionId
     * @param array $additionalData
     * @return array
     */
    public function confirmPayment(string $transactionId, array $additionalData = []): array
    {
        // Izipay confirmation is typically done via webhook
        return ['status' => 'pending', 'message' => 'Confirmation via webhook'];
    }

    /**
     * Verify webhook HMAC SHA256 signature (CRITICAL FOR SECURITY)
     * 
     * @param array $payload
     * @param string $signature
     * @return bool
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        if (!isset($payload['kr-answer'])) {
            return false;
        }

        $krAnswer = $payload['kr-answer'];
        $calculatedHash = hash_hmac('sha256', $krAnswer, $this->hashKey);

        return hash_equals($calculatedHash, $signature);
    }

    /**
     * Process webhook payload
     * 
     * @param array $payload
     * @return array Normalized data
     * @throws \Exception
     */
    public function processWebhookPayload(array $payload): array
    {
        $krAnswer = $payload['kr-answer'] ?? null;

        if (!$krAnswer) {
            throw new \Exception('Missing kr-answer in webhook payload');
        }

        $data = json_decode($krAnswer, true);

        if (!$data) {
            throw new \Exception('Invalid kr-answer JSON');
        }

        return [
            'transaction_id' => $data['transactions'][0]['uuid'] ?? null,
            'order_id' => $data['orderDetails']['orderId'] ?? null,
            'status' => $this->mapIzipayStatus($data['orderStatus'] ?? 'UNKNOWN'),
            'amount' => ($data['amount'] ?? 0) / 100, // Convert from cents
            'currency' => $data['currency'] ?? 'PEN',
            'raw_data' => $data,
        ];
    }

    /**
     * Refund a payment
     * 
     * @param string $transactionId
     * @param float|null $amount
     * @return array
     * @throws \Exception
     */
    public function refundPayment(string $transactionId, ?float $amount = null): array
    {
        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->post("{$this->apiUrl}/api-payment/V4/Charge/Refund", [
                    'uuid' => $transactionId,
                    'amount' => $amount ? (int)($amount * 100) : null,
                ]);

            if ($response->failed()) {
                throw new \Exception('Failed to refund: ' . $response->body());
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Izipay refund error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Map Izipay status to standard status
     */
    private function mapIzipayStatus(string $status): string
    {
        return match ($status) {
            'PAID' => 'paid',
            'PENDING' => 'pending',
            'FAILED', 'REFUSED' => 'failed',
            'CANCELED' => 'cancelled',
            'EXPIRED' => 'expired',
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
