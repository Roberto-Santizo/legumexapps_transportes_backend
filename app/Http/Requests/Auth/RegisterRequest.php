<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RegisterRequest',
    title: 'Registro de usuario',
    description: 'Datos para crear una cuenta. Solo se permite registrar los roles pilot y carrier.',
    required: ['name', 'email', 'password', 'password_confirmation', 'role'],
    properties: [
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Roberto Santizo'),
        new OA\Property(
            property: 'email',
            description: 'Debe ser único: no puede existir otra cuenta con el mismo correo.',
            type: 'string',
            format: 'email',
            maxLength: 255,
            example: 'piloto@legumex.com',
        ),
        new OA\Property(
            property: 'password',
            description: 'Mínimo 8 caracteres. Debe coincidir con password_confirmation.',
            type: 'string',
            format: 'password',
            minLength: 8,
            example: 'secret123',
        ),
        new OA\Property(
            property: 'password_confirmation',
            description: 'Repetición exacta de password.',
            type: 'string',
            format: 'password',
            minLength: 8,
            example: 'secret123',
        ),
        new OA\Property(
            property: 'role',
            description: 'Rol solicitado. Los roles administrator y manager no pueden autoregistrarse.',
            type: 'string',
            enum: ['pilot', 'carrier'],
            example: 'pilot',
        ),
    ],
    type: 'object',
)]
class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in([UserRole::Pilot->value, UserRole::Carrier->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio',
            'name.string' => 'El nombre debe ser texto',
            'name.max' => 'El nombre no puede superar los 255 caracteres',
            'email.required' => 'El correo es obligatorio',
            'email.email' => 'El correo no tiene un formato válido',
            'email.max' => 'El correo no puede superar los 255 caracteres',
            'email.unique' => 'El correo ya está registrado',
            'password.required' => 'La contraseña es obligatoria',
            'password.string' => 'La contraseña debe ser texto',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres',
            'password.confirmed' => 'La confirmación de la contraseña no coincide',
            'role.required' => 'El rol es obligatorio',
            'role.in' => 'El rol debe ser piloto o transportista',
        ];
    }
}
