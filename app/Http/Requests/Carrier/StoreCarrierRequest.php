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
            description: 'Archivo de imagen, obligatorio. Solo se aceptan jpg, jpeg y png; cualquier otro tipo devuelve 422. ATENCIÓN: el archivo se valida y se descarta. No se guarda en disco ni en la nube; lo único que se persiste es un UUID con la extensión original, que se devuelve en el campo image del recurso. El consumidor no debe asumir que el archivo quedó almacenado ni que la imagen es recuperable.',
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
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png'],
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
        ];
    }
}
