<?php

namespace App\Http\Requests\Location;

use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name before the rules run.
     *
     * Without it "bodega central" would pass the unique rule while BODEGA CENTRAL
     * exists and blow up against the unique index with a 500 instead of a 422.
     *
     * The google place id is deliberately left untouched: it is an opaque, case
     * sensitive identifier and upper casing it would point at a different place.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Location::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * status y registeredBy no se aceptan: el destino nace activo y la autoría sale del
         * usuario autenticado. Las coordenadas se validan por rango, no contra el lugar de
         * Google: no hay validación cruzada entre el googlePlaceId y el pin.
         */
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('locations', 'name')],
            'description' => ['nullable', 'string'],
            'googlePlaceId' => ['required', 'string', 'max:255', Rule::unique('locations', 'google_place_id')],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del destino es obligatorio',
            'name.string' => 'El nombre del destino debe ser texto',
            'name.max' => 'El nombre del destino no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un destino con ese nombre',
            'description.string' => 'La descripción debe ser texto',
            'googlePlaceId.required' => 'El lugar de Google es obligatorio',
            'googlePlaceId.string' => 'El lugar de Google debe ser texto',
            'googlePlaceId.max' => 'El lugar de Google no puede superar los 255 caracteres',
            'googlePlaceId.unique' => 'Ese lugar ya está registrado en otro destino',
            'latitude.required' => 'La latitud es obligatoria',
            'latitude.numeric' => 'La latitud debe ser numérica',
            'latitude.between' => 'La latitud debe estar entre -90 y 90',
            'longitude.required' => 'La longitud es obligatoria',
            'longitude.numeric' => 'La longitud debe ser numérica',
            'longitude.between' => 'La longitud debe estar entre -180 y 180',
        ];
    }
}
