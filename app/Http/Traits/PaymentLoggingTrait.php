<?php

namespace App\Http\Traits;

use Illuminate\Support\Facades\Log;

/**
 * PaymentLoggingTrait
 * Logging estructurado para transacciones de pago
 * Usa el canal 'payment' definido en config/logging.php
 */
trait PaymentLoggingTrait
{
    /**
     * Registrar creación de sesión de pago
     * 
     * @param array $data
     * @return void
     */
    protected function logPaymentSessionCreated(array $data): void
    {
        Log::channel('payment')->info('Payment session created', array_merge([
            'timestamp' => now(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ], $data));
    }

    /**
     * Registrar confirmación de pago
     * 
     * @param array $data
     * @return void
     */
    protected function logPaymentConfirmed(array $data): void
    {
        Log::channel('payment')->info('Payment confirmed', array_merge([
            'timestamp' => now(),
        ], $data));
    }

    /**
     * Registrar webhook recibido
     * 
     * @param string $gateway
     * @param array $data
     * @return void
     */
    protected function logWebhookReceived(string $gateway, array $data): void
    {
        Log::channel('payment')->info("Webhook received from {$gateway}", array_merge([
            'gateway' => $gateway,
            'timestamp' => now(),
            'ip' => request()->ip(),
        ], $data));
    }

    /**
     * Registrar webhook procesado exitosamente
     * 
     * @param string $gateway
     * @param array $data
     * @return void
     */
    protected function logWebhookProcessed(string $gateway, array $data): void
    {
        Log::channel('payment')->info("Webhook processed from {$gateway}", array_merge([
            'gateway' => $gateway,
            'timestamp' => now(),
        ], $data));
    }

    /**
     * Registrar error en webhook
     * 
     * @param string $gateway
     * @param string $error
     * @param array $additionalData
     * @return void
     */
    protected function logWebhookError(string $gateway, string $error, array $additionalData = []): void
    {
        Log::channel('payment')->error("Webhook error from {$gateway}: {$error}", array_merge([
            'gateway' => $gateway,
            'error' => $error,
            'timestamp' => now(),
            'ip' => request()->ip(),
        ], $additionalData));
    }

    /**
     * Registrar reembolso procesado
     * 
     * @param array $data
     * @return void
     */
    protected function logRefundProcessed(array $data): void
    {
        Log::channel('payment')->info('Refund processed', array_merge([
            'timestamp' => now(),
            'user_id' => auth()->id(),
        ], $data));
    }

    /**
     * Registrar error en reembolso
     * 
     * @param array $data
     * @return void
     */
    protected function logRefundError(array $data): void
    {
        Log::channel('payment')->error('Refund failed', array_merge([
            'timestamp' => now(),
            'user_id' => auth()->id(),
        ], $data));
    }

    /**
     * Registrar verificación de firma fallida
     * 
     * @param string $gateway
     * @param string $reason
     * @return void
     */
    protected function logSignatureVerificationFailed(string $gateway, string $reason): void
    {
        Log::channel('payment')->warning("Signature verification failed for {$gateway}: {$reason}", [
            'gateway' => $gateway,
            'reason' => $reason,
            'timestamp' => now(),
            'ip' => request()->ip(),
        ]);
    }

    /**
     * Registrar validación de HTTPS fallida
     * 
     * @param string $gateway
     * @return void
     */
    protected function logHttpsCheckFailed(string $gateway): void
    {
        Log::channel('payment')->warning("HTTPS check failed for webhook: {$gateway}", [
            'gateway' => $gateway,
            'timestamp' => now(),
            'ip' => request()->ip(),
        ]);
    }

    /**
     * Registrar rate limit excedido
     * 
     * @param string $gateway
     * @param string $ip
     * @return void
     */
    protected function logRateLimitExceeded(string $gateway, string $ip): void
    {
        Log::channel('payment')->warning("Rate limit exceeded for {$gateway}", [
            'gateway' => $gateway,
            'ip' => $ip,
            'timestamp' => now(),
        ]);
    }

    /**
     * Registrar intento de replay attack
     * 
     * @param string $gateway
     * @param string $requestId
     * @return void
     */
    protected function logReplayAttackDetected(string $gateway, string $requestId): void
    {
        Log::channel('payment')->warning("Replay attack detected for {$gateway}", [
            'gateway' => $gateway,
            'request_id' => $requestId,
            'timestamp' => now(),
            'ip' => request()->ip(),
        ]);
    }

    /**
     * Registrar operación de pago completada (resumen)
     * 
     * @param array $data
     * @return void
     */
    protected function logPaymentOperationComplete(array $data): void
    {
        Log::channel('payment')->info('Payment operation completed', array_merge([
            'timestamp' => now(),
            'user_id' => auth()->id(),
        ], $data));
    }

    /**
     * Registrar operación de pago fallida (resumen)
     * 
     * @param string $operation
     * @param string $error
     * @param array $additionalData
     * @return void
     */
    protected function logPaymentOperationFailed(string $operation, string $error, array $additionalData = []): void
    {
        Log::channel('payment')->error("Payment operation failed: {$operation}", array_merge([
            'operation' => $operation,
            'error' => $error,
            'timestamp' => now(),
            'user_id' => auth()->id(),
        ], $additionalData));
    }

    /**
     * Registrar estado de salud del gateway
     * 
     * @param string $gateway
     * @param bool $healthy
     * @param array $details
     * @return void
     */
    protected function logGatewayHealthCheck(string $gateway, bool $healthy, array $details = []): void
    {
        $level = $healthy ? 'info' : 'warning';
        Log::channel('payment')->{$level}("Gateway health check: {$gateway}", array_merge([
            'gateway' => $gateway,
            'healthy' => $healthy,
            'timestamp' => now(),
        ], $details));
    }
}
