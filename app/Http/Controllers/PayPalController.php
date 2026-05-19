<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
// Importación exacta de tus modelos según tu imagen
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Plan;
use App\Models\Audit; 
use App\Mail\PaymentConfirmed;

class PayPalController extends Controller
{
    /**
     * Obtener el tenant ID actual
     */
    private function getTenantId()
    {
        return app('tenant_id');
    }

    /**
     * Obtener el tenant ID con validación
     */
    private function getTenantIdOrFail()
    {
        $tenantId = $this->getTenantId();
        
        if (!$tenantId) {
            Log::error('Tenant no identificado en PayPalController', [
                'user_id' => auth()->id(),
                'ip' => request()->ip()
            ]);
            
            throw new \Exception('Tenant not identified');
        }
        
        return $tenantId;
    }

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
        // Obtener tenant ID
        $tenantId = $this->getTenantId();
        
        // VALIDACIÓN DE SEGURIDAD: Recalcular en backend
        $plan = Plan::findOrFail($request->plan_id);
        
        // Verificar que el plan pertenece al tenant
        if ($plan->tenant_id != $tenantId) {
            Log::error('Intento de acceder a plan de otro tenant', [
                'tenant_id' => $tenantId,
                'plan_tenant_id' => $plan->tenant_id,
                'user_id' => auth()->id()
            ]);
            
            return response()->json(['error' => 'Plan no válido para este tenant'], 403);
        }
        
        $calculatedTotal = $this->calculatePlanTotal($plan);
        
        // Validar si el frontend envió un monto manipulado
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
        
        // Crear orden con el monto recalculado
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

        // Guardar en sesión para validar después (incluyendo tenant_id)
        session(['paypal_calculated_total' => $calculatedTotal]);
        session(['paypal_plan_id' => $plan->plan_id]);
        session(['paypal_tenant_id' => $tenantId]); // ← Guardar tenant en sesión

        return response()->json($response->json());
    }

    public function captureOrder(Request $request)
    {
        $orderID = $request->orderID;
        $accessToken = $this->getPayPalAccessToken();

        // Recuperar datos de la sesión
        $calculatedTotal = session('paypal_calculated_total');
        $planId = session('paypal_plan_id');
        $sessionTenantId = session('paypal_tenant_id'); // ← Recuperar tenant de sesión
        $currentTenantId = $this->getTenantId();

        // Validar consistencia del tenant
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
            // VALIDACIÓN DE SEGURIDAD: Verificar monto capturado
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
                
                // Limpiar sesión
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
        // Validar que viene por HTTPS en producción
        if (config('app.env') === 'production' && !$request->secure()) {
            Log::warning('Webhook recibido sin HTTPS', [
                'ip' => $request->ip(),
                'url' => $request->fullUrl()
            ]);
            return response()->json(['error' => 'HTTPS Required'], 400);
        }

        $event = $request->all();
        
        // Para webhooks, el tenant puede venir en los metadatos o determinarse por el plan
        $tenantId = null;
        
        switch ($event['event_type']) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                // Actualizar orden como pagada
                try {
                    DB::transaction(function () use ($event, &$tenantId) {
                        // Extraer los datos del evento
                        $captureData = $event['resource'];
                        
                        // Intentar obtener tenant_id de los metadatos o del plan
                        $customId = $captureData['custom_id'] ?? null;
                        if ($customId) {
                            $tenantId = $customId;
                        }
                        
                        // Verificar si ya existe el pago
                        $existingPayment = Payment::where('order_id', $captureData['id'])->first();
                        
                        if (!$existingPayment) {
                            // Crear estructura similar a la de captureOrder
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
                // Marcar como fallida
                try {
                    $captureData = $event['resource'];
                    
                    // Buscar y actualizar el pago si existe
                    $payment = Payment::where('order_id', $captureData['id'])->first();
                    if ($payment) {
                        $payment->update(['status' => 'denied']);
                        Log::info('Webhook: Pago denegado', [
                            'order_id' => $captureData['id'],
                            'tenant_id' => $payment->tenant_id
                        ]);
                    } else {
                        // Crear registro de pago denegado
                        Payment::create([
                            'order_id' => $captureData['id'],
                            'email' => $captureData['payer_email'] ?? 'unknown@example.com',
                            'amount' => $captureData['amount']['value'],
                            'status' => 'denied',
                            'tenant_id' => $tenantId, // ← Asignar tenant
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

    /**
     * Método auxiliar para recalcular montos en backend (seguro)
     */
    private function calculatePlanTotal(Plan $plan)
    {
        // Tu lógica de cálculo (precio + impuestos - descuentos)
        $subtotal = $plan->price;
        $tax = $subtotal * 0.16; // Ejemplo: 16% IVA
        $discount = 0; // Aquí puedes aplicar descuentos por cupón
        
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
        /** @var \App\Models\User $user */
        $user = auth()->user();
        
        // Si no se pasó tenantId, obtener del contenedor
        if (!$tenantId) {
            $tenantId = $this->getTenantId();
        }
        
        $amount = $data['purchase_units'][0]['payments']['captures'][0]['amount']['value'];

        // 1. Crear el registro de pago (con tenant_id)
        $payment = Payment::create([
            'order_id' => $data['id'],
            'email'    => $data['payer']['email_address'],
            'amount'   => $amount,
            'status'   => 'completed',
            'tenant_id' => $tenantId, // ← Asignar tenant al pago
        ]);

        // 2. Lógica de Plan y Suscripción (filtrando por tenant)
        $plan = Plan::where('tenant_id', $tenantId) // ← Filtrar por tenant
            ->where('price', '<=', $amount)
            ->orderBy('price', 'desc')
            ->first();
        
        if ($plan && $user) {
            // Verificar que el usuario pertenece al tenant
            if ($user->tenant_id != $tenantId) {
                Log::error('Usuario no pertenece al tenant', [
                    'user_id' => $user->user_id,
                    'user_tenant' => $user->tenant_id,
                    'payment_tenant' => $tenantId
                ]);
                throw new \Exception('User does not belong to this tenant');
            }
            
            Subscription::create([
                'user_id'    => $user->user_id,
                'plan_id'    => $plan->plan_id,
                'start_date' => now(),
                'end_date'   => now()->addDays($plan->duration_days),
                'status'     => 'active',
                'tenant_id'  => $tenantId, // ← Asignar tenant a la suscripción
            ]);

            // Actualizar rol del usuario
            $user->update(['role' => 'premium']);

            // 3. Registrar en Auditoría (usando tu modelo Audit.php)
            Audit::create([
                'entity_type'  => 'Payment',
                'entity_id'    => $payment->id,
                'action'       => 'PAYMENT_SUCCESS',
                'performed_by' => $user->user_id,
                'description'  => "Compra de plan completada por {$amount} USD",
                'ip_address'   => request()->ip(),
                'user_agent'   => request()->userAgent()
            ]);
            
            // 4. Enviar Email
            Mail::to($user->email)->send(new PaymentConfirmed($payment, $plan));
            
            Log::info('Suscripción creada exitosamente', [
                'user_id' => $user->user_id,
                'plan_id' => $plan->plan_id,
                'tenant_id' => $tenantId
            ]);
            
        } elseif ($plan && !$user) {
            // Si no hay usuario autenticado (webhook), no podemos asignar la suscripción
            Log::warning('Webhook: Pago recibido pero no hay usuario autenticado', [
                'order_id' => $data['id'],
                'tenant_id' => $tenantId
            ]);
        } else {
            Log::warning('No se encontró un plan válido para el pago', [
                'amount' => $amount,
                'tenant_id' => $tenantId
            ]);
        }
    }
}