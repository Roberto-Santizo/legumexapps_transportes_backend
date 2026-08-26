<?php

namespace App\Http\Requests\DeparturePoint;

use App\Models\DeparturePoint;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeparturePointRequest extends FormRequest
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
         * Todos los campos son opcionales por separado y un PATCH con el cuerpo vacío se
         * acepta como no-op. El ignore() del propio id permite reenviar el mismo nombre o el
         * mismo lugar sin chocar consigo mismo. Las coordenadas se pueden corregir sueltas:
         * no se validan contra el googlePlaceId, que puede reapuntarse sin tocarlas.
         */
        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('departure_points', 'name')->ignore($this->route('departurePoint'))],
            'description' => ['sometimes', 'nullable', 'string'],
            /**
             * Sin regla unique a propósito: la unicidad del lugar la decide el service, que
             * responde 400 nombrando al punto que ya lo ocupa e ignora la propia fila, así
             * que reenviar el mismo googlePlaceId sigue siendo un 200.
             */
            'googlePlaceId' => ['sometimes', 'string', 'max:255'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.string' => 'El nombre del punto de partida debe ser texto',
            'name.max' => 'El nombre del punto de partida no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un punto de partida con ese nombre',
            'description.string' => 'La descripción debe ser texto',
            'googlePlaceId.string' => 'El lugar de Google debe ser texto',
            'googlePlaceId.max' => 'El lugar de Google no puede superar los 255 caracteres',
            'latitude.numeric' => 'La latitud debe ser numérica',
            'latitude.between' => 'La latitud debe estar entre -90 y 90',
            'longitude.numeric' => 'La longitud debe ser numérica',
            'longitude.between' => 'La longitud debe estar entre -180 y 180',
            'status.boolean' => 'El estado debe ser verdadero o falso',
        ];
    }
}
