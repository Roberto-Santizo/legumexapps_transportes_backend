<?php

namespace App\Http\Requests\Carrier;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateCarrierRequest',
    title: 'Actualización de empresa transportista',
    description: 'Actualización parcial: los tres campos son opcionales y solo se modifica lo que llega. Un campo enviado vacío sí es error (422). El code no se puede modificar ni rotar.',
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nuevo nombre de la empresa. Si se envía, no puede estar vacío.',
            type: 'string',
            maxLength: 255,
            example: 'Transportes del Sur',
        ),
        new OA\Property(
            property: 'image',
            description: 'Nuevo archivo de imagen. Solo jpg, jpeg y png, y no más de 3 MB (3072 KB, límite inclusivo). Igual que en el alta, se recorta a un cuadrado centrado de 800x800 px antes de subirla, conservando el formato. Al reemplazarla se borra la imagen anterior del almacenamiento, de forma irreversible. Requiere enviar el cuerpo como multipart/form-data.',
            type: 'string',
            format: 'binary',
        ),
        new OA\Property(
            property: 'active',
            description: 'Estado de la empresa. Hoy es informativo: no bloquea a los pilotos ni impide que se unan.',
            type: 'boolean',
            example: false,
        ),
    ],
    type: 'object',
)]
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
            'image' => ['sometimes', 'required', 'file', 'mimes:jpg,jpeg,png', 'max:3072'],
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
            'image.max' => 'La imagen no puede pesar más de 3 MB',
            'active.required' => 'El estado no puede estar vacío',
            'active.boolean' => 'El estado debe ser verdadero o falso',
        ];
    }
}
