<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateProductRequest',
    title: 'Actualización de producto',
    description: <<<'TEXT'
    Cuerpo JSON para actualizar un producto. Acepta name, status o ambos, y solo se toca lo que venga: enviar únicamente status no altera el nombre, y al revés.

    ATENCIÓN — los dos campos son opcionales por separado pero AL MENOS UNO debe venir. Una regla required_without cruzada entre ambos hace que el cuerpo vacío devuelva 422 con el mensaje "Debe enviar al menos el nombre o el estado" en los dos campos: un PATCH sin datos se trata como error del cliente, no como una operación sin efecto.

    El name se normaliza —recorte, colapso de espacios internos y mayúsculas— antes de validarse y antes de guardarse, igual que en el alta. La comprobación de unicidad ignora la propia fila, así que reenviar su mismo nombre responde 200 y no 422; reenviar el de otro producto sí devuelve 422.

    El registeredBy no se puede cambiar y el PATCH no lo reescribe: sigue apuntando a quien dio de alta el producto.
    TEXT,
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nuevo nombre del producto. Opcional si se envía status. Se guarda normalizado y en mayúsculas, debe seguir siendo único en todo el catálogo —ignorando este mismo producto— y no puede superar los 255 caracteres.',
            type: 'string',
            maxLength: 255,
            example: 'fresa',
        ),
        new OA\Property(
            property: 'status',
            description: 'Nueva disponibilidad del producto, como BOOLEANO. Opcional si se envía name. Con false se da de baja y con true se reactiva, lo mismo que consiguen DELETE /api/products/{product} y PATCH /api/products/{product}/toggle-status; el solapamiento es deliberado: el toggle sirve al interruptor de una tabla y este campo al formulario de edición que manda estado y nombre juntos. Un valor no booleano —por ejemplo "quizas"— devuelve 422 con el mensaje: El estado debe ser verdadero o falso.',
            type: 'boolean',
            example: false,
        ),
    ],
    type: 'object',
)]
class UpdateProductRequest extends FormRequest
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
        /**
         * Los dos campos son opcionales por separado, pero required_without cruzado impide
         * el cuerpo vacío: un PATCH sin datos es un error del cliente, no una operación sin efecto.
         * El ignore() del propio id permite reenviar el mismo nombre sin chocar consigo mismo.
         */
        return [
            'name' => ['required_without:status', 'string', 'max:255', Rule::unique('products', 'name')->ignore($this->route('product'))],
            'status' => ['required_without:name', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required_without' => 'Debe enviar al menos el nombre o el estado',
            'name.string' => 'El nombre del producto debe ser texto',
            'name.max' => 'El nombre del producto no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un producto con ese nombre',
            'status.required_without' => 'Debe enviar al menos el nombre o el estado',
            'status.boolean' => 'El estado debe ser verdadero o falso',
        ];
    }
}
