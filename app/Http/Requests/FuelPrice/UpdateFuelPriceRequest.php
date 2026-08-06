<?php

namespace App\Http\Requests\FuelPrice;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateFuelPriceRequest',
    title: 'Corrección de un precio vigente',
    description: <<<'TEXT'
    Cuerpo JSON para corregir el importe de un precio de combustible. El price es el ÚNICO campo aceptado y es obligatorio: un cuerpo vacío devuelve 422, porque un PATCH sin datos es un error del cliente y no una operación sin efecto.

    El fuelType no se acepta: cambiarlo dejaría dos precios vigentes del tipo destino. El status tampoco: para desactivar está PATCH /api/fuel-prices/{fuelPrice}/deactivate. Ambos se descartan sin error si se envían.

    Solo se puede aplicar sobre la fila active del tipo. Sobre una fila inactive la respuesta es 400 con el mensaje "Solo se puede modificar el precio vigente": el histórico es intocable.
    TEXT,
    required: ['price'],
    properties: [
        new OA\Property(
            property: 'price',
            description: 'Nuevo precio EN QUETZALES (GTQ) POR GALÓN. Debe ser numérico y estar entre 0.01 y 999999.99. Corrige el importe de la fila vigente en sitio: no crea una fila nueva ni deja rastro del importe anterior, así que no sirve para registrar un cambio de precio del mercado —para eso está POST /api/fuel-prices—, sino para arreglar una captura equivocada.',
            type: 'number',
            format: 'float',
            maximum: 999999.99,
            minimum: 0.01,
            example: 33.10,
        ),
    ],
    type: 'object',
)]
class UpdateFuelPriceRequest extends FormRequest
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
        /** El precio es el único campo del cuerpo, así que va como required: un PATCH vacío es un error del cliente, no una operación sin efecto. */
        return [
            'price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.required' => 'El precio es obligatorio',
            'price.numeric' => 'El precio debe ser un número en quetzales por galón',
            'price.min' => 'El precio debe ser mayor que cero',
            'price.max' => 'El precio no puede superar los 999999.99 quetzales por galón',
        ];
    }
}
