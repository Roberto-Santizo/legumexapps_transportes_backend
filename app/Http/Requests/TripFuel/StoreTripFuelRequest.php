<?php

namespace App\Http\Requests\TripFuel;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTripFuelRequest extends FormRequest
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
         * Dos campos y ninguno más. `tripId` no se acepta —el viaje va en la URL— y
         * `loadedAt`, `confirmedBy` y `registeredBy` tampoco: la fecha la pone el servidor
         * al confirmar, el piloto sale de su propio token y el autor del alta, del token de
         * quien registra. Mandarlos se descarta en silencio.
         *
         * No hay ninguna validación cruzada sobre `gallons`: no se compara contra
         * `vehicles.kilometers_per_gallon`, ni contra la distancia del viaje, ni contra un
         * techo de negocio. Y `fuelType` se valida solo contra el enum: no se exige que ese
         * tipo tenga un `FuelPrice` vigente, porque aquí no se guarda ningún precio.
         */
        return [
            'gallons' => ['required', 'numeric', 'min:0.01'],
            'fuelType' => ['required', Rule::enum(FuelType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gallons.required' => 'Los galones son obligatorios',
            'gallons.numeric' => 'Los galones deben ser un número',
            'gallons.min' => 'Los galones deben ser mayores a 0',
            'fuelType.required' => 'El tipo de combustible es obligatorio',
            'fuelType.enum' => 'El tipo de combustible seleccionado no es válido',
        ];
    }
}
