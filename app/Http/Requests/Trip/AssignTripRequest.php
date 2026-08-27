<?php

namespace App\Http\Requests\Trip;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AssignTripRequest extends FormRequest
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
         * Exactamente dos campos y los dos obligatorios: no se puede asignar solo piloto o
         * solo vehículo, y `null` en cualquiera de los dos es 422 porque la desasignación no
         * existe —una vez tomado, un viaje no vuelve nunca a la bolsa—.
         *
         * `assignedBy` no se acepta: sale del usuario autenticado, que por el middleware
         * role:carrier es siempre un transportista.
         *
         * El `exists:` convierte un id inventado en 422; que el usuario tenga rol de piloto,
         * que tenga empresa, que el vehículo esté activo y que los dos sean de la misma
         * empresa son reglas de negocio y las levanta el service con su 400.
         */
        return [
            'pilotId' => ['required', 'integer', 'exists:users,id'],
            'vehicleId' => ['required', 'integer', 'exists:vehicles,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pilotId.required' => 'El piloto es obligatorio',
            'pilotId.integer' => 'El piloto debe ser un identificador válido',
            'pilotId.exists' => 'El piloto seleccionado no existe',
            'vehicleId.required' => 'El vehículo es obligatorio',
            'vehicleId.integer' => 'El vehículo debe ser un identificador válido',
            'vehicleId.exists' => 'El vehículo seleccionado no existe',
        ];
    }
}
