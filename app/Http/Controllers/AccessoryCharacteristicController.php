<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\AccessoryCharacteristic\IndexAccessoryCharacteristicRequest;
use App\Http\Requests\AccessoryCharacteristic\StoreAccessoryCharacteristicRequest;
use App\Http\Requests\AccessoryCharacteristic\UpdateAccessoryCharacteristicRequest;
use App\Http\Resources\AccessoryCharacteristic\AccessoryCharacteristicResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\AccessoryCharacteristic\AccessoryCharacteristicServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'AccessoryCharacteristics',
    description: 'Características de los accesorios del inventario nacional: pares nombre/valor libres —PLACA, TIPO DE COMBUSTIBLE, MEDIDA— con los que cada accesorio se describe a sí mismo. El conjunto de campos NO está fijado por el esquema: dos accesorios pueden llevar listas completamente distintas sin migrar nada, y ninguno está obligado a tener ninguna característica. NO ES UN CATÁLOGO: no hay tabla de nombres permitidos, ni autocompletado, ni tipo declarado —el valor es SIEMPRE texto—, ni filtro que vaya en la dirección contraria (no existe "dame los accesorios cuya PLACA sea P-123ABC"). Los cinco endpoints exigen token JWT (Authorization: Bearer {token}) y el reparto de permisos es asimétrico, como en Accessories: la LECTURA (index y show) está abierta a los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier que todavía no ha registrado su empresa; la ESCRITURA (store, update y destroy) es exclusiva del administrator (middleware role:administrator) y los otros tres reciben 403. NINGUNA ruta lleva carrier.required: como el inventario del que cuelgan, las características son un dato nacional y no tienen carrierId ni vehicleId. ATENCIÓN — EL RECURSO CUELGA DE UN ACCESORIO PERO LA RUTA NO ESTÁ ANIDADA: NO existe /api/accessories/{accessory}/characteristics; el vínculo viaja en el campo accessory_id del cuerpo del alta y en el query param accessoryId del listado, que es OBLIGATORIO —sin él la respuesta es 422, no un listado global del inventario entero—. ATENCIÓN — ESA DIFERENCIA DE CAJA ES REAL: accessoryId en camelCase en el query param, accessory_id en snake_case en el cuerpo del POST. ATENCIÓN — AccessoryResource NO CAMBIÓ con esta spec: GET /api/accessories y GET /api/accessories/{accessory} NO devuelven characteristics ni un contador, y las características se piden SIEMPRE aparte; una pantalla que liste 40 accesorios con sus características hace 41 peticiones, y es el precio asumido de no tocar un recurso ya publicado. Reglas que atraviesan el dominio: la NORMALIZACIÓN ES ASIMÉTRICA —el name se recorta, colapsa espacios internos y sube a MAYÚSCULAS; el value solo se recorta y conserva su caja y sus espacios—; el name es único POR ACCESORIO y su colisión sale por 400 del service, NO por 422, porque no hay regla unique en el FormRequest; el accessory_id es INMUTABLE y mandarlo en el PATCH se ignora en silencio; el registered_by sale del usuario autenticado y no se reescribe al editar; el status del accesorio NO INTERVIENE, así que uno inactive o under_repair lista, acepta y edita características igual; el orden del listado es fijo id ASC y NO hay más filtros que el accessoryId obligatorio —ni search, ni status, ni sortBy—; y el DELETE es un BORRADO FÍSICO, sin status, sin SoftDeletes y sin historial, de modo que un segundo DELETE del mismo id es 404. La salida va en camelCase con seis claves exactas, registeredBy es el NOMBRE del usuario y createdAt va en d-m-Y h:i:s A, que NO es ISO 8601; no hay updatedAt.',
)]
class AccessoryCharacteristicController extends Controller
{
    #[OA\Get(
        path: '/api/accessory-characteristics',
        operationId: 'indexAccessoryCharacteristics',
        summary: 'Listar las características de un accesorio',
        description: <<<'TEXT'
        Devuelve las características de UN accesorio concreto, con el nombre de quien capturó cada una. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa, porque las características cuelgan del inventario nacional y no están acotadas por transportista ni por vehículo. No hay ámbito por empresa: todos los usuarios ven exactamente las mismas filas.

        ATENCIÓN — accessoryId ES OBLIGATORIO. Sin él la petición NO devuelve las características de todo el inventario ni una lista vacía: devuelve 422. Y ese 422 NO usa el sobre habitual {statusCode, message, data}: al venir de un FormRequest sale con el formato de validación de Laravel {message, errors: {accessoryId: [...]}}, con el mensaje El accesorio es obligatorio dentro de errors.accessoryId. Un cliente que lea siempre response.statusCode o response.data en los errores se encontrará undefined justo en este caso. Un accessoryId que no sea entero da el mismo formato con el mensaje El accesorio debe ser un número entero.

        ATENCIÓN — el query param va en camelCase (accessoryId), mientras que el cuerpo del POST lo lleva en snake_case (accessory_id). Es la única incoherencia de caja del dominio.

        Un accessoryId que no corresponde a ningún accesorio es 404 (El accesorio no existe), NO un listado vacío: recibir data: [] siempre significa que el accesorio EXISTE y todavía no tiene ninguna característica. Es distinto del alta, donde ese mismo id inexistente sale por 422 gracias a la regla exists.

        ATENCIÓN — EL STATUS DEL ACCESORIO NO IMPORTA: uno inactive o under_repair lista sus características con toda normalidad, porque la ficha de una pieza retirada sigue siendo información válida.

        NO HAY MÁS FILTROS: no existe search sobre el nombre ni sobre el valor, no existe filtro por status —la tabla no tiene estado— y no existe orden configurable. El orden es FIJO, id ASC, es decir el orden en que se capturaron. Cualquier otro query param se ignora en silencio. Tampoco existe la dirección contraria: no se pueden buscar accesorios por el valor de una característica.

        La forma de la respuesta depende del parámetro limit: sin limit se devuelven todas las características del accesorio y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos APLANADOS EN LA RAÍZ, no bajo meta. Como las características de un accesorio se cuentan con los dedos, lo normal es no paginar: el limit se mantiene solo por coherencia con el resto del proyecto.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['AccessoryCharacteristics'],
        parameters: [
            new OA\Parameter(
                name: 'accessoryId',
                description: 'OBLIGATORIO. Identificador del accesorio cuyas características se piden (accessories.id), EN CAMELCASE —el snake_case accessory_id es el del cuerpo del POST, no el de este query param—. Omitirlo devuelve 422 con el formato de validación de Laravel {message, errors} y el mensaje El accesorio es obligatorio; no hay valor por defecto ni listado global. Debe ser un entero: accessoryId=abc devuelve 422 con el mensaje El accesorio debe ser un número entero. Un id inexistente devuelve 404 (El accesorio no existe), nunca un listado vacío. NO lleva regla exists en el FormRequest a propósito: el 422 se reserva a la ausencia del parámetro y el 404 lo levanta el service. El estado del accesorio no se mira: inactive y under_repair listan igual que active.',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 12),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todas las características del accesorio sin error y SIN metadatos de paginación. Si es numérico se ACOTA al rango [10, 100]: limit=1 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Nunca provoca un 422: no está validado.',
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
                description: 'Características obtenidas correctamente. Sin limit se devuelve AccessoryCharacteristicListResponse; con limit numérico, PaginatedAccessoryCharacteristicListResponse, con total, currentPage y lastPage aplanados en la raíz del sobre. Un accesorio que existe y no tiene ninguna característica devuelve 200 con data vacío, nunca 404. Siempre ordenadas por id ASC.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/AccessoryCharacteristicListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedAccessoryCharacteristicListResponse'),
                    ],
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ningún accesorio con el accessoryId enviado. El mensaje devuelto es: El accesorio no existe. Nunca se responde con un listado vacío en este caso, para que el cliente pueda distinguir "el accesorio no está" de "el accesorio no tiene características"',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Falta el parámetro accessoryId (mensaje El accesorio es obligatorio) o no es un entero (mensaje El accesorio debe ser un número entero). ATENCIÓN: este error NO usa el sobre {statusCode, message, data} sino el formato de validación de Laravel {message, errors}. El limit nunca provoca un 422: no está validado y uno no numérico simplemente no pagina',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function index(IndexAccessoryCharacteristicRequest $request, AccessoryCharacteristicServiceInterface $accessoryCharacteristicService)
    {
        try {
            $result = $accessoryCharacteristicService->getAccessoryCharacteristics($this->filters($request));

            $characteristics = $result['characteristics'];

            $data = $characteristics instanceof LengthAwarePaginator
                ? (new PaginatedResource($characteristics, AccessoryCharacteristicResource::class))->resolve()
                /**
                 * Sin envolver en ['data' => ...]: ResponseHandler solo aplana esa clave
                 * cuando el array trae más de una, así que envolverla anidaría data
                 * dentro de data. VehicleExpenseController se salva porque añade
                 * totalAmount, que aquí no existe.
                 */
                : AccessoryCharacteristicResource::collection($characteristics);

            return ResponseHandler::success($data, 'Características obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/accessory-characteristics',
        operationId: 'storeAccessoryCharacteristic',
        summary: 'Registrar una característica de accesorio',
        description: <<<'TEXT'
        Añade UNA característica —un par nombre/valor— a UN accesorio del inventario nacional. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403, aunque los tres sí puedan leer las características. No lleva carrier.required, porque el accesorio no pertenece a ninguna empresa.

        ATENCIÓN — LA RUTA NO ESTÁ ANIDADA: el accesorio NO va en la URL, va en el campo accessory_id del cuerpo, EN SNAKE_CASE. No confundirlo con el query param accessoryId (camelCase) del listado: mandar accessoryId en el cuerpo no vincula nada y devuelve 422 por accessory_id ausente.

        Cada llamada crea EXACTAMENTE UNA fila: no hay alta en lote ni endpoint de sincronización. Tres características son tres peticiones, sin transacción que las agrupe.

        ATENCIÓN — UN accessory_id INEXISTENTE ES 422, NO 404: lo caza la regla exists del FormRequest con el mensaje El accesorio no existe. El service tiene su propio 404 con ese mismo mensaje, pero por HTTP no se alcanza aquí; en el LISTADO, en cambio, ese id inexistente sí sale por 404. El STATUS del accesorio no se mira: uno inactive o under_repair acepta características igual que uno active.

        ATENCIÓN — name y value SE NORMALIZAN CON REGLAS DISTINTAS. El name se recorta, se le COLAPSAN los espacios internos y se pasa a MAYÚSCULAS: enviar "  placa   trasera " crea "PLACA TRASERA". El value SOLO se recorta y NO cambia de caja ni pierde sus espacios internos: "  Diésel " crea "Diésel" y "Acero   inoxidable" se guarda con sus tres espacios. El cliente debe pintar el name y el value de la respuesta, no lo que tecleó el usuario.

        ATENCIÓN — REPETIR UN NOMBRE EN EL MISMO ACCESORIO ES 400, NO 422: no hay regla unique en el FormRequest, así que la colisión la corta el service con el mensaje de negocio "El accesorio ya tiene una característica con ese nombre" y el índice único (accessory_id, name) queda solo de red de seguridad. Es distinto de Accessories, donde los duplicados salen por 422. El MISMO nombre en OTRO accesorio devuelve 201 con toda normalidad: dos accesorios pueden tener ambos "PLACA", uno solo no puede tener dos.

        El registeredBy NO se envía: se toma del usuario autenticado y vuelve resuelto como el NOMBRE de esa persona; mandar registeredBy o registered_by en el cuerpo se ignora sin error. Tampoco hay status ni fecha que enviar: la tabla no tiene estado y el createdAt lo pone la base.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreAccessoryCharacteristicRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['AccessoryCharacteristics'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Característica registrada correctamente, con el name ya normalizado en MAYÚSCULAS, el value tal como se tecleó salvo por el recorte de los extremos, el accessoryId del cuerpo y registeredBy con el nombre del administrador autenticado. createdAt viene en d-m-Y h:i:s A y no hay updatedAt.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Característica registrada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/AccessoryCharacteristic'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'ATENCIÓN — ESTE 400 SÍ SE ALCANZA POR HTTP, al contrario que el de Accessories: el accesorio ya tiene una característica con ese nombre una vez normalizado. El mensaje devuelto es: El accesorio ya tiene una característica con ese nombre. No es un 422 porque el FormRequest no lleva regla unique: la unicidad es por accesorio y la comprueba el service. El mismo nombre en otro accesorio no da este error',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí, aunque los tres sí puedan leer las características—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos. Casos típicos: falta accessory_id, no es entero o NO EXISTE ningún accesorio con ese id (El accesorio es obligatorio / El accesorio debe ser un número entero / El accesorio no existe — este último es la regla exists, por eso es 422 y no 404); falta el name, va vacío, no es texto o supera los 255 caracteres (El nombre de la característica es obligatorio / El nombre de la característica debe ser texto / El nombre de la característica no puede superar los 255 caracteres); falta el value, va vacío, no es texto o supera los 500 caracteres (El valor de la característica es obligatorio / El valor de la característica debe ser texto / El valor de la característica no puede superar los 500 caracteres). Repetir un nombre en el mismo accesorio NO cae aquí: eso es 400',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreAccessoryCharacteristicRequest $request, AccessoryCharacteristicServiceInterface $accessoryCharacteristicService)
    {
        try {
            $characteristic = $accessoryCharacteristicService->createAccessoryCharacteristic($request->validated(), auth('api')->user());

            return ResponseHandler::success(new AccessoryCharacteristicResource($characteristic), 'Característica registrada correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/accessory-characteristics/{accessoryCharacteristic}',
        operationId: 'showAccessoryCharacteristic',
        summary: 'Obtener una característica por id',
        description: <<<'TEXT'
        Devuelve una característica concreta con el nombre de quien la capturó. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa.

        ATENCIÓN — el {accessoryCharacteristic} de la ruta es el id de la CARACTERÍSTICA (accessory_characteristics.id), NO el del accesorio. Pasar aquí el id de un accesorio devuelve otra fila o un 404, no las características de ese accesorio: para eso está GET /api/accessory-characteristics?accessoryId=.

        No hay ámbito por empresa ni por vehículo, así que no existe el 403 por recurso ajeno que sí tienen Vehicles o Carriers: o el id existe y se devuelve, o es 404. Y como el borrado es FÍSICO, una característica eliminada da 404 y no una fila marcada de baja: aquí no hay el 400 "ya fue eliminada" de FreightRates.

        El status del accesorio del que cuelga no interviene: la característica de un accesorio inactive o under_repair se consulta con toda normalidad.

        No hay consulta por nombre: el dominio no tiene búsqueda de ninguna clase, ni aquí ni en el listado.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['AccessoryCharacteristics'],
        parameters: [
            new OA\Parameter(
                name: 'accessoryCharacteristic',
                description: 'Identificador numérico de la CARACTERÍSTICA (accessory_characteristics.id), no del accesorio. Es el id que devuelve la clave id del recurso, no el de accessoryId. No cambia nunca —el vínculo con el accesorio es inmutable— y desaparece de verdad al borrar la fila, porque la baja es física.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 4),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Característica obtenida correctamente. data trae las SEIS claves del recurso —id, accessoryId, name, value, registeredBy y createdAt— y ninguna más: no hay accessoryName, no hay objeto accessory anidado y no hay updatedAt.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Característica obtenida correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/AccessoryCharacteristic'),
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
                description: 'No existe ninguna característica con ese id, o existía y ya fue BORRADA FÍSICAMENTE por un DELETE. El mensaje devuelto es: La característica no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $accessoryCharacteristic, AccessoryCharacteristicServiceInterface $accessoryCharacteristicService)
    {
        try {
            $found = $accessoryCharacteristicService->getAccessoryCharacteristicById($accessoryCharacteristic);

            return ResponseHandler::success(new AccessoryCharacteristicResource($found), 'Característica obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/accessory-characteristics/{accessoryCharacteristic}',
        operationId: 'updateAccessoryCharacteristic',
        summary: 'Actualizar una característica',
        description: <<<'TEXT'
        Modifica el nombre, el valor o los dos. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo: el PUT no reemplaza el recurso completo.

        Los dos campos son opcionales por separado y solo se toca lo que venga: un PATCH que solo manda value deja el name intacto, y al revés. Un CUERPO VACÍO responde 200 como no-op, devolviendo la característica sin cambios, en vez de 422. Enviarlos VACÍOS sí es 422: son sometimes|required, y ninguna de las dos columnas es nullable.

        ATENCIÓN — LA CARACTERÍSTICA NUNCA CAMBIA DE ACCESORIO Y EL INTENTO NO AVISA. accessory_id no forma parte del cuerpo aceptado: enviarlo —o enviar accessoryId— NO devuelve error, se IGNORA EN SILENCIO y la respuesta 200 trae el accessoryId original. Un cliente que mande la característica entera incluyendo el accesorio creerá que la movió. Mover una característica de accesorio es borrarla y volverla a crear.

        La normalización es la misma del alta y con la misma asimetría: el name se recorta, colapsa sus espacios internos y sube a MAYÚSCULAS; el value SOLO se recorta y conserva su caja y sus espacios.

        ATENCIÓN — el choque de nombre se comprueba contra EL ACCESORIO YA GUARDADO, no contra uno que venga en el cuerpo, e IGNORANDO LA PROPIA FILA: chocar con OTRA característica del mismo accesorio es 400 (El accesorio ya tiene una característica con ese nombre), reenviar SU PROPIO nombre sin cambios es 200, y usar un nombre que ya existe en OTRO accesorio también es 200.

        El registeredBy NO se acepta y NO se reescribe: la fila conserva a quien la capturó aunque la edite después otro administrador. Editar PISA el valor anterior sin dejar rastro: no hay historial de cambios, no hay updatedAt en la respuesta y el createdAt no se mueve. El status del accesorio no interviene: una característica de un accesorio dado de baja se edita igual.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateAccessoryCharacteristicRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['AccessoryCharacteristics'],
        parameters: [
            new OA\Parameter(
                name: 'accessoryCharacteristic',
                description: 'Identificador numérico de la CARACTERÍSTICA a editar (accessory_characteristics.id), no del accesorio. Es también el id que la comprobación de nombre repetido ignora, para que la fila pueda reenviar su propio nombre sin chocar consigo misma.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 4),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Característica actualizada correctamente. data trae la característica ya actualizada, con el MISMO accessoryId y el MISMO registeredBy que antes de la edición. Un cuerpo vacío devuelve este mismo 200 sin haber cambiado nada.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Característica actualizada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/AccessoryCharacteristic'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'El name enviado, ya normalizado, lo tiene OTRA característica del MISMO accesorio. El mensaje devuelto es: El accesorio ya tiene una característica con ese nombre. Reenviar el propio nombre de la fila NO cae aquí (es 200), y un nombre que ya existe en otro accesorio tampoco',
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
                description: 'No existe ninguna característica con ese id, o ya fue borrada físicamente. El mensaje devuelto es: La característica no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: el name o el value se enviaron VACÍOS (El nombre de la característica es obligatorio / El valor de la característica es obligatorio), no son texto o superan sus límites de 255 y 500 caracteres. ATENCIÓN — enviar accessory_id o accessoryId NO provoca un 422: se ignora en silencio',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateAccessoryCharacteristicRequest $request, int $accessoryCharacteristic, AccessoryCharacteristicServiceInterface $accessoryCharacteristicService)
    {
        try {
            $updated = $accessoryCharacteristicService->updateAccessoryCharacteristic($request->validated(), $accessoryCharacteristic);

            return ResponseHandler::success(new AccessoryCharacteristicResource($updated), 'Característica actualizada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/accessory-characteristics/{accessoryCharacteristic}',
        operationId: 'destroyAccessoryCharacteristic',
        summary: 'Eliminar una característica',
        description: <<<'TEXT'
        ATENCIÓN — ESTE DELETE BORRA DE VERDAD: es un BORRADO FÍSICO, la fila DESAPARECE de la tabla y no queda ningún rastro de ella. No hay baja lógica, no hay columna status, no hay SoftDeletes y no hay bitácora: una característica mal tecleada es basura, no historial, el mismo argumento de los gastos de vehículo. Es lo contrario del DELETE de Accessories, que solo pone el status en "inactive" y deja la fila viva.

        En consecuencia, NO ES IDEMPOTENTE: un SEGUNDO DELETE del mismo id responde 404 (La característica no existe), igual que un id que nunca existió. Y tampoco hay el 400 "ya fue eliminada" de FreightRates, porque aquí no hay soft delete que consultar: los dos casos son indistinguibles.

        Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        Borrar una característica NO TOCA NADA MÁS: el accesorio sigue igual —el DELETE no lo modifica ni cambia su status— y las demás características del mismo accesorio siguen intactas. Como la unicidad del name es por accesorio, borrar una LIBERA su nombre: después se puede volver a crear "PLACA" en ese accesorio con otro valor y otro id.

        La respuesta 200 devuelve la característica RECIÉN BORRADA con sus seis claves, como último recibo de lo que desapareció: ese id ya no se puede consultar.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['AccessoryCharacteristics'],
        parameters: [
            new OA\Parameter(
                name: 'accessoryCharacteristic',
                description: 'Identificador numérico de la CARACTERÍSTICA a borrar (accessory_characteristics.id), no del accesorio. Borrar el accesorio entero es otra cosa y vive en DELETE /api/accessories/{accessory}, que además es una baja lógica y no arrastra las características: no hay cascada.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 4),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Característica eliminada correctamente. data trae la fila tal como estaba justo antes de desaparecer —id, accessoryId, name, value, registeredBy y createdAt—; ese id ya no existe en la tabla y volver a consultarlo, editarlo o borrarlo devuelve 404.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Característica eliminada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/AccessoryCharacteristic'),
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
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna característica con ese id, o ya fue borrada por un DELETE anterior: al ser un borrado físico, los dos casos son indistinguibles. El mensaje devuelto es: La característica no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $accessoryCharacteristic, AccessoryCharacteristicServiceInterface $accessoryCharacteristicService)
    {
        try {
            $deleted = $accessoryCharacteristicService->deleteAccessoryCharacteristic($accessoryCharacteristic);

            return ResponseHandler::success(new AccessoryCharacteristicResource($deleted), 'Característica eliminada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters from the query string.
     *
     * `accessoryId` is the only filter of the domain: there is no search, no status
     * and no configurable sorting.
     *
     * @return array{accessoryId: int, limit: string|null}
     */
    private function filters(IndexAccessoryCharacteristicRequest $request): array
    {
        return [
            'accessoryId' => (int) $request->validated('accessoryId'),
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
