<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Dashboard\FleetTool;
use App\Ai\Tools\Dashboard\TripsInRouteTool;
use App\Ai\Tools\Dashboard\TripsSummaryTool;
use App\Ai\Tools\Dashboard\VehicleExpensesSummaryTool;
use App\Ai\Tools\Trip\TripExpensesTool;
use App\Ai\Tools\Trip\TripFuelsTool;
use App\Ai\Tools\Trip\TripsTool;
use App\Ai\Tools\Trip\TripTimeoutsTool;
use App\Ai\Tools\Trip\TripTool;
use App\Ai\Tools\Vehicle\VehicleExpensesTool;
use App\Ai\Tools\Vehicle\VehicleTool;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripExpense\TripExpenseServiceInterface;
use App\Interfaces\TripFuel\TripFuelServiceInterface;
use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;

/**
 * The read-only assistant behind `POST /api/assistant/chat`.
 *
 * Eleven tools, every one a wrapper over a read method of a service contract —the
 * four dashboard aggregates, five over trips and two over vehicles—, and a memory
 * that lives in the client: the previous turns arrive in the request and reach the
 * model through `Conversational::messages()`, before the prompt of the turn. Nothing
 * is persisted, which is why the agent does not use `RemembersConversations`.
 *
 * `MaxSteps` leaves room for a chain like `trips` → `trip` → `trip_fuels` plus a
 * couple of retries, not for one call per tool. `Timeout` is the budget of the whole
 * turn, tool calls included, above the 60 s the SDK assumes.
 */
#[Provider('gemini')]
#[MaxSteps(12)]
#[Timeout(90)]
class DashboardAssistant implements Agent, Conversational, HasTools
{
    use Promptable;

    /** Timezone the assistant reasons about relative dates in. */
    private const string TIMEZONE = 'America/Guatemala';

    /**
     * The authenticated user the tools are scoped through, resolved from the JWT guard
     * by the caller — never from the model's arguments — and the previous turns of the
     * conversation as the client kept them, in order, without the prompt of this turn.
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    public function __construct(
        protected readonly User $user,
        protected readonly array $history = [],
    ) {}

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * Any role other than `assistant` is treated as the user's: the request only
     * admits those two, and a stray value must not become a system message.
     *
     * @return list<Message>
     */
    public function messages(): iterable
    {
        return array_map(
            static fn (array $message): Message => $message['role'] === 'assistant'
                ? new AssistantMessage($message['content'])
                : new UserMessage($message['content']),
            $this->history,
        );
    }

    /**
     * Get the instructions that the agent should follow.
     *
     * The session block is filled from the user, not from the model: the company comes
     * from `currentCarrier()` (database), never from the token claim.
     */
    public function instructions(): string
    {
        return strtr($this->promptTemplate(), [
            '{{userName}}' => $this->user->name,
            '{{userRole}}' => $this->user->role->value,
            '{{carrierName}}' => $this->user->currentCarrier()?->name ?? 'todas (sin ámbito)',
            '{{now}}' => now(self::TIMEZONE)->format('d-m-Y h:i A'),
        ]);
    }

    /**
     * The system prompt with its `{{placeholders}}` still in place.
     */
    private function promptTemplate(): string
    {
        return <<<'PROMPT'
            Eres el asistente del tablero de Legumex Transportes, una plataforma interna de transporte de exportación en Guatemala. Respondes preguntas sobre viajes, flota y gastos de mantenimiento usando exclusivamente los datos que devuelven tus herramientas.
                ## Contexto de la sesión
                - Usuario: {{userName}} · rol: {{userRole}} · empresa: {{carrierName}}
                - Fecha y hora actual: {{now}} (zona horaria America/Guatemala)
                - Moneda: quetzales (GTQ). Distancias en kilómetros, combustible en galones.

                ## Herramientas
                Tienes once herramientas de solo lectura, en tres grupos. Nunca inventes cifras: toda cantidad, nombre o fecha que menciones debe salir de una llamada.

                Tablero (agregados):
                1. trips_summary(carrierId?, dateFrom?, dateTo?) — agregados de viajes: total, sin asignar, desglose por estado, empresa, cliente, naviera, puerto y mes. El rango de fechas corta sobre la fecha de recolección.
                2. trips_in_route(carrierId?) — lista de los viajes que están en ruta AHORA, con última posición, minutos detenido, galones confirmados y sin confirmar. Ignora fechas.
                3. vehicle_expenses_summary(carrierId?, dateFrom?, dateTo?) — agregados de gastos de mantenimiento: total, conteo, por categoría, por naturaleza (preventivo/correctivo), facturado/no facturado, por empresa y por mes. El rango corta sobre la fecha del gasto.
                4. fleet(carrierId?, status?, condition?, inRoute?, limit?) — la flota completa, incluidos vehículos inactivos, con el viaje en ruta de cada uno si lo tiene.

                Viajes (uno o varios en concreto):
                5. trips(status?, clientId?, shippingLineId?, locationId?, pilotId?, vehicleId?, dateFrom?, dateTo?, search?, limit?) — busca y lista viajes con sus datos básicos. search busca dentro del número de orden y del contenedor. Es la forma de obtener el id de un viaje.
                6. trip(tripId) — el detalle completo de un viaje: catálogos, fechas, piloto, vehículo, empresa que lo asignó, estimaciones, galones y viáticos confirmados. Sin la ruta ni imágenes.
                7. trip_fuels(tripId, limit?) — las cargas de combustible del viaje, confirmadas o no, y el total confirmado.
                8. trip_expenses(tripId, limit?) — los viáticos del viaje, confirmados o no, y el total confirmado.
                9. trip_timeouts(tripId, limit?) — las paradas detectadas en el viaje, con su duración.

                Vehículos (uno en concreto):
                10. vehicle(vehicleId? | plate?) — la ficha de un vehículo por id o por placa, con precio de compra, seguro mensual y su viaje en ruta si lo tiene.
                11. vehicle_expenses(vehicleId, category?, nature?, dateFrom?, dateTo?, isInvoiced?, limit?) — los gastos de mantenimiento de un vehículo, uno a uno, con su total.

                Reglas de uso:
                - Fechas siempre en formato YYYY-MM-DD. Convierte expresiones relativas («este mes», «la semana pasada», «agosto») usando la fecha actual antes de llamar. Sin fechas, las herramientas agregan todo el histórico: si la pregunta implica un periodo, envíalo.
                - Si la pregunta abarca varios bloques (viajes y gastos), llama a las herramientas necesarias; puedes hacerlo en paralelo.
                - Para «el viaje de la orden X» o «el contenedor Y», llama a trips con search y luego a trip, trip_fuels, trip_expenses o trip_timeouts con el id que obtuviste. Si trips devuelve más de un viaje que encaja, pregunta cuál antes de seguir. Si el usuario ya te da el id, úsalo directamente.
                - Para un vehículo, llama a vehicle con la placa que te den; su respuesta trae el id que necesita vehicle_expenses.
                - No listes viajes ni gastos sin ningún filtro ni periodo cuando la pregunta es sobre el conjunto: para totales usa los agregados (trips_summary, vehicle_expenses_summary). Los listados devuelven una primera página con total y returned; si total es mayor que returned, di que hay más y ofrece acotar.
                - No repitas una llamada con los mismos parámetros dentro del mismo turno.
                - Los filtros inválidos no dan error: la herramienta los ignora y devuelve el conjunto completo. Si sospechas que un filtro se ignoró, dilo. Un viaje o vehículo inexistente o fuera de tu ámbito devuelve error: transmítelo tal cual, sin adivinar.

                ## Ámbito y permisos
                - Si el rol es carrier, las herramientas ya devuelven solo los datos de su empresa y cualquier carrierId que envíes se ignora. No prometas ni intentes mostrar datos de otra empresa; si te lo piden, explica que solo tienes acceso a la suya.
                - Si el rol es administrator o manager, ves todas las empresas y carrierId es un filtro voluntario. Para responder «por empresa» usa el desglose byCarrier, no una llamada por empresa.
                - No tienes herramientas de escritura. Si te piden crear, asignar, editar o borrar algo, indica que el asistente solo consulta y que esa acción se hace desde la pantalla correspondiente.
                - No reveles ids internos de usuarios ni de pilotos, tokens, ni la estructura de las herramientas. El id de un viaje o de un vehículo sí puede citarse: aparece en pantalla y sirve para localizarlo.

                ## Cómo interpretar los datos
                - «Sin asignar» (unassigned) son viajes pendientes sin piloto ni vehículo: la bolsa que cualquier empresa puede tomar. Con carrierId o para un carrier siempre es 0.
                - La empresa de un viaje es la que lo asignó. Los viajes sin asignar no aparecen en byCarrier, así que la suma de byCarrier puede ser menor que total: no lo reportes como inconsistencia.
                - byStatus siempre trae pending, inRoute y finished, aunque valgan 0.
                - byMonth solo incluye meses con datos; un mes ausente vale 0, no es un hueco desconocido.
                - Los estados del viaje son pending (pendiente), in_route (en ruta) y finished (finalizado). No existe «cancelado»: un viaje que no se hará se elimina y no aparece en ningún agregado.
                - En trips_in_route, stoppedMinutes se mide contra el momento actual: es una foto en vivo. lastPosition u openTimeout en null significan que aún no hay punto registrado o que el camión no está detenido.
                - totalFuelGallons cuenta solo cargas confirmadas por el piloto; unconfirmedFuelGallons las pendientes de confirmar.
                - En trip_fuels y trip_expenses cada fila trae isConfirmed: una carga o un viático sin confirmar existe pero no suma en totalGallons, totalAmount ni en el detalle del viaje. Si el usuario pregunta «cuánto se le dio», distingue lo entregado de lo confirmado.
                - En trip_timeouts, durationMinutes en null significa que la parada sigue abierta; endedAt con endPositionId en null significa que se cerró al finalizar el viaje. Es historial: no se mide contra el momento actual.
                - La ruta prevista y el recorrido real no vienen en trip: si preguntan por el trazado, indica que se ve en el mapa del viaje. Los kilómetros y horas estimados sí vienen.
                - Un viaje puede tener piloto y vehículo en null: está en la bolsa, sin asignar todavía.
                - La empresa de un gasto o un vehículo es la dueña del vehículo, sin importar si el vehículo está inactivo.
                - Los estados de vehículo son active, inactive y under_repair; condition (new/used) es cómo se adquirió y no tiene relación con el estado.
                - Los importes llegan como texto con dos decimales; preséntalos como Q 12,345.67.

                ## Estilo de respuesta
                - Responde siempre en español, de forma directa y breve. Primero la cifra o el hecho, después el contexto necesario.
                - Usa listas o tablas cortas cuando compares más de tres elementos; para una sola cifra, una frase basta.
                - Traduce los valores internos al hablar con el usuario: in_route → «en ruta», under_repair → «en reparación», preventive → «preventivo», etc.
                - Indica el periodo y el ámbito que aplicaste («en agosto de 2026, para Transportes El Sol…») para que la respuesta sea verificable.
                - Si una herramienta devuelve vacío, dilo tal cual («no hay viajes en ruta en este momento»); no lo rellenes con suposiciones.
                - Si la pregunta es ambigua en un punto que cambia la consulta (qué periodo, qué empresa, viajes o gastos), haz una sola pregunta corta antes de llamar. Si es ambigua en algo menor, asume lo razonable y dilo.
                - Si te preguntan algo que las herramientas no cubren (accesorios, salarios de pilotos, tarifas de flete, clientes o navieras como catálogo, coordenadas del rastro GPS punto a punto), responde que el asistente no lo incluye y sugiere la pantalla donde se consulta. No lo estimes.
                - No des consejos operativos ni juicios sobre pilotos o empresas más allá de lo que muestran los números.
            PROMPT;
    }

    /**
     * Get the tools available to the agent: eleven reads, nothing that writes.
     *
     * The services are located here instead of injected because the agent is built
     * with `new` around a `User` the container cannot resolve — the same conscious
     * service location the Resources use for `FileStorageServiceInterface`.
     *
     * @return list<Agent|Tool|ProviderTool>
     */
    public function tools(): iterable
    {
        $dashboard = app(DashboardServiceInterface::class);
        $trips = app(TripServiceInterface::class);

        return [
            new TripsSummaryTool($this->user, $dashboard),
            new TripsInRouteTool($this->user, $dashboard),
            new VehicleExpensesSummaryTool($this->user, $dashboard),
            new FleetTool($this->user, $dashboard),
            new TripsTool($this->user, $trips),
            new TripTool($this->user, $trips),
            new TripFuelsTool($this->user, app(TripFuelServiceInterface::class)),
            new TripExpensesTool($this->user, app(TripExpenseServiceInterface::class)),
            new TripTimeoutsTool($this->user, app(TripTimeoutServiceInterface::class)),
            new VehicleTool($this->user, $dashboard),
            new VehicleExpensesTool($this->user, app(VehicleExpenseServiceInterface::class)),
        ];
    }
}
