<?php

namespace App\Http\Resources\FreightRate;

use App\Models\FreightRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * The answer to "how much does a pound cost up to this point".
 *
 * Unlike every other resource of the project this one does NOT wrap a model: it wraps
 * the array the service returns, because the three values that make the quote auditable
 * —the fuel price in effect, the band that was picked and the total— do not live in any
 * single row.
 */
#[OA\Schema(
    schema: 'FreightQuote',
    title: 'Cotización de flete',
    description: <<<'TEXT'
    Respuesta de GET /api/freight-rates/quote: cuánto cuesta la libra hasta el destino consultado y, si se enviaron las libras, cuánto cuesta el flete completo.

    NO PERSISTE NADA. Es una consulta pura: ninguna fila se crea, se edita ni se marca, y llamarla mil veces deja freight_rates exactamente igual. No es una reserva, no es un pedido y no bloquea el precio: la misma consulta mañana puede devolver otro número si cambió el precio del combustible o si se cotizó una banda nueva.

    UNIDADES FIJAS DEL DOMINIO: currentFuelPrice y appliedFuelMin en QUETZALES (GTQ) POR GALÓN, pricePerPound en GTQ POR LIBRA con seis decimales, pounds en LIBRAS y total en GTQ con dos decimales. No hay moneda configurable, ni kilos, ni litros, ni impuestos.

    ATENCIÓN — EL PRECIO DEL COMBUSTIBLE NUNCA VIAJA EN LA PETICIÓN. currentFuelPrice sale SIEMPRE del FuelPrice con status active de ese fuelType; cualquier precio que el cliente mande en la query se ignora por completo, por ningún nombre se acepta. No existe la simulación "¿y si el diésel subiera a 45?": si se aceptara, cada quien cotizaría al precio que le conviene.

    ATENCIÓN — EL TOTAL AUTORITATIVO ES EL DE LA API. Recalcularlo en pantalla redondeando pricePerPound a dos decimales da 20 250.00 en vez de 20 435.40 para 45 000 libras: 185 quetzales de diferencia en un solo flete. La API multiplica con los seis decimales completos y redondea SOLO al final.

    Este recurso NO envuelve un modelo: junta la tarifa elegida con los tres datos que la hacen auditable —el precio de combustible vigente, la banda que se aplicó y el total—, que no viven en ninguna fila.

    Ejemplo completo del dominio: 45 000 libras de brócoli con destino en la bodega central de Escuintla y el diésel vigente a 40.00 devuelven currentFuelPrice 40.00, appliedFuelMin 35.00, pricePerPound 0.454120, pounds 45000.00 y total 20435.40.
    TEXT,
    properties: [
        new OA\Property(
            property: 'freightRateId',
            description: 'Identificador de la tarifa que se APLICÓ (freight_rates.id): la banda concreta que ganó entre todas las del trío. Sirve para auditar la cotización y para ir al detalle de la fila, aunque GET /api/freight-rates/{freightRate} es exclusivo del administrator. No se crea nada: este id ya existía antes de la consulta.',
            type: 'integer',
            example: 12,
        ),
        new OA\Property(
            property: 'locationId',
            description: 'Identificador del destino cotizado (locations.id), el mismo que se envió en la query. No se deduce de ninguna coordenada: el destino se manda explícito, así que no hay ambigüedad que resolver ni forma de cotizar un lugar sin darlo de alta antes. Un destino con status false devuelve 400 y no llega a este schema; uno inexistente lo atrapa antes la validación con 422.',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'locationName',
            description: 'Nombre del destino cotizado, EN MAYÚSCULAS. Viaja resuelto para que el cliente confirme al usuario qué destino se cotizó sin un segundo GET. Las coordenadas del destino NO viajan aquí: quien cotiza mandó el locationId y ya las tiene.',
            type: 'string',
            nullable: true,
            example: 'BODEGA CENTRAL ESCUINTLA',
        ),
        new OA\Property(
            property: 'productId',
            description: 'Identificador del producto cotizado, el mismo que se envió en la query. Un producto con status false se trata como inexistente y devuelve 400, no 404.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'productName',
            description: 'Nombre del producto cotizado, resuelto por relación.',
            type: 'string',
            nullable: true,
            example: 'BRÓCOLI',
        ),
        new OA\Property(
            property: 'fuelType',
            description: 'Tipo de combustible con el que se cotizó, el mismo que se envió en la query. Determina de qué FuelPrice active se lee currentFuelPrice y qué bandas se consideran.',
            type: 'string',
            enum: ['regular', 'premium', 'diesel', 'diesel_premium'],
            example: 'diesel',
        ),
        new OA\Property(
            property: 'currentFuelPrice',
            description: 'Precio de combustible VIGENTE al momento de consultar, EN QUETZALES POR GALÓN, con dos decimales. Sale SIEMPRE del FuelPrice con status active de ese fuelType y NUNCA de la petición: mandar un precio en la query no lo altera en absoluto. Si el tipo no tiene ninguna fila active, la cotización responde 400 antes de llegar aquí. Junto con appliedFuelMin es la trazabilidad del cálculo.',
            type: 'string',
            example: '40.00',
        ),
        new OA\Property(
            property: 'appliedFuelMin',
            description: 'fuelMin de la banda que se aplicó, EN QUETZALES POR GALÓN. Se elige la banda de mayor fuelMin que sea MENOR O IGUAL que currentFuelPrice —el límite es inclusivo—; si el combustible vigente está por debajo de TODAS las bandas, se aplica la de menor fuelMin, sin error. Este paso NUNCA falla. ATENCIÓN — la distancia entre currentFuelPrice y appliedFuelMin es la ÚNICA señal de que una tarifa se quedó vieja: currentFuelPrice 60.00 junto a appliedFuelMin 28.00 significa que esa banda lleva mucho sin recotizarse y que el flete se está cobrando por debajo del costo real. El sistema no avisa por ningún otro medio: responde 200 con un número que parece perfectamente válido.',
            type: 'string',
            example: '35.00',
        ),
        new OA\Property(
            property: 'pricePerPound',
            description: 'Tarifa aplicada EN QUETZALES POR LIBRA, con los SEIS decimales completos. No lo redondees antes de multiplicar: es exactamente lo que produce la diferencia de 185 quetzales del ejemplo de 45 000 libras.',
            type: 'string',
            example: '0.454120',
        ),
        new OA\Property(
            property: 'pounds',
            description: 'Peso EN LIBRAS que se envió en la query, normalizado a dos decimales. Es NULL cuando la query no trae pounds: el parámetro es opcional y su ausencia no es un error, simplemente devuelve la tarifa por libra sin total. El resto de la respuesta es idéntica con o sin él.',
            type: 'string',
            nullable: true,
            example: '45000.00',
        ),
        new OA\Property(
            property: 'total',
            description: 'Costo total del flete EN QUETZALES, con dos decimales, calculado como pounds × pricePerPound y REDONDEADO SOLO AL FINAL, después del producto y nunca antes. Es NULL cuando no se enviaron pounds. ATENCIÓN — este es el total autoritativo: si el cliente lo recalcula con un pricePerPound redondeado a dos decimales, obtiene 20 250.00 en vez de 20 435.40. No incluye impuestos, IVA, recargos ni redondeo comercial: nada de eso existe en este dominio.',
            type: 'string',
            nullable: true,
            example: '20435.40',
        ),
    ],
    type: 'object',
)]
class FreightQuoteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FreightRate $rate */
        $rate = $this->resource['rate'];

        $pounds = $this->resource['pounds'] ?? null;
        $total = $this->resource['total'] ?? null;

        return [
            'freightRateId' => $rate->id,
            'locationId' => $rate->location_id,
            'locationName' => $rate->location?->name,
            'productId' => $rate->product_id,
            'productName' => $rate->product?->name,
            'fuelType' => $rate->fuel_type->value,
            /** El FuelPrice active al momento de consultar; nunca sale de la petición. */
            'currentFuelPrice' => $this->resource['currentFuelPrice'],
            /** La banda elegida: su distancia con currentFuelPrice delata una tarifa vieja. */
            'appliedFuelMin' => $rate->fuel_min,
            'pricePerPound' => $rate->price_per_pound,
            'pounds' => $this->money($pounds),
            'total' => $this->money($total),
        ];
    }

    /**
     * Render an amount with the two decimals money is read with, keeping null as null.
     */
    private function money(int|float|null $amount): ?string
    {
        return $amount === null ? null : number_format($amount, 2, '.', '');
    }
}
