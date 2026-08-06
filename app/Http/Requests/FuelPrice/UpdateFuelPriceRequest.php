<?php

namespace App\Http\Requests\FuelPrice;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFuelPriceRequest extends FormRequest
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
        /** El precio es el único campo del cuerpo, así que va como required: un PATCH vacío es un error del cliente, no una operación sin efecto. */
        return [
            'price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.required' => 'El precio es obligatorio',
            'price.numeric' => 'El precio debe ser un número en quetzales por galón',
            'price.min' => 'El precio debe ser mayor que cero',
            'price.max' => 'El precio no puede superar los 999999.99 quetzales por galón',
        ];
    }
}
