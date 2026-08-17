<?php

namespace App\Http\Resources\Pilot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PilotSalaryHistory',
    title: 'Entrada de la bitácora de salario',
    description: <<<'TEXT'
    Una entrada de la bitácora de cambios de salario de un piloto: qué salario tenía, cuál pasó a tener, quién lo cambió y cuándo. Todos los importes van EN QUETZALES (GTQ) y son SALARIOS MENSUALES, igual que el campo salary del piloto.

    TODA FILA DEL HISTORIAL ES UN CAMBIO REAL. Un PATCH con un salario idéntico al vigente responde 400 y NO escribe aquí, así que la bitácora no tiene filas de ruido en las que el salario anterior y el nuevo coincidan: previousSalary y newSalary de una misma fila son siempre distintos.

    La bitácora es de SOLO ESCRITURA por parte del sistema y SOLO LECTURA por parte del cliente: no hay endpoint que edite ni que borre una entrada, ni existe un historial global de la empresa. La única forma de crear una entrada es PATCH /api/pilots/{pilot}/salary, y la única de leerlas es GET /api/pilots/{pilot}/salary-history.

    NO HAY reason, notes NI effective_from: la bitácora registra el QUÉ y el QUIÉN, nunca el POR QUÉ, y el cambio rige desde que se guarda —changedAt es la fecha de vigencia—. No existen aumentos programados a futuro.

    ATENCIÓN — el salario vigente vive en dos sitios: la columna salary del piloto y la última fila de esta bitácora. Nada en la base garantiza que coincidan; solo la transacción del PATCH lo hace. Un UPDATE directo a la columna por fuera de la API dejaría este historial mintiendo sin ningún error ni señal.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la entrada de bitácora (carrier_pilot_salary_histories.id). No se usa en ninguna ruta —no hay detalle, edición ni baja de una entrada—, pero sirve como clave estable de la fila y refleja el orden real de los cambios: el listado se ordena por este id DESC y no por la fecha, porque dos cambios en el mismo segundo empatarían el created_at y el orden quedaría indefinido.',
            type: 'integer',
            example: 37,
        ),
        new OA\Property(
            property: 'previousSalary',
            description: 'Salario MENSUAL EN QUETZALES que el piloto tenía ANTES de este cambio, como cadena con dos decimales. ATENCIÓN — es null ÚNICAMENTE en la PRIMERA asignación de cada piloto, cuando no había salario previo; es la fila más antigua del historial y la única de todo el listado que puede traer null aquí. Un null NO significa que ganara cero. En cualquier otra fila coincide con el newSalary de la fila inmediatamente anterior en el tiempo, de modo que la bitácora queda encadenada.',
            type: 'string',
            nullable: true,
            example: '4000.00',
        ),
        new OA\Property(
            property: 'newSalary',
            description: 'Salario MENSUAL EN QUETZALES con el que quedó el piloto tras este cambio, como cadena con dos decimales. Nunca es null y nunca es igual al previousSalary de su propia fila. En la entrada más reciente coincide con el campo salary que devuelve GET /api/pilots. Puede ser MENOR que el anterior: bajar el salario está permitido sin restricción y se registra exactamente igual que una subida.',
            type: 'string',
            example: '4500.00',
        ),
        new OA\Property(
            property: 'changedById',
            description: 'Identificador del usuario que realizó el cambio (users.id). Sale SIEMPRE del usuario autenticado que llamó al PATCH, NUNCA del cuerpo de la petición: mandar un changedBy en el body no tiene ningún efecto. Es un administrator o un carrier, los dos únicos roles que pueden escribir el salario. La FK a users no cascadea a propósito: borrar al usuario que hizo el cambio no puede borrar el rastro del cambio.',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'changedByName',
            description: 'Nombre del usuario que realizó el cambio, resuelto por la relación changedBy, para que el cliente pinte la bitácora sin un segundo GET. Es null solo si la relación no se pudo cargar. Es el nombre actual del usuario, no una copia congelada en el momento del cambio: si se renombra, la bitácora entera pasa a mostrar el nombre nuevo.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'changedAt',
            description: 'Fecha en que el cambio se guardó, que es también su fecha de VIGENCIA: no hay effective_from ni aumentos a futuro, el salario rige desde este instante. ATENCIÓN — NO viaja en ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM), igual que el joinedAt de Pilot y las fechas de Products, Zones y FreightRates. Por eso se documenta como string SIN format date-time: parsearlo como ISO fallaría. No sirve para ordenar el historial —el orden lo da el id—, porque dos cambios del mismo segundo empatan aquí.',
            type: 'string',
            nullable: true,
            example: '15-08-2026 09:30:12 PM',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PilotSalaryHistoryListResponse',
    title: 'Historial de salario sin paginar',
    description: 'Respuesta de GET /api/pilots/{pilot}/salary-history cuando no se envía limit o cuando el limit no es numérico: se devuelve la bitácora completa del piloto y el sobre NO incluye total, currentPage ni lastPage. El orden es fijo: del cambio MÁS RECIENTE al MÁS ANTIGUO (id DESC), de modo que la última entrada de la lista es la primera asignación, la única con previousSalary null. Un piloto al que nunca se le ha cambiado el salario devuelve 200 con data vacío, nunca 404.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Historial de salario obtenido correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/PilotSalaryHistory')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedPilotSalaryHistoryListResponse',
    title: 'Historial de salario paginado',
    description: 'Respuesta de GET /api/pilots/{pilot}/salary-history cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, no anidados bajo meta. El historial pagina con la misma regla que el listado de pilotos, acotada a [10, 100].',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/PilotSalaryHistoryListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class PilotSalaryHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * One entry of the salary log. `changedAt` is the date the change took effect: the
     * change rules from the moment it is saved, so created_at is all the log needs.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Null on the first assignment: there was no salary before it. */
            'previousSalary' => $this->previous_salary,
            'newSalary' => $this->new_salary,
            'changedById' => $this->changed_by,
            'changedByName' => $this->changedBy?->name,
            'changedAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
