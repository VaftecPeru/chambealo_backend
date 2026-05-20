<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Plan;
use App\Models\Audit;
use App\Mail\PaymentConfirmed;

class PayPalController extends Controller
{
    private function getPayPalAccessToken()
    {
        $clientId = config('services.paypal.client_id');
        $clientSecret = config('services.paypal.secret');

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post('https://api-m.sandbox.paypal.com/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        return $response->json()['access_token'];
    }

    public function createOrder(Request $request)
    {
        $tenantId = $this->getTenantId();

        $plan = Plan::findOrFail($request->plan_id);

        if ($plan->tenant_id != $tenantId) {
            Log::error('Intento de acceder a plan de otro tenant', [
                'tenant_id' => $tenantId,
                'plan_tenant_id' => $plan->tenant_id,
                'user_id' => auth()->id()
            ]);

            return response()->json(['error' => 'Plan no válido para este tenant'], 403);
        }

        $calculatedTotal = $this->calculatePlanTotal($plan);

        $frontendAmount = $request->amount;
        if ($frontendAmount !== null && (float)$frontendAmount !== (float)$calculatedTotal) {
            Log::error('Intento de manipulación de monto', [
                'user_id' => auth()->id(),
                'tenant_id' => $tenantId,
                'frontend_amount' => $frontendAmount,
                'calculated_total' => $calculatedTotal
            ]);

            Audit::create([
                'entity_type' => 'Payment',
                'entity_id' => null,
                'action' => 'AMOUNT_MANIPULATION_ATTEMPT',
                'performed_by' => auth()->id(),
                'description' => "Intento de manipulación: frontend envió {$frontendAmount}, backend calculó {$calculatedTotal}",
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json(['error' => 'Monto inválido'], 400);
        }

        $accessToken = $this->getPayPalAccessToken();

        $response = Http::withToken($accessToken)
            ->post('https://api-m.sandbox.paypal.com/v2/checkout/orders', [
                "intent" => "CAPTURE",
                "purchase_units" => [[
                    "amount" => [
                        "currency_code" => "USD",
                        "value" => number_format($calculatedTotal, 2, '.', '')
                    ]
                ]]
            ]);

        session(['paypal_calculated_total' => $calculatedTotal]);
        session(['paypal_plan_id' => $plan->plan_id]);
        session(['paypal_tenant_id' => $tenantId]);

        return response()->json($response->json());
    }

    public function captureOrder(Request $request)
    {
        $orderID = $request->orderID;
        $accessToken = $this->getPayPalAccessToken();

        $calculatedTotal = session('paypal_calculated_total');
        $planId = session('paypal_plan_id');
        $sessionTenantId = session('paypal_tenant_id');
        $currentTenantId = $this->getTenantId();

        if ($sessionTenantId && $currentTenantId && $sessionTenantId != $currentTenantId) {
            Log::error('Inconsistencia de tenant en captura de pago', [
                'session_tenant' => $sessionTenantId,
                'current_tenant' => $currentTenantId,
                'user_id' => auth()->id()
            ]);

            return response()->json(['error' => 'Sesión inválida'], 400);
        }

        if (!$calculatedTotal || !$planId) {
            Log::error('No hay datos de pago en sesión', [
                'user_id' => auth()->id(),
                'tenant_id' => $currentTenantId
            ]);
            return response()->json(['error' => 'Sesión inválida'], 400);
        }

        $response = Http::withToken($accessToken)
            ->post("https://api-m.sandbox.paypal.com/v2/checkout/orders/{$orderID}/capture");

        $data = $response->json();

        if (isset($data['status']) && $data['status'] === 'COMPLETED') {
            $capturedAmount = $data['purchase_units'][0]['payments']['captures'][0]['amount']['value'];

            if ((float)$capturedAmount !== (float)$calculatedTotal) {
                Log::error('Discrepancia en el monto capturado', [
                    'user_id' => auth()->id(),
                    'tenant_id' => $currentTenantId,
                    'captured_amount' => $capturedAmount,
                    'expected_amount' => $calculatedTotal
                ]);

                Audit::create([
                    'entity_type' => 'Payment',
                    'entity_id' => null,
                    'action' => 'PAYPAL_AMOUNT_MISMATCH',
                    'performed_by' => auth()->id(),
                    'description' => "Discrepancia: PayPal capturó {$capturedAmount}, se esperaba {$calculatedTotal}",
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent()
                ]);

                return response()->json(['error' => 'Error de validación de pago'], 400);
            }

            try {
                DB::transaction(function () use ($data, $currentTenantId) {
                    $this->saveTransaction($data, $currentTenantId);
                });

                session()->forget(['paypal_calculated_total', 'paypal_plan_id', 'paypal_tenant_id']);

                return response()->json(['message' => 'Pago procesado exitosamente']);
            } catch (\Exception $e) {
                return response()->json(['error' => 'Error en DB: ' . $e->getMessage()], 500);
            }
        }
        return response()->json(['error' => 'No se pudo capturar el pago'], 500);
    }

    public function handleWebhook(Request $request)
    {
        if (config('app.env') === 'production' && !$request->secure()) {
            Log::warning('Webhook recibido sin HTTPS', [
                'ip' => $request->ip(),
                'url' => $request->fullUrl()
            ]);
            return response()->json(['error' => 'HTTPS Required'], 400);
        }

        $event = $request->all();
        $tenantId = null;

        switch ($event['event_type']) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                try {
                    DB::transaction(function () use ($event, &$tenantId) {
                        $captureData = $event['resource'];

                        $customId = $captureData['custom_id'] ?? null;
                        if ($customId) {
                            $tenantId = $customId;
                        }

                        $existingPayment = Payment::where('order_id', $captureData['id'])->first();

                        if (!$existingPayment) {
                            $data = [
                                'id' => $captureData['id'],
                                'status' => 'COMPLETED',
                                'purchase_units' => [[
                                    'payments' => [
                                        'captures' => [[
                                            'amount' => [
                                                'value' => $captureData['amount']['value']
                                            ]
                                        ]]
                                    ]
                                ]],
                                'payer' => [
                                    'email_address' => $captureData['payer_email'] ?? $event['resource']['payer']['email_address'] ?? 'unknown@example.com'
                                ]
                            ];

                            $this->saveTransaction($data, $tenantId);
                            Log::info('Webhook: Pago completado procesado', [
                                'order_id' => $captureData['id'],
                                'tenant_id' => $tenantId
                            ]);
                        } else {
                            Log::info('Webhook: Pago ya existente', [
                                'order_id' => $captureData['id'],
                                'tenant_id' => $existingPayment->tenant_id
                            ]);
                        }
                    });
                } catch (\Exception $e) {
                    Log::error('Webhook error en PAYMENT.CAPTURE.COMPLETED: ' . $e->getMessage());
                }
                break;

            case 'PAYMENT.CAPTURE.DENIED':
                try {
                    $captureData = $event['resource'];

                    $payment = Payment::where('order_id', $captureData['id'])->first();
                    if ($payment) {
                        $payment->update(['status' => 'denied']);
                        Log::info('Webhook: Pago denegado', [
                            'order_id' => $captureData['id'],
                            'tenant_id' => $payment->tenant_id
                        ]);
                    } else {
                        Payment::create([
                            'order_id' => $captureData['id'],
                            'email' => $captureData['payer_email'] ?? 'unknown@example.com',
                            'amount' => $captureData['amount']['value'],
                            'status' => 'denied',
                            'tenant_id' => $tenantId,
                        ]);
                        Log::info('Webhook: Pago denegado registrado', [
                            'order_id' => $captureData['id'],
                            'tenant_id' => $tenantId
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error('Webhook error en PAYMENT.CAPTURE.DENIED: ' . $e->getMessage());
                }
                break;

            default:
                Log::info('Webhook evento no manejado', ['event_type' => $event['event_type']]);
                break;
        }

        return response()->json(['status' => 'ok']);
    }

    private function calculatePlanTotal(Plan $plan)
    {
        $subtotal = $plan->price;
        $tax = $subtotal * 0.16;
        $discount = 0;

        $total = $subtotal + $tax - $discount;

        Log::info('Total recalculado en backend', [
            'plan_id' => $plan->plan_id,
            'tenant_id' => $plan->tenant_id ?? null,
            'price' => $plan->price,
            'tax' => $tax,
            'discount' => $discount,
            'total' => $total
        ]);

        return $total;
    }

        private function saveTransaction(array $data, $tenantId = null)
    {
        // 1. Intentar capturar el usuario (Corregido el doble '\')
        $user = auth()->user() ?? \App\Models\User::where('email', $data['payer']['email_address'])->first();

        // 2. Si no se pasa el tenantId de forma directa, se recupera del contenedor
        if (!$tenantId) {
            $tenantId = $this->getTenantId();
        }

        // Extraer monto de la captura de PayPal
        $amount = $data['purchase_units'][0]['payments']['captures'][0]['amount']['value'];
        
        // Recuperar el custom_id enviado originalmente como puente
        $customId = $data['purchase_units'][0]['custom_id'] ?? $data['id'];

        // 3. Registrar o actualizar el Pago (Sintaxis de arreglos corregida)
        $payment = Payment::updateOrCreate(
            ['identificador' => $customId],
            [
                'tenant_id' => $tenantId,
                'plan_id'   => $data['plan_id'] ?? null,
                'monto'     => $amount,
                'estado'    => 'completado'
            ]
        );

        // 4. Localizar el plan idóneo en base al monto cobrado
        $plan = Plan::where('tenant_id', $tenantId)
            ->where('price', '<=', $amount)
            ->orderBy('price', 'desc')
            ->first();

        if ($plan && $user) {
            
            // Validación opcional de consistencia Multi-tenant
            if ($user->tenant_id && $user->tenant_id != $tenantId) {
                Log::error('Usuario no pertenece al tenant del pago', [
                    'user_id' => $user->user_id,
                    'user_tenant' => $user->tenant_id,
                    'payment_tenant' => $tenantId
                ]);
                return;
            }

            // 5. Crear la suscripción correspondiente
            Subscription::create([
                'user_id'    => $user->user_id,
                'plan_id'    => $plan->plan_id,
                'start_date' => now(),
                'end_date'   => now()->addDays($plan->duration_days),
                'status'     => 'active',
                'tenant_id'  => $tenantId,
            ]);

            // 6. Actualización segura del rol
            \App\Models\User::where('user_id', $user->user_id)->update(['role' => 'premium']);

            // 7. Registro fidedigno de Auditoría (Corregido)
            Audit::create([
                'entity_type'  => 'Payment',
                'entity_id'    => $payment->id,
                'action'       => 'PAYMENT_SUCCESS',
                'performed_by' => $user->user_id,
                'description'  => "Compra de plan completada por {$payment->monto} USD",
                'ip_address'   => request()->ip(),
                'user_agent'   => request()->userAgent()
            ]);

            // 8. Notificaciones de éxito externas e internas
            Mail::to($user->email)->send(new PaymentConfirmed($payment, $plan));

            Log::info('Suscripción e infraestructura creada exitosamente en Webhook', [
                'user_id'   => $user->user_id,
                'plan_id'   => $plan->plan_id,
                'tenant_id' => $tenantId
            ]);

        } elseif ($plan && !$user) {
            Log::warning('Webhook: Pago recibido pero no se encontró un usuario con el email del pagador', [
                'paypal_email' => $data['payer']['email_address'],
                'custom_id'    => $customId,
                'tenant_id'    => $tenantId
            ]);
        } else {
            Log::warning('No se encontró un plan válido para el pago configurado', [
                'amount'    => $amount,
                'tenant_id' => $tenantId
            ]);
        }
    }
}