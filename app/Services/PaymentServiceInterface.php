<?php

namespace App\Services;

/**
 * PaymentServiceInterface
 * Contract for payment provider integrations (Izipay, PayPal, MercadoPago)
 */
interface PaymentServiceInterface
{
    /**
     * Create a payment session/form token
     * 
     * @param array $orderData Order details (amount, email, order_id, customer_info)
     * @return array Response from payment provider
     * @throws \Exception
     */
    public function createPayment(array $orderData): array;

    /**
     * Confirm/capture a payment
     * 
     * @param string $transactionId Transaction ID from provider
     * @param array $additionalData Any extra data needed for confirmation
     * @return array Response from payment provider
     * @throws \Exception
     */
    public function confirmPayment(string $transactionId, array $additionalData = []): array;

    /**
     * Verify webhook signature
     * 
     * @param array $payload Webhook payload
     * @param string $signature Signature from webhook header
     * @return bool True if signature is valid
     */
    public function verifyWebhookSignature(array $payload, string $signature): bool;

    /**
     * Process webhook payload
     * 
     * @param array $payload Webhook payload
     * @return array Normalized payment status data
     */
    public function processWebhookPayload(array $payload): array;

    /**
     * Refund a payment
     * 
     * @param string $transactionId Transaction ID to refund
     * @param float|null $amount Partial refund amount (null = full refund)
     * @return array Response from payment provider
     * @throws \Exception
     */
    public function refundPayment(string $transactionId, ?float $amount = null): array;
}
