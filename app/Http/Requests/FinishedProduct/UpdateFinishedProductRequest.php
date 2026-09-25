<?php

namespace App\Http\Requests\FinishedProduct;

use App\Models\FinishedProduct;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFinishedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the code and the name before the rules run, only when they arrive.
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
         * Los cinco campos son sometimes|required: omitirlos deja el valor anterior, pero
         * mandarlos vacíos es un error. Un cuerpo vacío es un no-op con 200.
         */
        return [
            'code' => ['sometimes', 'required', 'string', 'max:15', 'regex:/^\S+$/u'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'presentation' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'boxesPerPallet' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'clientId' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
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
