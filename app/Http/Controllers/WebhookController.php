<?php

namespace App\Http\Controllers;

use App\Services\PaymentFactory;
use App\Http\Traits\WebhookSecurityTrait;
use App\Http\Traits\PaymentLoggingTrait;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\Order;
use App\Events\PaymentConfirmed;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * WebhookController
 * 
 * Controlador centralizado para procesar webhooks de todos los gateways de pago:
 * - Izipay
 * - MercadoPago
 * - PayPal
 * 
 * Características:
 * - Validación de firma de webhook (HMAC-SHA256)
 * - Prevención de replay attacks
 * - Rate limiting por IP
 * - Validación HTTPS en producción
 * - Logging estructurado
 * - Actualización de estado de pagos y órdenes
 * 
 * @package App\Http\Controllers
 */
class WebhookController extends Controller
{
    use WebhookSecurityTrait;
    use PaymentLoggingTrait;

    public function __construct()
    {
        $this->middleware('throttle:100,1')->only(['handle']);
    }

    /**
     * ENDPOINT: POST /api/webhooks/{gateway}
     * Procesar notificaciones webhook de los gateways de pago
     * CRÍTICO: Valida la firma del webhook para prevenir fraude
     * 
     * @param Request $request
     * @param string $gateway
     * @return JsonResponse
     */
    public function handle(Request $request, string $gateway): JsonResponse
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

            // Procesar payload según el gateway
            $result = $gatewayService->processWebhookPayload($request->all());

            // Extraer datos del resultado
            $paymentId = $result['transaction_id'] ?? $result['payment_id'] ?? null;
            $orderId = $result['order_id'] ?? null;
            $status = strtolower($result['status'] ?? 'unknown');

            if (!$paymentId) {
                $this->logWebhookError($gateway, 'Could not extract payment ID');
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo extraer el ID de pago',
                ], 400);
            }

            // Buscar transacción existente
            $transaction = Transaction::where('transaction_id', $paymentId)
                ->orWhere('payment_id', $paymentId)
                ->first();

            if (!$transaction) {
                $this->logWebhookError($gateway, "Transaction not found: {$paymentId}");
                return response()->json([
                    'success' => false,
                    'message' => 'Transacción no encontrada',
                ], 404);
            }

            // Actualizar estado de transacción
            $transaction->update([
                'status' => $status,
                'raw_response' => $result['raw_data'] ?? $result,
            ]);

            // Obtener orden asociada y actualizar su estado
            if ($transaction->order_id) {
                $order = Order::find($transaction->order_id);

                if ($order) {
                    if (in_array($status, ['completed', 'paid', 'approved'])) {
                        $order->markAsPaid();
                        
                        // Disparar evento de pago confirmado
                        event(new PaymentConfirmed($transaction));
                    } elseif (in_array($status, ['failed', 'refused', 'cancelled'])) {
                        $order->markAsFailed();
                    }
                }
            }

            $this->logWebhookProcessed($gateway, [
                'transaction_id' => $paymentId,
                'order_id' => $transaction->order_id,
                'status' => $status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook procesado correctamente',
                'data' => [
                    'transaction_id' => $paymentId,
                    'status' => $status,
                    'order_id' => $transaction->order_id,
                ],
            ]);

        } catch (\Exception $e) {
            $this->logWebhookError($gateway, $e->getMessage());

            Log::error('Webhook processing error', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error procesando webhook',
            ], 500);
        }
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
}
