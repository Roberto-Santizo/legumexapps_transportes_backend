<?php

namespace App\Http\Requests\FinishedProduct;

use App\Models\FinishedProduct;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreFinishedProductRequest',
    title: 'Alta de producto terminado',
    description: <<<'TEXT'
    Cuerpo JSON para dar de alta un SKU. Los CINCO campos son OBLIGATORIOS: code, name, presentation, boxesPerPallet y clientId. Cualquier otra clave se descarta en silencio; registeredBy sale del usuario autenticado.

    ATENCIÓN — EL code DUPLICADO ES 400 DESDE EL SERVICE, NUNCA 422: no hay regla unique porque la de Laravel no ve las filas borradas. Mensaje literal: «Ya existe un producto terminado con ese código, que puede haber sido eliminado». El borrado NO libera el código.

    ATENCIÓN — clientId inexistente es 422 («El cliente seleccionado no existe»), pero un cliente BORRADO pasa el exists: y lo para el service con 400 «El cliente seleccionado ya fue eliminado».

    Normalización antes de validar: code se recorta y pasa a mayúsculas (con cualquier espacio interior es 422, no se arregla); name SOLO pasa a mayúsculas —sin recorte ni colapso de espacios— y NO es único.
    TEXT,
    required: ['code', 'name', 'presentation', 'boxesPerPallet', 'clientId'],
    properties: [
        new OA\Property(
            property: 'code',
            description: 'Código del SKU. Obligatorio, texto, máximo 15 caracteres. Se guarda recortado y en MAYÚSCULAS. NO ADMITE ESPACIOS NI TABULADORES, ni siquiera interiores: 422 «El código no puede contener espacios». Si otro producto terminado —vivo o BORRADO— ya lo usa: 400, no 422.',
            type: 'string',
            maxLength: 15,
            example: 'sku-bro-001',
        ),
        new OA\Property(
            property: 'name',
            description: 'Nombre del SKU. Obligatorio, texto, máximo 255 caracteres. Se guarda en MAYÚSCULAS SIN RECORTAR NI COLAPSAR ESPACIOS: " brócoli  florete " se guarda " BRÓCOLI  FLORETE " (esta ruta está excluida del middleware global TrimStrings). Un nombre de solo espacios es 422. NO es único.',
            type: 'string',
            maxLength: 255,
            example: 'brócoli florete iqf',
        ),
        new OA\Property(
            property: 'presentation',
            description: 'Presentación. Obligatoria, numérica, entre 0.01 y 99999999.99 (mensajes: La presentación debe ser mayor a 0 / La presentación no puede superar 99999999.99). Se devuelve como string con dos decimales.',
            type: 'number',
            format: 'float',
            maximum: 99999999.99,
            minimum: 0.01,
            example: 12.5,
        ),
        new OA\Property(
            property: 'boxesPerPallet',
            description: 'Cajas por tarima. Obligatorio, numérico (admite decimales), entre 0.01 y 99999999.99. Se devuelve como string con dos decimales.',
            type: 'number',
            format: 'float',
            maximum: 99999999.99,
            minimum: 0.01,
            example: 80,
        ),
        new OA\Property(
            property: 'clientId',
            description: 'Id del cliente (clients.id). Obligatorio, entero. Inexistente → 422 «El cliente seleccionado no existe»; cliente borrado → 400 «El cliente seleccionado ya fue eliminado».',
            type: 'integer',
            example: 7,
        ),
    ],
    type: 'object',
)]
class StoreFinishedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the code and the name before the rules run.
     *
     * The code is trimmed and upper cased; the name is only upper cased, keeping every
     * space exactly as typed.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => FinishedProduct::normalizeCode($this->input('code'))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => FinishedProduct::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * El code no lleva regla unique a propósito: la de Laravel no ve las filas
         * borradas, y el duplicado lo levanta el service con 400. El exists: de clientId
         * tampoco ve el borrado lógico: un cliente borrado lo para el service con 400.
         */
        return [
            'code' => ['required', 'string', 'max:15', 'regex:/^\S+$/u'],
            'name' => ['required', 'string', 'max:255'],
            'presentation' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'boxesPerPallet' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'clientId' => ['required', 'integer', 'exists:clients,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'El código del producto terminado es obligatorio',
            'code.string' => 'El código del producto terminado debe ser texto',
            'code.max' => 'El código del producto terminado no puede superar los 15 caracteres',
            'code.regex' => 'El código no puede contener espacios',
            'name.required' => 'El nombre del producto terminado es obligatorio',
            'name.string' => 'El nombre del producto terminado debe ser texto',
            'name.max' => 'El nombre del producto terminado no puede superar los 255 caracteres',
            'presentation.required' => 'La presentación es obligatoria',
            'presentation.numeric' => 'La presentación debe ser numérica',
            'presentation.min' => 'La presentación debe ser mayor a 0',
            'presentation.max' => 'La presentación no puede superar 99999999.99',
            'boxesPerPallet.required' => 'Las cajas por tarima son obligatorias',
            'boxesPerPallet.numeric' => 'Las cajas por tarima deben ser numéricas',
            'boxesPerPallet.min' => 'Las cajas por tarima deben ser mayores a 0',
            'boxesPerPallet.max' => 'Las cajas por tarima no pueden superar 99999999.99',
            'clientId.required' => 'El cliente es obligatorio',
            'clientId.integer' => 'El cliente debe ser un identificador válido',
            'clientId.exists' => 'El cliente seleccionado no existe',
        ];
    }
}
