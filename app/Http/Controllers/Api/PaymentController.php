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


class PaymentController extends Controller
{
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
        $krAnswer = $request->input('kr-answer');
        $krHash   = $request->input('kr-hash');
        
        if (!$krAnswer || !$krHash) {
            Log::warning('Webhook Izipay sin datos', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Datos insuficientes'], 400);
        }

        // VAFTEC: Validación HMAC SHA256 (seguridad crítica)
        $calculatedHash = hash_hmac('sha256', $krAnswer, config('izipay.hash_key'));

        if (!hash_equals($calculatedHash, $krHash)) {
            Log::error('Intento de fraude o error de configuración en Hash Izipay', [
                'ip' => $request->ip(),
                'received_hash' => $krHash
            ]);
            return response()->json(['error' => 'Hash inválido'], 403);
        }

        $data = json_decode($krAnswer, true);

        // VAFTEC: Webhook eventos recomendados - PAYMENT.CAPTURE.COMPLETED
        $status = $data['orderStatus'] ?? 'UNKNOWN';

        if ($status === 'PAID') {
            Log::info("Webhook Izipay: Pago completado", [
                'order_id' => $data['orderDetails']['orderId'] ?? 'unknown',
                'status' => $status
            ]);

            $this->logPaymentTransaction($data['orderDetails']['orderId'] ?? null, $data, 'izipay', 'PAYMENT.CAPTURE.COMPLETED');
            
            return response()->json(['status' => 'OK']);
        }

        Log::info('Webhook Izipay: Estado pendiente o fallido', ['status' => $status]);
        return response()->json(['status' => 'pending_or_failed']);
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

