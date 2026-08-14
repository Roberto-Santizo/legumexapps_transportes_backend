<?php

namespace App\Http\Requests\FreightRate;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateFreightRateRequest',
    title: 'Actualización de tarifa de flete',
    description: <<<'TEXT'
    Cuerpo JSON para corregir una tarifa ya cotizada. TODOS los campos son opcionales y solo se toca lo que venga: un PATCH que solo manda pricePerPound no altera zoneId, productId, fuelType ni fuelMin.

    Un CUERPO VACÍO es un NO-OP con 200, como en Zones y al contrario que en Products: hay cinco campos editables y encadenar required_without entre todos solo produciría cinco mensajes idénticos. La tarifa vuelve intacta salvo el updatedAt.

    El registeredBy NO se acepta ni se reescribe: sigue apuntando a quien dio de alta la fila aunque la edite otro administrador. Tampoco se guarda rastro del pricePerPound anterior: el PATCH sobrescribe sin histórico.

    ATENCIÓN — las dos reglas de negocio miran el PAR COMPLETO, no solo lo que se envió. Lo que no llega se toma de la propia fila, de modo que un PATCH con solo el precio revalida igualmente la zona y el producto: si cualquiera de los dos se DESACTIVÓ después de crear la tarifa, la respuesta es 400 y la fila queda CONGELADA hasta reactivarlo. El DELETE, en cambio, sí funciona en ese caso: borrar nunca se bloquea.

    Mover el fuelMin a un valor que ya ocupa otra tarifa VIVA del mismo par es 400; reenviar su propio fuelMin es 200, porque la comprobación ignora la propia fila.

    Sobre una tarifa YA ELIMINADA este PATCH responde 400 (La tarifa ya fue eliminada), no 404: la fila sigue existiendo con deleted_at. Un id que nunca existió sí es 404.

    La ruta acepta PATCH y PUT indistintamente y el comportamiento es el mismo: el PUT no reemplaza el recurso completo.
    TEXT,
    properties: [
        new OA\Property(
            property: 'zoneId',
            description: 'Nueva zona de la tarifa (zones.id). Opcional: omitirlo conserva la actual. Id inexistente: 422; zona con status false: 400. Mover la tarifa a otra zona cambia el par y por tanto revalida la unicidad de la banda contra las tarifas vivas de la zona destino.',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'productId',
            description: 'Nuevo producto de la tarifa (products.id). Opcional. Id inexistente: 422; producto con status false: 400, incluso si el PATCH no lo enviaba y se tomó de la propia fila.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'fuelType',
            description: 'Nuevo tipo de combustible de la banda. Opcional; fuera del enum devuelve 422. Cambiarlo mueve la tarifa a otra columna de la tabla de precios y revalida la unicidad contra las bandas de ese tipo.',
            type: 'string',
            enum: ['regular', 'premium', 'diesel', 'diesel_premium'],
            example: 'diesel',
        ),
        new OA\Property(
            property: 'fuelMin',
            description: 'Nuevo precio de combustible EN QUETZALES POR GALÓN desde el cual rige la tarifa. Opcional, numérico, entre 0.01 y 999999.99. Es editable precisamente porque un dedazo al crear (3 en vez de 30) no produce ningún error y solo se descubre al cotizar. Moverlo a un valor libre responde 200; moverlo a uno que ya ocupa otra tarifa viva del mismo par responde 400.',
            type: 'number',
            format: 'float',
            maximum: 999999.99,
            minimum: 0.01,
            example: 36.50,
        ),
        new OA\Property(
            property: 'pricePerPound',
            description: 'Nueva tarifa EN QUETZALES POR LIBRA, con hasta seis decimales. Opcional, entre 0.000001 y 999999.999999. Cambiarla NO deja rastro del valor anterior: no hay auditoría del precio. Aunque sea el único campo del cuerpo, la zona y el producto se revalidan igual y pueden devolver 400.',
            type: 'number',
            format: 'float',
            maximum: 999999.999999,
            minimum: 0.000001,
            example: 0.462500,
        ),
    ],
    type: 'object',
)]
class UpdateFreightRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is optional and an empty payload is accepted as a no-op.
     *
     * There are five editable fields: chaining `required_without` across all of them
     * would only produce five identical messages for the same empty body.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'zoneId' => ['sometimes', 'integer', 'exists:zones,id'],
            'productId' => ['sometimes', 'integer', 'exists:products,id'],
            'fuelType' => ['sometimes', Rule::enum(FuelType::class)],
            'fuelMin' => ['sometimes', 'numeric', 'min:0.01', 'max:999999.99'],
            'pricePerPound' => ['sometimes', 'numeric', 'min:0.000001', 'max:999999.999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'zoneId.integer' => 'La zona debe ser un identificador numérico',
            'zoneId.exists' => 'La zona seleccionada no existe',
            'productId.integer' => 'El producto debe ser un identificador numérico',
            'productId.exists' => 'El producto seleccionado no existe',
            'fuelType.enum' => 'El tipo de combustible no es válido',
            'fuelMin.numeric' => 'El precio de combustible debe ser un número en quetzales por galón',
            'fuelMin.min' => 'El precio de combustible debe ser mayor que cero',
            'fuelMin.max' => 'El precio de combustible no puede superar los 999999.99 quetzales por galón',
            'pricePerPound.numeric' => 'La tarifa por libra debe ser un número en quetzales',
            'pricePerPound.min' => 'La tarifa por libra debe ser mayor que cero',
            'pricePerPound.max' => 'La tarifa por libra no puede superar los 999999.999999 quetzales',
        ];
    }
}
