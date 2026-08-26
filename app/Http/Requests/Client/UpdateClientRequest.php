<?php

namespace App\Http\Requests\Client;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the code and the name before the rules run.
     *
     * Same asymmetry as the store request: the name collapses inner whitespace, the
     * code does not.
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
         * Los dos campos son sometimes|required: omitirlos deja el valor anterior, pero
         * mandarlos vacíos es un error. Un cuerpo vacío es un no-op con 200, como en el
         * resto de los PATCH del proyecto. registeredBy sigue sin aceptarse: mandarlo se
         * descarta sin error y el autor no cambia.
         */
        return [
            'code' => ['sometimes', 'required', 'string', 'max:15', 'regex:/^\S+$/u'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
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
