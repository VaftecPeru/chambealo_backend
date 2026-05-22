<?php

namespace App\Services\WebhookSecurity;

use Illuminate\Http\Request;

/**
 * WebhookSecurityInterface
 * 
 * Unified security interface for all payment gateway webhooks.
 * Ensures consistent security measures across PayPal, Izipay, and Mercado Pago.
 * 
 * All implementations MUST:
 * - Verify webhook signature/authenticity
 * - Validate timestamp (prevent old/delayed webhooks)
 * - Prevent replay attacks (detect duplicate webhooks)
 * - Enforce rate limiting (prevent abuse)
 * - Provide detailed verification logs
 */
interface WebhookSecurityInterface
{
    /**
     * Verify the webhook signature/authenticity
     * 
     * Different methods per gateway:
     * - PayPal: RSA certificate verification
     * - Izipay: HMAC-SHA256 verification
     * - Mercado Pago: X-Signature verification
     * 
     * @param Request $request The webhook request
     * @return bool True if signature is valid, false otherwise
     */
    public function verifySignature(Request $request): bool;

    /**
     * Validate webhook timestamp to prevent old/delayed webhooks
     * 
     * All gateways use 5-minute window:
     * - Request timestamp must be within ±5 minutes of server time
     * - Prevents processing of very old webhooks
     * - Prevents time-based attacks
     * 
     * @param int $timestamp Webhook timestamp (Unix seconds)
     * @param int $maxMinutes Maximum age in minutes (default: 5)
     * @return bool True if timestamp is valid, false if outside window
     */
    public function validateTimestamp(int $timestamp, int $maxMinutes = 5): bool;

    /**
     * Prevent replay attacks by checking webhook uniqueness
     * 
     * Must track processed webhook IDs to prevent re-processing:
     * - PayPal: Uses event_id
     * - Izipay: Uses hash of kr-answer
     * - Mercado Pago: Uses x-request-id
     * 
     * @param string $webhookId Unique webhook identifier
     * @return bool True if webhook is new/valid, false if duplicate detected
     */
    public function preventReplayAttack(string $webhookId): bool;

    /**
     * Validate payload integrity (full content hasn't been modified)
     * 
     * Ensures webhook data wasn't tampered with:
     * - Reconstruct payload exactly as sent
     * - Compare signature with freshly calculated signature
     * - Return false if mismatch detected
     * 
     * @param string $payload Raw webhook payload (canonical form)
     * @param string $signature Received signature from webhook header
     * @return bool True if payload integrity verified, false if tampered
     */
    public function validatePayloadIntegrity(string $payload, string $signature): bool;

    /**
     * Rate limit check to prevent webhook abuse
     * 
     * Implement per-IP rate limiting:
     * - Track requests per IP address
     * - Allow reasonable burst (e.g., 60 requests per minute)
     * - Return false if limit exceeded
     * 
     * @param string $ip Client IP address
     * @return bool True if under rate limit, false if limit exceeded
     */
    public function rateLimitCheck(string $ip): bool;

    /**
     * Get security verification details for logging
     * 
     * Return structured data about verification process:
     * - What checks passed/failed
     * - Timestamps
     * - Signature details
     * - Rate limiting status
     * 
     * @return array Associative array with verification details
     */
    public function getSecurityDetails(): array;

    /**
     * Get the gateway name this verification service handles
     * 
     * @return string One of: 'paypal', 'izipay', 'mercadopago'
     */
    public function getGateway(): string;
}
