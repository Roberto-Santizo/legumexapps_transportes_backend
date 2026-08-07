<?php

namespace App\Http\Requests\FuelPrice;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * This FormRequest validates the query string, so it has no body schema: it is
 * published as a reusable component parameter instead.
 */
#[OA\QueryParameter(
    parameter: 'currentFuelTypeQuery',
    name: 'fuelType',
    description: <<<'TEXT'
    Tipo de combustible cuyo precio vigente se consulta. Va en la QUERY STRING, no en un cuerpo: GET /api/fuel-prices/current?fuelType=regular.

    Es OBLIGATORIO, a diferencia del filtro homónimo de GET /api/fuel-prices: omitirlo o enviar un valor fuera del enum devuelve 422 (mensajes: "El tipo de combustible es obligatorio" y "El tipo de combustible no es válido"), nunca un 404 ni el precio de otro tipo.

    Un tipo válido que todavía no tiene ninguna fila active devuelve 404. Eso pasa antes del primer alta del tipo y también después de desactivar o eliminar su precio vigente: ninguna fila del histórico asciende para reemplazarlo, así que el tipo se queda sin vigente hasta el próximo POST.
    TEXT,
    in: 'query',
    required: true,
    schema: new OA\Schema(type: 'string', enum: ['regular', 'premium', 'diesel', 'diesel_premium'], example: 'regular'),
)]
class CurrentFuelPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the query string, not a body.
     *
     * Sin este FormRequest, un fuelType ausente acabaría en el 404 del service
     * en vez de un 422 con mensaje en español.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fuelType' => ['required', Rule::enum(FuelType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fuelType.required' => 'El tipo de combustible es obligatorio',
            'fuelType.enum' => 'El tipo de combustible no es válido',
        ];
    }
}
