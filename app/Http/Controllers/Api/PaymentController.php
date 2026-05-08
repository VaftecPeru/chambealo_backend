<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

#librerias de mercadopago
use MercadoPago\SDK;
use MercadoPago\Payment;


class PaymentController extends Controller
{
    #método para mercadopago
    public function process(Request $request)
    {
        // 1. Configuración del Token
        SDK::setAccessToken(config('services.mercadopago.access_token'));

        // 2. Obtener datos del Body
        $json = json_decode($request->getContent(), true);

        // Validar que el JSON no esté vacío
        if (!$json) {
            return response()->json(["message" => "JSON inválido o vacío"], 400);
        }

        $payment = new Payment();
        
        // CORRECCIÓN: Cambié $data por $json en todas las líneas
        $payment->transaction_amount = (float)($json['transaction_amount'] ?? 0);
        $payment->token = $json['token'] ?? '';
        $payment->description = "Servicio en Chambealo";
        $payment->installments = (int)($json['installments'] ?? 1);
        $payment->payment_method_id = $json['payment_method_id'] ?? null;
        
        // También corregido aquí
        $payment->payer = array(
            "email" => $json['payer']['email'] ?? null
        );

        try {
            $payment->save();

            if (!$payment->id) {
                return response()->json([
                    "message" => "Error en la validación de Mercado Pago",
                    "error_detail" => $payment->error 
                ], 400);
            }

            return response()->json([
                "status" => $payment->status,
                "status_detail" => $payment->status_detail,
                "id" => $payment->id
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                "error" => "Error de conexión o servidor: " . $e->getMessage()
            ], 500);
        }
    }

    # método para izipay
    public function createToken(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.10',
            'email'  => 'required|email',
        ]);

        // En PHP 8.1 podemos usar unpacking o named arguments si fuera necesario
        $config = config('izipay');

        $response = Http::withBasicAuth($config['client_id'], $config['secret'])
            ->post("{$config['url']}/api-payment/V4/Charge/CreatePayment", [
                'amount'   => (int)($request->amount * 100),
                'currency' => 'PEN',
                'orderId'  => 'ORD-' . bin2hex(random_bytes(4)), // Generación segura en PHP 8
                'customer' => [
                    'email' => $request->email
                ],
            ]);

        if ($response->failed()) {
            return response()->json([
                'error' => 'No se pudo generar el token de pago',
                'details' => $response->json()
            ], 500);
        }

        // Retornamos el answer que contiene el formToken
        return response()->json($response->json()['answer']);
    }

    /**
     * Paso 2: Webhook (IPN)
     */
    public function webhook(Request $request): JsonResponse
    {
        $krAnswer = $request->input('kr-answer');
        $krHash   = $request->input('kr-hash');
        
        if (!$krAnswer || !$krHash) {
            return response()->json(['error' => 'Datos insuficientes'], 400);
        }

        // Validación HMAC SHA256
        $calculatedHash = hash_hmac('sha256', $krAnswer, config('izipay.hash_key'));

        if (!hash_equals($calculatedHash, $krHash)) {
            Log::error('Intento de fraude o error de configuración en Hash Izipay');
            return response()->json(['error' => 'Hash inválido'], 403);
        }

        $data = json_decode($krAnswer, true);

        // PHP 8.1: Match expression para manejar estados (opcional pero elegante)
        $status = $data['orderStatus'] ?? 'UNKNOWN';

        if ($status === 'PAID') {
            // Lógica para marcar como pagado
            Log::info("Pago exitoso: Order " . $data['orderDetails']['orderId']);
            return response()->json(['status' => 'OK']);
        }

        return response()->json(['status' => 'pending_or_failed']);
    }
}
      /*  try {
            // 3. Ejecutar el pago
            $payment->save();

            return response()->json([
                "status" => $payment->status,
                "status_detail" => $payment->status_detail,
                "id" => $payment->id
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                "error" => "Error en la transacción: " . $e->getMessage()
            ], 500);
        }
    }
}
*/
