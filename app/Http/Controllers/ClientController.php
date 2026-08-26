<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Http\Resources\Client\ClientResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\Client\ClientServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Clients',
    description: 'Clientes: el catálogo nacional de las empresas a las que Legumex presta servicio, con SOLO DOS CAMPOS DE NEGOCIO —un código tecleado por el administrador y una razón social—. No hay dirección, NIT, teléfono, contacto ni estado de publicación. El cliente NO pertenece a ninguna empresa transportista, por eso NINGUNA ruta lleva el middleware carrier.required y el recurso no expone carrierId. Los cinco endpoints exigen token JWT (Authorization: Bearer {token}); sin él la respuesta es 401 con "El token de sesión no es válido o ha expirado". El reparto de permisos es asimétrico: la LECTURA (listado y detalle) está abierta a los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier que todavía no ha registrado su empresa; la ESCRITURA (alta, actualización y borrado) es exclusiva del administrator (middleware role:administrator) y los otros tres roles reciben 403 con "No tienes permisos para acceder a este recurso". ATENCIÓN — ESTE CATÁLOGO BORRA DE VERDAD Y ES EL AVISO CENTRAL DEL DOMINIO: en Products, Locations, Zones y Departure Points el DELETE es una baja lógica sobre un status booleano, la fila se queda, se sigue listando y se puede reactivar; aquí el DELETE es un soft delete real, la fila DESAPARECE del listado y del detalle, NO hay filtro que la devuelva, NO existe /restore y NO HAY FORMA DE RECUPERARLA POR LA API —solo desde la base de datos—. ATENCIÓN — EL BORRADO NO LIBERA NI EL code NI EL name: los dos índices únicos siguen ocupados para siempre por la fila borrada, así que un alta puede chocar con 400 contra un cliente que no aparece en ningún endpoint; de ahí el "que puede haber sido eliminado" de los dos mensajes de duplicado. Es el ÚNICO catálogo del proyecto SIN NINGUNA RUTA FIJA antes del apiResource: no hay /toggle-status —un cliente no se pausa, se borra— ni /restore, así que el comodín {client} no captura nada. Reglas que atraviesan el dominio: los DOS duplicados posibles se responden con 400 DESDE EL SERVICE y NUNCA con 422 —los FormRequests no llevan regla unique, porque la de Laravel no ve las filas borradas—, ganando el mensaje del código si ambos chocan; el code se guarda recortado y en MAYÚSCULAS y NO ADMITE NINGÚN ESPACIO (422, no colapso), mientras que el name sí colapsa sus espacios internos antes de pasar a mayúsculas; el registeredBy sale siempre del usuario autenticado y el PATCH no lo reescribe; el listado ordena por id ASC sin alternativa, filtra con un search tolerante sobre código Y nombre y pagina opt-in por limit acotado a [10, 100]; y createdAt, updatedAt y deletedAt usan el formato propio d-m-Y h:i:s A, NO ISO 8601. NADA CUELGA TODAVÍA DE UN CLIENTE: ninguna tabla del proyecto tiene client_id, así que borrar uno no rompe ninguna otra entidad y el cliente no participa en tarifas, cotizaciones ni viajes.',
)]
class ClientController extends Controller
{
    #[OA\Get(
        path: '/api/clients',
        operationId: 'indexClients',
        summary: 'Listar clientes',
        description: <<<'TEXT'
        Devuelve los clientes del catálogo nacional con el nombre de quien los capturó. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa, porque el cliente es un dato de Legumex y no está acotado por transportista. No hay ámbito ni filtrado por empresa: todos los usuarios ven exactamente las mismas filas.

        ATENCIÓN — LOS CLIENTES BORRADOS NO APARECEN NUNCA Y NO HAY FORMA DE VERLOS. Aquí no funciona la costumbre de los otros catálogos, donde lo dado de baja sigue en el listado con status false y se filtra con status=true: este listado excluye siempre las filas borradas y NO EXISTE NINGÚN PARÁMETRO —ni status, ni withTrashed, ni onlyTrashed— que las devuelva. Un cliente que desapareció del listado está borrado, y solo se recupera desde la base de datos. El total de la paginación tampoco los cuenta.

        El orden es fijo y no configurable: id ASC, es decir, el orden en que se dieron de alta. No hay sortBy ni sortDir.

        Los dos filtros —search y limit— se combinan entre sí y son TOLERANTES: un search en blanco o de solo espacios y un limit no numérico se ignoran en silencio y la lectura devuelve 200, NUNCA 422. Un filtro sin coincidencias devuelve 200 con data vacío, tampoco 404, igual que un catálogo vacío. No hay filtro por estado —no existe—, ni por autor del alta, ni por rango de fechas: cualquier otro query param se ignora.

        La forma de la respuesta depende del parámetro limit: sin limit se devuelven todos los registros y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos APLANADOS EN LA RAÍZ, no bajo meta.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(
                name: 'search',
                description: 'Búsqueda parcial sobre el CÓDIGO Y EL NOMBRE a la vez (LIKE %TERM% sobre los dos, unidos por OR). El término se normaliza igual que un nombre —recorte, colapso de espacios y mayúsculas— y ambas columnas están siempre en mayúsculas, así que la búsqueda es insensible a mayúsculas: search=cli, search=CLI y search=Agro funcionan igual. No distingue en cuál de los dos campos casó: un término que aparezca en el código de un cliente y en el nombre de otro devuelve los dos. En blanco o de solo espacios se ignora y se devuelve el catálogo completo, nunca 422. NO alcanza a los clientes borrados, ni siquiera cuando el término coincidiría exactamente con su código.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'agro'),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación —que es lo que quiere un selector de cliente—. Si es numérico se ACOTA al rango [10, 100]: limit=1 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Se combina con search.',
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
                description: 'Clientes obtenidos correctamente. Sin limit se devuelve ClientListResponse; con limit numérico, PaginatedClientListResponse, con total, currentPage y lastPage aplanados en la raíz del sobre. Un catálogo vacío o un search sin coincidencias devuelven 200 con data vacío, nunca 404. El deletedAt de cada elemento es siempre null: los borrados no se listan.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/ClientListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedClientListResponse'),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, ClientServiceInterface $clientService)
    {
        try {
            $clients = $clientService->getClients($this->filters($request));

            $data = $clients instanceof LengthAwarePaginator
                ? new PaginatedResource($clients, ClientResource::class)
                : ClientResource::collection($clients);

            return ResponseHandler::success($data, 'Clientes obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/clients',
        operationId: 'storeClient',
        summary: 'Registrar un cliente',
        description: <<<'TEXT'
        Da de alta un cliente nacional. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403, aunque los tres sí puedan leer los clientes. No lleva carrier.required, porque el cliente no pertenece a ninguna empresa.

        El cuerpo acepta SOLO DOS CAMPOS, code y name, y los dos son obligatorios. No hay dirección, NIT, teléfono, contacto ni status: es el alta más pequeña del proyecto. El registeredBy no se envía —se toma del usuario autenticado y se devuelve resuelto en registeredByName— y mandarlo en el cuerpo no tiene efecto.

        ATENCIÓN — NO HAY status: NO SE PUEDE CREAR UN CLIENTE «INACTIVO» ni pausarlo después. Este catálogo no tiene el booleano de publicación de Products, Locations, Zones y Departure Points; un cliente existe o está borrado, y el borrado no se deshace por la API.

        LOS DOS CAMPOS SE NORMALIZAN, Y NO IGUAL: el name se recorta, se le COLAPSAN los espacios internos y se pasa a mayúsculas —"  agro   del sur  " crea "AGRO DEL SUR"—; el code solo se recorta y se pasa a mayúsculas, porque un código con cualquier espacio se rechaza con 422 en vez de arreglarse por dentro. El cliente debe pintar el code y el name de la respuesta, no los que tecleó el usuario.

        ATENCIÓN — LOS DOS DUPLICADOS POSIBLES SON 400 DESDE EL SERVICE Y NUNCA 422, al revés que en Departure Points y Locations, donde el nombre repetido es 422. Ninguno de los dos campos lleva regla unique a propósito: LA REGLA unique DE LARAVEL NO VE LAS FILAS BORRADAS, así que dejaría pasar un código ocupado por un cliente eliminado y el alta reventaría contra el índice único con un 500. Mensajes literales: «Ya existe un cliente con ese código, que puede haber sido eliminado» y «Ya existe un cliente con ese nombre, que puede haber sido eliminado». Si el código y el nombre están ocupados a la vez, GANA EL MENSAJE DEL CÓDIGO, que se comprueba primero.

        ATENCIÓN — EL BORRADO NO LIBERA NI EL CÓDIGO NI EL NOMBRE. Un cliente eliminado los sigue reservando para siempre, de modo que este 400 puede venir de una fila INVISIBLE EN TODOS LOS ENDPOINTS: no se lista, no se consulta por id y no hay parámetro que la muestre. El «que puede haber sido eliminado» del mensaje está precisamente para que el usuario no crea que el error miente. Reutilizar el código de un cliente borrado exige tocar la base de datos.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreClientRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Cliente registrado correctamente, con el code y el name ya normalizados en mayúsculas, registeredByName el del administrador autenticado, createdAt y updatedAt con el formato d-m-Y h:i:s A y deletedAt en null.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Cliente registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Client'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida, nunca un valor mal formado: el código o el nombre ya están ocupados por otro cliente, VIVO O BORRADO. Mensajes literales: «Ya existe un cliente con ese código, que puede haber sido eliminado» y «Ya existe un cliente con ese nombre, que puede haber sido eliminado»; si chocan los dos a la vez, se devuelve el del código. ATENCIÓN — ES AQUÍ, Y NO EN EL 422, DONDE CAEN LOS DUPLICADOS: los FormRequests no llevan regla unique porque la de Laravel no vería las filas borradas. El ocupante puede ser un cliente eliminado que no aparece en ningún endpoint.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí, aunque los tres sí puedan leer los clientes—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos, con el formato propio de Laravel {message, errors} y NO con el sobre {statusCode, message, data} del resto de la API. Casos posibles: falta el code o el name, o llegan vacíos o de solo espacios —el recorte los deja vacíos antes de validarse— (El código del cliente es obligatorio / El nombre del cliente es obligatorio); alguno no es texto (El código del cliente debe ser texto / El nombre del cliente debe ser texto); el code supera los 15 caracteres o el name los 255 (El código del cliente no puede superar los 15 caracteres / El nombre del cliente no puede superar los 255 caracteres); o el code TRAE ALGÚN ESPACIO O TABULADOR, aunque sea interior (El código no puede contener espacios). ATENCIÓN — un código o un nombre ya registrados NO caen aquí: son 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreClientRequest $request, ClientServiceInterface $clientService)
    {
        try {
            $client = $clientService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new ClientResource($client), 'Cliente registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/clients/{client}',
        operationId: 'showClient',
        summary: 'Obtener un cliente por id',
        description: <<<'TEXT'
        Devuelve un cliente concreto con el nombre del administrador que lo capturó.

        Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa. No hay ámbito por empresa, así que no existe el 403 por recurso ajeno que sí tienen Vehicles o Carriers: o el id existe y se devuelve, o es 404.

        ATENCIÓN — UN CLIENTE BORRADO RESPONDE 404 CON EL MISMO MENSAJE QUE UN id INEXISTENTE, y es deliberado: quien lee no distingue «ya no está» de «nunca existió», porque distinguirlo revelaría qué ids llegaron a existir. La única operación que sí los diferencia es la escritura, que responde 400 «El cliente ya fue eliminado» sobre una fila borrada. Es lo contrario de Departure Points o Locations, donde una fila dada de baja se sigue consultando con 200.

        El deletedAt de esta respuesta es SIEMPRE null: por definición, aquí solo se alcanzan clientes vivos.

        No hay consulta por código ni por nombre: para buscar está el filtro search del listado, que barre los dos campos.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(
                name: 'client',
                description: 'Identificador numérico del cliente (clients.id). NO es el code del cliente —no existe consulta por código— y no se reutiliza: el id de un cliente borrado sigue ocupado en la base, pero ya no es alcanzable por esta ruta.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cliente obtenido correctamente. Siempre un cliente vivo, con el code y el name en mayúsculas, deletedAt en null y las dos fechas con el formato d-m-Y h:i:s A, no ISO 8601.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Cliente obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Client'),
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
                response: 404,
                description: 'No existe ningún cliente alcanzable con ese id. ATENCIÓN — AQUÍ CAEN DOS CASOS INDISTINGUIBLES A PROPÓSITO: el id que nunca existió y el del cliente que fue borrado. El mensaje es el mismo en ambos: El cliente no existe. Si se necesita saber si la fila estuvo ahí, el PATCH y el DELETE responden 400 «El cliente ya fue eliminado» sobre una borrada.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $client, ClientServiceInterface $clientService)
    {
        try {
            $found = $clientService->getClientById($client);

            return ResponseHandler::success(new ClientResource($found), 'Cliente obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/clients/{client}',
        operationId: 'updateClient',
        summary: 'Actualizar un cliente',
        description: <<<'TEXT'
        Corrige el código, el nombre o los dos. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo: el PUT no reemplaza el recurso completo.

        Los dos campos son opcionales y solo se toca lo que venga: un PATCH que solo manda name no altera el código. Un CUERPO VACÍO responde 200 como no-op, devolviendo el cliente sin cambios, en vez de 422. Opcional no es vaciable: enviar un campo vacío —o de solo espacios— es 422, y ninguno acepta null.

        No hay nada más que editar: este catálogo no tiene status, así que aquí no se pausa ni se reactiva nada, y NO EXISTE FORMA DE RESTAURAR UN CLIENTE BORRADO —ni con este PATCH ni con ningún otro endpoint—.

        ATENCIÓN — SOBRE UN CLIENTE YA BORRADO ESTE ENDPOINT RESPONDE 400 «El cliente ya fue eliminado», NO 404. Es la asimetría deliberada del dominio: la lectura no distingue el borrado del inexistente (los dos son 404), la escritura sí, porque quien edita necesita saber que la fila estuvo ahí. Un id que nunca existió sigue siendo 404 aquí.

        MISMA REGLA DE DUPLICADOS QUE EL ALTA, Y SIGUE SIENDO 400 Y NUNCA 422: el código o el nombre de otro cliente —VIVO O BORRADO— responden «Ya existe un cliente con ese código/nombre, que puede haber sido eliminado», y si chocan los dos gana el del código. La comprobación IGNORA LA PROPIA FILA, así que reenviar el propio código y el propio nombre responde 200 y no choca consigo mismo.

        Los dos campos se normalizan igual que en el alta y con la misma asimetría: el name colapsa sus espacios internos y pasa a mayúsculas; el code solo se recorta y pasa a mayúsculas, y con cualquier espacio es 422.

        El registeredBy NO SE REESCRIBE: sigue apuntando a quien dio de alta el cliente, aunque lo edite otro administrador, y mandarlo en el cuerpo se descarta sin error. El createdAt tampoco cambia; el updatedAt sí, salvo en el no-op del cuerpo vacío. No se guarda el valor anterior: no hay bitácora de cambios.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateClientRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(
                name: 'client',
                description: 'Identificador numérico del cliente (clients.id). Es también la fila que se ignora al revalidar la unicidad del código y la del nombre, de modo que reenviar los suyos propios no choca consigo misma. El id NO cambia al corregir el código: editar un cliente conserva su identidad y su historial.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cliente actualizado correctamente. data trae la fila ya modificada, con el code y el name normalizados en mayúsculas, el mismo registeredByName, el mismo createdAt, el updatedAt refrescado y deletedAt en null. Un cuerpo vacío también responde 200, con el cliente intacto.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Cliente actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Client'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Dos causas distintas, ambas de negocio. (1) EL CLIENTE YA FUE BORRADO: mensaje «El cliente ya fue eliminado». Un cliente borrado no se edita ni se restaura por la API. (2) El código o el nombre enviados ya los ocupa OTRO cliente, vivo o borrado: «Ya existe un cliente con ese código, que puede haber sido eliminado» / «Ya existe un cliente con ese nombre, que puede haber sido eliminado», ganando el del código si chocan los dos. Reenviar el propio código o el propio nombre del cliente que se edita NO cae aquí: es 200.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna fila con ese id, ni viva ni borrada. El mensaje devuelto es: El cliente no existe. ATENCIÓN — un cliente BORRADO no cae aquí: la escritura sí distingue los dos casos y responde 400 «El cliente ya fue eliminado», al contrario que el GET del detalle, que devuelve 404 en ambos.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos, con el formato propio de Laravel {message, errors}. Casos posibles: se envía el code o el name vacíos o de solo espacios (El código del cliente es obligatorio / El nombre del cliente es obligatorio); alguno no es texto; el code supera los 15 caracteres o el name los 255; o el code trae algún espacio o tabulador (El código no puede contener espacios). Un cuerpo vacío NO produce 422, y un código o un nombre ya registrados tampoco: son 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateClientRequest $request, int $client, ClientServiceInterface $clientService)
    {
        try {
            $updated = $clientService->update($client, $request->validated());

            return ResponseHandler::success(new ClientResource($updated), 'Cliente actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/clients/{client}',
        operationId: 'destroyClient',
        summary: 'Eliminar un cliente',
        description: <<<'TEXT'
        ATENCIÓN — ESTE DELETE BORRA DE VERDAD, Y ES LA OPERACIÓN MÁS PELIGROSA DEL DOMINIO. No se parece al DELETE de Products, Locations, Zones o Departure Points, que solo ponen un status booleano en false y dejan la fila listándose y reactivable. Aquí es un soft delete real: el cliente DESAPARECE de GET /api/clients y de GET /api/clients/{client} —que pasa a responder 404—, NO hay ningún filtro que lo devuelva, NO existe endpoint /restore y NO HAY FORMA DE RECUPERARLO POR LA API. Deshacerlo exige tocar la base de datos.

        Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        ATENCIÓN — EL BORRADO NO LIBERA NI EL CÓDIGO NI EL NOMBRE. Los dos índices únicos siguen ocupados por la fila borrada para siempre, así que después de este DELETE NADIE PUEDE DAR DE ALTA OTRO CLIENTE CON ESE CÓDIGO O ESE NOMBRE: el alta responde 400 «Ya existe un cliente con ese código, que puede haber sido eliminado» contra una fila que ya no se ve por ninguna vía. Es lo contrario de la placa de un Vehicle, que un vehículo inactivo sí libera. Borrar para volver a crear con los mismos datos NO FUNCIONA.

        NO ES IDEMPOTENTE, y esa es su segunda diferencia con los catálogos booleanos: el segundo DELETE sobre el mismo id responde 400 «El cliente ya fue eliminado», no 200. Un id que nunca existió responde 404 «El cliente no existe». Distinguirlos es intencionado —igual que en FreightRates—, para que repetir la llamada no parezca un éxito.

        LA RESPUESTA ES LA ÚNICA DE LA API CON deletedAt NO NULO: devuelve la fila que se acaba de borrar, con su marca de tiempo en el formato d-m-Y h:i:s A. Conviene guardarla, porque después ya no se puede volver a leer.

        Borrar un cliente NO ROMPE NADA en el resto de la API: ninguna tabla del proyecto tiene client_id, así que no hay tarifas, cotizaciones ni viajes que dependan de él. El riesgo es la pérdida del dato, no una cascada.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Clients'],
        parameters: [
            new OA\Parameter(
                name: 'client',
                description: 'Identificador numérico del cliente (clients.id) que se va a borrar. Debe estar vivo: uno ya borrado responde 400 y uno inexistente 404. Tras el borrado, ese id deja de ser alcanzable por cualquier endpoint, aunque la fila siga en la base reservando su código y su nombre.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cliente eliminado correctamente. data trae la fila recién borrada, y es la ÚNICA respuesta de toda la API en la que deletedAt viene con valor —con el formato d-m-Y h:i:s A— en vez de null. A partir de aquí el cliente ya no se lista ni se consulta por id, y su código y su nombre quedan ocupados para siempre.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Cliente eliminado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Client'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'El cliente ya fue borrado antes: este DELETE NO ES IDEMPOTENTE y el segundo intento no responde 200. El mensaje devuelto es: El cliente ya fue eliminado. Es la respuesta que distingue «ya no está» de «nunca existió», que sería 404.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna fila con ese id, ni viva ni borrada. El mensaje devuelto es: El cliente no existe. Un cliente ya borrado NO cae aquí: es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $client, ClientServiceInterface $clientService)
    {
        try {
            $deleted = $clientService->destroy($client);

            return ResponseHandler::success(new ClientResource($deleted), 'Cliente eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters the service understands.
     *
     * @return array{search: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $this->queryString($request, 'search'),
            'limit' => $this->queryString($request, 'limit'),
        ];
    }

    /**
     * Read a query string parameter, keeping only actual strings.
     *
     * An array or a missing key degrades to null, which every filter treats as absent.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
