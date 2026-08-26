<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\VehicleExpense\IndexVehicleExpenseRequest;
use App\Http\Requests\VehicleExpense\StoreVehicleExpenseRequest;
use App\Http\Requests\VehicleExpense\UpdateVehicleExpenseRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\VehicleExpense\VehicleExpenseResource;
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'VehicleExpenses',
    description: 'Gastos de mantenimiento imputados a un vehículo: llantas, frenos, aceite, mano de obra y otras 18 categorías, cada una con su naturaleza preventiva o correctiva, su monto en quetzales, su fecha y su descripción. Todos los endpoints requieren token JWT (Authorization: Bearer {token}). ATENCIÓN — el recurso cuelga de un vehículo pero la ruta NO está anidada: no existe /api/vehicles/{vehicle}/expenses; el vínculo viaja en el campo vehicle_id del cuerpo y en el query param vehicleId del listado, que es OBLIGATORIO. NO EXISTE UN LISTADO GLOBAL de los gastos de toda la flota, ni comparativas entre vehículos, ni reportes por categoría o por mes: el único agregado del dominio es el totalAmount del listado de un vehículo. Este dominio NO lleva el middleware carrier.required, a diferencia de Vehicles: el ámbito lo comprueba el servicio a partir del vehículo, y un carrier sin empresa recibe 403 con el mensaje No perteneces a ninguna empresa transportista. Los permisos van por acción: leer (index y show) es cosa de carrier, administrator y manager; escribir (store, update y destroy) solo de carrier y administrator, así que un manager recibe 403 en los tres. Un pilot recibe 403 en los cinco. Cada gasto guarda además si fue facturado (isInvoiced) y, en ese caso, el archivo de la factura —imagen o PDF—, que SE ADJUNTA EN EL ALTA Y NO SE PUEDE CAMBIAR DESPUÉS: no hay endpoint para marcar, desmarcar ni reemplazar la factura, el PATCH ignora esos campos en silencio y el DELETE borra el archivo junto con la fila. Este dominio NO es un módulo de facturación: no hay número de factura, proveedor, NIT, estado de pago ni desglose del totalAmount entre facturado y no facturado. El ámbito lo fija el rol: un carrier solo alcanza los vehículos de su propia empresa —tocar uno ajeno es 403, no 404— y administrator y manager alcanzan los de todas. El estado del vehículo no importa: uno inactive acepta y lista gastos igual que uno active. GET /api/vehicles/{vehicle} no cambió con este dominio: no devuelve expenses ni totales, y los gastos se piden siempre aparte.',
)]
class VehicleExpenseController extends Controller
{
    #[OA\Get(
        path: '/api/vehicle-expenses',
        operationId: 'indexVehicleExpenses',
        summary: 'Listar los gastos de un vehículo',
        description: <<<'TEXT'
        Devuelve los gastos de UN vehículo concreto. Pueden llamarlo los roles carrier, administrator y manager (middlewares jwt.auth y role:carrier,administrator,manager); un pilot recibe 403.

        ATENCIÓN — vehicleId ES OBLIGATORIO. Es el único filtro obligatorio de todo el proyecto: sin él la petición NO devuelve un listado de toda la flota ni una lista vacía, devuelve 422. Y ese 422 NO usa el sobre habitual {statusCode, message, data}: al venir de un FormRequest sale con el formato de validación de Laravel {message, errors: {vehicleId: [...]}}, con el mensaje El vehículo es obligatorio dentro de errors.vehicleId. Un cliente que lea siempre response.statusCode o response.data en los errores se encontrará undefined justo en este caso. Un vehicleId que no sea entero da el mismo formato con el mensaje El vehículo debe ser un número entero.

        Un vehicleId que no corresponde a ningún vehículo es 404 (El vehículo no existe), NO un listado vacío: recibir data: [] siempre significa que el vehículo existe y no tiene gastos que cumplan los filtros. Si el vehículo es de otra empresa y quien pregunta es un carrier, la respuesta es 403.

        Los cinco filtros opcionales —category, nature, dateFrom, dateTo e isInvoiced— son TOLERANTES: un valor fuera del enum, una fecha malformada o un parámetro vacío se IGNORAN EN SILENCIO y se devuelve el listado COMPLETO del vehículo con 200, nunca un 422 ni una lista vacía. Se combinan entre sí sin interferir. No hay filtro por descripción, ni por monto, ni por el usuario que registró el gasto.

        El orden es FIJO y no configurable: expense_date descendente y, para dos gastos del mismo día, id descendente. No existen parámetros sortBy ni order.

        La forma de la respuesta depende de limit: sin limit —o con un limit no numérico— se devuelven todos los gastos y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelven esos tres campos aplanados en la raíz. En LAS DOS FORMAS viaja totalAmount.

        ATENCIÓN — totalAmount NO ES total. totalAmount es la SUMA EN QUETZALES del campo amount de TODOS los gastos que cumplen los filtros, calculada antes de paginar, y sale como cadena con dos decimales; total es el CONTEO de registros que aporta el paginador y solo aparece al paginar. Con 12 gastos de 100.50 y limit=10 la respuesta trae 10 elementos en data, total = 12 y totalAmount = "1206.00".
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['VehicleExpenses'],
        parameters: [
            new OA\Parameter(
                name: 'vehicleId',
                description: 'OBLIGATORIO. Identificador del vehículo cuyos gastos se piden (vehicles.id). Omitirlo devuelve 422 con el formato de validación de Laravel {message, errors} y el mensaje El vehículo es obligatorio; no hay valor por defecto ni listado global. Debe ser un entero: vehicleId=abc devuelve 422 con el mensaje El vehículo debe ser un número entero. Un id inexistente devuelve 404 (El vehículo no existe) y uno de otra empresa, para un carrier, 403. No hay filtro carrierId en este listado: el vehículo ya determina la empresa.',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
            new OA\Parameter(
                name: 'category',
                description: 'Filtra por categoría, por COINCIDENCIA EXACTA con el valor del enum. ES TOLERANTE: solo se aplica si el valor pertenece a los 22 casos; cualquier otro (category=inexistente, o el parámetro vacío) se ignora en silencio y se devuelve el listado COMPLETO del vehículo, nunca una lista vacía ni un 422. Se combina con nature: los dos ejes son independientes y ?category=tires&nature=corrective es una consulta válida. Solo admite un valor: no se pueden pedir varias categorías a la vez.',
                in: 'query',
                required: false,
                schema: new OA\Schema(
                    type: 'string',
                    enum: [
                        'tires', 'oil_change', 'brakes', 'spare_part', 'battery', 'suspension',
                        'engine', 'transmission', 'electrical_system', 'cooling_system', 'filters',
                        'alignment_balancing', 'clutch', 'exhaust', 'air_conditioning', 'bodywork_paint',
                        'glass_mirrors', 'inspection', 'washing', 'towing', 'labor', 'other',
                    ],
                    example: 'tires',
                ),
            ),
            new OA\Parameter(
                name: 'nature',
                description: 'Filtra por naturaleza preventiva o correctiva. ES TOLERANTE igual que category: un valor fuera del enum se ignora en silencio y devuelve el listado completo con 200. No depende de la categoría elegida.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['preventive', 'corrective'], example: 'preventive'),
            ),
            new OA\Parameter(
                name: 'dateFrom',
                description: 'Cota INFERIOR sobre expense_date —la fecha del gasto, no la de captura—, en formato Y-m-d e INCLUSIVE: dateFrom=2026-01-15 incluye los gastos del propio 15 de enero. ES TOLERANTE: una fecha malformada (2026-13-45), en otro formato (15/01/2026) o vacía se ignora en silencio y se devuelve el listado completo, nunca un 422 ni una lista vacía. Se combina con dateTo para un rango cerrado. Si el rango deja fuera todos los gastos, la respuesta es 200 con data vacío y totalAmount "0.00".',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-01-01'),
            ),
            new OA\Parameter(
                name: 'dateTo',
                description: 'Cota SUPERIOR sobre expense_date, en formato Y-m-d y también INCLUSIVE: dateTo=2026-01-15 incluye los gastos del propio 15 de enero. Igual de tolerante que dateFrom. No se valida que dateTo sea posterior a dateFrom: un rango invertido es válido y simplemente no devuelve resultados.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-01-31'),
            ),
            new OA\Parameter(
                name: 'isInvoiced',
                description: 'Filtra por si el gasto fue facturado. isInvoiced=true devuelve solo los facturados e isInvoiced=false solo los que no lo están; omitirlo devuelve ambos. ES TOLERANTE: un valor ilegible (isInvoiced=quizá, isInvoiced=2) o el PARÁMETRO VACÍO se ignoran en silencio y devuelven el listado COMPLETO, nunca una lista vacía ni un 422. OJO CON totalAmount: se calcula sobre los gastos ya filtrados, así que con isInvoiced=true acumula solo lo facturado; ese es el único desglose disponible, porque el listado no devuelve un total facturado y otro sin facturar por separado.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'boolean', example: true),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (limit=abc), se devuelven todos los gastos sin error y sin metadatos de paginación. Si es numérico se acota al rango [10, 100]: limit=5 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Paginar NO cambia totalAmount, que se calcula sobre todos los gastos filtrados y no sobre la página.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 10, example: 10),
            ),
            new OA\Parameter(
                name: 'page',
                description: 'Página solicitada. Solo tiene efecto cuando se envía un limit numérico.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Gastos obtenidos correctamente. Sin limit se devuelve VehicleExpenseListResponse; con limit numérico, PaginatedVehicleExpenseListResponse. Las dos formas incluyen totalAmount en la raíz.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/VehicleExpenseListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedVehicleExpenseListResponse'),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es carrier, administrator ni manager —un pilot cae aquí— (mensaje del middleware role: No tienes permisos para acceder a este recurso); el carrier autenticado pide los gastos de un vehículo de otra empresa (mensaje: No puedes acceder a un vehículo que no pertenece a tu empresa transportista); o es un carrier que todavía no está vinculado a ninguna empresa (mensaje: No perteneces a ninguna empresa transportista, emitido por el servicio, porque este dominio no lleva el middleware carrier.required)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún vehículo con el vehicleId enviado. El mensaje devuelto es: El vehículo no existe. Nunca se responde con un listado vacío en este caso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Falta el parámetro vehicleId (mensaje El vehículo es obligatorio) o no es un entero (mensaje El vehículo debe ser un número entero). ATENCIÓN: este error NO usa el sobre {statusCode, message, data} sino el formato de validación de Laravel {message, errors}. Los demás filtros nunca provocan un 422: son tolerantes y se ignoran si son inválidos',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function index(IndexVehicleExpenseRequest $request, VehicleExpenseServiceInterface $vehicleExpenseService)
    {
        try {
            $result = $vehicleExpenseService->getVehicleExpenses(auth('api')->user(), $this->filters($request));

            $expenses = $result['expenses'];

            $data = $expenses instanceof LengthAwarePaginator
                ? (new PaginatedResource($expenses, VehicleExpenseResource::class))->resolve()
                : ['data' => VehicleExpenseResource::collection($expenses)->resolve()];

            /** El acumulado viaja en la raíz del sobre junto a la metadata de paginación, y también sin ella: es dato de negocio, no del paginador. */
            $data['totalAmount'] = $result['totalAmount'];

            return ResponseHandler::success($data, 'Gastos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/vehicle-expenses',
        operationId: 'storeVehicleExpense',
        summary: 'Registrar un gasto de mantenimiento',
        description: <<<'TEXT'
        Registra un gasto contra un vehículo. Pueden llamarlo los roles carrier y administrator (middlewares jwt.auth y role:carrier,administrator); un manager recibe 403 —lee gastos pero no los escribe— y un pilot también.

        El vehículo se indica en el cuerpo con vehicle_id, en snake_case, no en la URL: la ruta no está anidada bajo /api/vehicles. Un carrier solo puede registrar gastos de vehículos de su propia empresa (403 con uno ajeno) y un administrator puede hacerlo sobre el vehículo de cualquier empresa. Un carrier sin empresa recibe 403 con el mensaje del servicio, no de un middleware.

        El vehicle_id NO se valida contra la base en el FormRequest: un id inexistente es 404 (El vehículo no existe) y no un 422, y en ese caso no se crea nada.

        EL ESTADO DEL VEHÍCULO NO IMPORTA: registrar un gasto sobre un vehículo con status inactive —dado de baja con DELETE /api/vehicles/{vehicle}— devuelve 201 con normalidad, porque el mantenimiento pudo ocurrir antes de la baja. Tampoco se comprueba under_repair ni ninguna otra condición del vehículo.

        registered_by SALE DEL USUARIO AUTENTICADO y nunca del cuerpo: enviar registered_by con el id de otro usuario no produce error y tampoco cambia nada, el gasto queda a nombre de quien hizo la petición. En la respuesta ese dato vuelve como registeredBy y es el NOMBRE del usuario, no su id.

        No hay validación cruzada entre category y nature: cualquiera de las 22 categorías se acepta con preventive y con corrective. Tampoco hay control de duplicados: dos gastos idénticos —mismo vehículo, misma categoría, mismo monto y misma fecha— se crean como dos filas distintas y suman dos veces en el totalAmount.

        ATENCIÓN — CAMBIO INCOMPATIBLE SIN PERIODO DE GRACIA: is_invoiced pasó a ser obligatorio. Un alta con los seis campos de siempre, que antes devolvía 201, ahora devuelve 422 con el mensaje Debes indicar si el gasto fue facturado. Hay que desplegar el cliente junto con esta versión de la API.

        ATENCIÓN — CUANDO SE ADJUNTA FACTURA EL CUERPO VA EN multipart/form-data Y NO EN JSON, porque un archivo no viaja en un cuerpo JSON. Con is_invoiced en false sirven los dos formatos. El adjunto exige upload_max_filesize y post_max_size de 4M o más en el servidor.

        LA FACTURACIÓN SE FIJA AQUÍ Y NO SE PUEDE CAMBIAR DESPUÉS: no existe ningún endpoint para marcar, desmarcar, reemplazar ni eliminar la factura, y el PATCH ignora los dos campos en silencio. La única corrección es borrar el gasto y volverlo a crear, perdiendo su id, su createdAt y su registeredBy original.

        CUIDADO — CON is_invoiced EN false UN ARCHIVO ENVIADO SE DESCARTA EN SILENCIO: la respuesta es 201 y no 422, no se sube nada y el gasto queda con invoiceUrl en null. El booleano manda y el archivo es accesorio; el cliente puede detectarlo leyendo isInvoiced en la respuesta y avisar en pantalla en vez de dar por hecho que la factura quedó adjunta.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: [
                new OA\MediaType(
                    mediaType: 'multipart/form-data',
                    schema: new OA\Schema(ref: '#/components/schemas/StoreVehicleExpenseRequest'),
                ),
                new OA\JsonContent(ref: '#/components/schemas/StoreVehicleExpenseRequest'),
            ],
        ),
        security: [['bearerAuth' => []]],
        tags: ['VehicleExpenses'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Gasto registrado correctamente, con registeredBy el nombre del usuario autenticado y vehicleId el del cuerpo. El campo amount vuelve como cadena con dos decimales y expenseDate en formato d-m-Y.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Gasto registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/VehicleExpense'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es carrier ni administrator —un manager o un pilot caen aquí— (mensaje del middleware role: No tienes permisos para acceder a este recurso); el carrier intenta registrar un gasto sobre un vehículo de otra empresa (mensaje: No puedes acceder a un vehículo que no pertenece a tu empresa transportista); o es un carrier sin empresa vinculada (mensaje: No perteneces a ninguna empresa transportista). En los tres casos no se crea ninguna fila',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún vehículo con el vehicle_id enviado y no se registra nada. El mensaje devuelto es: El vehículo no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: falta alguno de los SIETE campos obligatorios, el vehículo no es un entero, la categoría o la naturaleza no pertenecen a su enum, el monto no es numérico, no llega a 0.01 o supera 99999999.99, la fecha no es válida o es futura, la descripción supera los 1000 caracteres, is_invoiced no es un booleano, o —con is_invoiced en true— falta la factura, su tipo no es jpg, jpeg, png ni pdf, o pesa más de 3 MB. Con is_invoiced en false el archivo NO se valida: llegue lo que llegue, la respuesta es 201',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreVehicleExpenseRequest $request, VehicleExpenseServiceInterface $vehicleExpenseService)
    {
        try {
            $expense = $vehicleExpenseService->createVehicleExpense($request->validated(), auth('api')->user());

            return ResponseHandler::success(new VehicleExpenseResource($expense), 'Gasto registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/vehicle-expenses/{vehicleExpense}',
        operationId: 'showVehicleExpense',
        summary: 'Obtener un gasto por id',
        description: <<<'TEXT'
        Devuelve un gasto concreto. Pueden llamarlo los roles carrier, administrator y manager (middlewares jwt.auth y role:carrier,administrator,manager); un pilot recibe 403.

        El ámbito se resuelve por el VEHÍCULO del gasto, que es lo único que lo ata a una empresa: un carrier solo alcanza los gastos de vehículos de su propia empresa y con uno ajeno recibe 403 —no 404—, la misma regla que ya aplica el recurso Vehicle; administrator y manager alcanzan los de todas las empresas.

        Un id que no existe —o el de un gasto ya borrado, que es lo mismo, porque el DELETE es real— devuelve 404 con el mensaje El gasto no existe.

        La respuesta es el mismo objeto que devuelve cada elemento del listado: no trae campos extra, ni el vehículo embebido, ni el histórico de ediciones, que no existe.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['VehicleExpenses'],
        parameters: [
            new OA\Parameter(
                name: 'vehicleExpense',
                description: 'Identificador numérico del gasto (vehicle_expenses.id), no el del vehículo. Es el id que devuelve el campo id del recurso.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 41),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Gasto obtenido correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Gasto obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/VehicleExpense'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol no es carrier, administrator ni manager —un pilot cae aquí— (mensaje del middleware role: No tienes permisos para acceder a este recurso); el carrier pide un gasto de un vehículo de otra empresa (mensaje: No puedes acceder a un gasto que no pertenece a tu empresa transportista); o es un carrier sin empresa vinculada (mensaje: No perteneces a ninguna empresa transportista)',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún gasto con ese id, o ya fue borrado: el DELETE es real y no deja fila que consultar. El mensaje devuelto es: El gasto no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $vehicleExpense, VehicleExpenseServiceInterface $vehicleExpenseService)
    {
        try {
            $found = $vehicleExpenseService->getVehicleExpenseById(auth('api')->user(), $vehicleExpense);

            return ResponseHandler::success(new VehicleExpenseResource($found), 'Gasto obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/vehicle-expenses/{vehicleExpense}',
        operationId: 'updateVehicleExpense',
        summary: 'Actualizar un gasto',
        description: <<<'TEXT'
        Actualización PARCIAL de un gasto: solo se modifica lo que se envía y lo omitido queda intacto. Pueden llamarlo los roles carrier y administrator (middlewares jwt.auth y role:carrier,administrator); un manager recibe 403 y un pilot también. Un carrier solo puede editar gastos de vehículos de su propia empresa: con uno ajeno recibe 403, no 404.

        UN CUERPO VACÍO ES VÁLIDO: responde 200 con el gasto sin cambios y no toca ni siquiera updated_at. No es un 422, a diferencia de PATCH /api/pilots/{pilot}/salary.

        ATENCIÓN — EL VEHÍCULO NO SE PUEDE CAMBIAR y el intento no avisa. vehicle_id no forma parte del cuerpo aceptado: enviarlo (o enviar vehicleId) NO devuelve error, se ignora en silencio y la respuesta 200 trae el vehicleId original. Un cliente que mande el gasto entero incluyendo el vehículo creerá que lo movió. Mover un gasto de vehículo es borrarlo y volverlo a crear.

        registered_by tampoco se toca: el gasto conserva PARA SIEMPRE al usuario que lo creó, aunque quien edite sea un administrator, y el campo registeredBy de la respuesta seguirá mostrando el nombre original. Enviar registered_by en el cuerpo no hace nada.

        ATENCIÓN — LA FACTURACIÓN TAMPOCO SE PUEDE CAMBIAR, y el intento tampoco avisa. is_invoiced e invoice no forman parte del cuerpo aceptado: mandarlos NO devuelve error, se ignoran en silencio y la respuesta 200 trae el isInvoiced y el invoiceUrl originales. SUBIR AQUÍ UN ARCHIVO DE 3 MB DEVUELVE 200 SIN GUARDAR NADA: no se sube al almacenamiento, no se reemplaza la factura anterior y no queda ni rastro del intento. No existe forma de marcar, desmarcar, reemplazar ni eliminar la factura de un gasto ya creado; la única corrección es borrar el gasto y volverlo a crear, perdiendo su id, su createdAt y su registeredBy original.

        NO HAY BITÁCORA DE EDICIONES: a diferencia del salario de los pilotos, aquí no se guarda el valor anterior ni quién lo cambió, y no existe endpoint de historial. Editar el monto cambia el totalAmount del listado sin dejar rastro de cuál era antes.

        La ruta acepta también PUT, con el mismo comportamiento parcial.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateVehicleExpenseRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['VehicleExpenses'],
        parameters: [
            new OA\Parameter(
                name: 'vehicleExpense',
                description: 'Identificador numérico del gasto (vehicle_expenses.id), no el del vehículo.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 41),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Gasto actualizado correctamente. data trae el gasto ya actualizado, con el mismo vehicleId y el mismo registeredBy que antes de la edición.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Gasto actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/VehicleExpense'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol no es carrier ni administrator —un manager o un pilot caen aquí— (mensaje del middleware role: No tienes permisos para acceder a este recurso); el carrier intenta editar un gasto de un vehículo de otra empresa (mensaje: No puedes acceder a un gasto que no pertenece a tu empresa transportista); o es un carrier sin empresa vinculada (mensaje: No perteneces a ninguna empresa transportista). No se guarda ningún cambio',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún gasto con ese id, o ya fue borrado. El mensaje devuelto es: El gasto no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: la categoría o la naturaleza enviadas no pertenecen a su enum, el monto no es numérico, no llega a 0.01 o supera 99999999.99, la fecha no es válida o es futura, o la descripción supera los 1000 caracteres. Enviar vehicle_id NO provoca un 422: se ignora',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    #[OA\Put(
        path: '/api/vehicle-expenses/{vehicleExpense}',
        operationId: 'replaceVehicleExpense',
        summary: 'Actualizar un gasto (alias PUT)',
        description: 'Mismo comportamiento que PATCH /api/vehicle-expenses/{vehicleExpense}: la ruta admite ambos verbos y la actualización es parcial en los dos, no un reemplazo completo del recurso —enviar solo amount deja los demás campos intactos—. Consulta esa operación para el detalle de permisos, del ámbito por rol, de la inmutabilidad del vehículo y de la conservación de registered_by.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateVehicleExpenseRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['VehicleExpenses'],
        parameters: [
            new OA\Parameter(
                name: 'vehicleExpense',
                description: 'Identificador numérico del gasto (vehicle_expenses.id), no el del vehículo.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 41),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Gasto actualizado correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Gasto actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/VehicleExpense'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol no es carrier ni administrator, el carrier intenta editar un gasto de otra empresa o es un carrier sin empresa vinculada',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún gasto con ese id. El mensaje devuelto es: El gasto no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: algún campo enviado incumple su regla de tipo, rango, longitud o enum',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateVehicleExpenseRequest $request, int $vehicleExpense, VehicleExpenseServiceInterface $vehicleExpenseService)
    {
        try {
            $updated = $vehicleExpenseService->updateVehicleExpense($request->validated(), $vehicleExpense, auth('api')->user());

            return ResponseHandler::success(new VehicleExpenseResource($updated), 'Gasto actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/vehicle-expenses/{vehicleExpense}',
        operationId: 'destroyVehicleExpense',
        summary: 'Eliminar un gasto (borrado real)',
        description: <<<'TEXT'
        ATENCIÓN — ESTE ENDPOINT BORRA DE VERDAD. No es una baja lógica como DELETE /api/vehicles/{vehicle}: no hay soft deletes, no hay columna de estado y la fila DESAPARECE de la base de datos. El gasto no se puede recuperar, no aparece en ningún listado y deja de contar en el totalAmount. Un gasto mal tecleado es basura, no historial.

        Por eso un SEGUNDO DELETE del mismo id devuelve 404 con el mensaje El gasto no existe, indistinguible de un id que nunca existió: aquí no se aplica el 400 "ya fue eliminada" de las tarifas de flete, que sí usan soft deletes.

        La respuesta 200 devuelve en data el gasto tal como estaba justo antes de borrarse, con su id incluido. Ese objeto es lo único que queda de él: si el cliente lo necesita, debe guardarlo, porque una petición posterior a ese id ya responde 404.

        Pueden llamarlo los roles carrier y administrator (middlewares jwt.auth y role:carrier,administrator); un manager recibe 403 y un pilot también. Un carrier solo puede borrar gastos de vehículos de su propia empresa: con uno ajeno recibe 403 y la fila sigue intacta.

        ATENCIÓN — EL ARCHIVO DE LA FACTURA SE BORRA CON EL GASTO. Es el único DELETE del proyecto que elimina también el objeto del almacenamiento: la URL que devolvía invoiceUrl deja de resolver y el archivo no se puede recuperar. Si el cliente necesita conservarlo, debe descargarlo ANTES de borrar. El archivo se elimina después de la fila, así que un fallo de esa limpieza no cambia la respuesta: sigue siendo 200 y el gasto sigue borrado.

        Borrar un gasto no toca el vehículo ni ninguna otra tabla, y no queda registro de quién lo borró: no hay bitácora.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['VehicleExpenses'],
        parameters: [
            new OA\Parameter(
                name: 'vehicleExpense',
                description: 'Identificador numérico del gasto (vehicle_expenses.id), no el del vehículo. Tras el borrado este id queda libre de contenido: cualquier petición posterior con él devuelve 404.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 41),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Gasto eliminado correctamente. La fila YA NO EXISTE: data devuelve el gasto tal como estaba antes de borrarse, y ese id ya no se puede volver a consultar.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Gasto eliminado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/VehicleExpense'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol no es carrier ni administrator —un manager o un pilot caen aquí— (mensaje del middleware role: No tienes permisos para acceder a este recurso); el carrier intenta borrar un gasto de un vehículo de otra empresa (mensaje: No puedes acceder a un gasto que no pertenece a tu empresa transportista); o es un carrier sin empresa vinculada (mensaje: No perteneces a ninguna empresa transportista). La fila no se toca',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún gasto con ese id, incluido el caso de un segundo DELETE sobre un gasto ya borrado. El mensaje devuelto es: El gasto no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $vehicleExpense, VehicleExpenseServiceInterface $vehicleExpenseService)
    {
        try {
            $deleted = $vehicleExpenseService->deleteVehicleExpense($vehicleExpense, auth('api')->user());

            return ResponseHandler::success(new VehicleExpenseResource($deleted), 'Gasto eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Listing filters, read from the query string.
     *
     * `vehicleId` comes from the validated payload because it is the only
     * required one; the rest are tolerant and travel raw, so the service can
     * ignore whatever it cannot use.
     *
     * @return array{vehicleId: int, category: string|null, nature: string|null, dateFrom: string|null, dateTo: string|null, isInvoiced: string|null, limit: string|null}
     */
    private function filters(IndexVehicleExpenseRequest $request): array
    {
        return [
            'vehicleId' => (int) $request->validated('vehicleId'),
            'category' => $this->queryString($request, 'category'),
            'nature' => $this->queryString($request, 'nature'),
            'dateFrom' => $this->queryString($request, 'dateFrom'),
            'dateTo' => $this->queryString($request, 'dateTo'),
            'isInvoiced' => $this->queryString($request, 'isInvoiced'),
            'limit' => $this->queryString($request, 'limit'),
        ];
    }

    /**
     * Read a query parameter, discarding anything that is not a plain string.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
