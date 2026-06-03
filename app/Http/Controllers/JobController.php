<?php

namespace App\Http\Controllers;

use App\Http\Requests\JobProcessRequest;
use App\Services\LogTransferService;
use App\Services\PaymentFactory;
use App\Models\Job;
use App\Models\Transaction;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class JobController extends Controller
{
    protected LogTransferService $logTransfer;

    public function __construct(
        LogTransferService $logTransfer
    ) {
        $this->logTransfer = $logTransfer;
        
        $this->middleware('throttle:60,1', ['only' => ['connect']]);
        $this->middleware('throttle:10,1', ['only' => ['process']]);
        $this->middleware('throttle:30,1', ['only' => ['health']]);
    }

    /**
     * ✅ NUEVO: Verificar límites de tasa por usuario, IP y acción
     * 
     * @param Request $request
     * @param string $action
     * @return JsonResponse|null
     */
    private function checkRateLimit(Request $request, string $action): ?JsonResponse
    {
        $userId = auth()->id() ?? 'guest';
        $ip = $request->ip();
        
        // Configuración de límites por acción
        $limits = [
            'payment' => [
                'per_minute' => 5,      // Máximo 5 pagos por minuto
                'per_hour' => 20,       // Máximo 20 pagos por hora
                'per_day' => 50,        // Máximo 50 pagos por día
                'per_user_minute' => 3, // Máximo 3 pagos por usuario por minuto
            ],
            'checkout' => [
                'per_minute' => 10,
                'per_hour' => 50,
                'per_day' => 200,
                'per_user_minute' => 5,
            ],
            'order' => [
                'per_minute' => 20,
                'per_hour' => 100,
                'per_day' => 500,
                'per_user_minute' => 10,
            ],
            'status' => [
                'per_minute' => 30,
                'per_hour' => 200,
                'per_day' => 1000,
                'per_user_minute' => 15,
            ],
            'refund' => [
                'per_minute' => 3,
                'per_hour' => 10,
                'per_day' => 30,
                'per_user_minute' => 2,
            ],
            'cancel' => [
                'per_minute' => 3,
                'per_hour' => 10,
                'per_day' => 30,
                'per_user_minute' => 2,
            ],
            'default' => [
                'per_minute' => 10,
                'per_hour' => 50,
                'per_day' => 200,
                'per_user_minute' => 5,
            ]
        ];
        
        $limit = $limits[$action] ?? $limits['default'];
        
        // Claves para diferentes tipos de límites
        $keys = [
            'global_minute' => "rate_limit:{$action}:minute:" . date('YmdHi'),
            'global_hour' => "rate_limit:{$action}:hour:" . date('YmdH'),
            'global_day' => "rate_limit:{$action}:day:" . date('Ymd'),
            'user_minute' => "rate_limit:{$action}:user:{$userId}:minute:" . date('YmdHi'),
            'ip_minute' => "rate_limit:{$action}:ip:{$ip}:minute:" . date('YmdHi'),
            'ip_hour' => "rate_limit:{$action}:ip:{$ip}:hour:" . date('YmdH'),
        ];
        
        // Verificar límites
        $globalMinuteCount = (int)Cache::get($keys['global_minute'], 0);
        if ($globalMinuteCount >= $limit['per_minute']) {
            return $this->rateLimitResponse('GLOBAL_MINUTE_LIMIT', 60 - (int)date('s'), $limit, $globalMinuteCount);
        }
        
        $globalHourCount = (int)Cache::get($keys['global_hour'], 0);
        if ($globalHourCount >= $limit['per_hour']) {
            return $this->rateLimitResponse('GLOBAL_HOUR_LIMIT', 3600 - ((int)date('i') * 60 + (int)date('s')), $limit, $globalHourCount);
        }
        
        $globalDayCount = (int)Cache::get($keys['global_day'], 0);
        if ($globalDayCount >= $limit['per_day']) {
            return $this->rateLimitResponse('GLOBAL_DAY_LIMIT', 86400 - ((int)date('H') * 3600 + (int)date('i') * 60 + (int)date('s')), $limit, $globalDayCount);
        }
        
        if (auth()->check()) {
            $userMinuteCount = (int)Cache::get($keys['user_minute'], 0);
            if ($userMinuteCount >= $limit['per_user_minute']) {
                return $this->rateLimitResponse('USER_MINUTE_LIMIT', 60 - (int)date('s'), $limit, $userMinuteCount);
            }
        }
        
        $ipMinuteCount = (int)Cache::get($keys['ip_minute'], 0);
        if ($ipMinuteCount >= $limit['per_minute']) {
            return $this->rateLimitResponse('IP_MINUTE_LIMIT', 60 - (int)date('s'), $limit, $ipMinuteCount);
        }
        
        $ipHourCount = (int)Cache::get($keys['ip_hour'], 0);
        if ($ipHourCount >= $limit['per_hour']) {
            return $this->rateLimitResponse('IP_HOUR_LIMIT', 3600 - ((int)date('i') * 60 + (int)date('s')), $limit, $ipHourCount);
        }
        
        // Incrementar contadores
        $this->incrementRateCounters($keys);
        
        return null;
    }
    
    /**
     * ✅ NUEVO: Respuesta de rate limit excedido
     */
    private function rateLimitResponse(string $type, int $retryAfter, array $limit, int $current): JsonResponse
    {
        Log::warning('Rate limit excedido', [
            'type' => $type,
            'retry_after' => $retryAfter,
            'limit' => $limit,
            'current' => $current
        ]);
        
        return response()->json([
            'success' => false,
            'message' => "Has excedido el límite de solicitudes. Espera {$retryAfter} segundos.",
            'code' => 'RATE_LIMIT_EXCEEDED',
            'data' => [
                'limit_type' => $type,
                'retry_after' => $retryAfter,
                'current_requests' => $current,
                'max_requests' => $limit['per_minute'] ?? $limit['per_hour'] ?? $limit['per_day']
            ]
        ], 429);
    }
    
    /**
     * ✅ NUEVO: Incrementar contadores de rate limit
     */
    private function incrementRateCounters(array $keys): void
    {
        $ttl = [
            'global_minute' => 60,
            'global_hour' => 3600,
            'global_day' => 86400,
            'user_minute' => 60,
            'ip_minute' => 60,
            'ip_hour' => 3600,
        ];
        
        foreach ($keys as $type => $key) {
            Cache::increment($key);
            Cache::expire($key, $ttl[$type]);
        }
    }

    /**
     * 1. Conectar frontend con backend
     */
    public function connect(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        
        $connectionData = [
            'type' => 'connection',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => auth()->id(),
            'timestamp' => now()->toIso8601String(),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'status' => 'connected',
            'session_id' => session()->getId()
        ];

        Log::channel('payment')->info('Conexión frontend-backend', $connectionData);
        
        try {
            $this->logTransfer->storeLog($connectionData, $request);
        } catch (\Exception $e) {
            Log::error('No se pudo guardar log de conexión en BD', [
                'error' => $e->getMessage()
            ]);
        }

        $executionTime = microtime(true) - $startTime;

        return response()->json([
            'success' => true,
            'message' => 'Conexión establecida correctamente',
            'data' => [
                'status' => 'connected',
                'server_time' => now()->toIso8601String(),
                'session_id' => session()->getId(),
                'environment' => app()->environment(),
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]
        ]);
    }

    /**
     * 2. Procesar job principal usando PaymentServiceInterface
     */
    public function process(JobProcessRequest $request): JsonResponse
    {
        $startTime = microtime(true);
        
        $action = $request->input('action');
        
        // ✅ VERIFICAR RATE LIMIT ANTES DE PROCESAR
        $rateLimitResponse = $this->checkRateLimit($request, $action);
        if ($rateLimitResponse) {
            return $rateLimitResponse;
        }
        
        $processData = $this->logTransfer->logProcessStart($request, [
            'action' => $action,
            'order_id' => $request->input('order_id'),
            'gateway' => $request->input('gateway', 'mercadopago')
        ]);
        
        $jobId = $processData['job_id'];

        // Crear registro de Job
        $job = Job::create([
            'order_id' => $request->input('order_id'),
            'user_id' => auth()->id(),
            'status' => Job::STATUS_PENDING,
            'action' => $action,
            'data' => $request->input('data', []),
        ]);

        try {
            $gateway = $request->input('gateway', 'mercadopago');
            
            // Marcar Job como en procesamiento
            $job->markAsProcessing();
            
            // OBTENER SERVICIO MEDIANTE FACTORY
            $paymentService = PaymentFactory::make($gateway);
            
            $result = match ($action) {
                'payment' => $this->processPayment($request, $paymentService, $jobId, $job),
                'checkout' => $this->processCheckout($request, $jobId, $job),
                'order' => $this->processOrder($request, $jobId, $job),
                'status' => $this->getOrderStatus($request, $jobId, $job),
                'refund' => $this->processRefund($request, $paymentService, $jobId, $job),
                'cancel' => $this->processCancel($request, $paymentService, $jobId, $job),
                default => [
                    'success' => false,
                    'message' => 'Acción no implementada',
                    'code' => 'INVALID_ACTION',
                    'http_code' => 400
                ]
            };

            $executionTime = microtime(true) - $startTime;
            
            // Actualizar estado del Job
            if ($result['success'] ?? false) {
                $job->markAsCompleted();
            } else {
                $job->markAsFailed($result['message'] ?? 'Unknown error');
            }
            
            $this->logTransfer->logProcessEnd($jobId, $request, $result, $executionTime);

            return response()->json(array_merge($result, [
                'job_id' => $jobId,
                'job_db_id' => $job->id,
                'server_time' => now()->toIso8601String(),
                'execution_time_ms' => round($executionTime * 1000, 2)
            ]), $result['http_code'] ?? 200);

        } catch (\Exception $e) {
            Log::error('Error crítico en JobController', [
                'job_id' => $jobId,
                'job_db_id' => $job->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $job->markAsFailed($e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor',
                'code' => 'INTERNAL_ERROR',
                'job_id' => $jobId,
                'job_db_id' => $job->id
            ], 500);
        }
    }

    /**
     * Procesar pago usando PaymentServiceInterface
     */
    private function processPayment(JobProcessRequest $request, $paymentService, string $jobId, Job $job): array
    {
        $orderId = $request->input('order_id');
        $paymentData = $request->input('data');
        $gateway = $request->input('gateway', 'mercadopago');
        
        // Validar monto
        $amount = (float)($paymentData['amount'] ?? 0);
        if ($amount <= 0) {
            return [
                'success' => false,
                'message' => 'Monto inválido',
                'code' => 'INVALID_AMOUNT',
                'http_code' => 422
            ];
        }

        // Verificar transacción existente
        $existingTransaction = Transaction::where('order_id', $orderId)->first();
        if ($existingTransaction && in_array($existingTransaction->status, ['completed', 'processing'])) {
            return [
                'success' => false,
                'message' => 'Ya existe una transacción en proceso',
                'code' => 'DUPLICATE_TRANSACTION',
                'reference_code' => $existingTransaction->reference_code,
                'http_code' => 409
            ];
        }

        $referenceCode = Transaction::generateReferenceCode();

        // Crear transacción
        $transaction = Transaction::create([
            'transaction_id' => 'TXN-' . strtoupper(uniqid()),
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $paymentData['currency'] ?? 'PEN',
            'payment_method' => $gateway,
            'status' => 'processing',
            'customer_data' => $paymentData['customer'] ?? [],
            'reference_code' => $referenceCode,
            'gateway' => $gateway
        ]);

        // Asociar transacción con el Job
        $job->update(['transaction_id' => $transaction->id]);

        try {
            $orderData = [
                'order_id' => $orderId,
                'amount' => $amount,
                'currency' => $paymentData['currency'] ?? 'PEN',
                'customer_info' => $paymentData['customer'] ?? [],
                'email' => $paymentData['customer']['email'] ?? null,
                'description' => $paymentData['description'] ?? 'Pago en tienda',
                'return_url' => route('api.payment.confirm'),
                'cancel_url' => route('api.payment.cancel'),
            ];
            
            $gatewayResult = $paymentService->createPayment($orderData);
            
            $transaction->update([
                'payment_id' => $gatewayResult['id'] ?? $gatewayResult['payment_id'] ?? null,
                'raw_response' => $gatewayResult
            ]);
            
            return [
                'success' => true,
                'message' => '✅ Sesión de pago creada',
                'code' => 'PAYMENT_SESSION_CREATED',
                'reference_code' => $referenceCode,
                'transaction_id' => $transaction->transaction_id,
                'payment_url' => $gatewayResult['payment_url'] ?? null,
                'form_token' => $gatewayResult['form_token'] ?? null,
                'status' => 'pending',
                'http_code' => 201
            ];
            
        } catch (\Exception $e) {
            $transaction->markAsFailed($e->getMessage());
            
            return [
                'success' => false,
                'message' => '❌ Error: ' . $e->getMessage(),
                'code' => 'GATEWAY_ERROR',
                'reference_code' => $referenceCode,
                'http_code' => 402
            ];
        }
    }

    /**
     * Procesar reembolso
     */
    private function processRefund(JobProcessRequest $request, $paymentService, string $jobId, Job $job): array
    {
        $orderId = $request->input('order_id');
        $transaction = Transaction::where('order_id', $orderId)->first();
        
        if (!$transaction || $transaction->status !== 'completed') {
            return [
                'success' => false,
                'message' => 'No se puede reembolsar',
                'code' => 'REFUND_NOT_AVAILABLE',
                'http_code' => 400
            ];
        }
        
        try {
            $result = $paymentService->refundPayment(
                $transaction->payment_id ?? $transaction->transaction_id,
                $request->input('data.amount', null)
            );
            
            $transaction->update(['status' => 'refunded']);
            
            return [
                'success' => true,
                'message' => '✅ Reembolso procesado',
                'code' => 'REFUND_SUCCESS',
                'data' => ['refund_id' => $result['refund_id'] ?? null],
                'http_code' => 200
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error en reembolso: ' . $e->getMessage(),
                'code' => 'REFUND_ERROR',
                'http_code' => 500
            ];
        }
    }

    /**
     * Procesar cancelación de pago
     */
    private function processCancel(JobProcessRequest $request, $paymentService, string $jobId, Job $job): array
    {
        $orderId = $request->input('order_id');
        $paymentId = $request->input('payment_id');
        $reason = $request->input('reason', 'Cancelado por el usuario');
        
        // Buscar la transacción
        $transaction = Transaction::where('order_id', $orderId)
            ->orWhere('payment_id', $paymentId)
            ->first();
        
        if (!$transaction) {
            return [
                'success' => false,
                'message' => 'Transacción no encontrada',
                'code' => 'TRANSACTION_NOT_FOUND',
                'http_code' => 404
            ];
        }
        
        // Verificar si la transacción puede ser cancelada
        if (!in_array($transaction->status, ['processing', 'pending'])) {
            return [
                'success' => false,
                'message' => 'La transacción no puede ser cancelada porque ya está ' . $transaction->status,
                'code' => 'CANCEL_NOT_ALLOWED',
                'http_code' => 400
            ];
        }
        
        try {
            // Verificar si el servicio de pago soporta cancelación
            if (!method_exists($paymentService, 'cancelPayment')) {
                Log::warning('Gateway no soporta cancelación directa', [
                    'gateway' => get_class($paymentService),
                    'transaction_id' => $transaction->id
                ]);
                
                $transaction->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_reason' => $reason
                ]);
                
                return [
                    'success' => true,
                    'message' => '⚠️ Transacción cancelada localmente (gateway no soporta cancelación)',
                    'code' => 'CANCEL_LOCAL_ONLY',
                    'data' => [
                        'transaction_id' => $transaction->transaction_id,
                        'status' => 'cancelled',
                        'cancelled_at' => now()->toIso8601String()
                    ],
                    'http_code' => 200
                ];
            }
            
            // Cancelar en el gateway
            $cancelResult = $paymentService->cancelPayment(
                $transaction->payment_id ?? $transaction->transaction_id,
                $reason
            );
            
            // Actualizar transacción
            $transaction->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'raw_cancel_response' => $cancelResult
            ]);
            
            // Actualizar orden si existe
            $order = Order::find($transaction->order_id);
            if ($order) {
                $order->update(['status' => 'cancelled']);
            }
            
            return [
                'success' => true,
                'message' => '✅ Pago cancelado exitosamente',
                'code' => 'CANCEL_SUCCESS',
                'data' => [
                    'transaction_id' => $transaction->transaction_id,
                    'payment_id' => $transaction->payment_id,
                    'status' => 'cancelled',
                    'cancelled_at' => now()->toIso8601String(),
                    'gateway_response' => $cancelResult
                ],
                'http_code' => 200
            ];
            
        } catch (\Exception $e) {
            Log::error('Error al cancelar pago', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => '❌ Error al cancelar: ' . $e->getMessage(),
                'code' => 'CANCEL_ERROR',
                'http_code' => 500
            ];
        }
    }

    private function processCheckout(JobProcessRequest $request, string $jobId, Job $job): array
    {
        $orderId = $request->input('order_id');
        $checkoutData = $request->input('data');
        
        $order = Order::firstOrCreate(
            ['order_id' => $orderId],
            [
                'user_id' => auth()->id(),
                'total_amount' => $checkoutData['total'] ?? 0,
                'items' => $checkoutData['items'] ?? [],
                'shipping_address' => $checkoutData['shipping_address'] ?? [],
                'billing_address' => $checkoutData['billing_address'] ?? [],
                'status' => 'checkout'
            ]
        );

        // Actualizar Job con la orden
        $job->update(['order_id' => $order->id]);
        
        return [
            'success' => true,
            'message' => '✅ Checkout completado',
            'code' => 'CHECKOUT_SUCCESS',
            'data' => [
                'order_id' => $orderId,
                'total' => $order->total_amount,
                'status' => 'ready_for_payment'
            ],
            'next_action' => 'payment',
            'http_code' => 200
        ];
    }

    private function processOrder(JobProcessRequest $request, string $jobId, Job $job): array
    {
        $orderId = $request->input('order_id');
        $orderData = $request->input('data');
        
        $order = Order::updateOrCreate(
            ['order_id' => $orderId],
            [
                'user_id' => auth()->id(),
                'total_amount' => $orderData['total'] ?? 0,
                'items' => $orderData['items'] ?? [],
                'shipping_address' => $orderData['shipping_address'] ?? [],
                'billing_address' => $orderData['billing_address'] ?? [],
                'status' => 'pending_payment'
            ]
        );

        // Actualizar Job con la orden
        $job->update(['order_id' => $order->id]);
        
        return [
            'success' => true,
            'message' => '✅ Orden creada',
            'code' => 'ORDER_CREATED',
            'data' => ['order_id' => $orderId, 'status' => $order->status],
            'http_code' => 201
        ];
    }

    private function getOrderStatus(JobProcessRequest $request, string $jobId, Job $job): array
    {
        $orderId = $request->input('order_id');
        
        $order = Order::where('order_id', $orderId)->first();
        $transaction = Transaction::where('order_id', $orderId)->first();
        
        if (!$order && !$transaction) {
            return [
                'success' => false,
                'message' => 'Orden no encontrada',
                'code' => 'ORDER_NOT_FOUND',
                'http_code' => 404
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Estado recuperado',
            'code' => 'STATUS_RETRIEVED',
            'data' => [
                'order_id' => $orderId,
                'order_status' => $order->status ?? null,
                'payment_status' => $transaction->status ?? 'pending',
                'reference_code' => $transaction->reference_code ?? null
            ],
            'http_code' => 200
        ];
    }

    public function health(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'job-controller',
            'version' => '2.0.0',
            'timestamp' => now()->toIso8601String(),
            'gateways' => PaymentFactory::getAvailableGateways()
        ]);
    }
}