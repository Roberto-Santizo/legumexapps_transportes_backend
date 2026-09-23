<?php

namespace App\Http\Requests\DeviceToken;

use App\Enums\DevicePlatform;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * Dos campos. `user_id` no se acepta: el dueño es siempre quien llama. El token
         * es un identificador opaco y sensible a mayúsculas, como `google_place_id`: se
         * guarda tal cual y un espacio es 422 — se rechaza, no se arregla.
         */
        return [
            'token' => ['required', 'string', 'max:512', 'regex:/^\S+$/u'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'token.required' => 'El token de dispositivo es obligatorio',
            'token.string' => 'El token de dispositivo debe ser un texto',
            'token.max' => 'El token de dispositivo no puede superar los 512 caracteres',
            'token.regex' => 'El token de dispositivo no puede contener espacios',
            'platform.required' => 'La plataforma es obligatoria',
            'platform.enum' => 'La plataforma seleccionada no es válida',
        ];
    }
}
