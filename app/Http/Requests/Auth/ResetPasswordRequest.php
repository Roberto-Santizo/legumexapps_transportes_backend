<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ResetPasswordRequest',
    title: 'Restablecimiento de contraseña',
    description: 'Correo, código de recuperación de 6 dígitos y nueva contraseña.',
    required: ['email', 'code', 'password'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'piloto@legumex.com'),
        new OA\Property(
            property: 'code',
            description: 'Código de 6 dígitos generado por forgot-password. Expira una hora después de generarse.',
            type: 'string',
            example: '048213',
        ),
        new OA\Property(
            property: 'password',
            description: 'Nueva contraseña, mínimo 8 caracteres. Este endpoint no exige confirmación.',
            type: 'string',
            format: 'password',
            minLength: 8,
            example: 'secret123',
        ),
    ],
    type: 'object',
)]
class ResetPasswordRequest extends FormRequest
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
        return [
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'El correo es obligatorio',
            'email.email' => 'El correo no tiene un formato válido',
            'code.required' => 'El código es obligatorio',
            'code.digits' => 'El código debe tener 6 dígitos',
            'password.required' => 'La contraseña es obligatoria',
            'password.string' => 'La contraseña debe ser texto',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
        ];
    }
}
