<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name before the rules run.
     *
     * Without this step "brocoli" would pass the unique rule while BROCOLI
     * exists and blow up against the unique index with a 500 instead of a 422.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Product::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * Los dos campos son opcionales por separado, pero required_without cruzado impide
         * el cuerpo vacío: un PATCH sin datos es un error del cliente, no una operación sin efecto.
         * El ignore() del propio id permite reenviar el mismo nombre sin chocar consigo mismo.
         */
        return [
            'name' => ['required_without:status', 'string', 'max:255', Rule::unique('products', 'name')->ignore($this->route('product'))],
            'status' => ['required_without:name', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required_without' => 'Debe enviar al menos el nombre o el estado',
            'name.string' => 'El nombre del producto debe ser texto',
            'name.max' => 'El nombre del producto no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un producto con ese nombre',
            'status.required_without' => 'Debe enviar al menos el nombre o el estado',
            'status.boolean' => 'El estado debe ser verdadero o falso',
        ];
    }
}
