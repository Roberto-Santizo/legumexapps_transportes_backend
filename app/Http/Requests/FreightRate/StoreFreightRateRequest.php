<?php

namespace App\Http\Requests\FreightRate;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreFreightRateRequest',
    title: 'Alta de tarifa de flete',
    description: <<<'TEXT'
    Cuerpo JSON para cotizar una banda nueva. Los cinco campos —locationId, productId, fuelType, fuelMin y pricePerPound— son OBLIGATORIOS; no hay ningún campo opcional.

    El registeredBy NO SE ACEPTA por ningún nombre: se resuelve desde el usuario autenticado, que por el middleware role:administrator es siempre un administrador. Mandarlo en el cuerpo no tiene ningún efecto, ni siquiera un 422. Tampoco se acepta ningún precio de combustible vigente: la tarifa guarda un fuelMin, no una referencia a fuel_prices.

    Las claves del cuerpo van en camelCase; el service traduce a snake_case al persistir.

    ATENCIÓN — 422 Y 400 SIGNIFICAN COSAS DISTINTAS. La regla exists solo comprueba que la fila ESTÉ, nunca que esté activa: un locationId o un productId inexistente es 422 (validación), mientras que un destino o un producto con status false es 400 (regla de negocio del service, con su propio mensaje: El destino seleccionado no está activo / El producto seleccionado no está activo). Repetir una banda ya cotizada también es 400, no 422: Ya existe una tarifa para ese destino, ese producto y ese combustible desde ese precio.

    La unicidad mira SOLO las filas vivas: tras un DELETE, ese mismo fuelMin vuelve a estar libre y se puede cotizar otra vez con 201.
    TEXT,
    required: ['locationId', 'productId', 'fuelType', 'fuelMin', 'pricePerPound'],
    properties: [
        new OA\Property(
            property: 'locationId',
            description: 'Identificador del destino puntual al que aplica la tarifa (locations.id). Obligatorio y entero. Si el id no existe es 422 con "El destino seleccionado no existe"; si existe pero tiene status false es 400 con "El destino seleccionado no está activo". Forma parte de la clave de la banda junto a productId, fuelType y fuelMin.',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'productId',
            description: 'Identificador del producto transportado (products.id). Obligatorio y entero. Id inexistente: 422 con "El producto seleccionado no existe"; producto con status false: 400 con "El producto seleccionado no está activo". Forma parte de la clave de la banda.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'fuelType',
            description: 'Tipo de combustible de la banda, del enum compartido con FuelPrices. Obligatorio; ausente o fuera del enum devuelve 422 (mensajes: El tipo de combustible es obligatorio / El tipo de combustible no es válido). No se comprueba que ese tipo tenga un precio vigente: se puede cotizar una banda de un combustible que todavía no tiene FuelPrice active. El mismo fuelMin para OTRO fuelType es una tarifa distinta y se acepta.',
            type: 'string',
            enum: ['regular', 'premium', 'diesel', 'diesel_premium'],
            example: 'diesel',
        ),
        new OA\Property(
            property: 'fuelMin',
            description: 'Precio de combustible EN QUETZALES POR GALÓN DESDE EL CUAL rige la tarifa. Obligatorio, numérico, entre 0.01 y 999999.99 (fuera de rango: 422). Se normaliza a DOS decimales antes de comprobar la unicidad, de modo que 30.005 y 30.01 son la misma banda. NO es un rango cerrado: no existe fuelMax y la banda queda abierta hacia arriba hasta la siguiente banda más alta del mismo par. ATENCIÓN — un dedazo aquí no falla: teclear 3 en vez de 30 crea una banda válida que pasa la unicidad y se convierte en la más barata del par, y el error solo se descubre cuando alguien cotiza y el número sale raro. Por eso este campo es editable con PATCH.',
            type: 'number',
            format: 'float',
            maximum: 999999.99,
            minimum: 0.01,
            example: 35.00,
        ),
        new OA\Property(
            property: 'pricePerPound',
            description: 'Tarifa del flete EN QUETZALES POR LIBRA. Obligatorio, numérico, entre 0.000001 y 999999.999999 (fuera de rango: 422). Se guarda con SEIS decimales, así que 0.454120 vuelve íntegro en la respuesta: no lo redondees a dos antes de enviarlo, porque sobre 45 000 libras esa pérdida vale 185 quetzales. No incluye impuestos ni recargos.',
            type: 'number',
            format: 'float',
            maximum: 999999.999999,
            minimum: 0.000001,
            example: 0.454120,
        ),
    ],
    type: 'object',
)]
class StoreFreightRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The five fields are mandatory; registeredBy is not accepted at all.
     *
     * `exists` only proves the row is there, never that it is active: the status is a
     * business rule of the service, with its own message and its own 400.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locationId' => ['required', 'integer', 'exists:locations,id'],
            'productId' => ['required', 'integer', 'exists:products,id'],
            'fuelType' => ['required', Rule::enum(FuelType::class)],
            'fuelMin' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'pricePerPound' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
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
            'productId.required' => 'El producto es obligatorio',
            'productId.integer' => 'El producto debe ser un identificador numérico',
            'productId.exists' => 'El producto seleccionado no existe',
            'fuelType.required' => 'El tipo de combustible es obligatorio',
            'fuelType.enum' => 'El tipo de combustible no es válido',
            'fuelMin.required' => 'El precio de combustible desde el cual rige la tarifa es obligatorio',
            'fuelMin.numeric' => 'El precio de combustible debe ser un número en quetzales por galón',
            'fuelMin.min' => 'El precio de combustible debe ser mayor que cero',
            'fuelMin.max' => 'El precio de combustible no puede superar los 999999.99 quetzales por galón',
            'pricePerPound.required' => 'La tarifa por libra es obligatoria',
            'pricePerPound.numeric' => 'La tarifa por libra debe ser un número en quetzales',
            'pricePerPound.min' => 'La tarifa por libra debe ser mayor que cero',
            'pricePerPound.max' => 'La tarifa por libra no puede superar los 999999.999999 quetzales',
        ];
    }
}
