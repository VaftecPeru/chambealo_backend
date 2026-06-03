<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * JobProcessRequest
 * Solicitud unificada para procesar diferentes acciones de pago
 * Maneja: payment, checkout, order, status, refund
 */
class JobProcessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * 
     * @return bool
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     * Las reglas varían según la acción
     * 
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return match($this->input('action')) {
            'payment' => $this->paymentRules(),
            'checkout' => $this->checkoutRules(),
            'order' => $this->orderRules(),
            'status' => $this->statusRules(),
            'refund' => $this->refundRules(),
            default => ['action' => 'required|in:payment,checkout,order,status,refund'],
        };
    }

    /**
     * Get custom messages for validator errors.
     * 
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => 'La acción es requerida',
            'action.in' => 'La acción no es válida',
            'gateway.required' => 'El gateway de pago es requerido',
            'gateway.in' => 'El gateway no está soportado',
            'amount.required' => 'El monto es requerido',
            'amount.numeric' => 'El monto debe ser un número',
            'amount.min' => 'El monto debe ser mayor a 0',
            'order_id.required' => 'El ID de orden es requerido',
            'order_id.exists' => 'La orden no existe',
            'email.required' => 'El email es requerido',
            'email.email' => 'El email no es válido',
            'currency.required' => 'La moneda es requerida',
            'currency.size' => 'El código de moneda debe ser de 3 caracteres',
            'payment_id.required' => 'El ID de pago es requerido',
            'refund_amount.required' => 'El monto de reembolso es requerido',
            'refund_amount.numeric' => 'El monto de reembolso debe ser un número',
            'refund_amount.min' => 'El monto de reembolso debe ser mayor a 0',
        ];
    }

    /**
     * Reglas para acción 'payment'
     * Crear una sesión de pago
     * 
     * @return array
     */
    private function paymentRules(): array
    {
        return [
            'action' => 'required|in:payment',
            'gateway' => 'required|in:izipay,mercadopago,paypal',
            'order_id' => 'required|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'email' => 'required|email',
            'description' => 'nullable|string|max:255',
        ];
    }

    /**
     * Reglas para acción 'checkout'
     * Similar a payment pero puede incluir datos adicionales
     * 
     * @return array
     */
    private function checkoutRules(): array
    {
        return [
            'action' => 'required|in:checkout',
            'gateway' => 'required|in:izipay,mercadopago,paypal',
            'order_id' => 'required|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'email' => 'required|email',
            'description' => 'nullable|string|max:255',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
        ];
    }

    /**
     * Reglas para acción 'order'
     * Obtener detalles de una orden
     * 
     * @return array
     */
    private function orderRules(): array
    {
        return [
            'action' => 'required|in:order',
            'order_id' => 'required|exists:orders,id',
        ];
    }

    /**
     * Reglas para acción 'status'
     * Obtener estado de un pago
     * 
     * @return array
     */
    private function statusRules(): array
    {
        return [
            'action' => 'required|in:status',
            'gateway' => 'required|in:izipay,mercadopago,paypal',
            'payment_id' => 'required|string',
        ];
    }

    /**
     * Reglas para acción 'refund'
     * Procesar reembolso
     * 
     * @return array
     */
    private function refundRules(): array
    {
        return [
            'action' => 'required|in:refund',
            'gateway' => 'required|in:izipay,mercadopago,paypal',
            'payment_id' => 'required|string',
            'refund_amount' => 'nullable|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
        ];
    }
}
