<?php

namespace App\Http\Requests\TripFinishedProduct;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreTripFinishedProductRequest',
    title: 'Alta de línea de producto terminado',
    description: 'Cuerpo JSON de POST /api/trip-finished-products. Tres campos obligatorios. registeredBy no se acepta: sale del usuario autenticado. Los exists: leen la tabla en crudo, así que un viaje o un producto BORRADOS pasan la validación y los para el service con 400.',
    required: ['tripId', 'finishedProductId', 'boxes'],
    properties: [
        new OA\Property(property: 'tripId', description: 'Id del viaje (trips.id). Obligatorio, entero y existente (El viaje es obligatorio / El viaje debe ser un número entero / El viaje seleccionado no existe). Debe estar pending y no borrado (400).', type: 'integer', example: 12),
        new OA\Property(property: 'finishedProductId', description: 'Id del producto terminado (finished_products.id). Obligatorio, entero y existente (El producto terminado es obligatorio / El producto terminado debe ser un identificador válido / El producto terminado seleccionado no existe). Debe estar vivo, ser del cliente del viaje y no estar ya en él (400).', type: 'integer', example: 4),
        new OA\Property(property: 'boxes', description: 'Cajas físicas, entero entre 1 y 999999 (Las cajas son obligatorias / Las cajas deben ser un número entero / Las cajas deben ser al menos 1 / Las cajas no pueden superar 999999).', type: 'integer', maximum: 999999, minimum: 1, example: 960),
    ],
    type: 'object',
)]
class StoreTripFinishedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Both `exists:` rules read the raw table, deleted rows included: a deleted trip or
     * a deleted finished product passes here and is stopped by the service with a 400.
     *
     * `boxes` is capped at 999999 so an overflow is a 422 and never a 500 from Postgres.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tripId' => ['required', 'integer', 'exists:trips,id'],
            'finishedProductId' => ['required', 'integer', 'exists:finished_products,id'],
            'boxes' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tripId.required' => 'El viaje es obligatorio',
            'tripId.integer' => 'El viaje debe ser un número entero',
            'tripId.exists' => 'El viaje seleccionado no existe',
            'finishedProductId.required' => 'El producto terminado es obligatorio',
            'finishedProductId.integer' => 'El producto terminado debe ser un identificador válido',
            'finishedProductId.exists' => 'El producto terminado seleccionado no existe',
            'boxes.required' => 'Las cajas son obligatorias',
            'boxes.integer' => 'Las cajas deben ser un número entero',
            'boxes.min' => 'Las cajas deben ser al menos 1',
            'boxes.max' => 'Las cajas no pueden superar 999999',
        ];
    }
}
