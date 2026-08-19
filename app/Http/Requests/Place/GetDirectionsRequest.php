<?php

namespace App\Http\Requests\Place;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GetDirectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the query string, not a body.
     *
     * The three parameters are required and nothing here is tolerant: unlike the
     * listing filters of the catalogues, an invalid value is not ignored. Every route
     * is billed by the provider —at a higher rate than a text search—, so a malformed
     * call is a 422 that never leaves the application.
     *
     * The destination arrives by id and the origin as two loose numbers: the origin is
     * never a registered location, and there is no reverse trip.
     *
     * Nothing else is accepted. travelMode, polylineQuality and limit are ignored
     * outright: they are constants of the service, not choices of the client.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locationId' => ['required', 'integer', 'exists:locations,id'],
            /** El rango es lo único que se comprueba del origen: nada garantiza que sea tierra firme. */
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'locationId.required' => 'El destino es obligatorio',
            'locationId.integer' => 'El destino debe ser un identificador numérico',
            'locationId.exists' => 'El destino seleccionado no existe',
            'lat.required' => 'La latitud de origen es obligatoria',
            'lat.numeric' => 'La latitud de origen debe ser numérica',
            'lat.between' => 'La latitud de origen debe estar entre -90 y 90',
            'lng.required' => 'La longitud de origen es obligatoria',
            'lng.numeric' => 'La longitud de origen debe ser numérica',
            'lng.between' => 'La longitud de origen debe estar entre -180 y 180',
        ];
    }
}
