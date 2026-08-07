<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreProductRequest',
    title: 'Alta de producto',
    description: <<<'TEXT'
    Cuerpo JSON para dar de alta un producto del catálogo nacional. El único campo aceptado es name, y es obligatorio.

    El status NO se acepta: el producto nace siempre en true y enviarlo se descarta sin error, así que no hay forma de crear un producto ya dado de baja. El registeredBy tampoco se envía: se resuelve desde el usuario autenticado, que por el middleware role:administrator es siempre un administrador; mandarlo en el cuerpo no cambia nada.

    ATENCIÓN — el name se NORMALIZA antes de validarse y antes de guardarse: se recorta, se colapsan los espacios internos y se pasa a mayúsculas. Enviar "brocoli" crea el producto "BROCOLI", y "  mini   zanahoria  " crea "MINI ZANAHORIA". Como la normalización ocurre ANTES de la regla de unicidad, enviar "brocoli" existiendo ya "BROCOLI" devuelve 422, no 500 ni una fila duplicada.
    TEXT,
    required: ['name'],
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nombre del producto. Se guarda normalizado y en mayúsculas, así que se puede enviar en minúsculas. Debe ser único en todo el catálogo, comparado ya normalizado: la unicidad es global e insensible a mayúsculas. Vacío, ausente o de más de 255 caracteres devuelve 422 (mensajes: El nombre del producto es obligatorio / El nombre del producto no puede superar los 255 caracteres / Ya existe un producto con ese nombre). El límite de 255 es el de la columna, no una regla de negocio.',
            type: 'string',
            maxLength: 255,
            example: 'brocoli',
        ),
    ],
    type: 'object',
)]
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name before the rules run.
     *
     * Without this step "brocoli" would pass the unique rule while BROCOLI
     * exists and blow up against the unique index with a 500 instead of a 422.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Product::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** status y registeredBy no se aceptan: el producto nace activo y la autoría sale del usuario autenticado. */
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('products', 'name')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio',
            'name.string' => 'El nombre del producto debe ser texto',
            'name.max' => 'El nombre del producto no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un producto con ese nombre',
        ];
    }
}
