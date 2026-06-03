<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PaymentSessionRequest
 * Solicitud para crear una sesión de pago
 */
class PaymentSessionRequest extends FormRequest
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
     * 
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'gateway' => 'required|in:izipay,mercadopago,paypal',
            'order_id' => 'required|exists:orders,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'email' => 'required|email',
            'description' => 'nullable|string|max:255',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:20',
            'customer_document' => 'nullable|string|max:50',
        ];
    }

    /**
     * Get custom messages for validator errors.
     * 
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gateway.required' => 'El gateway de pago es requerido',
            'gateway.in' => 'El gateway no está soportado',
            'order_id.required' => 'El ID de orden es requerido',
            'order_id.exists' => 'La orden no existe',
            'amount.required' => 'El monto es requerido',
            'amount.numeric' => 'El monto debe ser un número',
            'amount.min' => 'El monto debe ser mayor a 0',
            'currency.required' => 'La moneda es requerida',
            'currency.size' => 'El código de moneda debe ser de 3 caracteres',
            'email.required' => 'El email es requerido',
            'email.email' => 'El email no es válido',
        ];
    }
}
