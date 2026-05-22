<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Traits\LogsPaymentEvents;

/**
 * LogWebhookRequests Middleware
 * 
 * Automatically logs all webhook requests to payment_logs table.
 * This middleware can be applied to webhook routes to ensure all webhooks
 * are tracked without requiring explicit logging in each webhook handler.
 * 
 * Usage in routes:
 *   Route::post('/webhooks/paypal', [PayPalController::class, 'handleWebhook'])->middleware(LogWebhookRequests::class);
 *   Route::post('/webhooks/izipay', [PaymentController::class, 'webhook'])->middleware(LogWebhookRequests::class);
 */
class LogWebhookRequests
{
    use LogsPaymentEvents;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Determine webhook source and generate unique ID
        $gateway = $this->detectGateway($request);
        $webhookId = $this->generateWebhookId($request);

        // Log incoming webhook before processing
        try {
            $this->logWebhookReceived(
                gateway: $gateway,
                webhook_id: $webhookId,
                payload: $this->sanitizePayload($request),
                headers: $request->headers->all()
            );
        } catch (\Exception $e) {
            // If logging fails, don't interrupt the request
            \Illuminate\Support\Facades\Log::error('Failed to log webhook reception', [
                'error' => $e->getMessage(),
                'gateway' => $gateway,
            ]);
        }

        // Store webhook ID in request for later use
        $request->attributes->set('webhook_id', $webhookId);
        $request->attributes->set('webhook_gateway', $gateway);

        // Continue with the request
        $response = $next($request);

        return $response;
    }

    /**
     * Detect which payment gateway sent the webhook based on request parameters.
     *
     * @param Request $request
     * @return string
     */
    private function detectGateway(Request $request): string
    {
        // Check for PayPal indicators
        if ($request->has('event_type')) {
            $eventType = $request->input('event_type');
            if (strpos($eventType, 'CHECKOUT.ORDER') === 0 || strpos($eventType, 'PAYMENT.CAPTURE') === 0) {
                return 'paypal';
            }
        }

        // Check for Izipay indicators
        if ($request->has('kr-answer') && $request->has('kr-hash')) {
            return 'izipay';
        }

        // Default to unknown
        return 'unknown';
    }

    /**
     * Generate a unique webhook identifier.
     *
     * @param Request $request
     * @return string
     */
    private function generateWebhookId(Request $request): string
    {
        // For PayPal, use the webhook event ID
        if ($request->has('id')) {
            return $request->input('id');
        }

        // For Izipay, use hash of kr-answer
        if ($request->has('kr-answer')) {
            return hash('sha256', $request->input('kr-answer'));
        }

        // Fallback: create hash from request body and timestamp
        return hash('sha256', $request->getContent() . time());
    }

    /**
     * Sanitize payload for logging (remove sensitive data).
     *
     * @param Request $request
     * @return array
     */
    private function sanitizePayload(Request $request): array
    {
        $payload = $request->all();

        // Remove sensitive fields
        $sensitiveFields = ['kr-hash', 'signature', 'secret', 'token', 'password', 'api_key'];

        foreach ($sensitiveFields as $field) {
            if (isset($payload[$field])) {
                $payload[$field] = '***REDACTED***';
            }
        }

        // Limit kr-answer to first 500 chars for Izipay
        if (isset($payload['kr-answer']) && strlen($payload['kr-answer']) > 500) {
            $payload['kr-answer'] = substr($payload['kr-answer'], 0, 500) . '...';
        }

        return $payload;
    }
}
