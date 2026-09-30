<?php

namespace App\Http\Controllers;

use App\Services\PaymentFactory;
use App\Repositories\PaymentRepository;
use App\Events\PaymentConfirmed;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected PaymentRepository $paymentRepository;

    public function __construct(PaymentRepository $paymentRepository)
    {
        $this->paymentRepository = $paymentRepository;
        $this->middleware('auth:api')->only(['createSession', 'confirm']);
        $this->middleware('throttle:5,1')->only(['createSession', 'confirm']);
        $this->middleware('throttle:20,1')->only(['webhook']);
    }

    /**
     * ENDPOINT 1: POST /api/payment/session
     * Create a payment session for the specified gateway
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gateway' => 'required|in:izipay,mercadopago,paypal',
            'order_id' => 'required|integer|exists:orders,id',
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $user = auth('api')->user();
            $tenantId = app('tenant_id');

            $orderQuery = Order::whereKey($validated['order_id'])
                ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId));

            if (!$user->isAdmin()) {
                $orderQuery->where('user_id', $user->user_id);
            }

            $order = $orderQuery->first();

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pedido no encontrado o no autorizado.',
                ], 404);
            }

            if (!in_array($order->status, [Order::STATUS_CHECKOUT, Order::STATUS_PAYMENT_PENDING], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'El pedido no está disponible para iniciar pago.',
                ], 422);
            }

            $amount = (float) $order->total_amount;
            $currency = config('payment.default_currency', 'PEN');
            $email = $order->user?->email ?? $user->email;
            $frontendUrl = rtrim(config('payment.frontend_url', config('app.url')), '/');

            $gateway = PaymentFactory::make($validated['gateway']);

            $result = $gateway->createPayment([
                'order_id' => $order->order_id,
                'amount' => $amount,
                'currency' => $currency,
                'email' => $email,
                'description' => $validated['description'] ?? "Pedido {$order->order_id}",
                'user_id' => $user->user_id,
                'tenant_id' => $order->tenant_id,
                'return_url' => $frontendUrl . '/checkout?payment=success',
                'cancel_url' => $frontendUrl . '/checkout?payment=cancelled',
                'webhook_url' => route('api.payment.webhook', ['gateway' => $validated['gateway']]),
            ]);

            $payment = $this->paymentRepository->upsertPaymentForOrder([
                'order_id' => $order->order_id,
                'gateway' => $validated['gateway'],
                'payment_id' => $result['id'] ?? $result['payment_id'] ?? null,
                'status' => 'pending',
                'amount' => $amount,
                'currency' => $currency,
                'email' => $email,
                'user_id' => $user->user_id,
                'tenant_id' => $order->tenant_id,
                'raw_response' => $result,
            ]);

            if ($order->status === Order::STATUS_CHECKOUT) {
                $order->update(['status' => Order::STATUS_PAYMENT_PENDING]);
            }

            Log::info('Payment session created', [
                'payment_id' => $payment->id,
                'order_id' => $order->order_id,
                'gateway' => $validated['gateway'],
                'amount' => $amount,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_id' => $payment->id,
                    'gateway_id' => $result['id'] ?? $result['payment_id'] ?? null,
                    'form_token' => $result['form_token'] ?? null,
                    'init_point' => $result['init_point'] ?? $result['sandbox_init_point'] ?? null,
                    'approve_url' => $result['approve_url'] ?? null,
                    'redirect_url' => $result['approve_url']
                        ?? $result['init_point']
                        ?? $result['sandbox_init_point']
                        ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Payment session creation failed', [
                'error' => $e->getMessage(),
                'gateway' => $validated['gateway'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo iniciar el pago.',
            ], 500);
        }
    }

    /**
     * ENDPOINT 2: POST /api/payment/confirm
     * Manually confirm a payment status
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gateway' => 'required|in:izipay,mercadopago,paypal',
            'payment_id' => 'required|string',
        ]);

        try {
            $user = auth('api')->user();
            $payment = $this->paymentRepository->getPaymentByPaymentId($validated['payment_id']);

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pago no encontrado.',
                ], 404);
            }

            if (!$user->isAdmin() && (int) $payment->user_id !== (int) $user->user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No autorizado para confirmar este pago.',
                ], 403);
            }

            $gateway = PaymentFactory::make($validated['gateway']);
            $result = $gateway->confirmPayment($validated['payment_id']);
            $status = strtolower($result['status'] ?? 'unknown');

            $payment = $this->paymentRepository->updatePaymentStatus(
                $validated['payment_id'],
                $status,
                $result
            );

            if (in_array($status, ['completed', 'paid', 'approved'], true)) {
                event(new PaymentConfirmed($payment));
            }

            Log::info('Payment confirmed', [
                'payment_id' => $validated['payment_id'],
                'status' => $status,
                'gateway' => $validated['gateway'],
            ]);

            return response()->json([
                'success' => true,
                'status' => $status,
                'message' => "Payment {$status}",
            ]);
        } catch (\Throwable $e) {
            Log::error('Payment confirmation failed', [
                'error' => $e->getMessage(),
                'payment_id' => $validated['payment_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo confirmar el pago.',
            ], 500);
        }
    }

    /**
     * ENDPOINT 3: POST /api/payment/webhook/{gateway}
     * Handle webhook notifications from payment gateways
     * CRITICAL: Validates gateway signature to prevent fraud
     * 
     * @param Request $request
     * @param string $gateway
     * @return JsonResponse
     */
    public function webhook(Request $request, string $gateway): JsonResponse
    {
        Log::info('Payment webhook received', ['gateway' => $gateway]);

        // Validate gateway is supported
        if (!in_array($gateway, PaymentFactory::getAvailableGateways())) {
            Log::warning('Webhook received for unsupported gateway', ['gateway' => $gateway]);
            return response()->json(['error' => 'Gateway not supported'], 400);
        }

        try {
            $gatewayService = PaymentFactory::make($gateway);

            // ⚠️ CRITICAL: Validate webhook signature ⚠️
            $isValid = $this->validateWebhookSignature($request, $gatewayService, $gateway);

            if (!$isValid) {
                Log::warning('Webhook signature validation failed', [
                    'gateway' => $gateway,
                    'payload_size' => strlen($request->getContent()),
                ]);
                return response()->json(['error' => 'Invalid signature'], 401);
            }

            // Extract payment ID based on gateway
            $paymentId = $this->extractPaymentId($request, $gateway);

            if (!$paymentId) {
                Log::warning('Could not extract payment ID from webhook', ['gateway' => $gateway]);
                return response()->json(['error' => 'Could not process webhook'], 400);
            }

            // Check if payment exists
            $payment = $this->paymentRepository->getPaymentByPaymentId($paymentId);

            if (!$payment) {
                Log::warning('Payment not found for webhook', [
                    'payment_id' => $paymentId,
                    'gateway' => $gateway,
                ]);
                return response()->json(['error' => 'Payment not found'], 404);
            }

            // Get payment status from gateway
            $result = $gatewayService->confirmPayment($paymentId);
            $status = strtolower($result['status'] ?? 'unknown');

            // Update payment in database
            $payment = $this->paymentRepository->updatePaymentStatus(
                $paymentId,
                $status,
                $result
            );

            if (in_array($status, ['completed', 'paid', 'approved'], true)) {
                event(new PaymentConfirmed($payment));
            }

            Log::info('Webhook processed successfully', [
                'payment_id' => $paymentId,
                'gateway' => $gateway,
                'status' => $status,
            ]);

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'gateway' => $gateway,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate webhook signature based on gateway
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
     * Validate Izipay webhook signature (HMAC-SHA256)
     */
    protected function validateIzipaySignature(Request $request, $gatewayService): bool
    {
        $signature = $request->header('X-Izipay-Signature');
        return $gatewayService->verifyWebhookSignature($request->all(), $signature ?? '');
    }

    /**
     * Validate MercadoPago webhook signature (HMAC-SHA256)
     */
    protected function validateMercadoPagoSignature(Request $request, $gatewayService): bool
    {
        $signature = $request->header('x-signature');
        return $gatewayService->verifyWebhookSignature($request->all(), $signature ?? '');
    }

    /**
     * Validate PayPal webhook signature
     */
    protected function validatePayPalSignature(Request $request, $gatewayService): bool
    {
        return $gatewayService->verifyWebhookSignature($request->all(), '');
    }

    /**
     * Extract payment ID from webhook payload based on gateway
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
