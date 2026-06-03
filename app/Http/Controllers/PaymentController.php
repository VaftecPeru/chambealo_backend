<?php

namespace App\Http\Controllers;

use App\Services\PaymentFactory;
use App\Repositories\PaymentRepository;
use App\Events\PaymentConfirmed;
use App\Http\Traits\WebhookSecurityTrait;
use App\Http\Traits\PaymentValidationTrait;
use App\Http\Traits\PaymentLoggingTrait;
use App\Http\Requests\PaymentSessionRequest;
use App\Http\Requests\RefundRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * PaymentController (Unificado)
 * 
 * Controlador único para gestionar pagos a través de múltiples gateways:
 * - MercadoPago
 * - Izipay
 * - PayPal
 * 
 * Características:
 * - Arquitectura limpia con Factory Pattern
 * - Todas las medidas de seguridad (HTTPS, webhook verification, rate limiting, VAFTEC)
 * - Logging estructurado
 * - Prevención de replay attacks
 * - Repository Pattern para acceso a datos
 * 
 * @package App\Http\Controllers
 */
class PaymentController extends Controller
{
    use WebhookSecurityTrait;
    use PaymentValidationTrait;
    use PaymentLoggingTrait;

    protected PaymentRepository $paymentRepository;

    public function __construct(PaymentRepository $paymentRepository)
    {
        $this->paymentRepository = $paymentRepository;
        
        $this->middleware('auth:sanctum', ['only' => ['createSession', 'confirm', 'refund']]);
        $this->middleware('throttle:5,1', ['only' => ['createSession', 'confirm']]);
        $this->middleware('throttle:50,1', ['only' => ['refund']]);
        $this->middleware('throttle:100,1', ['only' => ['webhook']]);
    }

    /**
     * ENDPOINT 1: POST /api/payment/session
     * Crear una sesión de pago para el gateway especificado
     * 
     * @param PaymentSessionRequest $request
     * @return JsonResponse
     */
    public function createSession(PaymentSessionRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // VAFTEC: Validar monto desde backend
            $amount = $this->validatePaymentAmount((float)$validated['amount']);
            $currency = $this->validateCurrency($validated['currency']);
            $email = $this->validateEmail($validated['email']);

            // Verificar que la orden existe y pertenece al usuario
            $order = Order::findOrFail($validated['order_id']);
            
            // Verificar que el usuario tiene acceso a esta orden
            if ($order->user_id !== auth()->id() && !auth()->user()->isAdmin()) {
                throw new \Exception('Unauthorized access to this order');
            }

            // VAFTEC: Validar que el monto coincide con la orden
            $this->validateAmountMatches($amount, (float)$order->total_amount ?? $amount);

            // Obtener el servicio de gateway
            $gateway = PaymentFactory::make($validated['gateway']);

            // Generar sesión/token de pago
            $result = $gateway->createPayment([
                'order_id' => $order->id,
                'amount' => $amount,
                'currency' => $currency,
                'email' => $email,
                'description' => $validated['description'] ?? "Orden #{$order->id}",
                'user_id' => auth()->id(),
                'tenant_id' => $order->tenant_id ?? null,
                'return_url' => route('api.payment.success'),
                'cancel_url' => route('api.payment.cancel'),
                'webhook_url' => route('api.payment.webhook', ['gateway' => $validated['gateway']]),
            ]);

            // Crear registro de pago en BD
            $payment = $this->paymentRepository->createPayment([
                'order_id' => $order->id,
                'gateway' => $validated['gateway'],
                'payment_id' => $result['id'] ?? $result['payment_id'] ?? null,
                'status' => 'pending',
                'amount' => $amount,
                'currency' => $currency,
                'email' => $email,
                'user_id' => auth()->id(),
                'tenant_id' => $order->tenant_id ?? null,
                'raw_response' => $result,
            ]);

            // Actualizar estado de orden
            $order->update(['status' => Order::STATUS_PAYMENT_PENDING]);

            $this->logPaymentSessionCreated([
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'gateway' => $validated['gateway'],
                'amount' => $amount,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'gateway_id' => $result['id'] ?? $result['payment_id'] ?? null,
                    'form_token' => $result['form_token'] ?? null,
                    'init_point' => $result['init_point'] ?? $result['sandbox_init_point'] ?? null,
                    'approve_url' => $result['approve_url'] ?? null,
                    'redirect_url' => $result['init_point'] ?? $result['sandbox_init_point'] ?? null,
                ],
                'message' => 'Sesión de pago creada exitosamente',
            ], 201);

        } catch (\Exception $e) {
            $this->logPaymentOperationFailed('createSession', $e->getMessage(), [
                'gateway' => $request->input('gateway'),
                'order_id' => $request->input('order_id'),
            ]);

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'No se pudo crear la sesión de pago',
                'errors' => ['error' => $e->getMessage()],
            ], 500);
        }
    }

    /**
     * ENDPOINT 2: POST /api/payment/confirm
     * Confirmar manualmente el estado de un pago
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function confirm(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'gateway' => 'required|in:izipay,mercadopago,paypal',
                'payment_id' => 'required|string',
            ]);

            $gateway = PaymentFactory::make($request->input('gateway'));

            // Obtener estado actual del pago desde el gateway
            $result = $gateway->confirmPayment($request->input('payment_id'));

            // Mapear resultado a formato estándar
            $status = strtolower($result['status'] ?? 'unknown');

            // Actualizar estado de pago en BD
            $payment = $this->paymentRepository->updatePaymentStatus(
                $request->input('payment_id'),
                $status,
                $result
            );

            // Obtener la orden asociada y actualizar su estado
            if ($payment && $payment->order_id) {
                $order = Order::findOrFail($payment->order_id);
                
                if (in_array($status, ['completed', 'paid', 'approved'])) {
                    $order->markAsPaid();
                    event(new PaymentConfirmed($payment));
                } elseif (in_array($status, ['failed', 'refused', 'cancelled'])) {
                    $order->markAsFailed();
                }
            }

            $this->logPaymentConfirmed([
                'payment_id' => $request->input('payment_id'),
                'status' => $status,
                'gateway' => $request->input('gateway'),
                'order_id' => $payment->order_id ?? null,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->order_id ?? null,
                    'status' => $status,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                ],
                'message' => "Pago {$status}",
            ]);

        } catch (\Exception $e) {
            $this->logPaymentOperationFailed('confirm', $e->getMessage(), [
                'payment_id' => $request->input('payment_id'),
            ]);

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'No se pudo confirmar el pago',
                'errors' => ['error' => $e->getMessage()],
            ], 500);
        }
    }

    /**
     * ENDPOINT 3: POST /api/payment/webhook/{gateway}
     * Procesar notificaciones webhook de los gateways de pago
     * CRÍTICO: Valida la firma del webhook para prevenir fraude
     * 
     * @param Request $request
     * @param string $gateway
     * @return JsonResponse
     */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        $this->logWebhookReceived($gateway, [
            'payload_size' => strlen($request->getContent()),
            'ip' => $request->ip(),
        ]);

        try {
            // Validar que el gateway sea soportado
            if (!in_array($gateway, PaymentFactory::getAvailableGateways())) {
                $this->logWebhookError($gateway, 'Unsupported gateway');
                return response()->json([
                    'success' => false,
                    'message' => 'Gateway no soportado',
                ], 400);
            }

            // ⚠️ CRÍTICO: Validaciones de seguridad
            // 1. Validar HTTPS
            $https = $this->checkHttps($request);
            if (!$https['verified'] && app()->environment('production')) {
                $this->logHttpsCheckFailed($gateway);
                return response()->json([
                    'success' => false,
                    'message' => 'Se requiere HTTPS',
                ], 403);
            }

            // 2. Validar rate limit por IP
            $rateLimit = $this->checkRateLimitByIp($request, $gateway, 100, 60);
            if (!$rateLimit['allowed']) {
                $this->logRateLimitExceeded($gateway, $request->ip());
                return response()->json([
                    'success' => false,
                    'message' => 'Rate limit excedido',
                ], 429);
            }

            // 3. Prevenir replay attacks
            if (!$this->checkReplayAttack($request, $gateway)) {
                $this->logReplayAttackDetected($gateway, $request->header('X-Request-Id') ?? 'unknown');
                return response()->json([
                    'success' => false,
                    'message' => 'Replay attack detected',
                ], 403);
            }

            $gatewayService = PaymentFactory::make($gateway);

            // ⚠️ CRÍTICO: Validar firma del webhook
            $isValid = $this->validateWebhookSignature($request, $gatewayService, $gateway);

            if (!$isValid) {
                $this->logSignatureVerificationFailed($gateway, 'Invalid signature');
                return response()->json([
                    'success' => false,
                    'message' => 'Firma inválida',
                ], 401);
            }

            // Extraer payment ID del payload según el gateway
            $paymentId = $this->extractPaymentId($request, $gateway);

            if (!$paymentId) {
                $this->logWebhookError($gateway, 'Could not extract payment ID');
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo extraer el ID de pago',
                ], 400);
            }

            // Verificar que el pago existe
            $payment = $this->paymentRepository->getPaymentByPaymentId($paymentId);

            if (!$payment) {
                $this->logWebhookError($gateway, "Payment not found: {$paymentId}");
                return response()->json([
                    'success' => false,
                    'message' => 'Pago no encontrado',
                ], 404);
            }

            // Obtener estado de pago del gateway
            $result = $gatewayService->confirmPayment($paymentId);
            $status = strtolower($result['status'] ?? 'unknown');

            // Actualizar estado en BD
            $this->paymentRepository->updatePaymentStatus(
                $paymentId,
                $status,
                $result
            );

            // Disparar evento si pago completado
            if (in_array($status, ['completed', 'paid', 'approved'])) {
                event(new PaymentConfirmed($payment));
            }

            $this->logWebhookProcessed($gateway, [
                'payment_id' => $paymentId,
                'status' => $status,
            ]);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            $this->logWebhookError($gateway, $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error procesando webhook',
            ], 500);
        }
    }

    /**
     * ENDPOINT 4: POST /api/payment/refund
     * Procesar reembolso de un pago
     * 
     * @param RefundRequest $request
     * @return JsonResponse
     */
    public function refund(RefundRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            // Validar que el pago se puede reembolsar
            $payment = $this->validateRefundEligibility(
                $validated['payment_id'],
                $validated['gateway']
            );

            // VAFTEC: Validar monto de refund
            if (isset($validated['refund_amount'])) {
                $this->validateRefundAmount(
                    (float)$validated['refund_amount'],
                    (float)$payment->amount
                );
            }

            // Obtener servicio del gateway
            $gateway = PaymentFactory::make($validated['gateway']);

            // Procesar reembolso
            $result = $gateway->refundPayment(
                $validated['payment_id'],
                $validated['refund_amount'] ?? null
            );

            // Actualizar estado del pago
            $payment->status = 'refunded';
            $payment->raw_response = $result;
            $payment->save();

            $this->logRefundProcessed([
                'payment_id' => $validated['payment_id'],
                'gateway' => $validated['gateway'],
                'refund_amount' => $validated['refund_amount'] ?? 'full',
                'reason' => $validated['reason'] ?? 'No especificada',
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_id' => $payment->id,
                    'refund_id' => $result['refund_id'] ?? $result['id'] ?? null,
                    'status' => 'refunded',
                    'amount' => $payment->amount,
                ],
                'message' => 'Reembolso procesado exitosamente',
            ]);

        } catch (\Exception $e) {
            $this->logRefundError([
                'payment_id' => $request->input('payment_id'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'No se pudo procesar el reembolso',
                'errors' => ['error' => $e->getMessage()],
            ], 500);
        }
    }

    /**
     * ENDPOINT 5: GET /api/payment/health
     * Verificar estado de los gateways de pago
     * 
     * @return JsonResponse
     */
    public function healthCheck(): JsonResponse
    {
        $results = [];

        foreach (PaymentFactory::getAvailableGateways() as $gatewayName) {
            try {
                $gateway = PaymentFactory::make($gatewayName);
                $isHealthy = true;
                $error = null;

                $results[$gatewayName] = [
                    'healthy' => $isHealthy,
                    'status' => 'operational',
                    'last_check' => now(),
                ];

                $this->logGatewayHealthCheck($gatewayName, $isHealthy);

            } catch (\Exception $e) {
                $results[$gatewayName] = [
                    'healthy' => false,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'last_check' => now(),
                ];

                $this->logGatewayHealthCheck($gatewayName, false, ['error' => $e->getMessage()]);
            }
        }

        $allHealthy = collect($results)->every(fn($r) => $r['healthy']);

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => $allHealthy ? 'Todos los gateways operacionales' : 'Algunos gateways con problemas',
        ], $allHealthy ? 200 : 503);
    }

    /**
     * Validar firma del webhook según el gateway
     * 
     * @param Request $request
     * @param mixed $gatewayService
     * @param string $gateway
     * @return bool
     */
    protected function validateWebhookSignature(Request $request, $gatewayService, string $gateway): bool
    {
        return match($gateway) {
            'izipay' => $this->validateIzipaySignature($request, $gatewayService),
            'mercadopago' => $this->validateMercadoPagoSignature($request, $gatewayService),
            'paypal' => $this->validatePayPalSignature($request, $gatewayService),
            default => false,
        };
    }

    /**
     * Validar firma de Izipay
     * 
     * @param Request $request
     * @param mixed $gatewayService
     * @return bool
     */
    protected function validateIzipaySignature(Request $request, $gatewayService): bool
    {
        $signature = $request->header('X-Izipay-Signature') ?? 
                    $request->header('x-izipay-signature') ?? 
                    '';
        return $gatewayService->verifyWebhookSignature($request->all(), $signature);
    }

    /**
     * Validar firma de MercadoPago
     * 
     * @param Request $request
     * @param mixed $gatewayService
     * @return bool
     */
    protected function validateMercadoPagoSignature(Request $request, $gatewayService): bool
    {
        $signature = $request->header('x-signature') ?? 
                    $request->header('X-Signature') ?? 
                    '';
        return $gatewayService->verifyWebhookSignature($request->all(), $signature);
    }

    /**
     * Validar firma de PayPal
     * 
     * @param Request $request
     * @param mixed $gatewayService
     * @return bool
     */
    protected function validatePayPalSignature(Request $request, $gatewayService): bool
    {
        return $gatewayService->verifyWebhookSignature($request->all(), '');
    }

    /**
     * Extraer payment ID del payload según el gateway
     * 
     * @param Request $request
     * @param string $gateway
     * @return string|null
     */
    protected function extractPaymentId(Request $request, string $gateway): ?string
    {
        return match($gateway) {
            'izipay' => $request->input('paymentId') ?? $request->input('payment_id'),
            'mercadopago' => $request->input('data.id'),
            'paypal' => $request->input('resource.id'),
            default => null,
        };
    }
}
