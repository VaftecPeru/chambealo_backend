<?php

namespace App\Traits;

use App\Models\PaymentLog;
use Illuminate\Support\Facades\Log;

/**
 * LogsPaymentEvents Trait
 * 
 * Provides reusable logging functionality for payment events and webhooks.
 * Can be used in any controller or service that handles payments.
 * 
 * All logging operations are wrapped in try-catch to ensure that
 * logging failures never interrupt the payment flow.
 * 
 * Usage:
 *   use LogsPaymentEvents;
 *   
 *   $this->logPaymentEvent(
 *       event_type: 'webhook.received',
 *       status: 'success',
 *       gateway: 'paypal',
 *       request_payload: $request->all(),
 *       ip_address: $request->ip(),
 *       user_agent: $request->userAgent(),
 *   );
 */
trait LogsPaymentEvents
{
    /**
     * Log a payment event to the payment_logs table.
     * 
     * This method is wrapped in try-catch to ensure it never
     * interrupts the payment flow if logging fails.
     * 
     * @param string $event_type One of: webhook.received, webhook.verification, webhook.processed, webhook.error, payment.initiated, payment.completed, payment.failed
     * @param string $status One of: success, failed, pending, processing, retry
     * @param string|null $gateway One of: paypal, izipay
     * @param int|null $transaction_id Foreign key to transactions table
     * @param string|null $webhook_id Unique webhook identifier for deduplication
     * @param array|null $request_payload Original request data
     * @param array|null $response_payload Provider response data
     * @param array|null $headers HTTP headers from request
     * @param string|null $error_message Error message if failed
     * @param string|null $ip_address Client IP address
     * @param string|null $user_agent Client user agent
     * @param int $attempt Retry attempt number
     * @param bool|null $signature_verified
     * @param string|null $signature_method
     * @param string|null $signature_details
     * @param bool|null $timestamp_validated
     * @param string|null $replay_prevention_id
     * @param bool|null $https_verified
     * @param string|null $tls_version
     * 
     * @return void
     */
    public function logPaymentEvent(
        string $event_type,
        string $status,
        ?string $gateway = null,
        ?int $transaction_id = null,
        ?string $webhook_id = null,
        ?array $request_payload = null,
        ?array $response_payload = null,
        ?array $headers = null,
        ?string $error_message = null,
        ?string $ip_address = null,
        ?string $user_agent = null,
        int $attempt = 1,
        ?bool $signature_verified = null,
        ?string $signature_method = null,
        ?string $signature_details = null,
        ?bool $timestamp_validated = null,
        ?string $replay_prevention_id = null,
        ?bool $https_verified = null,
        ?string $tls_version = null
    ): void {
        try {
            // Check for webhook duplication using webhook_id
            if ($webhook_id) {
                $existingLog = PaymentLog::byWebhookId($webhook_id)->first();
                if ($existingLog) {
                    // Increment attempt counter for duplicated webhook
                    $existingLog->increment('attempt');
                    Log::info('Duplicate webhook detected, incrementing attempt count', [
                        'webhook_id' => $webhook_id,
                        'new_attempt' => $existingLog->attempt,
                    ]);
                    return;
                }
            }

            // Create the payment log record
            PaymentLog::create([
                'transaction_id' => $transaction_id,
                'event_type' => $event_type,
                'status' => $status,
                'gateway' => $gateway,
                'webhook_id' => $webhook_id,
                'request_payload' => $request_payload,
                'response_payload' => $response_payload,
                'headers' => $headers,
                'error_message' => $error_message,
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'attempt' => $attempt,
                'signature_verified' => $signature_verified,
                'signature_method' => $signature_method,
                'signature_details' => $signature_details,
                'timestamp_validated' => $timestamp_validated,
                'replay_prevention_id' => $replay_prevention_id,
                'https_verified' => $https_verified,
                'tls_version' => $tls_version,
            ]);

            // Log to Laravel's logging system as well
            Log::info("Payment event logged: {$event_type}", [
                'status' => $status,
                'gateway' => $gateway,
                'webhook_id' => $webhook_id,
                'transaction_id' => $transaction_id,
            ]);

        } catch (\Exception $e) {
            // Log the logging error to Laravel's logging system
            // but do NOT throw - this ensures payment flow is never interrupted
            Log::error('Failed to create PaymentLog record', [
                'error' => $e->getMessage(),
                'event_type' => $event_type,
                'gateway' => $gateway,
                'webhook_id' => $webhook_id,
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Log webhook verification result.
     * 
     * Convenience method for logging webhook signature verification.
     * 
     * @param string $gateway Gateway name
     * @param bool $verified Whether signature verification passed
     * @param string|null $webhook_id Webhook identifier
     * @param array|null $payload Webhook payload
     * @param array|null $headers Request headers
     * @param string|null $error_message Error if verification failed
     * @param bool|null $https_verified
     * @param string|null $tls_version
     * 
     * @return void
     */
    public function logWebhookVerification(
        string $gateway,
        bool $verified,
        ?string $webhook_id = null,
        ?array $payload = null,
        ?array $headers = null,
        ?string $error_message = null,
        ?bool $https_verified = null,
        ?string $tls_version = null
    ): void {
        $this->logPaymentEvent(
            event_type: 'webhook.verification',
            status: $verified ? 'success' : 'failed',
            gateway: $gateway,
            webhook_id: $webhook_id,
            request_payload: $payload,
            headers: $headers,
            signature_verified: $verified,
            error_message: $error_message,
            ip_address: request()->ip(),
            user_agent: request()->userAgent(),
            https_verified: $https_verified,
            tls_version: $tls_version,
        );
    }

    /**
     * Log webhook reception.
     * 
     * Convenience method for logging initial webhook reception.
     * 
     * @param string $gateway Gateway name
     * @param string|null $webhook_id Webhook identifier
     * @param array|null $payload Webhook payload
     * @param array|null $headers Request headers
     * @param bool|null $https_verified
     * @param string|null $tls_version
     * 
     * @return void
     */
    public function logWebhookReceived(
        string $gateway,
        ?string $webhook_id = null,
        ?array $payload = null,
        ?array $headers = null,
        ?bool $https_verified = null,
        ?string $tls_version = null
    ): void {
        $this->logPaymentEvent(
            event_type: 'webhook.received',
            status: 'success',
            gateway: $gateway,
            webhook_id: $webhook_id,
            request_payload: $payload,
            headers: $headers,
            ip_address: request()->ip(),
            user_agent: request()->userAgent(),
            https_verified: $https_verified,
            tls_version: $tls_version,
        );
    }

    /**
     * Log webhook processing result.
     * 
     * Convenience method for logging webhook processing outcome.
     * 
     * @param string $gateway Gateway name
     * @param bool $success Whether processing succeeded
     * @param string|null $webhook_id Webhook identifier
     * @param int|null $transaction_id Associated transaction ID
     * @param array|null $response Response data
     * @param string|null $error_message Error if processing failed
     * 
     * @return void
     */
    public function logWebhookProcessed(
        string $gateway,
        bool $success,
        ?string $webhook_id = null,
        ?int $transaction_id = null,
        ?array $response = null,
        ?string $error_message = null
    ): void {
        $this->logPaymentEvent(
            event_type: $success ? 'webhook.processed' : 'webhook.error',
            status: $success ? 'success' : 'failed',
            gateway: $gateway,
            transaction_id: $transaction_id,
            webhook_id: $webhook_id,
            response_payload: $response,
            error_message: $error_message,
            ip_address: request()->ip(),
            user_agent: request()->userAgent(),
        );
    }

    /**
     * Check HTTPS for webhook security
     */
    public function checkHttps(\Illuminate\Http\Request $request): array
    {
        return [
            'verified' => $request->attributes->get('https_verified', false),
            'tls_version' => $request->attributes->get('tls_version')
        ];
    }

    /**
     * Log generic security events
     */
    public function logSecurityEvent(
        string $action,
        string $gateway,
        string $description,
        ?string $webhook_id = null
    ): void {
        $this->logPaymentEvent(
            event_type: 'security.event',
            status: 'failed',
            gateway: $gateway,
            webhook_id: $webhook_id,
            error_message: "{$action}: {$description}",
            ip_address: request()->ip(),
            user_agent: request()->userAgent(),
        );
    }
}
