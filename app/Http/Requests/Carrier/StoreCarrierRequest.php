<?php

namespace App\Http\Requests\Carrier;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreCarrierRequest',
    title: 'Alta de empresa transportista',
    description: 'Cuerpo multipart/form-data para crear la empresa. El code y el estado active no se envían: el code lo genera el servidor y active nace siempre en true.',
    required: ['name', 'image'],
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nombre de la empresa. No es único: dos empresas pueden llamarse igual.',
            type: 'string',
            maxLength: 255,
            example: 'Transportes del Norte',
        ),
        new OA\Property(
            property: 'image',
            description: 'Archivo de imagen, obligatorio. Solo se aceptan jpg, jpeg y png; cualquier otro tipo devuelve 422. No puede pesar más de 3 MB (3072 KB, límite inclusivo). El archivo se almacena, pero NO tal cual: antes de subirlo se recorta a un cuadrado centrado y se reescala a 800x800 px, conservando el formato de entrada. Se pierden los bordes del lado largo y el original no se guarda en ningún sitio. Si la imagen no se puede procesar o el almacenamiento falla, la respuesta es 400 y la empresa no se crea.',
            type: 'string',
            format: 'binary',
        ),
    ],
    type: 'object',
)]
class StoreCarrierRequest extends FormRequest
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
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:3072'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la empresa es obligatorio',
            'name.string' => 'El nombre de la empresa debe ser texto',
            'name.max' => 'El nombre de la empresa no puede superar los 255 caracteres',
            'image.required' => 'La imagen es obligatoria',
            'image.file' => 'La imagen debe ser un archivo',
            'image.mimes' => 'La imagen debe ser un archivo jpg, jpeg o png',
            'image.max' => 'La imagen no puede pesar más de 3 MB',
        ];
    }
}
