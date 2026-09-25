<?php

namespace App\Http\Requests\FinishedProduct;

use App\Models\FinishedProduct;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateFinishedProductRequest',
    title: 'Actualización de producto terminado',
    description: <<<'TEXT'
    Cuerpo JSON para corregir un SKU. Los cinco campos son OPCIONALES (sometimes|required): se toca solo lo que venga, y UN CUERPO VACÍO ES UN NO-OP CON 200. Opcional no es vaciable: enviar un campo vacío o null es 422.

    Mismas reglas que el alta: code duplicado contra otro SKU —vivo o borrado— es 400 «Ya existe un producto terminado con ese código, que puede haber sido eliminado» (reenviar el propio código es 200); clientId inexistente 422; cliente borrado 400 «El cliente seleccionado ya fue eliminado», INCLUSO SI ES EL MISMO CLIENTE QUE YA TENÍA. registeredBy no se reescribe.
    TEXT,
    properties: [
        new OA\Property(
            property: 'code',
            description: 'Nuevo código. Máximo 15 caracteres, recortado y en MAYÚSCULAS, sin ningún espacio (422 «El código no puede contener espacios»). El de otro SKU —vivo o borrado— es 400.',
            type: 'string',
            maxLength: 15,
            example: 'sku-bro-002',
        ),
        new OA\Property(
            property: 'name',
            description: 'Nuevo nombre. Máximo 255 caracteres, en MAYÚSCULAS sin recortar ni colapsar espacios. No es único.',
            type: 'string',
            maxLength: 255,
            example: 'brócoli florete iqf 2kg',
        ),
        new OA\Property(
            property: 'presentation',
            description: 'Nueva presentación, numérica entre 0.01 y 99999999.99.',
            type: 'number',
            format: 'float',
            maximum: 99999999.99,
            minimum: 0.01,
            example: 2,
        ),
        new OA\Property(
            property: 'boxesPerPallet',
            description: 'Nuevas cajas por tarima, numéricas entre 0.01 y 99999999.99.',
            type: 'number',
            format: 'float',
            maximum: 99999999.99,
            minimum: 0.01,
            example: 96,
        ),
        new OA\Property(
            property: 'clientId',
            description: 'Nuevo cliente (clients.id). Inexistente → 422; borrado → 400, aunque sea el mismo cliente actual.',
            type: 'integer',
            example: 8,
        ),
    ],
    type: 'object',
)]
class UpdateFinishedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the code and the name before the rules run, only when they arrive.
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
         * Los cinco campos son sometimes|required: omitirlos deja el valor anterior, pero
         * mandarlos vacíos es un error. Un cuerpo vacío es un no-op con 200.
         */
        return [
            'code' => ['sometimes', 'required', 'string', 'max:15', 'regex:/^\S+$/u'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'presentation' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'boxesPerPallet' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'clientId' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
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
