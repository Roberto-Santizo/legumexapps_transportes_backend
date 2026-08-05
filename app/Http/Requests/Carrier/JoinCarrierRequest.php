<?php

namespace App\Http\Requests\Carrier;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'JoinCarrierRequest',
    title: 'Vinculación de un piloto a una empresa',
    description: 'Código de 6 caracteres que el carrier comparte con sus pilotos.',
    required: ['code'],
    properties: [
        new OA\Property(
            property: 'code',
            description: 'Código de la empresa: exactamente 6 caracteres. Se normaliza a mayúsculas antes de buscarlo, así que a7k2qx y A7K2QX vinculan a la misma empresa.',
            type: 'string',
            maxLength: 6,
            minLength: 6,
            example: 'A7K2QX',
        ),
    ],
    type: 'object',
)]
class JoinCarrierRequest extends FormRequest
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
            'code' => ['required', 'string', 'size:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'El código de la empresa es obligatorio',
            'code.string' => 'El código de la empresa debe ser texto',
            'code.size' => 'El código de la empresa debe tener 6 caracteres',
        ];
    }
}
