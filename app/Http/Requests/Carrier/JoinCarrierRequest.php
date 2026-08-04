<?php

namespace App\Http\Requests\Carrier;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
