<?php

namespace App\Http\Requests\TripFinishedProduct;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
