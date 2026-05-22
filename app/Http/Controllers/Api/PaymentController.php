<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;


// Clases del SDK de Mercado Pago
use MercadoPago\SDK;
use MercadoPago\Payment;
use App\Traits\LogsPaymentEvents;
use App\Services\WebhookSecurity\IzipayWebhookVerification;
use App\Services\WebhookSecurity\MercadoPagoWebhookVerification;

class PaymentController extends Controller
{
    use LogsPaymentEvents;
    /**
     * Process a payment through Mercado Pago
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function process(Request $request): JsonResponse
    {
        // VAFTEC: Validación obligatoria desde backend
        $request->validate([
            'transaction_amount' => 'required|numeric|min:0.01',
            'token' => 'required|string',
            'payment_method_id' => 'required|string',
            'payer' => 'required|array',
            'payer.email' => 'required|email',
            'installments' => 'required|integer|min:1',
        ]);

        try {
            // 1. Configuración del Token
            SDK::setAccessToken(config('services.mercadopago.access_token'));

            // 2. VAFTEC: Obtener datos desde validación, no desde JSON directo
            $validatedData = $request->validated();

            $payment = new Payment();
            
            // VAFTEC: Backend recalcula y valida monto (no confía en frontend)
            $calculatedAmount = $this->validatePaymentAmount($validatedData['transaction_amount']);
            
            $payment->transaction_amount = (float)$calculatedAmount;
            $payment->token = $validatedData['token'];
            $payment->description = "Servicio en Chambealo";
            $payment->installments = (int)$validatedData['installments'];
            $payment->payment_method_id = $validatedData['payment_method_id'];
            
            $payment->payer = array(
                "email" => $validatedData['payer']['email']
            );

            $payment->save();

            if (!$payment->id) {
                Log::error('Error validación Mercado Pago', [
                    'error' => $payment->error,
                    'user_id' => auth()->id() ?? 'guest'
                ]);
                return response()->json([
                    "message" => "Error en la validación de Mercado Pago",
                    "error_detail" => $payment->error 
                ], 400);
            }

            // VAFTEC: Registrar transacción en BD
            $this->logPaymentTransaction($payment->id, $validatedData, 'mercadopago');

            Log::info('Pago Mercado Pago procesado', [
                'payment_id' => $payment->id,
                'amount' => $calculatedAmount,
                'status' => $payment->status
            ]);

            return response()->json([
                "status" => $payment->status,
                "status_detail" => $payment->status_detail,
                "id" => $payment->id
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error procesando pago Mercado Pago', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id() ?? 'guest'
            ]);
            return response()->json([
                "error" => "Error de conexión o servidor"
            ], 500);
        }
    }

    /**
     * Create a payment token through Izipay
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createToken(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.10',
            'email'  => 'required|email',
        ]);

        // VAFTEC: Validar monto desde backend
        $validatedAmount = $this->validatePaymentAmount($request->amount);

        $config = config('izipay');

        $response = Http::withBasicAuth($config['client_id'], $config['secret'])
            ->post("{$config['url']}/api-payment/V4/Charge/CreatePayment", [
                'amount'   => (int)($validatedAmount * 100),
                'currency' => 'PEN',
                'orderId'  => 'ORD-' . bin2hex(random_bytes(4)),
                'customer' => [
                    'email' => $request->email
                ],
            ]);

        if ($response->failed()) {
            Log::error('Token Izipay fallido', [
                'email' => $request->email,
                'amount' => $validatedAmount,
                'response' => $response->json()
            ]);
            return response()->json([
                'error' => 'No se pudo generar el token de pago',
                'details' => $response->json()
            ], 500);
        }

        Log::info('Token Izipay creado', [
            'email' => $request->email,
            'amount' => $validatedAmount
        ]);

        return response()->json($response->json()['answer']);
    }

    /**
     * Webhook para Izipay (IPN)
     */
    public function webhook(Request $request): JsonResponse
    {
        // NUEVO: Verificar HTTPS
        $httpsInfo = $this->checkHttps($request);
        
        if (!$httpsInfo['verified']) {
            Log::error('Webhook Izipay recibido sin HTTPS', ['ip' => $request->ip()]);
            return response()->json(['error' => 'HTTPS required'], 403);
        }

        $krAnswer = $request->input('kr-answer');
        $krHash   = $request->input('kr-hash');
        $webhookId = hash('sha256', $krAnswer . time()); // Generate unique webhook ID
        
        // Log webhook reception
        $this->logWebhookReceived(
            gateway: 'izipay',
            webhook_id: $webhookId,
            payload: $request->all(),
            headers: $request->headers->all(),
            https_verified: $httpsInfo['verified'],
            tls_version: $httpsInfo['tls_version']
        );
        
        if (!$krAnswer || !$krHash) {
            Log::warning('Webhook Izipay sin datos', ['ip' => $request->ip()]);
            
            // Log webhook verification failure
            $this->logWebhookVerification(
                gateway: 'izipay',
                verified: false,
                webhook_id: $webhookId,
                error_message: 'Missing kr-answer or kr-hash'
            );
            
            return response()->json(['error' => 'Datos insuficientes'], 400);
        }

        try {
            $verifier = new IzipayWebhookVerification();
            
            // 1. HMAC SHA256 verification (preserving existing)
            if (!$verifier->verifySignature($request)) {
                Log::error('Intento de fraude o error de configuración en Hash Izipay', [
                    'ip' => $request->ip(),
                    'received_hash' => $krHash
                ]);
                
                // Log webhook verification failure
                $this->logWebhookVerification(
                    gateway: 'izipay',
                    verified: false,
                    webhook_id: $webhookId,
                    error_message: 'Hash mismatch - invalid signature'
                );
                
                return response()->json(['error' => 'Hash inválido'], 403);
            }
            
            $data = json_decode($krAnswer, true);
            
            // NUEVO: Agregar validación de timestamp explícita
            $timestampRaw = $data['timestamp'] ?? null;
            if ($timestampRaw && abs(time() - (int)($timestampRaw / 1000)) > 300) {
                $this->logSecurityEvent('replay_attempt', 'izipay', 'Invalid timestamp', $webhookId);
                return response()->json(['error' => 'Invalid timestamp'], 403);
            }

            // 2. Timestamp validation (NEW - matching PayPal level)
            $timestamp = $data['timestamp'] ?? time();
            if (!$verifier->validateTimestamp($timestamp)) {
                Log::warning('Izipay webhook timestamp outside acceptable window', [
                    'timestamp' => $timestamp,
                    'ip' => $request->ip(),
                ]);
                
                $this->logWebhookVerification(
                    gateway: 'izipay',
                    verified: false,
                    webhook_id: $webhookId,
                    error_message: 'Timestamp outside acceptable window'
                );
                
                return response()->json(['error' => 'Timestamp inválido'], 403);
            }
            
            // 3. Replay attack prevention (NEW - matching PayPal level)
            if (!$verifier->preventReplayAttack($webhookId)) {
                Log::warning('Izipay replay attack detected', [
                    'webhook_id' => $webhookId,
                    'ip' => $request->ip(),
                ]);
                
                $this->logWebhookVerification(
                    gateway: 'izipay',
                    verified: false,
                    webhook_id: $webhookId,
                    error_message: 'Replay attack detected'
                );
                
                return response()->json(['error' => 'Replay detectado'], 403);
            }
            
            // 4. Rate limiting (NEW - matching PayPal level)
            if (!$verifier->rateLimitCheck($request->ip())) {
                Log::warning('Izipay rate limit exceeded', [
                    'ip' => $request->ip(),
                ]);
                
                $this->logWebhookVerification(
                    gateway: 'izipay',
                    verified: false,
                    webhook_id: $webhookId,
                    error_message: 'Rate limit exceeded'
                );
                
                return response()->json(['error' => 'Rate limit exceeded'], 429);
            }
            
        } catch (\Exception $e) {
            Log::error('Izipay webhook security verification error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);
            
            $this->logWebhookVerification(
                gateway: 'izipay',
                verified: false,
                webhook_id: $webhookId,
                error_message: 'Security verification error: ' . $e->getMessage()
            );
            
            return response()->json(['error' => 'Error de verificación'], 500);
        }
        
        // Log successful webhook verification
        $this->logWebhookVerification(
            gateway: 'izipay',
            verified: true,
            webhook_id: $webhookId,
            payload: ['kr-answer' => substr($krAnswer, 0, 100) . '...'] // Log partial for security
        );

        // VAFTEC: Webhook eventos recomendados - PAYMENT.CAPTURE.COMPLETED
        $status = $data['orderStatus'] ?? 'UNKNOWN';

        if ($status === 'PAID') {
            Log::info("Webhook Izipay: Pago completado", [
                'order_id' => $data['orderDetails']['orderId'] ?? 'unknown',
                'status' => $status
            ]);

            $this->logPaymentTransaction($data['orderDetails']['orderId'] ?? null, $data, 'izipay', 'PAYMENT.CAPTURE.COMPLETED');
            
            // Log successful webhook processing
            $this->logWebhookProcessed(
                gateway: 'izipay',
                success: true,
                webhook_id: $webhookId,
                response: ['status' => 'PAID']
            );
            
            return response()->json(['status' => 'OK']);
        }

        Log::info('Webhook Izipay: Estado pendiente o fallido', ['status' => $status]);
        
        // Log webhook processing (status pending or failed)
        $this->logWebhookProcessed(
            gateway: 'izipay',
            success: false,
            webhook_id: $webhookId,
            error_message: "Order status: {$status}"
        );
        
        return response()->json(['status' => 'pending_or_failed']);
    }

    /**
     * Handle webhook from Mercado Pago
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handleMercadoPagoWebhook(Request $request): JsonResponse
    {
        // Verificar HTTPS
        $httpsInfo = $this->checkHttps($request);
        
        if (!$httpsInfo['verified']) {
            return response()->json(['error' => 'HTTPS required'], 403);
        }

        $requestId = $request->header('X-Request-Id', 'unknown');
        $signature = $request->header('X-Signature');
        
        // Log webhook reception
        $this->logWebhookReceived(
            gateway: 'mercadopago',
            webhook_id: $requestId,
            payload: $request->all(),
            headers: $request->headers->all(),
            https_verified: $httpsInfo['verified'],
            tls_version: $httpsInfo['tls_version']
        );
        
        if (!$signature) {
            Log::warning('Mercado Pago webhook sin X-Signature', [
                'ip' => $request->ip(),
                'request_id' => $requestId,
            ]);
            
            $this->logWebhookVerification(
                gateway: 'mercadopago',
                verified: false,
                webhook_id: $requestId,
                error_message: 'Missing X-Signature header'
            );
            
            return response()->json(['error' => 'Missing signature'], 400);
        }

        try {
            $verifier = new MercadoPagoWebhookVerification();
            
            // 1. X-Signature verification
            if (!$verifier->verifySignature($request)) {
                Log::error('Mercado Pago X-Signature verification failed', [
                    'ip' => $request->ip(),
                    'request_id' => $requestId,
                ]);
                
                $this->logWebhookVerification(
                    gateway: 'mercadopago',
                    verified: false,
                    webhook_id: $requestId,
                    error_message: 'X-Signature verification failed'
                );
                
                return response()->json(['error' => 'Invalid signature'], 403);
            }
            
            // 2. Timestamp validation
            $xRequestTimestamp = $request->header('X-Request-Timestamp', time());
            if (!$verifier->validateTimestamp((int)$xRequestTimestamp)) {
                Log::warning('Mercado Pago timestamp outside acceptable window', [
                    'timestamp' => $xRequestTimestamp,
                    'ip' => $request->ip(),
                ]);
                
                $this->logWebhookVerification(
                    gateway: 'mercadopago',
                    verified: false,
                    webhook_id: $requestId,
                    error_message: 'Timestamp outside acceptable window'
                );
                
                return response()->json(['error' => 'Invalid timestamp'], 403);
            }
            
            // 3. Replay attack prevention (using X-Request-Id)
            if (!$verifier->preventReplayAttack($requestId)) {
                Log::warning('Mercado Pago replay attack detected', [
                    'request_id' => $requestId,
                    'ip' => $request->ip(),
                ]);
                
                $this->logWebhookVerification(
                    gateway: 'mercadopago',
                    verified: false,
                    webhook_id: $requestId,
                    error_message: 'Replay attack detected'
                );
                
                return response()->json(['error' => 'Replay detected'], 403);
            }
            
            // 4. Rate limiting
            if (!$verifier->rateLimitCheck($request->ip())) {
                Log::warning('Mercado Pago rate limit exceeded', [
                    'ip' => $request->ip(),
                ]);
                
                $this->logWebhookVerification(
                    gateway: 'mercadopago',
                    verified: false,
                    webhook_id: $requestId,
                    error_message: 'Rate limit exceeded'
                );
                
                return response()->json(['error' => 'Rate limit exceeded'], 429);
            }
            
        } catch (\Exception $e) {
            Log::error('Mercado Pago webhook security verification error', [
                'error' => $e->getMessage(),
                'request_id' => $requestId,
                'ip' => $request->ip(),
            ]);
            
            $this->logWebhookVerification(
                gateway: 'mercadopago',
                verified: false,
                webhook_id: $requestId,
                error_message: 'Security verification error: ' . $e->getMessage()
            );
            
            return response()->json(['error' => 'Verification error'], 500);
        }
        
        // Log successful webhook verification
        $this->logWebhookVerification(
            gateway: 'mercadopago',
            verified: true,
            webhook_id: $requestId
        );

        try {
            $data = $request->all();
            $action = $data['action'] ?? null;
            $type = $data['type'] ?? null;
            
            if ($action === 'payment.created' || $type === 'payment') {
                $paymentData = $data['data'] ?? [];
                $paymentStatus = $paymentData['status'] ?? 'pending';
                
                Log::info('Mercado Pago webhook: payment event', [
                    'payment_id' => $paymentData['id'] ?? 'unknown',
                    'status' => $paymentStatus,
                    'request_id' => $requestId,
                ]);
                
                if ($paymentStatus === 'approved') {
                    $this->logPaymentTransaction(
                        $paymentData['id'] ?? null,
                        $paymentData,
                        'mercadopago',
                        'payment.approved'
                    );
                    
                    $this->logWebhookProcessed(
                        gateway: 'mercadopago',
                        success: true,
                        webhook_id: $requestId,
                        response: ['payment_id' => $paymentData['id'], 'status' => 'approved']
                    );
                    
                    return response()->json(['status' => 'ok']);
                }
            }
            
            Log::info('Mercado Pago webhook: evento procesado', [
                'action' => $action,
                'type' => $type,
                'request_id' => $requestId,
            ]);
            
            $this->logWebhookProcessed(
                gateway: 'mercadopago',
                success: true,
                webhook_id: $requestId,
                response: ['action' => $action, 'type' => $type]
            );
            
            return response()->json(['status' => 'ok']);
            
        } catch (\Exception $e) {
            Log::error('Mercado Pago webhook processing error', [
                'error' => $e->getMessage(),
                'request_id' => $requestId,
            ]);
            
            $this->logWebhookProcessed(
                gateway: 'mercadopago',
                success: false,
                webhook_id: $requestId,
                error_message: $e->getMessage()
            );
            
            return response()->json(['error' => 'Processing error'], 500);
        }
    }

    /**
     * Validate payment amount from backend (never trust frontend)
     *
     * @param float|int $amount
     * @return float
     * @throws \Exception
     */
    private function validatePaymentAmount(float|int $amount): float
    {
        $amount = (float)$amount;
        
        if ($amount <= 0) {
            throw new \Exception('Monto inválido');
        }

        Log::info('Monto validado en backend', [
            'amount' => $amount,
            'user_id' => auth()->id() ?? 'guest'
        ]);

        return $amount;
    }

    /**
     * Log payment transaction to database
     *
     * @param int|string|null $paymentId
     * @param array $data
     * @param string $provider
     * @param string|null $webhookEvent
     * @return void
     */
    private function logPaymentTransaction(int|string|null $paymentId, array $data, string $provider, ?string $webhookEvent = null): void
    {
        try {
            \App\Models\Payment::create([
                'order_id' => $paymentId,
                'email' => $data['payer']['email'] ?? $data['customer']['email'] ?? 'unknown@example.com',
                'amount' => $data['transaction_amount'] ?? $data['amount'] ?? 0,
                'status' => $data['status'] ?? 'pending',
                'payment_method' => $provider,
                'webhook_event' => $webhookEvent,
                'webhook_received_at' => now(),
                'tenant_id' => auth()->user()?->tenant_id ?? null,
                'user_id' => auth()->id() ?? null,
            ]);

            Log::info('Transacción registrada en BD', [
                'order_id' => $paymentId,
                'provider' => $provider,
                'amount' => $data['transaction_amount'] ?? $data['amount'] ?? 0
            ]);
        } catch (\Exception $e) {
            Log::error('Error registrando transacción en BD', [
                'order_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
