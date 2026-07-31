<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ConfirmAccountRequest',
    title: 'Confirmación de cuenta',
    description: 'Correo de la cuenta y código de confirmación de 6 dígitos.',
    required: ['email', 'code'],
    properties: [
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'piloto@legumex.com'),
        new OA\Property(
            property: 'code',
            description: 'Código de 6 dígitos generado durante el registro. Expira una hora después de generarse.',
            type: 'string',
            example: '048213',
        ),
    ],
    type: 'object',
)]
class ConfirmAccountRequest extends FormRequest
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
        ];
    }
}
