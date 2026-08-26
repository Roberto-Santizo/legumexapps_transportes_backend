<?php

namespace App\Http\Requests\Client;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the code and the name before the rules run.
     *
     * The two normalizers differ on purpose: the name collapses inner whitespace, the
     * code does not — it does not need to, because a code carrying any space at all is
     * rejected a line later by its own regex.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Client::normalizeCode($this->input('code'))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => Client::normalizeName($this->input('name'))]);
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
         * Ninguno de los dos campos lleva regla unique, a propósito: la de Laravel no ve
         * las filas borradas, así que dejaría pasar un código ocupado por un cliente
         * eliminado y el 400 del service llegaría igual, un paso más tarde. Los dos
         * duplicados los levanta el service, y por un solo camino.
         */
        return [
            /** El espacio en un código es un error de captura, no una variante: se rechaza en vez de colapsarlo. */
            'code' => ['required', 'string', 'max:15', 'regex:/^\S+$/u'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'El código del cliente es obligatorio',
            'code.string' => 'El código del cliente debe ser texto',
            'code.max' => 'El código del cliente no puede superar los 15 caracteres',
            'code.regex' => 'El código no puede contener espacios',
            'name.required' => 'El nombre del cliente es obligatorio',
            'name.string' => 'El nombre del cliente debe ser texto',
            'name.max' => 'El nombre del cliente no puede superar los 255 caracteres',
        ];
    }
}
