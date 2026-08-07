<?php

namespace App\Http\Requests\FuelPrice;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreFuelPriceRequest',
    title: 'Alta de precio de combustible',
    description: <<<'TEXT'
    Cuerpo JSON para registrar un precio de combustible. Los dos campos son obligatorios: omitir cualquiera devuelve 422.

    El status NO se acepta: el precio nace siempre en active y enviarlo se descarta sin error. El registeredBy tampoco se envía: se resuelve desde el usuario autenticado, que por el middleware role:administrator es siempre un administrador.

    ATENCIÓN — el alta tiene un efecto colateral sobre otra fila. Si ya existía un precio active del MISMO fuelType, pasa a inactive dentro de la misma transacción, de forma que el tipo nunca se queda con cero ni con dos vigentes. Los demás tipos de combustible no se tocan. No hay regla de unicidad que lo impida: registrar dos veces el mismo tipo devuelve 201 las dos veces y deja la primera fila en el histórico.
    TEXT,
    required: ['fuelType', 'price'],
    properties: [
        new OA\Property(
            property: 'fuelType',
            description: 'Tipo de combustible al que se le fija el precio. Un valor fuera del enum devuelve 422. Es el tipo cuyo precio vigente anterior quedará desactivado.',
            type: 'string',
            enum: ['regular', 'premium', 'diesel', 'diesel_premium'],
            example: 'regular',
        ),
        new OA\Property(
            property: 'price',
            description: 'Precio EN QUETZALES (GTQ) POR GALÓN. Debe ser numérico y estar entre 0.01 y 999999.99: un cero, un negativo o un valor mayor devuelven 422. La moneda y la unidad son convención del dominio, no se guardan ni se validan: nada impide enviar dólares o precios por litro y nada lo detectará. Se persiste con dos decimales, así que 32.456 se guarda como 32.46.',
            type: 'number',
            format: 'float',
            maximum: 999999.99,
            minimum: 0.01,
            example: 32.45,
        ),
    ],
    type: 'object',
)]
class StoreFuelPriceRequest extends FormRequest
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
        return [
            'fuelType' => ['required', Rule::enum(FuelType::class)],
            'price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
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
            'price.required' => 'El precio es obligatorio',
            'price.numeric' => 'El precio debe ser un número en quetzales por galón',
            'price.min' => 'El precio debe ser mayor que cero',
            'price.max' => 'El precio no puede superar los 999999.99 quetzales por galón',
        ];
    }
}
