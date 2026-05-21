<?php

namespace App\Utilities;

use Illuminate\Support\Facades\Log;

/**
 * PaymentLogger
 * Provides structured JSON logging for payment transactions following VAFTEC standards
 * 
 * Required fields per transaction:
 * - timestamp (ISO 8601)
 * - process (create_session, confirm_payment, webhook, pdf_generation, email_send)
 * - status (success, failed)
 * - order_id / transaction_id
 * - tenant_id (if multi-tenant)
 * - user_id
 * - ip
 * - user_agent
 * - error_message (if applicable)
 */
class PaymentLogger
{
    /**
     * Log payment session creation.
     */
    public static function logSessionCreation(
        string $orderId,
        float $amount,
        string $paymentMethod,
        ?string $tenantId = null,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        self::log([
            'process' => 'create_session',
            'status' => 'success',
            'order_id' => $orderId,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Log payment confirmation.
     */
    public static function logPaymentConfirmation(
        string $transactionId,
        string $orderId,
        string $paymentStatus,
        float $amount,
        string $paymentMethod,
        ?string $tenantId = null,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        self::log([
            'process' => 'confirm_payment',
            'status' => 'success',
            'transaction_id' => $transactionId,
            'order_id' => $orderId,
            'payment_status' => $paymentStatus,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /**
     * Log webhook event.
     */
    public static function logWebhookEvent(
        string $orderId,
        string $paymentStatus,
        string $paymentMethod,
        bool $pdfGenerated = false,
        bool $emailSent = false,
        ?string $emailRecipient = null,
        ?string $tenantId = null,
        ?int $userId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $error = null
    ): void {
        self::log([
            'process' => 'webhook.payment_captured',
            'status' => $error ? 'failed' : 'success',
            'order_id' => $orderId,
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentMethod,
            'pdf_generated' => $pdfGenerated,
            'email_sent' => $emailSent,
            'email_recipient' => $emailRecipient,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'error_message' => $error,
        ]);
    }

    /**
     * Log PDF generation.
     */
    public static function logPdfGeneration(
        string $orderId,
        ?string $filePath = null,
        ?string $error = null,
        ?string $tenantId = null,
        ?int $userId = null
    ): void {
        self::log([
            'process' => 'pdf_generation',
            'status' => $error ? 'failed' : 'success',
            'order_id' => $orderId,
            'file_path' => $filePath,
            'error_message' => $error,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Log email sending.
     */
    public static function logEmailSend(
        string $orderId,
        string $recipient,
        bool $success = true,
        ?string $error = null,
        ?string $tenantId = null,
        ?int $userId = null
    ): void {
        self::log([
            'process' => 'email_send',
            'status' => $success ? 'success' : 'failed',
            'order_id' => $orderId,
            'email_recipient' => $recipient,
            'error_message' => $error,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Log payment error.
     */
    public static function logPaymentError(
        string $process,
        ?string $orderId = null,
        ?string $error = null,
        ?string $paymentMethod = null,
        ?string $tenantId = null,
        ?int $userId = null,
        ?string $ip = null
    ): void {
        self::log([
            'process' => $process,
            'status' => 'failed',
            'order_id' => $orderId,
            'payment_method' => $paymentMethod,
            'error_message' => $error,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'ip' => $ip,
        ]);
    }

    /**
     * Generic log method for custom entries.
     */
    public static function log(array $data): void
    {
        $logData = array_merge([
            'timestamp' => now()->toIso8601String(),
        ], array_filter($data, fn($value) => $value !== null));

        Log::info('PAYMENT_TRANSACTION', $logData);
    }
}
