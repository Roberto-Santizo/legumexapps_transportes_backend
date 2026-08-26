<?php

namespace App\Http\Requests\ShippingLine;

use App\Models\ShippingLine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateShippingLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name before the rules run.
     *
     * Same rule as the store request: this catalog has a single business field and it
     * is validated identically whether it is being created or corrected.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => ShippingLine::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * sometimes|required: omitir el campo deja el valor anterior, pero mandarlo vacío
         * es un error. Un cuerpo vacío es un no-op con 200, como en el resto de los PATCH
         * del proyecto. registeredBy sigue sin aceptarse: mandarlo se descarta sin error y
         * el autor no cambia.
         */
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la naviera es obligatorio',
            'name.string' => 'El nombre de la naviera debe ser texto',
            'name.max' => 'El nombre de la naviera no puede superar los 255 caracteres',
        ];
    }
}
