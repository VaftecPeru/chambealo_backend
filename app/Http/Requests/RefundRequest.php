<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RefundRequest
 * Solicitud para procesar un reembolso
 */
class RefundRequest extends FormRequest
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
            'payment_id' => 'required|string',
            'refund_amount' => 'nullable|numeric|min:0.01',
            'reason' => 'nullable|string|max:500',
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
            'payment_id.required' => 'El ID de pago es requerido',
            'refund_amount.numeric' => 'El monto de reembolso debe ser un número',
            'refund_amount.min' => 'El monto de reembolso debe ser mayor a 0',
            'reason.max' => 'La razón no puede exceder 500 caracteres',
        ];
    }
}
