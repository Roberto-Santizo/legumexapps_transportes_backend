<?php

namespace App\Http\Requests\DeviceToken;

use App\Enums\DevicePlatform;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreDeviceTokenRequest',
    title: 'Registro de un token de dispositivo',
    description: <<<'TEXT'
    Cuerpo JSON con el que el dispositivo registra su token FCM. EXACTAMENTE DOS CAMPOS —token y platform— Y LOS DOS SON OBLIGATORIOS.

    ATENCIÓN — NO SE ACEPTA user_id: el dueño es siempre el usuario del JWT. Mandarlo se descarta en silencio.

    ATENCIÓN — EL TOKEN SE GUARDA TAL CUAL: no se recorta ni se pasa a mayúsculas. Un espacio en cualquier posición es 422: se rechaza, no se arregla.
    TEXT,
    required: ['token', 'platform'],
    properties: [
        new OA\Property(
            property: 'token',
            description: 'Token FCM del dispositivo. OBLIGATORIO, texto, máximo 512 caracteres y sin espacios. Mensajes literales: El token de dispositivo es obligatorio / El token de dispositivo debe ser un texto / El token de dispositivo no puede superar los 512 caracteres / El token de dispositivo no puede contener espacios.',
            type: 'string',
            maxLength: 512,
            pattern: '^\S+$',
            example: 'dXk3:APA91bHfake_token-123',
        ),
        new OA\Property(
            property: 'platform',
            description: 'Plataforma del dispositivo. OBLIGATORIO, android o ios. Mensajes literales: La plataforma es obligatoria / La plataforma seleccionada no es válida.',
            type: 'string',
            enum: ['android', 'ios'],
            example: 'android',
        ),
    ],
    type: 'object',
)]
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
