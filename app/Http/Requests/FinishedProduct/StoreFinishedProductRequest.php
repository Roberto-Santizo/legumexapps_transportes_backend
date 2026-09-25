<?php

namespace App\Http\Requests\FinishedProduct;

use App\Models\FinishedProduct;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFinishedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the code and the name before the rules run.
     *
     * The code is trimmed and upper cased; the name is only upper cased, keeping every
     * space exactly as typed.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => FinishedProduct::normalizeCode($this->input('code'))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => FinishedProduct::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * El code no lleva regla unique a propósito: la de Laravel no ve las filas
         * borradas, y el duplicado lo levanta el service con 400. El exists: de clientId
         * tampoco ve el borrado lógico: un cliente borrado lo para el service con 400.
         */
        return [
            'code' => ['required', 'string', 'max:15', 'regex:/^\S+$/u'],
            'name' => ['required', 'string', 'max:255'],
            'presentation' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'boxesPerPallet' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'clientId' => ['required', 'integer', 'exists:clients,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'El código del producto terminado es obligatorio',
            'code.string' => 'El código del producto terminado debe ser texto',
            'code.max' => 'El código del producto terminado no puede superar los 15 caracteres',
            'code.regex' => 'El código no puede contener espacios',
            'name.required' => 'El nombre del producto terminado es obligatorio',
            'name.string' => 'El nombre del producto terminado debe ser texto',
            'name.max' => 'El nombre del producto terminado no puede superar los 255 caracteres',
            'presentation.required' => 'La presentación es obligatoria',
            'presentation.numeric' => 'La presentación debe ser numérica',
            'presentation.min' => 'La presentación debe ser mayor a 0',
            'presentation.max' => 'La presentación no puede superar 99999999.99',
            'boxesPerPallet.required' => 'Las cajas por tarima son obligatorias',
            'boxesPerPallet.numeric' => 'Las cajas por tarima deben ser numéricas',
            'boxesPerPallet.min' => 'Las cajas por tarima deben ser mayores a 0',
            'boxesPerPallet.max' => 'Las cajas por tarima no pueden superar 99999999.99',
            'clientId.required' => 'El cliente es obligatorio',
            'clientId.integer' => 'El cliente debe ser un identificador válido',
            'clientId.exists' => 'El cliente seleccionado no existe',
        ];
    }
}
