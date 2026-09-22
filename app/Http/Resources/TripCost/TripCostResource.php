<?php

namespace App\Http\Resources\TripCost;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TripCost',
    title: 'Desglose del costo directo de un viaje finalizado',
    description: <<<'TEXT'
    El costo en GTQ de un viaje ya cerrado, repartido en CUATRO COMPONENTES. SIETE CLAVES de primer nivel en camelCase y ninguna más, en este orden: tripId, order, traveledHours, fuel, expenses, pilot, vehicle y totalCost.

    ATENCIÓN — ES COSTO DIRECTO, NO «LO QUE COSTÓ EL VIAJE». Los cuatro componentes son los únicos que existen: combustible confirmado, viáticos confirmados, salario del piloto prorrateado y seguro del vehículo prorrateado. NO INCLUYE depreciación del vehículo, mantenimiento (los vehicle_expenses NO se imputan al viaje: la fecha no prueba a qué viaje pertenece un cambio de llantas), peajes, administración, ni ingreso o margen —freight_rates cotiza por libra y trips no guarda peso—. Etiquetar la cifra como «costo total del viaje» en la interfaz sería engañoso.

    ATENCIÓN — NADA DE ESTO ESTÁ GUARDADO. No hay tabla, ni columna, ni snapshot, ni caché: el número se calcula en cada lectura, como el currentValue de SPEC 17 y el durationMinutes de SPEC 27. Aun así NO CAMBIA entre dos lecturas, porque los cuatro insumos son históricos y están congelados: el precio vigente en cada loaded_at, el salario vigente en el start_date y las traveled_hours ya cerradas por /finish.

    ATENCIÓN — TODO IMPORTE Y TODO DECIMAL SALEN COMO STRING de dos decimales ("1347.50"), igual que gallons en SPEC 27 y estimatedKilometers en SPEC 30: hay que parsearlos para operar. La ÚNICA excepción es expenses.count, que es un entero de verdad.

    UN INSUMO QUE FALTA VALE 0.00 Y SE VE COMO null. El endpoint nunca falla por un dato de catálogo incompleto: sin piloto o sin vehículo asignados, sin salario capturado, sin traveled_hours (viaje cerrado antes de SPEC 32) o sin precio de combustible para esa fecha, el insumo sale en null y su subtotal en "0.00", siempre con 200. El frontend puede avisar del hueco justamente porque lo ve.

    totalCost ES LA SUMA DE LOS CUATRO SUBTOTALES TAL COMO SALEN, no el redondeo de una suma en crudo: el desglose SIEMPRE cuadra con el total a la vista.
    TEXT,
    properties: [
        new OA\Property(
            property: 'tripId',
            description: 'Id del viaje cuyo costo se desglosa (trips.id), el mismo que viaja en la URL. Va aquí para que un objeto de costo suelto en el estado del frontend siga sabiendo a qué viaje pertenece.',
            type: 'integer',
            example: 42,
        ),
        new OA\Property(
            property: 'order',
            description: 'Número de orden del viaje, en MAYÚSCULAS tal como lo normalizó SPEC 24. Es el mismo order de TripResource y de TripListResource, repetido aquí solo para poder titular la pantalla del costo sin pedir el viaje entero. NO ES ÚNICO: dos viajes pueden compartir orden.',
            type: 'string',
            example: 'ORD-1024',
        ),
        new OA\Property(
            property: 'traveledHours',
            description: 'Horas reales del viaje (trips.traveled_hours, SPEC 32), como CADENA de dos decimales. SALE UNA SOLA VEZ EN LA RAÍZ a propósito: es el MISMO multiplicador de pilot.subtotal y de vehicle.subtotal, y repetirlo dentro de los dos bloques invitaría a creer que pueden diferir. Es tiempo BRUTO entre start_date y end_date, SIN descontar las paradas de trip_timeouts. ATENCIÓN — null EN UN VIAJE CERRADO ANTES DE SPEC 32 (no hubo backfill): en ese caso los DOS prorrateos salen en "0.00" aunque sus insumos tengan valor, y este null en la raíz es la explicación de un total anormalmente bajo.',
            type: 'string',
            nullable: true,
            example: '2.50',
        ),
        new OA\Property(
            property: 'fuel',
            ref: '#/components/schemas/TripCostFuel',
        ),
        new OA\Property(
            property: 'expenses',
            ref: '#/components/schemas/TripCostExpenses',
        ),
        new OA\Property(
            property: 'pilot',
            ref: '#/components/schemas/TripCostPilot',
        ),
        new OA\Property(
            property: 'vehicle',
            ref: '#/components/schemas/TripCostVehicle',
        ),
        new OA\Property(
            property: 'totalCost',
            description: 'Costo directo total del viaje en GTQ, como CADENA de dos decimales. Es la SUMA EXACTA de los cuatro subtotales tal como salen en esta misma respuesta —se suman ya redondeados, nunca se redondea al final—, así que el desglose siempre cuadra a la vista. ATENCIÓN — un total bajo no significa que el viaje fuera barato: mirar primero si traveledHours es null y si algún pricePerGallon salió en null.',
            type: 'string',
            example: '1814.35',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripCostFuel',
    title: 'Componente de combustible del costo',
    description: <<<'TEXT'
    Los galones CONFIRMADOS del viaje, cotizados al precio que regía el día de cada carga. Solo cuentan las cargas confirmadas por el piloto —el mismo criterio que totalFuelGallons de TripResource, así que los dos números siempre coinciden—: lo que no se ha confirmado todavía no se ha incurrido, y una carga sin confirmar NO aparece aquí ni suma galones.

    ATENCIÓN — EL PRECIO ES EL HISTÓRICO, NO EL DE HOY. Por cada carga se busca la fila de fuel_prices de su tipo con el created_at más reciente que no supere su loaded_at, y el status de esa fila NO IMPORTA: una fila inactive es precisamente el precio que estuvo vigente entonces. Consecuencia: capturar el precio del mes que viene NO mueve el costo de un viaje ya cerrado.

    ATENCIÓN — created_at de fuel_prices ES CUANDO EL ADMINISTRADOR CAPTURÓ EL PRECIO, no desde cuándo rigió (SPEC 06 no publicó columna de vigencia). Si capturó el viernes el precio del lunes, las cargas de esa semana se cotizan al precio anterior. Es el modelo que hay y se declara.
    TEXT,
    properties: [
        new OA\Property(
            property: 'gallons',
            description: 'Galones CONFIRMADOS del viaje, sumando todos los tipos, como CADENA de dos decimales. Es el mismo número que totalFuelGallons de TripResource y que totalGallons de GET /api/trips/{trip}/fuels. ATENCIÓN — PUEDE HABER GALONES CON IMPORTE CERO: los galones de una carga sin precio capturado para su fecha SÍ suman aquí aunque no aporten nada al subtotal, y esa discrepancia es la señal visible de que al tipo de combustible le falta historial de precios.',
            type: 'string',
            example: '35.00',
        ),
        new OA\Property(
            property: 'byType',
            description: 'Desglose por tipo de combustible, UN ELEMENTO POR TIPO presente entre las cargas confirmadas. ES LISTA VACÍA —nunca null— cuando el viaje no tiene ninguna carga confirmada. ATENCIÓN — SE AGRUPA POR TIPO PERO SE MULTIPLICA POR CARGA: dos cargas del mismo tipo a ambos lados de un cambio de precio se cotizan cada una a su precio y caen en un SOLO elemento con los importes ya sumados.',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/TripCostFuelType'),
        ),
        new OA\Property(
            property: 'subtotal',
            description: 'Importe del combustible en GTQ, como CADENA de dos decimales: la suma de los amount de byType, ya redondeados uno a uno.',
            type: 'string',
            example: '1347.50',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripCostFuelType',
    title: 'Combustible de un tipo dentro del costo',
    properties: [
        new OA\Property(
            property: 'fuelType',
            description: 'Tipo de combustible con el VALOR CRUDO DEL ENUM EN INGLÉS, sin traducir, igual que en TripFuelResource: regular, premium, diesel o diesel_premium. Traducirlo para la interfaz es del frontend.',
            type: 'string',
            enum: ['regular', 'premium', 'diesel', 'diesel_premium'],
            example: 'diesel',
        ),
        new OA\Property(
            property: 'gallons',
            description: 'Galones confirmados de este tipo, como CADENA de dos decimales. Incluye también los galones de las cargas que no pudieron cotizarse.',
            type: 'string',
            example: '35.00',
        ),
        new OA\Property(
            property: 'pricePerGallon',
            description: 'Precio por galón en GTQ, como CADENA de dos decimales. Con un único precio vigente para todas las cargas de este tipo es exactamente ese precio; cuando el viaje cruzó un cambio de precio es el PRECIO MEDIO PONDERADO de las cargas que sí se cotizaron, para que galones por precio siga cuadrando con el importe. ATENCIÓN — null SIGNIFICA QUE NINGUNA CARGA DE ESTE TIPO ENCONTRÓ PRECIO para su fecha —todas son anteriores al primer fuel_prices capturado de ese tipo—: en ese caso amount es "0.00" mientras gallons sigue contando, y es la señal de que falta historial de precios, no de que el combustible fuera gratis.',
            type: 'string',
            nullable: true,
            example: '38.50',
        ),
        new OA\Property(
            property: 'amount',
            description: 'Importe de este tipo en GTQ, como CADENA de dos decimales: la suma de galones por precio vigente de CADA carga, redondeada una sola vez al final del bloque. Vale "0.00" cuando ninguna carga del tipo pudo cotizarse.',
            type: 'string',
            example: '1347.50',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripCostExpenses',
    title: 'Componente de viáticos del costo',
    description: 'Los viáticos que el piloto CONFIRMÓ haber recibido. Mismo criterio que totalExpensesAmount de TripResource —solo los confirmados—, así que los dos números siempre coinciden para el mismo viaje. Un viático registrado y no confirmado no cuenta ni en count ni en subtotal.',
    properties: [
        new OA\Property(
            property: 'count',
            description: 'Cuántos viáticos confirmados tiene el viaje. ES LA ÚNICA CLAVE NUMÉRICA DE VERDAD DE TODA LA RESPUESTA: un entero, no una cadena. Vale 0 cuando no hay ninguno confirmado.',
            type: 'integer',
            example: 2,
        ),
        new OA\Property(
            property: 'subtotal',
            description: 'Suma de los viáticos confirmados en GTQ, como CADENA de dos decimales. Es el MISMO número que totalExpensesAmount de TripResource y que el totalAmount de GET /api/trips/{trip}/expenses.',
            type: 'string',
            example: '450.00',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripCostPilot',
    title: 'Componente de salario del piloto',
    description: <<<'TEXT'
    El salario mensual del piloto repartido sobre las horas que duró el viaje: monthlySalary / 720 × traveledHours.

    ATENCIÓN — EL MES SON 720 HORAS (30 × 24), no una jornada laboral. El sueldo se reparte sobre el mes completo porque este dominio no modela turnos: un viaje de madrugada no puede costar el triple que el mismo viaje de día. Consecuencia asumida: el prorrateo de un viaje corto es SIMBÓLICO (4 500 / 720 × 3 h = 18.75 GTQ) y no se parece a lo que la empresa paga de verdad por ese viaje.

    ATENCIÓN — EL SALARIO ES EL VIGENTE AL ARRANCAR EL VIAJE, leído de la bitácora de SPEC 11: la fila más reciente con created_at anterior o igual al start_date. Un aumento posterior NO mueve el costo. Sin ninguna fila de bitácora anterior al viaje se usa el salary actual del pivote carrier_pilots.
    TEXT,
    properties: [
        new OA\Property(
            property: 'pilotId',
            description: 'Id del usuario piloto del viaje (users.id). null en un viaje finalizado SIN piloto, solo alcanzable por el PATCH general del administrador —el hueco declarado de SPEC 24—.',
            type: 'integer',
            nullable: true,
            example: 7,
        ),
        new OA\Property(
            property: 'pilotName',
            description: 'Nombre del piloto, para pintar el bloque sin cruzar con el viaje. null cuando no hay piloto asignado.',
            type: 'string',
            nullable: true,
            example: 'Juan Pérez',
        ),
        new OA\Property(
            property: 'monthlySalary',
            description: 'Salario mensual base en GTQ vigente cuando arrancó el viaje, como CADENA de dos decimales. ATENCIÓN — SU null TIENE TRES CAUSAS INDISTINGUIBLES: el viaje no tiene piloto; el piloto YA NO ESTÁ VINCULADO a la empresa que tomó el viaje —se cambió de transportista y el pivote desapareció, y carrier_pilots no guarda bitácora de altas y bajas para reconstruirlo—; o el pivote existe pero nunca se le asignó salario (null significa «sin asignar», no «gana cero»). En los tres casos subtotal es "0.00" y la respuesta sigue siendo 200.',
            type: 'string',
            nullable: true,
            example: '4500.00',
        ),
        new OA\Property(
            property: 'subtotal',
            description: 'Salario imputado al viaje en GTQ, como CADENA de dos decimales: monthlySalary / 720 × traveledHours, redondeado a dos decimales. Vale "0.00" cuando falta el salario O cuando faltan las horas, así que un "0.00" aquí NO significa que el piloto no cobre: hay que mirar monthlySalary y traveledHours para saber cuál de los dos insumos falta.',
            type: 'string',
            example: '15.63',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripCostVehicle',
    title: 'Componente de seguro del vehículo',
    description: 'El seguro mensual del vehículo (SPEC 13) repartido sobre las horas del viaje con la MISMA base de 720 horas que el salario: monthlyInsuranceCost / 720 × traveledHours. ATENCIÓN — EL SEGURO ES EL ÚNICO COSTO FIJO DEL VEHÍCULO QUE ENTRA. La depreciación NO se imputa, por decisión explícita: purchase_price no participa en el cálculo ni siquiera prorrateado.',
    properties: [
        new OA\Property(
            property: 'vehicleId',
            description: 'Id del vehículo del viaje (vehicles.id). null en un viaje finalizado sin vehículo, solo alcanzable por el PATCH general del administrador.',
            type: 'integer',
            nullable: true,
            example: 3,
        ),
        new OA\Property(
            property: 'plate',
            description: 'Placa del vehículo en MAYÚSCULAS, para pintar el bloque sin cruzar con el viaje. null cuando no hay vehículo asignado.',
            type: 'string',
            nullable: true,
            example: 'C-123BCD',
        ),
        new OA\Property(
            property: 'monthlyInsuranceCost',
            description: 'Costo mensual del seguro del vehículo en GTQ, como CADENA de dos decimales, leído de vehicles.monthly_insurance_cost tal como está HOY. ATENCIÓN — ES EL ÚNICO INSUMO DEL DESGLOSE QUE NO ES HISTÓRICO: el vehículo no guarda bitácora de su seguro, así que cambiarlo SÍ mueve el costo de los viajes ya cerrados de ese vehículo. null solo cuando el viaje no tiene vehículo asignado.',
            type: 'string',
            nullable: true,
            example: '350.00',
        ),
        new OA\Property(
            property: 'subtotal',
            description: 'Seguro imputado al viaje en GTQ, como CADENA de dos decimales: monthlyInsuranceCost / 720 × traveledHours. Vale "0.00" cuando falta el seguro O cuando faltan las horas.',
            type: 'string',
            example: '1.22',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripCostResponse',
    title: 'Respuesta del costo de un viaje',
    description: 'Respuesta de GET /api/trips/{trip}/cost. Es un OBJETO, no un listado: no hay paginación, ni total, ni currentPage, ni lastPage, porque el costo se pide de un viaje a la vez. NO EXISTE GET /api/trips/costs ni clave de costo en el listado de viajes: pintar el costo de cien viajes en una tabla dispararía seiscientas consultas, y por eso este es un endpoint de detalle, uno por pantalla.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Costo del viaje obtenido correctamente'),
        new OA\Property(property: 'data', ref: '#/components/schemas/TripCost'),
    ],
    type: 'object',
)]
class TripCostResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The resource wraps the array shaped by `TripCostService::getTripCost()` and not a
     * model, like `TripsSummaryResource` in SPEC 29: the keys are listed here so the
     * output order is a contract.
     *
     * Every amount and every decimal leaves as a two decimal **string**, as `gallons` in
     * SPEC 27 and `estimatedKilometers` in SPEC 30 do. `expenses.count` is the only real
     * number of the whole response.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Trip $trip */
        $trip = $this->resource['trip'];

        return [
            'tripId' => $trip->id,
            'order' => $trip->order,
            /**
             * En la raíz y una sola vez: es el mismo multiplicador de los dos prorrateos, y
             * repetirlo dentro de cada bloque invitaría a creer que pueden diferir.
             */
            'traveledHours' => $this->decimal($this->resource['traveledHours']),
            'fuel' => $this->fuelBlock(),
            'expenses' => [
                /** El único entero de la respuesta: contar no es medir dinero. */
                'count' => $this->resource['expenses']['count'],
                'subtotal' => $this->decimal($this->resource['expenses']['subtotal']),
            ],
            'pilot' => [
                'pilotId' => $trip->pilot_id,
                'pilotName' => $trip->pilot?->name,
                'monthlySalary' => $this->decimal($this->resource['pilot']['monthlySalary']),
                'subtotal' => $this->decimal($this->resource['pilot']['subtotal']),
            ],
            'vehicle' => [
                'vehicleId' => $trip->vehicle_id,
                'plate' => $trip->vehicle?->plate,
                'monthlyInsuranceCost' => $this->decimal($this->resource['vehicle']['monthlyInsuranceCost']),
                'subtotal' => $this->decimal($this->resource['vehicle']['subtotal']),
            ],
            'totalCost' => $this->decimal($this->resource['totalCost']),
        ];
    }

    /**
     * The fuel block with its per type breakdown.
     *
     * `byType` is an empty list —never null— when the trip has no confirmed load, so the
     * consumer can map over it without a guard.
     *
     * @return array<string, mixed>
     */
    private function fuelBlock(): array
    {
        return [
            'gallons' => $this->decimal($this->resource['fuel']['gallons']),
            'byType' => array_map(fn (array $group): array => [
                /** El valor crudo del enum en inglés, como en TripFuelResource: traducir es del frontend. */
                'fuelType' => $group['fuelType'],
                'gallons' => $this->decimal($group['gallons']),
                'pricePerGallon' => $this->decimal($group['pricePerGallon']),
                'amount' => $this->decimal($group['amount']),
            ], $this->resource['fuel']['byType']),
            'subtotal' => $this->decimal($this->resource['fuel']['subtotal']),
        ];
    }

    /**
     * Format one number as a two decimal string, keeping a missing input as `null`.
     *
     * The `null` is never flattened to `"0.00"`: a missing input and an input worth zero
     * are different facts, and the frontend can only warn about the hole because it
     * sees it.
     */
    private function decimal(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 2, '.', '');
    }
}
