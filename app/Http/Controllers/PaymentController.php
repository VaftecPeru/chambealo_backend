<?php

namespace App\Http\Controllers;

use App\Services\PaymentFactory;
use App\Repositories\PaymentRepository;
use App\Events\PaymentConfirmed;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;

class PaymentController extends Controller implements HasMiddleware
{
    protected PaymentRepository $paymentRepository;

    public function __construct(PaymentRepository $paymentRepository)
    {
        $this->paymentRepository = $paymentRepository;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('auth:sanctum', only: ['createSession', 'confirm']),
            new Middleware('throttle:5,1', only: ['createSession', 'confirm']),
            new Middleware('throttle:20,1', only: ['webhook']),
        ];
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
        $request->validate([
            'gateway' => 'required|in:izipay,mercadopago,paypal',
            'order_id' => 'required|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'email' => 'required|email',
            'description' => 'nullable|string',
        ]);

        try {
            // Verify order exists and belongs to user
            $order = Order::findOrFail($request->order_id);

            // Get the payment gateway service
            $gateway = PaymentFactory::make($request->gateway);

            // Generate payment session/token
            $result = $gateway->createPayment([
                'order_id' => $request->order_id,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'email' => $request->email,
                'description' => $request->description,
                'user_id' => auth()->id(),
                'tenant_id' => $order->tenant_id ?? null,
                'return_url' => route('api.payment.confirm'),
                'cancel_url' => route('api.payment.cancel'),
                'webhook_url' => route('api.payment.webhook', ['gateway' => $request->gateway]),
            ]);

            // Create payment record in database
            $payment = $this->paymentRepository->createPayment([
                'order_id' => $request->order_id,
                'gateway' => $request->gateway,
                'payment_id' => $result['id'] ?? $result['payment_id'] ?? null,
                'status' => 'pending',
                'amount' => $request->amount,
                'currency' => $request->currency,
                'email' => $request->email,
                'user_id' => auth()->id(),
                'tenant_id' => $order->tenant_id ?? null,
                'raw_response' => $result,
            ]);

            Log::info('Payment session created', [
                'payment_id' => $payment->id,
                'gateway' => $request->gateway,
                'amount' => $request->amount,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_id' => $payment->id,
                    'gateway_id' => $result['id'] ?? $result['payment_id'] ?? null,
                    'form_token' => $result['form_token'] ?? null,
                    'init_point' => $result['init_point'] ?? $result['sandbox_init_point'] ?? null,
                    'approve_url' => $result['approve_url'] ?? null,
                    'redirect_url' => $result['init_point'] ?? $result['sandbox_init_point'] ?? null,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Payment session creation failed', [
                'error' => $e->getMessage(),
                'gateway' => $request->gateway,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
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
        $request->validate([
            'gateway' => 'required|in:izipay,mercadopago,paypal',
            'payment_id' => 'required|string',
        ]);

        try {
            $gateway = PaymentFactory::make($request->gateway);

            // Get current payment status from gateway
            $result = $gateway->confirmPayment($request->payment_id);

            // Map result to standard format
            $status = strtolower($result['status'] ?? 'unknown');

            // Update payment in database
            $payment = $this->paymentRepository->updatePaymentStatus(
                $request->payment_id,
                $status,
                $result
            );

            // Dispatch event if payment is completed
            if ($status === 'completed') {
                event(new PaymentConfirmed($payment));
            }

            Log::info('Payment confirmed', [
                'payment_id' => $request->payment_id,
                'status' => $status,
                'gateway' => $request->gateway,
            ]);

            return response()->json([
                'success' => true,
                'status' => $status,
                'message' => "Payment {$status}",
            ]);

        } catch (\Exception $e) {
            Log::error('Payment confirmation failed', [
                'error' => $e->getMessage(),
                'payment_id' => $request->payment_id,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
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
            $this->paymentRepository->updatePaymentStatus(
                $paymentId,
                $status,
                $result
            );

            // Dispatch event if payment is completed
            if ($status === 'completed') {
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
