<?php

namespace App\Http\Requests\FreightRate;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFreightRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The five fields are mandatory; registeredBy is not accepted at all.
     *
     * `exists` only proves the row is there, never that it is active: the status is a
     * business rule of the service, with its own message and its own 400.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'zoneId' => ['required', 'integer', 'exists:zones,id'],
            'productId' => ['required', 'integer', 'exists:products,id'],
            'fuelType' => ['required', Rule::enum(FuelType::class)],
            'fuelMin' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'pricePerPound' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zoneId.required' => 'La zona es obligatoria',
            'zoneId.integer' => 'La zona debe ser un identificador numérico',
            'zoneId.exists' => 'La zona seleccionada no existe',
            'productId.required' => 'El producto es obligatorio',
            'productId.integer' => 'El producto debe ser un identificador numérico',
            'productId.exists' => 'El producto seleccionado no existe',
            'fuelType.required' => 'El tipo de combustible es obligatorio',
            'fuelType.enum' => 'El tipo de combustible no es válido',
            'fuelMin.required' => 'El precio de combustible desde el cual rige la tarifa es obligatorio',
            'fuelMin.numeric' => 'El precio de combustible debe ser un número en quetzales por galón',
            'fuelMin.min' => 'El precio de combustible debe ser mayor que cero',
            'fuelMin.max' => 'El precio de combustible no puede superar los 999999.99 quetzales por galón',
            'pricePerPound.required' => 'La tarifa por libra es obligatoria',
            'pricePerPound.numeric' => 'La tarifa por libra debe ser un número en quetzales',
            'pricePerPound.min' => 'La tarifa por libra debe ser mayor que cero',
            'pricePerPound.max' => 'La tarifa por libra no puede superar los 999999.999999 quetzales',
        ];
    }
}
