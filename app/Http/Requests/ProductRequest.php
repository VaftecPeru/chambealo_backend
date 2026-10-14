<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El acceso por rol ya lo controla el middleware de la ruta.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('slug') && $this->filled('name')) {
            $this->merge(['slug' => Str::slug($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('product')?->product_id;

        return [
            'brand_id'       => ['required', 'integer', 'exists:brands,brand_id'],
            'category_id'    => ['required', 'integer', 'exists:categories,category_id'],
            'name'           => ['required', 'string', 'max:255'],
            'slug'           => ['required', 'string', 'max:255',
                                 Rule::unique('products', 'slug')->ignore($id, 'product_id')],
            'description'    => ['nullable', 'string', 'max:5000'],
            'price'          => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'stock'          => ['sometimes', 'integer', 'min:0'],
            'status'         => ['sometimes', Rule::in(['active', 'inactive', 'out_of_stock'])],
            'specifications' => ['nullable', 'array'],
        ];
    }
}