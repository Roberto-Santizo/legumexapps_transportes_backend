<?php

namespace App\Http\Requests\VehicleExpense;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexVehicleExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the query string, not a body.
     *
     * `vehicleId` is the only required listing filter of the whole project: the
     * expenses of a single vehicle are the only listing this domain serves, so
     * omitting it is a 422 rather than a fleet-wide listing nobody asked for. A
     * vehicle that does not exist is still a 404 raised by the service, so no
     * `exists` rule lives here.
     *
     * The optional filters (category, nature, dateFrom, dateTo and limit) are
     * deliberately unvalidated: they are tolerant, and an invalid one is
     * ignored by the service instead of failing the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vehicleId' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vehicleId.required' => 'El vehículo es obligatorio',
            'vehicleId.integer' => 'El vehículo debe ser un número entero',
        ];
    }
}
