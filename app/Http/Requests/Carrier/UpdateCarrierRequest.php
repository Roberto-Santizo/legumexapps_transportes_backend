<?php

namespace App\Http\Requests\Carrier;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCarrierRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'image' => ['sometimes', 'required', 'file', 'mimes:jpg,jpeg,png'],
            'active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la empresa no puede estar vacío',
            'name.string' => 'El nombre de la empresa debe ser texto',
            'name.max' => 'El nombre de la empresa no puede superar los 255 caracteres',
            'image.required' => 'La imagen no puede estar vacía',
            'image.file' => 'La imagen debe ser un archivo',
            'image.mimes' => 'La imagen debe ser un archivo jpg, jpeg o png',
            'active.required' => 'El estado no puede estar vacío',
            'active.boolean' => 'El estado debe ser verdadero o falso',
        ];
    }
}
