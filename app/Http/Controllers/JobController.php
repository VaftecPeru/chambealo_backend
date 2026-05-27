<?php

namespace App\Http\Controllers;

use App\Http\Controllers\PaymentController;
use App\Http\Controllers\LogController;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class JobController extends Controller
{
    /**
     * Conectar frontend con backend
     * ✅ Registra en log de archivo
     * ✅ Transfiere log al backend (él lo guarda en BD)
     */
    public function connect(Request $request): JsonResponse
    {
        $connectionData = [
            'type' => 'connection',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => auth()->id(),
            'timestamp' => now()->toIso8601String(),
            'endpoint' => $request->path(),
            'method' => $request->method(),
            'status' => 'connected'
        ];

        // ✅ 1. Registrar en log de archivo (NO BD)
        Log::channel('daily')->info('Conexión frontend-backend', $connectionData);

        // ✅ 2. Transferir log al backend para que LO GUARDE EN BD
        $this->transferLogToBackend($connectionData, $request);

        // Verificar HTTPS
        if (!$request->secure()) {
            $errorData = [
                'type' => 'security_error',
                'ip' => $request->ip(),
                'error' => 'HTTPS requerido',
                'timestamp' => now()->toIso8601String()
            ];
            
            Log::channel('daily')->warning('Conexión insegura', $errorData);
            $this->transferLogToBackend($errorData, $request);
            
            return response()->json([
                'success' => false,
                'message' => 'HTTPS requerido'
            ], 426);
        }

        return response()->json([
            'success' => true,
            'message' => 'Conexión segura establecida',
            'data' => [
                'status' => 'connected',
                'log_transferred' => true,
                'timestamp' => now()
            ]
        ]);
    }

    /**
     * Procesar job y transferir logs
     */
    public function process(Request $request): JsonResponse
    {
        $startTime = microtime(true);

        // ✅ Registrar inicio del proceso
        $processLog = [
            'type' => 'job_process',
            'job_id' => uniqid('job_'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id' => auth()->id(),
            'action' => $request->input('action'),
            'order_id' => $request->input('order_id'),
            'timestamp_start' => now()->toIso8601String(),
            'status' => 'processing'
        ];

        Log::channel('daily')->info('Job process started', $processLog);
        $this->transferLogToBackend($processLog, $request);

        // Validar datos
        $validator = Validator::make($request->all(), [
            'action' => 'required|string|in:payment,checkout,order,status',
            'data' => 'required|array',
            'order_id' => 'required|string'
        ]);

        if ($validator->fails()) {
            $errorLog = [
                'type' => 'validation_error',
                'ip' => $request->ip(),
                'user_id' => auth()->id(),
                'errors' => $validator->errors()->toArray(),
                'timestamp' => now()->toIso8601String()
            ];
            
            Log::channel('daily')->warning('Validación fallida', $errorLog);
            $this->transferLogToBackend($errorLog, $request);

            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        // Rate limiting
        $rateLimitResult = $this->checkRateLimit($request);
        
        if (!$rateLimitResult['allowed']) {
            $rateLimitLog = [
                'type' => 'rate_limit_exceeded',
                'ip' => $request->ip(),
                'user_id' => auth()->id(),
                'attempts' => $rateLimitResult['attempts'],
                'timestamp' => now()->toIso8601String()
            ];
            
            Log::channel('daily')->warning('Rate limit excedido', $rateLimitLog);
            $this->transferLogToBackend($rateLimitLog, $request);
            
            return response()->json([
                'success' => false,
                'message' => 'Demasiadas peticiones'
            ], 429);
        }

        // Transferir al backend correspondiente
        $result = $this->transferToBackend($validator->validated(), $request);

        // ✅ Registrar finalización del proceso
        $executionTime = microtime(true) - $startTime;
        
        $completionLog = [
            'type' => 'job_completed',
            'job_id' => $processLog['job_id'],
            'ip' => $request->ip(),
            'user_id' => auth()->id(),
            'action' => $request->input('action'),
            'order_id' => $request->input('order_id'),
            'success' => $result['success'],
            'execution_time_ms' => round($executionTime * 1000, 2),
            'timestamp_end' => now()->toIso8601String()
        ];

        Log::channel('daily')->info('Job process completed', $completionLog);
        $this->transferLogToBackend($completionLog, $request);

        return response()->json($result, $result['status'] ?? 200);
    }

    /**
     * Transferir log al backend (él lo guardará en BD)
     */
    private function transferLogToBackend(array $logData, Request $request): void
    {
        try {
            // ✅ Opción 1: Llamar internamente a LogController
            $logRequest = new Request([
                'log_type' => $logData['type'] ?? 'job_log',
                'log_data' => json_encode($logData),
                'ip' => $logData['ip'] ?? $request->ip(),
                'user_id' => $logData['user_id'] ?? auth()->id(),
                'user_agent' => $logData['user_agent'] ?? $request->userAgent(),
                'timestamp' => $logData['timestamp'] ?? now()->toIso8601String()
            ]);

            $logController = app(LogController::class);
            $logController->store($logRequest);

            // ✅ Opción 2: Enviar por HTTP a endpoint de logs (si está en otro servidor)
            // Http::post(config('app.backend_url') . '/api/logs', $logData);

        } catch (\Exception $e) {
            // Si falla la transferencia, solo registrar en log local
            Log::channel('daily')->error('Error transfiriendo log al backend', [
                'error' => $e->getMessage(),
                'log_data' => $logData
            ]);
        }
    }

    /**
     * Transferir datos al backend correspondiente
     */
    private function transferToBackend(array $validated, Request $request): array
    {
        $action = $validated['action'];
        $data = $validated['data'];
        $orderId = $validated['order_id'];

        // Registrar transferencia
        $transferLog = [
            'type' => 'data_transfer',
            'action' => $action,
            'order_id' => $orderId,
            'ip' => $request->ip(),
            'user_id' => auth()->id(),
            'timestamp' => now()->toIso8601String()
        ];
        
        Log::channel('daily')->info('Transfiriendo datos al backend', $transferLog);
        $this->transferLogToBackend($transferLog, $request);

        switch ($action) {
            case 'payment':
                return $this->transferToPayment($data, $orderId, $request);
            
            case 'checkout':
                return $this->transferToCheckout($data, $orderId, $request);
            
            case 'order':
                return $this->transferToOrder($data, $orderId, $request);
            
            case 'status':
                return $this->transferToStatus($data, $orderId, $request);
            
            default:
                return [
                    'success' => false,
                    'message' => 'Acción no válida',
                    'status' => 400
                ];
        }
    }

    /**
     * Transferir datos de pago
     */
    private function transferToPayment(array $data, string $orderId, Request $request): array
    {
        // Validar firma si viene de webhook
        $signature = $request->header('X-Signature');
        if ($signature && !$this->validateSignature($data, $signature)) {
            $signatureLog = [
                'type' => 'invalid_signature',
                'order_id' => $orderId,
                'ip' => $request->ip(),
                'timestamp' => now()->toIso8601String()
            ];
            
            Log::channel('daily')->warning('Firma inválida', $signatureLog);
            $this->transferLogToBackend($signatureLog, $request);
            
            return [
                'success' => false,
                'message' => 'Firma inválida',
                'status' => 401
            ];
        }

        // Crear request para PaymentController
        $paymentRequest = new Request([
            'order_id' => $orderId,
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? 'PEN',
            'payment_method' => $data['payment_method'] ?? 'izipay',
            'customer_data' => $data['customer'] ?? [],
            'items' => $data['items'] ?? [],
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        // Llamar a PaymentController (ÉL guardará en BD)
        $paymentController = app(PaymentController::class);
        $response = $paymentController->store($paymentRequest);
        
        $responseData = $response->getData();

        // Registrar resultado de transferencia
        $resultLog = [
            'type' => 'payment_transfer_result',
            'order_id' => $orderId,
            'success' => $responseData->success ?? true,
            'payment_id' => $responseData->payment_id ?? null,
            'ip' => $request->ip(),
            'timestamp' => now()->toIso8601String()
        ];
        
        Log::channel('daily')->info('Resultado transferencia pago', $resultLog);
        $this->transferLogToBackend($resultLog, $request);

        return [
            'success' => $responseData->success ?? true,
            'message' => $responseData->message ?? 'Pago procesado',
            'data' => [
                'order_id' => $orderId,
                'payment_id' => $responseData->payment_id ?? null,
                'status' => $responseData->status ?? 'pending',
                'redirect_url' => $responseData->redirect_url ?? null
            ],
            'status' => 200
        ];
    }

    /**
     * Transferir datos de checkout
     */
    private function transferToCheckout(array $data, string $orderId, Request $request): array
    {
        $checkoutRequest = new Request([
            'order_id' => $orderId,
            'customer' => $data['customer'] ?? [],
            'shipping_address' => $data['shipping_address'] ?? [],
            'billing_address' => $data['billing_address'] ?? [],
            'items' => $data['items'] ?? [],
            'total' => $data['total'] ?? 0,
            'taxes' => $data['taxes'] ?? 0,
            'shipping_cost' => $data['shipping_cost'] ?? 0,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        // Llamar a CheckoutController
        $checkoutController = app(CheckoutController::class);
        $response = $checkoutController->process($checkoutRequest);
        
        $responseData = $response->getData();

        return [
            'success' => true,
            'message' => 'Checkout procesado',
            'data' => [
                'order_id' => $orderId,
                'checkout_id' => $responseData->checkout_id ?? null,
                'status' => 'ready'
            ],
            'status' => 200
        ];
    }

    /**
     * Transferir datos de orden
     */
    private function transferToOrder(array $data, string $orderId, Request $request): array
    {
        $orderRequest = new Request([
            'order_id' => $orderId,
            'customer_id' => auth()->id(),
            'items' => $data['items'] ?? [],
            'total' => $data['total'] ?? 0,
            'status' => 'pending',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        $orderController = app(OrderController::class);
        $response = $orderController->store($orderRequest);
        
        $responseData = $response->getData();

        return [
            'success' => true,
            'message' => 'Orden creada',
            'data' => [
                'order_id' => $orderId,
                'db_order_id' => $responseData->id ?? null,
                'status' => 'created'
            ],
            'status' => 200
        ];
    }

    /**
     * Consultar estado
     */
    private function transferToStatus(array $data, string $orderId, Request $request): array
    {
        return [
            'success' => true,
            'message' => 'Estado consultado',
            'data' => [
                'order_id' => $orderId,
                'status' => 'processing',
                'updated_at' => now()
            ],
            'status' => 200
        ];
    }

    /**
     * Rate limiting con cache
     */
    private function checkRateLimit(Request $request): array
    {
        $key = 'job_limit_' . auth()->id() . '_' . date('Y-m-d_H');
        $attempts = Cache::get($key, 0);

        if ($attempts >= 60) {
            return [
                'allowed' => false,
                'attempts' => $attempts
            ];
        }

        Cache::put($key, $attempts + 1, 3600);
        
        return [
            'allowed' => true,
            'attempts' => $attempts + 1
        ];
    }

    /**
     * Validar firma
     */
    private function validateSignature(array $data, string $signature): bool
    {
        $expected = hash_hmac('sha256', json_encode($data), config('app.key'));
        return hash_equals($expected, $signature);
    }

    /**
     * Health check
     */
    public function health(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'job-controller',
            'role' => 'data_transfer_and_logger',
            'security' => [
                'jwt' => auth()->check(),
                'https' => $request->secure()
            ]
        ]);
    }
}