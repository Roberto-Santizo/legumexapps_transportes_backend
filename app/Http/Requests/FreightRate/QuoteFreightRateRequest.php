<?php

namespace App\Http\Requests\FreightRate;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteFreightRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the query string, not a body.
     *
     * Sin este FormRequest, un fuelType ausente acabaría en un error del service en vez
     * de un 422 con mensaje en español. A diferencia del listado de zonas, aquí una
     * coordenada fuera de rango NO se ignora: la cotización es dinero, y un punto
     * imposible tiene que decirse, no resolverse en silencio.
     *
     * El precio del combustible no se acepta por ningún nombre: sale siempre del
     * FuelPrice vigente.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'productId' => ['required', 'integer', 'exists:products,id'],
            'fuelType' => ['required', Rule::enum(FuelType::class)],
            'pounds' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lat.required' => 'La latitud del destino es obligatoria',
            'lat.numeric' => 'La latitud debe ser un número',
            'lat.between' => 'La latitud debe estar entre -90 y 90',
            'lng.required' => 'La longitud del destino es obligatoria',
            'lng.numeric' => 'La longitud debe ser un número',
            'lng.between' => 'La longitud debe estar entre -180 y 180',
            'productId.required' => 'El producto es obligatorio',
            'productId.integer' => 'El producto debe ser un identificador numérico',
            'productId.exists' => 'El producto seleccionado no existe',
            'fuelType.required' => 'El tipo de combustible es obligatorio',
            'fuelType.enum' => 'El tipo de combustible no es válido',
            'pounds.numeric' => 'Las libras deben ser un número',
            'pounds.min' => 'Las libras deben ser mayores que cero',
            'pounds.max' => 'Las libras no pueden superar las 99999999.99',
        ];
    }
}
