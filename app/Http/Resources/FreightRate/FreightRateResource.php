<?php

namespace App\Http\Resources\FreightRate;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FreightRate',
    title: 'Tarifa de flete',
    description: <<<'TEXT'
    Precio del flete por libra para una combinación concreta de destino, producto y tipo de combustible. NO pertenece a ninguna empresa transportista —es un dato de Legumex, común a todos—, por eso el recurso no expone carrierId y ninguna ruta lleva el middleware carrier.required.

    UNIDADES FIJAS DEL DOMINIO, no configurables y sin campo que las declare: el dinero va SIEMPRE en QUETZALES (GTQ), el fuelMin en GTQ POR GALÓN, el pricePerPound en GTQ POR LIBRA con SEIS decimales y el peso en LIBRAS. No hay moneda alternativa, ni kilos, ni litros, ni IVA, ni redondeo comercial.

    ATENCIÓN — BANDA ABIERTA. Una tarifa no se ata a ningún precio de combustible concreto: rige DESDE su fuelMin HACIA ARRIBA, hasta que exista otra banda más alta del mismo trío (destino + producto + combustible). Con bandas desde 28.00 y desde 35.00, un diésel a 40 aplica la de 35 y un diésel a 25 aplica la de 28. Ninguna cotización falla nunca por el precio del combustible, y por lo mismo una banda vieja se sigue aplicando en silencio: una tarifa cotizada desde 28.00 sigue rigiendo con el diésel a 60 sin ningún aviso, sin 400 y sin log. La única señal disponible es la distancia entre currentFuelPrice y appliedFuelMin en la respuesta de GET /api/freight-rates/quote.

    La unicidad es de la banda entera: no pueden existir dos tarifas VIVAS con el mismo (locationId, productId, fuelType, fuelMin). No la respalda ningún índice único a propósito, porque el DELETE es soft delete y un índice bloquearía para siempre un fuelMin ya borrado.

    ATENCIÓN — la baja de este recurso es un SOFT DELETE REAL y NO idempotente, al contrario que Locations y Products: DELETE marca deleted_at, la fila desaparece del listado y de la cotización, y un SEGUNDO DELETE responde 400 (La tarifa ya fue eliminada), no 404 ni 200. No hay campo status, no hay /toggle-status y no hay restore: si la banda hace falta otra vez, se vuelve a cotizar con un POST.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la fila (freight_rates.id). Es el valor que va en el parámetro {freightRate} de las rutas de detalle, actualización y baja. Sobrevive a la baja: la fila sigue en base con deleted_at poblado, así que el id nunca se reutiliza ni desaparece, solo deja de ser utilizable con 400.',
            type: 'integer',
            example: 1,
        ),
        new OA\Property(
            property: 'locationId',
            description: 'Identificador del destino puntual al que aplica la tarifa (locations.id). El destino debe estar activo para poder crear o editar la tarifa; si se desactiva después, la fila queda CONGELADA: el PATCH responde 400 aunque solo se cambie el precio. Es también el único filtro del listado.',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'locationName',
            description: 'Nombre del destino resuelto por relación, siempre EN MAYÚSCULAS como lo devuelve el dominio de Locations. Viaja resuelto para que el cliente pinte la tabla de precios sin un segundo GET. Es null solo si la relación no se pudo cargar.',
            type: 'string',
            nullable: true,
            example: 'BODEGA CENTRAL ESCUINTLA',
        ),
        new OA\Property(
            property: 'productId',
            description: 'Identificador del producto transportado (products.id). Igual que el destino, debe estar activo para crear o editar la tarifa, y desactivarlo congela todas las tarifas de ese producto hasta reactivarlo.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'productName',
            description: 'Nombre del producto resuelto por relación, para no obligar a un segundo GET al pintar la tabla de precios. Es null solo si la relación no se pudo cargar.',
            type: 'string',
            nullable: true,
            example: 'BRÓCOLI',
        ),
        new OA\Property(
            property: 'fuelType',
            description: 'Tipo de combustible al que corresponde la banda, del enum compartido con FuelPrices. Forma parte de la clave de la banda: el mismo fuelMin para otro fuelType es una tarifa distinta y perfectamente válida. La cotización exige este mismo valor para elegir la banda.',
            type: 'string',
            enum: ['regular', 'premium', 'diesel', 'diesel_premium'],
            example: 'diesel',
        ),
        new OA\Property(
            property: 'fuelMin',
            description: 'Precio de combustible EN QUETZALES POR GALÓN DESDE EL CUAL rige la tarifa, como cadena con DOS decimales (misma forma que fuel_prices.price, para que la comparación de la banda no arrastre redondeos). NO es un precio máximo ni un rango cerrado: la banda es abierta hacia arriba y solo la corta la siguiente banda más alta del mismo par. Teclear 3.00 en vez de 30.00 crea una banda válida que pasa la unicidad sin problema y pasa a ser la más barata del par; el error no se detecta al crear, solo cuando alguien cotiza y el número sale raro.',
            type: 'string',
            example: '35.00',
        ),
        new OA\Property(
            property: 'pricePerPound',
            description: 'Tarifa del flete EN QUETZALES POR LIBRA, como cadena con SEIS decimales, que es la precisión real de la columna decimal(12,6). ATENCIÓN — no lo redondees antes de multiplicar: redondear 0.454120 a 0.45 y multiplicar por 45 000 libras da 20 250.00 en vez de 20 435.40, es decir 185 quetzales de menos en un solo flete. Para obtener el total autoritativo, manda pounds a GET /api/freight-rates/quote y usa el total que devuelve la API.',
            type: 'string',
            example: '0.454120',
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del administrador que dio de alta la tarifa, resuelto por la relación registeredBy. NO se acepta en el cuerpo del POST ni del PATCH: sale siempre del usuario autenticado, y el PATCH no lo reescribe, así que sigue apuntando a quien creó la fila aunque la edite otro administrador. No se guarda ningún rastro del pricePerPound anterior.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de alta de la tarifa. ATENCIÓN — igual que en Products y Locations: NO viaja en ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM). Por eso se documenta como string SIN format date-time: un cliente generado desde este schema que intentara parsearlo como ISO fallaría. Está pensado para mostrarse tal cual.',
            type: 'string',
            nullable: true,
            example: '13-08-2026 08:45:12 PM',
        ),
        new OA\Property(
            property: 'updatedAt',
            description: 'Fecha del último cambio de cualquiera de los cinco campos editables. Es lo más cercano a una auditoría que ofrece el dominio: el PATCH sobrescribe el precio sin conservar el valor anterior. Mismo formato propio d-m-Y h:i:s A que createdAt, tampoco ISO 8601. El deleted_at del soft delete no se expone en el recurso.',
            type: 'string',
            nullable: true,
            example: '13-08-2026 09:02:40 PM',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'FreightRateListResponse',
    title: 'Listado de tarifas de flete',
    description: 'Respuesta de GET /api/freight-rates. ATENCIÓN — este listado NO PAGINA NUNCA, a diferencia de Carriers, Vehicles, FuelPrices, Products y Locations: no existe el parámetro limit, no hay PaginatedResource y el sobre NO trae total, currentPage ni lastPage. Enviar limit=10 no cambia nada. Es deliberado: la tabla de tarifas se lee entera, como una tabla de precios, no se navega. Las tarifas eliminadas (soft delete) NO aparecen. El orden es fijo: fuelType ASC y, dentro de cada tipo, fuelMin ASC, que es el orden en que se leen las bandas de un par.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Tarifas obtenidas correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FreightRate')),
    ],
    type: 'object',
)]
class FreightRateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The location and the product travel resolved by name so the client never needs a
     * second GET just to label a row of the price table.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'locationId' => $this->location_id,
            'locationName' => $this->location?->name,
            'productId' => $this->product_id,
            'productName' => $this->product?->name,
            'fuelType' => $this->fuel_type->value,
            /** Rige desde este precio de combustible hacia arriba. */
            'fuelMin' => $this->fuel_min,
            'pricePerPound' => $this->price_per_pound,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
