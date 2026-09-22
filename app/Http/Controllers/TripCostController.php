<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Resources\TripCost\TripCostResource;
use App\Interfaces\TripCost\TripCostServiceInterface;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Trip Costs',
    description: <<<'TEXT'
    Costo directo en GTQ de un viaje FINALIZADO, calculado en lectura. UN SOLO ENDPOINT Y NINGUNO MÁS: GET /api/trips/{trip}/cost, con token JWT (Authorization: Bearer {token}); sin él la respuesta es 401 con «El token de sesión no es válido o ha expirado».

    ATENCIÓN — ES COSTO DIRECTO Y SON CUATRO COMPONENTES, NO «LO QUE COSTÓ EL VIAJE»: combustible confirmado a precio histórico, viáticos confirmados, salario del piloto prorrateado y seguro del vehículo prorrateado. NO INCLUYE depreciación del vehículo (decisión explícita: purchase_price no entra ni prorrateado), NO imputa los vehicle_expenses de mantenimiento —la fecha no prueba a qué viaje pertenece un cambio de llantas—, y NO hay ingreso, margen ni rentabilidad, porque freight_rates cotiza por libra y trips no guarda peso. Presentar la cifra como «costo total» en la interfaz sería engañoso.

    ATENCIÓN — SOLO VIAJES finished. Un viaje pending o in_route responde 400 «El costo solo está disponible para viajes finalizados», AUNQUE YA TENGA CARGAS Y VIÁTICOS CONFIRMADOS: no existe el costo parcial ni una bandera isFinal. Un costo en curso obligaría a medir la duración contra now(), que sube en cada refresco, y el frontend tendría que distinguir «va por 1 800» de «costó 1 800».

    ATENCIÓN — NO HAY NADA GUARDADO Y NO HAY QUE GUARDARLO. Sin tabla, sin migración, sin columna en trips, sin snapshot en /finish y sin caché: el desglose se recalcula en cada lectura, como el currentValue de SPEC 17 y el durationMinutes de SPEC 27. Aun así NO CAMBIA entre dos lecturas, porque los insumos son históricos y están congelados: el precio de combustible vigente en cada loaded_at, el salario vigente en el start_date y las traveled_hours ya cerradas.

    PERMISOS: la ruta lleva jwt.auth A SECAS —sin role: y sin carrier.required—, pero NO la alcanzan los cuatro roles. El service da 403 A CUALQUIER pilot, INCLUIDO EL ASIGNADO AL VIAJE, con «No tienes permisos para consultar el costo de un viaje», porque el desglose revela su propio salario mensual: mismo trato que el rastro y las paradas, y AL REVÉS que /fuels y /expenses, donde el dato sí es suyo. Los otros tres roles entran acotados por el ÁMBITO DE SPEC 24, que no se reescribe aquí: administrator y manager alcanzan cualquier viaje; un carrier, los que asignó su empresa (la bolsa libre son pending y nunca llegan a tener costo).

    EL ORDEN DE LAS GUARDAS ES CONTRATO: 404 viaje inexistente O BORRADO → 403 piloto o fuera de ámbito → 400 no finalizado. Un viaje in_route de otra empresa responde 403, NO 400. Un viaje borrado es 404 y no 400, porque esta es una ruta de LECTURA y sigue a GET /api/trips/{trip}, no a las rutas de escritura.

    ATENCIÓN — UN INSUMO QUE FALTA VALE 0.00 Y SE VE COMO null, NUNCA UN ERROR: sin piloto o vehículo asignados, sin salario capturado, sin traveled_hours (viaje cerrado antes de SPEC 32) o sin precio de combustible para la fecha de una carga, el insumo sale en null, su subtotal en "0.00" y la respuesta sigue siendo 200. El endpoint no falla por un catálogo incompleto, y el frontend puede avisar porque ve el hueco.

    ES UN ENDPOINT DE DETALLE, UNO POR PANTALLA: NO EXISTE GET /api/trips/costs, ni filtros u orden por costo en GET /api/trips, ni agregados de costo en /api/dashboard, ni exportación a Excel del costo. Sin caché y con seis consultas por viaje, pintar el costo de cien viajes en una tabla dispararía seiscientas consultas.

    LO QUE ESTE DOMINIO NO CAMBIÓ: TripResource sigue con 42 claves y TripListResource con 19 —el viaje NO gana totalCost—, la tabla trips no gana ni una columna y no hay ninguna migración nueva.
    TEXT,
)]
class TripCostController extends Controller
{
    /**
     * Break the direct cost of one finished trip down into its four components.
     *
     * The only method of the controller: the domain only reads and only computes, so
     * there is nothing to create, edit or delete.
     */
    #[OA\Get(
        path: '/api/trips/{trip}/cost',
        operationId: 'showTripCost',
        summary: 'Consultar el costo de un viaje finalizado',
        description: <<<'TEXT'
        Devuelve el desglose del costo directo en GTQ de un viaje ya cerrado: cuánto costó el combustible que el piloto confirmó, cuánto se le entregó en viáticos, y qué parte de su salario mensual y del seguro del vehículo le corresponde por las horas que duró. NO PERSISTE NADA: se calcula al pedirlo.

        SOLO ATIENDE VIAJES finished. Un viaje pending o in_route es 400 «El costo solo está disponible para viajes finalizados», aunque tenga cargas y viáticos confirmados. No hay costo parcial ni bandera isFinal: si hace falta seguir el gasto de un viaje en curso, hoy se pide GET /api/trips/{trip}/fuels y GET /api/trips/{trip}/expenses y se suma en el frontend.

        LA RUTA NO LLEVA role:, PERO NO LA ALCANZAN LOS CUATRO ROLES. El service rechaza con 403 A CUALQUIER pilot, INCLUIDO EL ASIGNADO AL VIAJE, con «No tienes permisos para consultar el costo de un viaje»: el desglose revela su salario mensual. Los otros tres entran acotados por el ámbito de SPEC 24: administrator y manager alcanzan cualquier viaje; un carrier, los que asignó su empresa. Fuera de ámbito es 403 «No puedes acceder a un viaje que no pertenece a tu empresa transportista», NO 404.

        EL ORDEN DE LAS GUARDAS ES CONTRATO Y SE NOTA: 404 (inexistente o borrado) → 403 (piloto o fuera de ámbito) → 400 (no finalizado). Un viaje in_route de otra empresa responde 403 y no 400, y un viaje BORRADO responde 404 aunque además sea ajeno.

        NO HAY NI UN SOLO QUERY PARAM NI CUERPO: no hay limit, ni page, ni moneda, ni fecha de corte, ni bandera para incluir depreciación. Cualquier parámetro enviado SE IGNORA en silencio, nunca 422. La respuesta es un OBJETO y no un listado: sin total, sin currentPage y sin lastPage.

        CÓMO LEER LA RESPUESTA, en siete claves: traveledHours sale UNA SOLA VEZ en la raíz porque es el mismo multiplicador de pilot y de vehicle; fuel.byType agrupa POR TIPO de combustible pero multiplica POR CARGA, así que dos cargas a ambos lados de un cambio de precio caen en un solo elemento con sus importes ya sumados; expenses.count es el ÚNICO entero de toda la respuesta y todo lo demás sale como CADENA de dos decimales; y totalCost es la suma EXACTA de los cuatro subtotales tal como salen, nunca el redondeo de una suma en crudo.

        ATENCIÓN — UN TOTAL BAJO SUELE SER UN INSUMO QUE FALTA, NO UN VIAJE BARATO. Con traveledHours en null (viaje cerrado antes de SPEC 32) los DOS prorrateos salen en "0.00" aunque el salario y el seguro tengan valor; con pricePerGallon en null hay galones que no aportan importe porque la carga es anterior al primer precio capturado de su tipo. Los dos huecos se ven en la respuesta a propósito.

        LOS NÚMEROS CUADRAN CON EL RESTO DE LA API: fuel.gallons es el mismo totalFuelGallons de TripResource y expenses.subtotal el mismo totalExpensesAmount, porque los tres cuentan solo lo CONFIRMADO.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Trip Costs'],
        parameters: [
            new OA\Parameter(
                name: 'trip',
                description: 'Id numérico del viaje cuyo costo se consulta (trips.id), EN LA URL: la ruta es anidada, como el rastro, las cargas, las paradas y los viáticos, y no existe ningún listado global de costos ni un query param tripId que lo sustituya. Un id inexistente o de un viaje borrado devuelve 404 «El viaje no existe»; uno fuera del ámbito del usuario, 403; uno que todavía no ha terminado, 400.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 42),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Costo obtenido correctamente. Devuelve un OBJETO con las siete claves del desglose, sin metadatos de paginación. ATENCIÓN — un 200 NO garantiza que los cuatro componentes tengan valor: un insumo ausente sale en null con su subtotal en "0.00", porque el endpoint nunca falla por un dato de catálogo incompleto.',
                content: new OA\JsonContent(ref: '#/components/schemas/TripCostResponse'),
            ),
            new OA\Response(
                response: 400,
                description: 'El viaje todavía no ha terminado. El mensaje devuelto es: El costo solo está disponible para viajes finalizados. Sale con status pending y con in_route, aunque el viaje ya tenga cargas y viáticos confirmados: no existe el costo parcial.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'Sin permiso para leer este costo. DOS CAUSAS DISTINTAS con mensajes distintos: cualquier pilot —INCLUIDO EL ASIGNADO AL VIAJE— recibe «No tienes permisos para consultar el costo de un viaje», porque el desglose revela su propio salario mensual; un carrier que pide un viaje asignado por otra empresa recibe «No puedes acceder a un viaje que no pertenece a tu empresa transportista». Fuera de ámbito es 403, NO 404, y llega ANTES que el 400 del viaje sin terminar.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El viaje no existe o fue borrado —los dos casos son indistinguibles a propósito—. El mensaje devuelto es: El viaje no existe. ATENCIÓN — aquí el viaje borrado da 404 y NO el 400 «El viaje ya fue eliminado» de las rutas de escritura: esta es una ruta de lectura y sigue el criterio de GET /api/trips/{trip}.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $trip, TripCostServiceInterface $tripCostService)
    {
        try {
            $cost = $tripCostService->getTripCost(auth('api')->user(), $trip);

            return ResponseHandler::success(new TripCostResource($cost), 'Costo del viaje obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }
}
