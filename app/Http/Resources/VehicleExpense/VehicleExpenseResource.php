<?php

namespace App\Http\Resources\VehicleExpense;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VehicleExpense',
    title: 'Gasto de mantenimiento de un vehículo',
    description: <<<'TEXT'
    Gasto de mantenimiento imputado a un vehículo concreto: qué se hizo (category), si fue programado o por avería (nature), cuánto costó (amount, en quetzales), qué día ocurrió (expenseDate), el detalle libre (description) y quién lo capturó (registeredBy).

    ATENCIÓN — LA SALIDA VA EN camelCase Y EL CUERPO QUE SE ENVÍA VA EN snake_case. No son los mismos nombres: aquí se lee vehicleId, expenseDate, registeredBy y createdAt, pero el POST y el PATCH esperan vehicle_id, expense_date y description. Copiar un objeto de esta respuesta y reenviarlo tal cual a POST /api/vehicle-expenses devuelve 422, porque vehicleId y expenseDate no son campos válidos del cuerpo y los obligatorios vehicle_id y expense_date faltarían. Los únicos nombres que coinciden en las dos direcciones son category, nature, amount y description.

    ATENCIÓN — category y nature SON DOS EJES INDEPENDIENTES y no existe ninguna validación cruzada entre ellos: cualquiera de las 22 categorías admite tanto preventive como corrective. Cambiar llantas por desgaste programado es tires + preventive y cambiarlas por un reventón es tires + corrective; el backend acepta las dos combinaciones sin opinar. Es la misma relación que hay entre condition y status en el recurso Vehicle.

    expenseDate y createdAt NO son lo mismo: expenseDate es el DÍA en que ocurrió el gasto (sin hora, y nunca en el futuro) y createdAt es el instante en que se capturó en el sistema. Un gasto de hace tres meses capturado hoy tiene expenseDate de hace tres meses y createdAt de hoy. Ninguna de las dos viaja en ISO 8601.

    El gasto se BORRA DE VERDAD: no hay soft deletes, ni bitácora de ediciones, ni forma de recuperar un gasto eliminado. Tras un DELETE la fila desaparece de la base y cualquier petición posterior sobre ese id devuelve 404.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador del gasto (vehicle_expenses.id). Es el valor que viaja en {vehicleExpense} en las rutas de detalle, edición y borrado.',
            type: 'integer',
            example: 41,
        ),
        new OA\Property(
            property: 'vehicleId',
            description: 'Identificador del vehículo al que se imputa el gasto (vehicles.id). ES INMUTABLE: se fija en el alta con el campo vehicle_id del cuerpo y el PATCH no lo acepta —mandarlo no es un error, simplemente se ignora—. Mover un gasto a otro vehículo es borrarlo y volverlo a crear. Es también el valor que se manda en el filtro OBLIGATORIO vehicleId de GET /api/vehicle-expenses. Ojo con el cambio de nombre: aquí sale como vehicleId y en el cuerpo del alta se envía como vehicle_id.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'category',
            description: 'Categoría del gasto, uno de los 22 valores cerrados del enum. Viajan en inglés y en snake_case; la etiqueta en español la pone el cliente, porque el backend no devuelve ninguna. other es un valor más, sin trato especial en el código. La lista solo cambia con un despliegue: no hay catálogo administrable ni endpoint que la devuelva, así que un cliente que la escriba a mano debe revisarla en cada versión de la API.',
            type: 'string',
            enum: [
                'tires', 'oil_change', 'brakes', 'spare_part', 'battery', 'suspension',
                'engine', 'transmission', 'electrical_system', 'cooling_system', 'filters',
                'alignment_balancing', 'clutch', 'exhaust', 'air_conditioning', 'bodywork_paint',
                'glass_mirrors', 'inspection', 'washing', 'towing', 'labor', 'other',
            ],
            example: 'tires',
        ),
        new OA\Property(
            property: 'nature',
            description: 'Naturaleza del gasto: preventive es lo que se hizo ANTES de que fallara y corrective lo que se hizo PORQUE ya falló. Es una decisión de quien registra el gasto, no una propiedad de la categoría, y por eso es un eje aparte: no hay un tercer valor "sin clasificar" ni se admite null.',
            type: 'string',
            enum: ['preventive', 'corrective'],
            example: 'preventive',
        ),
        new OA\Property(
            property: 'amount',
            description: 'Monto del gasto EN QUETZALES (GTQ), como CADENA con DOS decimales por el cast decimal:2 — nunca como número JSON. La moneda es convención del dominio y no se guarda en base: nada valida que el importe sean quetzales. Nunca es 0.00 ni negativo, porque la validación exige un mínimo de 0.01: un gasto de cero es un error de captura. El máximo es 99999999.99, el que cabe en la columna decimal(10,2).',
            type: 'string',
            example: '1250.50',
        ),
        new OA\Property(
            property: 'expenseDate',
            description: 'DÍA en que ocurrió el gasto, sin hora. ATENCIÓN — NO viaja en ISO 8601 sino con el formato propio d-m-Y (día-mes-año), la misma convención del dominio Pilot; por eso se documenta como string SIN format date, y un cliente que lo parsee como ISO leerá el día como año. En el cuerpo del POST y del PATCH este mismo dato se ENVÍA en snake_case y en formato Y-m-d: entra 2026-08-12 y sale 12-08-2026. Nunca es una fecha futura, porque la validación es before_or_equal:today.',
            type: 'string',
            example: '12-08-2026',
        ),
        new OA\Property(
            property: 'description',
            description: 'Detalle libre del gasto, obligatorio y de hasta 1000 caracteres. Es donde caben hoy el taller, el número de factura y la pieza concreta, que no tienen columna propia: no existen los campos supplier ni invoiceNumber, así que buscar por proveedor no es posible.',
            type: 'string',
            example: 'Cuatro llantas nuevas, taller El Rodaje, factura A-9912',
        ),
        new OA\Property(
            property: 'registeredBy',
            description: 'NOMBRE del usuario que registró el gasto, NO su id: la pantalla lo imprime y nadie navega a ese usuario. El id vive en la columna registered_by y NO SALE NUNCA por la API, así que este campo no sirve para filtrar ni para enlazar. Sale siempre del usuario autenticado en el alta —enviar registered_by en el cuerpo no cambia nada— y NO SE REESCRIBE en el PATCH: si un administrator edita el gasto de un transportista, aquí sigue apareciendo el transportista que lo creó. Es null solo si la relación no se pudo cargar; en la práctica siempre viaja poblado, porque la FK a users no admite huérfanos.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Instante en que el gasto se CAPTURÓ en el sistema, que no es cuándo ocurrió: para eso está expenseDate. ATENCIÓN — tampoco viaja en ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM). No existe updatedAt en la salida ni bitácora de ediciones: el PATCH no deja ningún rastro visible.',
            type: 'string',
            nullable: true,
            example: '12-08-2026 04:31:07 PM',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'VehicleExpenseListResponse',
    title: 'Listado de gastos sin paginar',
    description: <<<'TEXT'
    Respuesta de GET /api/vehicle-expenses cuando no se envía limit o cuando el limit no es numérico: se devuelven TODOS los gastos del vehículo que cumplen los filtros y el sobre NO incluye total, currentPage ni lastPage. El orden es fijo —expense_date descendente y, para dos gastos del mismo día, id descendente— y no es configurable: no hay parámetros sortBy ni order.

    totalAmount SÍ aparece en esta forma, aunque no haya paginación: es dato de negocio y no metadata del paginador.
    TEXT,
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Gastos obtenidos correctamente'),
        new OA\Property(
            property: 'totalAmount',
            description: 'SUMA en quetzales del campo amount de TODOS los gastos que cumplen los filtros, como CADENA con dos decimales. NO CONFUNDIR CON total: total es el CONTEO de registros que aporta el paginador y solo aparece cuando se pagina; totalAmount es una CANTIDAD DE DINERO y aparece SIEMPRE, con y sin paginación. La suma se calcula sobre la consulta ya filtrada y ANTES de paginar, así que con limit=10 sobre 12 gastos el array data trae 10 elementos pero totalAmount suma los 12. Es "0.00" cuando el vehículo no tiene gastos que cumplan los filtros. No hay desglose por categoría, por naturaleza ni por mes: este es el único agregado que devuelve el dominio.',
            type: 'string',
            example: '18430.50',
        ),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/VehicleExpense')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedVehicleExpenseListResponse',
    title: 'Listado de gastos paginado',
    description: <<<'TEXT'
    Respuesta de GET /api/vehicle-expenses cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS en la raíz del sobre, junto a statusCode, message, totalAmount y data, y no anidados bajo una clave meta.

    ATENCIÓN — en esta forma conviven en la misma raíz totalAmount y total, y NO significan lo mismo: total es el CONTEO de gastos que cumplen los filtros (un entero) y totalAmount es la SUMA en quetzales de sus importes (una cadena con dos decimales). Con 12 gastos de 100.50 la respuesta trae total = 12 y totalAmount = "1206.00". Confundirlos imprime doce quetzales o mil doscientos gastos.
    TEXT,
    allOf: [
        new OA\Schema(ref: '#/components/schemas/VehicleExpenseListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class VehicleExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `registeredBy` is the name of the user that created the expense, not its
     * id: the screen prints it and nobody navigates to that user. The id stays
     * in the `registered_by` column and never leaves the API.
     *
     * `expenseDate` is the day the expense happened and carries no time, while
     * `createdAt` is when it was captured — two different things.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicleId' => $this->vehicle_id,
            'category' => $this->category?->value,
            'nature' => $this->nature?->value,
            /** Amount in GTQ, always with two decimals. */
            'amount' => $this->amount,
            'expenseDate' => $this->expense_date?->format('d-m-Y'),
            'description' => $this->description,
            'registeredBy' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
