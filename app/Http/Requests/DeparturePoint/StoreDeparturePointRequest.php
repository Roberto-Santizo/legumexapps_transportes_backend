<?php

namespace App\Http\Requests\DeparturePoint;

use App\Models\DeparturePoint;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeparturePointRequest extends FormRequest
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
            $this->merge(['name' => DeparturePoint::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * status y registeredBy no se aceptan: el punto nace activo y la autoría sale del
         * usuario autenticado. Las coordenadas se validan por rango, no contra el lugar de
         * Google: no hay validación cruzada entre el googlePlaceId y el pin.
         */
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('departure_points', 'name')],
            'description' => ['nullable', 'string'],
            /**
             * Sin regla unique a propósito: la unicidad del lugar la decide el service, que
             * responde 400 nombrando al punto que ya lo ocupa. Un 422 aquí cortaría antes y
             * el cliente nunca vería ese nombre, que es justo lo que hace útil el error.
             * La unicidad se comprueba solo en esta tabla: el mismo lugar puede estar dado
             * de alta como destino y no es conflicto.
             */
            'googlePlaceId' => ['required', 'string', 'max:255'],
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
            'name.required' => 'El nombre del punto de partida es obligatorio',
            'name.string' => 'El nombre del punto de partida debe ser texto',
            'name.max' => 'El nombre del punto de partida no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un punto de partida con ese nombre',
            'description.string' => 'La descripción debe ser texto',
            'googlePlaceId.required' => 'El lugar de Google es obligatorio',
            'googlePlaceId.string' => 'El lugar de Google debe ser texto',
            'googlePlaceId.max' => 'El lugar de Google no puede superar los 255 caracteres',
            'latitude.required' => 'La latitud es obligatoria',
            'latitude.numeric' => 'La latitud debe ser numérica',
            'latitude.between' => 'La latitud debe estar entre -90 y 90',
            'longitude.required' => 'La longitud es obligatoria',
            'longitude.numeric' => 'La longitud debe ser numérica',
            'longitude.between' => 'La longitud debe estar entre -180 y 180',
        ];
    }
}
