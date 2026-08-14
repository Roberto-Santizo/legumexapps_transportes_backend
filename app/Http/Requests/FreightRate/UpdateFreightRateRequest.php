<?php

namespace App\Http\Requests\FreightRate;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFreightRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is optional and an empty payload is accepted as a no-op.
     *
     * There are five editable fields: chaining `required_without` across all of them
     * would only produce five identical messages for the same empty body.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'zoneId' => ['sometimes', 'integer', 'exists:zones,id'],
            'productId' => ['sometimes', 'integer', 'exists:products,id'],
            'fuelType' => ['sometimes', Rule::enum(FuelType::class)],
            'fuelMin' => ['sometimes', 'numeric', 'min:0.01', 'max:999999.99'],
            'pricePerPound' => ['sometimes', 'numeric', 'min:0.000001', 'max:999999.999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zoneId.integer' => 'La zona debe ser un identificador numérico',
            'zoneId.exists' => 'La zona seleccionada no existe',
            'productId.integer' => 'El producto debe ser un identificador numérico',
            'productId.exists' => 'El producto seleccionado no existe',
            'fuelType.enum' => 'El tipo de combustible no es válido',
            'fuelMin.numeric' => 'El precio de combustible debe ser un número en quetzales por galón',
            'fuelMin.min' => 'El precio de combustible debe ser mayor que cero',
            'fuelMin.max' => 'El precio de combustible no puede superar los 999999.99 quetzales por galón',
            'pricePerPound.numeric' => 'La tarifa por libra debe ser un número en quetzales',
            'pricePerPound.min' => 'La tarifa por libra debe ser mayor que cero',
            'pricePerPound.max' => 'La tarifa por libra no puede superar los 999999.999999 quetzales',
        ];
    }
}
