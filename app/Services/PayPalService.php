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
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->post("{$this->apiUrl}/v2/checkout/orders", [
                    'intent' => 'CAPTURE',
                    'purchase_units' => [[
                        'reference_id' => $orderData['order_id'],
                        'amount' => [
                            'currency_code' => $orderData['currency'] ?? 'USD',
                            'value' => number_format($orderData['amount'], 2, '.', ''),
                        ],
                        'custom_id' => $orderData['tenant_id'] ?? null,
                    ]],
                    'application_context' => [
                        'return_url' => $orderData['return_url'] ?? config('app.url') . '/payment/success',
                        'cancel_url' => $orderData['cancel_url'] ?? config('app.url') . '/payment/cancel',
                    ],
                ]);

            if ($response->failed()) {
                throw new \Exception('Failed to create PayPal order: ' . $response->body());
            }

            return $response->json();

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
    public function confirmPayment(string $orderId, array $additionalData = []): array
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->post("{$this->apiUrl}/v2/checkout/orders/{$orderId}/capture");

            if ($response->failed()) {
                throw new \Exception('Failed to capture PayPal order: ' . $response->body());
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('PayPal confirmPayment error', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
            ]);
            throw $e;
        }
    }

    /**
     * Verify webhook signature
     * PayPal utiliza verificación vía API call
     * 
     * @param array $payload
     * @param string $signature
     * @return bool
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool
    {
        try {
            $transmissionId = request()->header('PAYPAL-TRANSMISSION-ID');
            $transmissionTime = request()->header('PAYPAL-TRANSMISSION-TIME');
            $certUrl = request()->header('PAYPAL-CERT-URL');
            $transmissionSig = request()->header('PAYPAL-TRANSMISSION-SIG');

            if (!$transmissionId || !$transmissionTime || !$certUrl || !$transmissionSig) {
                Log::warning('PayPal webhook: missing required headers for verification');
                return false;
            }

            // Validar timestamp (anti-replay)
            $transmissionTimeObj = \DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $transmissionTime);
            if (!$transmissionTimeObj) {
                Log::warning('PayPal webhook: invalid transmission time format');
                return false;
            }

            $now = new \DateTime('now', new \DateTimeZone('UTC'));
            $diff = $now->getTimestamp() - $transmissionTimeObj->getTimestamp();
            $maxDiffSeconds = 300; // 5 minutos

            if (abs($diff) > $maxDiffSeconds) {
                Log::warning('PayPal webhook: timestamp outside valid window', [
                    'transmission_time' => $transmissionTime,
                    'current_time' => $now->format('Y-m-d\TH:i:s\Z'),
                    'diff_seconds' => $diff,
                ]);
                return false;
            }

            // Construir mensaje a verificar
            $webhookId = config('payment.paypal.webhook_id');
            $expectedSig = hash_hmac(
                'sha256',
                "{$transmissionId}|{$transmissionTime}|{$webhookId}|" . $this->hashWebhookBody($payload),
                $this->clientSecret,
                true
            );

            // Comparar firmas con hash_equals para prevenir timing attacks
            $isValid = hash_equals(
                base64_encode($expectedSig),
                $transmissionSig
            );

            if (!$isValid) {
                Log::warning('PayPal webhook: signature verification failed');
            }

            return $isValid;

        } catch (\Exception $e) {
            Log::error('PayPal webhook signature verification error', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Hash del cuerpo del webhook para PayPal
     * 
     * @param array $payload
     * @return string
     */
    private function hashWebhookBody(array $payload): string
    {
        return hash('sha256', json_encode($payload));
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
