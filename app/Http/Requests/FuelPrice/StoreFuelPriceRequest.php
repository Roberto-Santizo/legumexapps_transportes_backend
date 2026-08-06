<?php

namespace App\Http\Requests\FuelPrice;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFuelPriceRequest extends FormRequest
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
            'fuelType' => ['required', Rule::enum(FuelType::class)],
            'price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fuelType.required' => 'El tipo de combustible es obligatorio',
            'fuelType.enum' => 'El tipo de combustible no es válido',
            'price.required' => 'El precio es obligatorio',
            'price.numeric' => 'El precio debe ser un número en quetzales por galón',
            'price.min' => 'El precio debe ser mayor que cero',
            'price.max' => 'El precio no puede superar los 999999.99 quetzales por galón',
        ];
    }
}
