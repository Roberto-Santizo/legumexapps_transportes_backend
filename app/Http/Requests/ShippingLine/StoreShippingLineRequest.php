<?php

namespace App\Http\Requests\ShippingLine;

use App\Models\ShippingLine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreShippingLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name before the rules run.
     *
     * Trimming happens here and not in the rules so that a name of only spaces is left
     * empty and caught by required, instead of passing as a 255 character blank.
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
         * registeredBy no se acepta: la autoría sale del usuario autenticado, que por el
         * middleware role:administrator es siempre un administrador.
         *
         * El campo no lleva regla unique, a propósito: la de Laravel no ve las filas
         * borradas, así que dejaría pasar un nombre ocupado por una naviera eliminada y el
         * 400 del service llegaría igual, un paso más tarde. El duplicado lo levanta el
         * service, y por un solo camino.
         */
        return [
            'name' => ['required', 'string', 'max:255'],
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
