<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El acceso por rol se resuelve con middleware (C-037).
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Si no mandan slug, se genera a partir del nombre.
        if (!$this->filled('slug') && $this->filled('name')) {
            $this->merge(['slug' => Str::slug($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $category = $this->route('category');
        $id = $category?->category_id;

        $parentRules = ['nullable', 'integer', 'exists:categories,category_id'];
        if ($id) {
            // Una categoría no puede ser su propio padre.
            $parentRules[] = Rule::notIn([$id]);
        }

        return [
            'name'        => ['required', 'string', 'max:255'],
            'slug'        => ['required', 'string', 'max:255',
                              Rule::unique('categories', 'slug')->ignore($id, 'category_id')],
            'parent_id'   => $parentRules,
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}