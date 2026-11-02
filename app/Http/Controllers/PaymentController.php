<?php

namespace App\Http\Controllers;

use App\Events\PaymentConfirmed;
use App\Models\Order;
use App\Repositories\PaymentRepository;
use App\Services\PaymentFactory;
use App\Services\Payments\PayPalCaptureValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use App\Services\Payments\PenToUsdConverter;
use App\Services\Payments\PayPalExchangeRate;

class PaymentController extends Controller
{
    protected PaymentRepository $paymentRepository;

    public function __construct(PaymentRepository $paymentRepository)
    {
        $this->paymentRepository = $paymentRepository;

        $this->middleware('auth:api')
            ->only(['createSession', 'confirm']);

        $this->middleware('throttle:5,1')
            ->only(['createSession', 'confirm']);

        $this->middleware('throttle:20,1')
            ->only(['webhook']);
    }

    /**
     * POST /api/payment/session
     *
     * Crear una sesión de pago.
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
            ->when(
                $tenantId,
                fn ($query) => $query->where('tenant_id', $tenantId)
            );

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

        if (!in_array($order->status, [
            Order::STATUS_CHECKOUT,
            Order::STATUS_PAYMENT_PENDING,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'El pedido no esta disponible para iniciar pago.',
            ], 422);
        }

        $amount = (float) $order->total_amount;

        $currency = strtoupper(
            config('payment.default_currency', 'PEN')
        );

        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'El importe del pedido no es valido.',
            ], 422);
        }

        $email = $order->user?->email ?? $user->email;

        $frontendUrl = rtrim(
            config('payment.frontend_url', config('app.url')),
            '/'
        );

        $existingPayment = $this->paymentRepository
            ->getPaymentByOrderId($order->order_id);

        if ($existingPayment) {
            $existingStatus = strtolower(
                $existingPayment->status
            );

            if (in_array($existingStatus, [
                'completed',
                'paid',
            ], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este pedido ya fue pagado.',
                ], 422);
            }

            if (in_array($existingStatus, [
                'creating',
                'creation_uncertain',
                'pending',
                'approved',
            ], true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este pedido ya tiene una sesion de pago activa o pendiente de revision.',
                ], 409);
            }
        }

        /*
         * Flujo exclusivo para PayPal.
         *
         * El pedido permanece expresado en PEN.
         * La reserva registra el importe convertido a USD.
         */
        if ($validated['gateway'] === 'paypal') {
            if ($currency !== 'PEN') {
                return response()->json([
                    'success' => false,
                    'message' => 'La conversion PayPal esta configurada solamente para pedidos en PEN.',
                ], 422);
            }

            try {
                $conversion = $this->preparePayPalConversion(
                    $order
                );
            } catch (Throwable $e) {
                Log::warning('PayPal conversion unavailable', [
                    'order_id' => $order->order_id,
                    'error_type' => get_class($e),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No hay una cotizacion valida para procesar el pago con PayPal.',
                ], 503);
            }

            /*
             * Reservar ANTES de llamar a PayPal.
             * El repositorio impide crear dos reservas
             * para el mismo pedido.
             */
            try {
                $payment = $this->paymentRepository
                    ->reservePayPalSession([
                        'order_id' => $order->order_id,
                        'gateway' => 'paypal',
                        'email' => $email,
                        'user_id' => $user->user_id,
                        'tenant_id' => $order->tenant_id,
                        ...$conversion,
                    ]);
            } catch (Throwable $e) {
                Log::warning('PayPal reservation rejected', [
                    'order_id' => $order->order_id,
                    'error_type' => get_class($e),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo reservar el pago. Verifica si el pedido ya tiene una sesion.',
                ], 409);
            }

            /*
             * Desde este punto, si no obtenemos una
             * respuesta final valida, conservamos
             * la reserva para reconciliacion.
             */
            try {
                $gateway = PaymentFactory::make('paypal');

                $result = $gateway->createPayment([
                    'reservation_id' => $payment->id,
                    'order_id' => $order->order_id,
                    'amount' => $conversion['amount'],
                    'currency' => 'USD',
                    'email' => $email,
                    'description' => $validated['description']
                        ?? "Pedido {$order->order_id}",
                    'user_id' => $user->user_id,
                    'tenant_id' => $order->tenant_id,
                    'return_url' => $frontendUrl . '/pago-exitoso',
                    'cancel_url' => $frontendUrl . '/pago-cancelado',
                    'webhook_url' => route(
                        'api.payment.webhook',
                        ['gateway' => 'paypal']
                    ),
                ]);

                $payment = $this->paymentRepository
                    ->finalizePayPalSession(
                        $payment->id,
                        $result
                    );
            } catch (Throwable $e) {
                Log::error('PayPal session creation uncertain', [
                    'order_id' => $order->order_id,
                    'payment_id' => $payment->id,
                    'error_type' => get_class($e),
                ]);

                try {
                    $this->paymentRepository
                        ->markPayPalSessionUncertain(
                            $payment->id,
                            'La creacion externa no pudo confirmarse.'
                        );
                } catch (Throwable $markError) {
                    Log::critical(
                        'Could not mark PayPal reservation uncertain',
                        [
                            'payment_id' => $payment->id,
                            'error_type' => get_class($markError),
                        ]
                    );
                }

                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo confirmar la creacion de la orden PayPal. El pedido requiere revision antes de reintentar.',
                ], 502);
            }

            if ($order->status === Order::STATUS_CHECKOUT) {
                $order->update([
                    'status' => Order::STATUS_PAYMENT_PENDING,
                ]);
            }

            Log::info('PayPal session created', [
                'payment_id' => $payment->id,
                'order_id' => $order->order_id,
                'gateway' => 'paypal',
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'payment_id' => $payment->id,
                    'gateway_id' => $result['id'],
                    'form_token' => null,
                    'init_point' => null,
                    'approve_url' => $result['approve_url'],
                    'redirect_url' => $result['approve_url'],
                    'amount' => $conversion['amount'],
                    'currency' => 'USD',
                    'original_amount' => $conversion['original_amount'],
                    'original_currency' => 'PEN',
                    'exchange_rate' => $conversion['exchange_rate'],
                ],
            ]);
        }

        /*
         * Flujo anterior para Izipay y Mercado Pago.
         * No se modifica su funcionamiento.
         */
        $gateway = PaymentFactory::make(
            $validated['gateway']
        );

        $result = $gateway->createPayment([
            'order_id' => $order->order_id,
            'amount' => $amount,
            'currency' => $currency,
            'email' => $email,
            'description' => $validated['description']
                ?? "Pedido {$order->order_id}",
            'user_id' => $user->user_id,
            'tenant_id' => $order->tenant_id,
            'return_url' => $frontendUrl . '/pago-exitoso',
            'cancel_url' => $frontendUrl . '/pago-cancelado',
            'webhook_url' => route(
                'api.payment.webhook',
                ['gateway' => $validated['gateway']]
            ),
        ]);

        $payment = $this->paymentRepository
            ->upsertPaymentForOrder([
                'order_id' => $order->order_id,
                'gateway' => $validated['gateway'],
                'payment_id' => $result['id']
                    ?? $result['payment_id']
                    ?? null,
                'status' => 'pending',
                'amount' => $amount,
                'currency' => $currency,
                'email' => $email,
                'user_id' => $user->user_id,
                'tenant_id' => $order->tenant_id,
                'raw_response' => $result,
            ]);

        if ($order->status === Order::STATUS_CHECKOUT) {
            $order->update([
                'status' => Order::STATUS_PAYMENT_PENDING,
            ]);
        }

        Log::info('Payment session created', [
            'payment_id' => $payment->id,
            'order_id' => $order->order_id,
            'gateway' => $validated['gateway'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'payment_id' => $payment->id,
                'gateway_id' => $result['id']
                    ?? $result['payment_id']
                    ?? null,
                'form_token' => $result['form_token'] ?? null,
                'init_point' => $result['init_point']
                    ?? $result['sandbox_init_point']
                    ?? null,
                'approve_url' => $result['approve_url']
                    ?? null,
                'redirect_url' => $result['approve_url']
                    ?? $result['init_point']
                    ?? $result['sandbox_init_point']
                    ?? null,
            ],
        ]);
    } catch (Throwable $e) {
        Log::error('Payment session creation failed', [
            'error_type' => get_class($e),
            'gateway' => $validated['gateway'] ?? null,
        ]);

        return response()->json([
            'success' => false,
            'message' => 'No se pudo iniciar el pago.',
        ], 500);
    }
}
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gateway' => 'required|in:izipay,mercadopago,paypal',
            'payment_id' => 'required|string',
        ]);

        try {
            $user = auth('api')->user();

            $payment = $this->paymentRepository
                ->getPaymentByPaymentId($validated['payment_id']);

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Pago no encontrado.',
                ], 404);
            }

            if (!$user->isAdmin()
                && (int) $payment->user_id !== (int) $user->user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'No autorizado para confirmar este pago.',
                ], 403);
            }

            if ($payment->gateway !== $validated['gateway']) {
                return response()->json([
                    'success' => false,
                    'message' => 'El proveedor no coincide con el pago registrado.',
                ], 422);
            }

            $gateway = PaymentFactory::make($validated['gateway']);

            if ($validated['gateway'] === 'paypal') {
                $validator = app(PayPalCaptureValidator::class);

                // Verificar la orden antes de intentar capturar.
                $before = $gateway->getOrder(
                    $validated['payment_id']
                );

                if (($before['id'] ?? null)
                    !== $validated['payment_id']) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Orden PayPal incorrecta.',
                    ], 422);
                }

                if (!$this->paypalPurchaseUnitMatches(
                    $payment,
                    $before
                )) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Importe o referencia de PayPal incorrectos.',
                    ], 422);
                }

                if (!in_array(
                    $before['status'] ?? '',
                    ['APPROVED', 'COMPLETED'],
                    true
                )) {
                    return response()->json([
                        'success' => false,
                        'message' => 'La orden PayPal no esta aprobada.',
                    ], 422);
                }

                // confirmPayment utiliza un identificador estable
                // para los reintentos de captura.
                $gateway->confirmPayment(
                    $validated['payment_id']
                );

                // Consultar el estado real de la captura.
                $verifiedOrder = $gateway->getOrder(
                    $validated['payment_id']
                );

                // Exigir una captura completada y verificada.
                $verification = $validator->validate(
                    $payment,
                    $verifiedOrder
                );

                $result = array_merge(
                    $verifiedOrder,
                    $verification
                );

                $status = 'completed';

                // Respuesta idempotente para confirmaciones repetidas.
                if ($payment->status === 'completed') {
                    return response()->json([
                        'success' => true,
                        'status' => 'completed',
                        'message' => 'Pago ya confirmado.',
                    ]);
                }
            } else {
                // Conservar el funcionamiento actual
                // de Izipay y Mercado Pago.
                $result = $gateway->confirmPayment(
                    $validated['payment_id']
                );

                $status = strtolower(
                    $result['status'] ?? 'unknown'
                );
            }

            $payment = $this->paymentRepository
                ->updatePaymentStatus(
                    $validated['payment_id'],
                    $status,
                    $result
                );

            if (in_array(
                $status,
                ['completed', 'paid'],
                true
            )) {
                event(new PaymentConfirmed($payment));
            }

            Log::info('Payment confirmed', [
                'payment_id' => $validated['payment_id'],
                'gateway' => $validated['gateway'],
                'status' => $status,
            ]);

            return response()->json([
                'success' => true,
                'status' => $status,
                'message' => "Payment {$status}",
            ]);
        } catch (InvalidArgumentException $e) {
            Log::warning('Invalid payment verification', [
                'error' => $e->getMessage(),
                'payment_id' => $validated['payment_id'],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Los datos de la captura no coinciden.',
            ], 422);
        } catch (Throwable $e) {
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
     * POST /api/payment/webhook/{gateway}
     *
     * Procesar notificaciones verificadas del proveedor.
     */
    public function webhook(
        Request $request,
        string $gateway
    ): JsonResponse {
        if (!in_array(
            $gateway,
            PaymentFactory::getAvailableGateways(),
            true
        )) {
            return response()->json([
                'error' => 'Gateway not supported',
            ], 400);
        }

        try {
            $gatewayService = PaymentFactory::make($gateway);

            if (!$this->validateWebhookSignature(
                $request,
                $gatewayService,
                $gateway
            )) {
                Log::warning('Webhook signature validation failed', [
                    'gateway' => $gateway,
                ]);

                return response()->json([
                    'error' => 'Invalid signature',
                ], 401);
            }

            // Por ahora, únicamente procesamos el evento
            // de captura completada de PayPal.
            if ($gateway === 'paypal'
                && $request->input('event_type')
                    !== 'PAYMENT.CAPTURE.COMPLETED') {
                return response()->json([
                    'success' => true,
                    'message' => 'Evento PayPal ignorado.',
                ]);
            }

            $paymentId = $this->extractPaymentId(
                $request,
                $gateway
            );

            if (!$paymentId) {
                return response()->json([
                    'error' => 'Could not process webhook',
                ], 400);
            }

            $payment = $this->paymentRepository
                ->getPaymentByPaymentId($paymentId);

            if (!$payment) {
                return response()->json([
                    'error' => 'Payment not found',
                ], 404);
            }

            if ($payment->gateway !== $gateway) {
                return response()->json([
                    'error' => 'Gateway mismatch',
                ], 422);
            }

            if ($gateway === 'paypal') {
                // IMPORTANTE: los webhooks no capturan pagos.
                $result = $gatewayService->getOrder($paymentId);

                $validator = app(
                    PayPalCaptureValidator::class
                );

                $verification = $validator->validate(
                    $payment,
                    $result,
                    $request->input('resource.id')
                );

                // Comparar los datos del evento firmado
                // con los datos consultados a PayPal.
                if (
                    $request->input(
                        'resource.amount.currency_code'
                    ) !== $verification['currency']
                    ||
                    $request->input(
                        'resource.amount.value'
                    ) !== $verification['amount']
                    ||
                    $request->input('resource.status')
                        !== 'COMPLETED'
                ) {
                    return response()->json([
                        'error' => 'Webhook amount mismatch',
                    ], 422);
                }

                if ($payment->status === 'completed') {
                    return response()->json([
                        'success' => true,
                        'message' => 'Pago ya confirmado.',
                    ]);
                }

                $result = array_merge(
                    $result,
                    $verification
                );

                $status = 'completed';
            } else {
                // Mantener los proveedores existentes.
                $result = $gatewayService->confirmPayment(
                    $paymentId
                );

                $status = strtolower(
                    $result['status'] ?? 'unknown'
                );
            }

            $payment = $this->paymentRepository
                ->updatePaymentStatus(
                    $paymentId,
                    $status,
                    $result
                );

            if (in_array(
                $status,
                ['completed', 'paid'],
                true
            )) {
                event(new PaymentConfirmed($payment));
            }

            Log::info('Payment webhook processed', [
                'gateway' => $gateway,
                'payment_id' => $paymentId,
                'status' => $status,
            ]);

            return response()->json([
                'success' => true,
            ]);
        } catch (InvalidArgumentException $e) {
            Log::warning('Webhook payment validation failed', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Payment verification failed',
            ], 422);
        } catch (Throwable $e) {
            Log::error('Webhook processing failed', [
                'gateway' => $gateway,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Webhook processing failed',
            ], 500);
        }
    }

    /**
     * Verificar referencia, moneda e importe de una
     * unidad de compra antes de capturar la orden.
     */
    protected function paypalPurchaseUnitMatches(
        $payment,
        array $paypalOrder
    ): bool {
        $units = $paypalOrder['purchase_units'] ?? [];

        if (!is_array($units) || count($units) !== 1) {
            return false;
        }

        $unit = $units[0];

        $amount = $unit['amount'] ?? [];

        $expected = number_format(
            (float) $payment->amount,
            2,
            '.',
            ''
        );

        return ($unit['reference_id'] ?? null)
                === (string) $payment->order_id
            && ($amount['currency_code'] ?? null)
                === $payment->currency
            && ($amount['value'] ?? null)
                === $expected;
    }

    /**
     * Verificar la firma del webhook.
     */
    protected function validateWebhookSignature(
        Request $request,
        $gatewayService,
        string $gateway
    ): bool {
        return match ($gateway) {
            'izipay' => $this->validateIzipaySignature(
                $request,
                $gatewayService
            ),
            'mercadopago' => $this->validateMercadoPagoSignature(
                $request,
                $gatewayService
            ),
            'paypal' => $this->validatePayPalSignature(
                $request,
                $gatewayService
            ),
            default => false,
        };
    }

    /**
     * Firma Izipay.
     */
    protected function validateIzipaySignature(
        Request $request,
        $gatewayService
    ): bool {
        $signature = $request->header('X-Izipay-Signature');

        return $gatewayService->verifyWebhookSignature(
            $request->all(),
            $signature ?? ''
        );
    }

    /**
     * Firma Mercado Pago.
     */
    protected function validateMercadoPagoSignature(
        Request $request,
        $gatewayService
    ): bool {
        $signature = $request->header('x-signature');

        return $gatewayService->verifyWebhookSignature(
            $request->all(),
            $signature ?? ''
        );
    }

    /**
     * Firma PayPal.
     */
    protected function validatePayPalSignature(
        Request $request,
        $gatewayService
    ): bool {
        return $gatewayService->verifyWebhookRequest(
            $request
        );
    }

    /**
     * Obtener el identificador externo.
     *
     * Para PAYMENT.CAPTURE.COMPLETED,
     * resource.id es el Capture ID, no el Order ID.
     */
    protected function extractPaymentId(
        Request $request,
        string $gateway
    ): ?string {
        return match ($gateway) {
            'izipay' => $request->input('paymentId')
                ?? $request->input('payment_id'),

            'mercadopago' => $request->input('data.id'),

            'paypal' => $request->input(
                'resource.supplementary_data.related_ids.order_id'
            ),

            default => null,
        };
    }

/**
 * Preparar conversion del importe original PEN a USD.
 *
 * No crea ordenes ni realiza cobros.
 */
protected function preparePayPalConversion(Order $order): array
{
    $quote = app(PayPalExchangeRate::class)->getQuote();

    $originalAmount = (string) $order->getRawOriginal('total_amount');

    $convertedAmount = app(PenToUsdConverter::class)->convert(
        $originalAmount,
        $quote['rate']
    );

    return [
        'amount' => $convertedAmount,
        'currency' => 'USD',
        'original_amount' => $originalAmount,
        'original_currency' => 'PEN',
        'exchange_rate' => $quote['rate'],
        'exchange_rate_source' => $quote['source'],
        'exchange_rate_at' => $quote['quoted_at'],
    ];
}
}
