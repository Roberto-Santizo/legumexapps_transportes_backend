<?php

namespace App\Http\Requests\TripFinishedProduct;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateTripFinishedProductRequest',
    title: 'Edición de línea de producto terminado',
    description: 'Cuerpo JSON de PATCH /api/trip-finished-products/{tripFinishedProduct}. UN SOLO CAMPO Y ES OBLIGATORIO: el cuerpo vacío es 422, no un no-op. tripId y finishedProductId se ignoran en silencio: cambiar de producto es borrar la línea y crear otra.',
    required: ['boxes'],
    properties: [
        new OA\Property(property: 'boxes', description: 'Nuevas cajas de la línea, entero entre 1 y 999999 (Las cajas son obligatorias / Las cajas deben ser un número entero / Las cajas deben ser al menos 1 / Las cajas no pueden superar 999999).', type: 'integer', maximum: 999999, minimum: 1, example: 480),
    ],
    type: 'object',
)]
class UpdateTripFinishedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The single editable field, and it is required: an empty body is a 422, with the
     * precedent of the pilot salary (SPEC 11). `tripId` and `finishedProductId` are not
     * validated nor read: sending them is silently ignored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'boxes' => ['required', 'integer', 'min:1', 'max:999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'boxes.required' => 'Las cajas son obligatorias',
            'boxes.integer' => 'Las cajas deben ser un número entero',
            'boxes.min' => 'Las cajas deben ser al menos 1',
            'boxes.max' => 'Las cajas no pueden superar 999999',
        ];
    }
}
