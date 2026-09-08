<?php

namespace App\Http\Requests\TripPosition;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTripPositionRequest extends FormRequest
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
        /**
         * Dos campos y ninguno más. `recordedAt` y `pilotId` no se aceptan: la hora la pone
         * el servidor con now() y el autor sale del usuario autenticado, así que mandarlos
         * se descarta en silencio, como el `vehicleId` de un gasto de vehículo.
         *
         * Los rangos son los del sistema de coordenadas y nada más: no se comprueba que el
         * punto caiga cerca de la polilínea del viaje, ni dentro de Guatemala, ni que el
         * salto contra el punto anterior sea físicamente posible.
         */
        return [
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
            'latitude.required' => 'La latitud es obligatoria',
            'latitude.numeric' => 'La latitud debe ser un número',
            'latitude.between' => 'La latitud debe estar entre -90 y 90',
            'longitude.required' => 'La longitud es obligatoria',
            'longitude.numeric' => 'La longitud debe ser un número',
            'longitude.between' => 'La longitud debe estar entre -180 y 180',
        ];
    }
}
