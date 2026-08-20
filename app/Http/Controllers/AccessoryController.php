<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Accessory\StoreAccessoryRequest;
use App\Http\Requests\Accessory\UpdateAccessoryRequest;
use App\Http\Resources\Accessory\AccessoryResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\Accessory\AccessoryServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Accessories',
    description: 'Inventario nacional de accesorios: llantas, gatos hidráulicos, juegos de cadenas y demás material suelto. El accesorio NO pertenece a ninguna empresa transportista NI está asignado a ningún vehículo —es un dato de Legumex, común a todos—, por eso NINGUNA ruta lleva el middleware carrier.required y el recurso no expone carrierId ni vehicleId. Los cinco endpoints exigen token JWT (Authorization: Bearer {token}), pero el reparto de permisos es asimétrico: la LECTURA (listado y detalle) está abierta a los cuatro roles —administrator, carrier, pilot y manager—, incluso a un carrier que todavía no ha registrado su empresa; la ESCRITURA (alta, actualización y baja) es exclusiva del administrator (middleware role:administrator) y los otros tres roles reciben 403. ATENCIÓN — UNA FILA ES UNA UNIDAD FÍSICA: no hay campo quantity; dos llantas iguales son DOS registros con DOS códigos distintos, y contar existencias es contar filas. ATENCIÓN — currentValue ES DERIVADO Y DE SOLO SALIDA: no hay columna, ni job, ni caché; se recalcula en cada lectura con depreciación LINEAL (años = purchaseDate.diffInDays(hoy) / 365; depreciado = price * (annualDepreciation / 100) * años; currentValue = round(max(0, price - depreciado), 2)), así que el mismo accesorio devuelve un valor distinto cada día sin que nadie escriba en la tabla y NO se puede filtrar ni ordenar por él. Reglas que atraviesan el dominio: el name se guarda normalizado y EN MAYÚSCULAS con los espacios internos colapsados, mientras que el code se guarda en mayúsculas pero SIN colapsarlos —"A 100" y "A100" son códigos distintos—; ambos son únicos a nivel global y sus duplicados salen por 422 desde el FormRequest (sin la asimetría 422/400 de Locations), aunque el service repite esos mismos mensajes como 400 para las llamadas directas; la unicidad del code es CIEGA AL STATUS, de modo que un accesorio dado de baja NO libera su código, al contrario que la placa de un vehículo; el status es un ENUM DE TRES VALORES (active, inactive, under_repair) y NO un booleano, no se acepta en el alta y se mueve libremente por el PATCH, que es la única vía —NO existe /toggle-status—; y el DELETE es una BAJA LÓGICA que solo pone status en "inactive", con la fila viva y todavía visible en el listado. La salida va en camelCase, con price, annualDepreciation y currentValue como CADENAS de dos decimales (GTQ por convención), purchaseDate en d-m-Y y createdAt en d-m-Y h:i:s A, NUNCA ISO 8601; el recurso no expone updatedAt y registeredBy es el NOMBRE del usuario, no su id.',
)]
class AccessoryController extends Controller
{
    #[OA\Get(
        path: '/api/accessories',
        operationId: 'indexAccessories',
        summary: 'Listar accesorios',
        description: <<<'TEXT'
        Devuelve los accesorios del inventario nacional con su valor depreciado a día de hoy y el nombre de quien los capturó. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa, porque el accesorio es un dato nacional y no está acotado por transportista ni por vehículo. No hay ámbito ni filtrado por empresa: todos los usuarios ven exactamente las mismas filas.

        ATENCIÓN — el listado devuelve por defecto LOS TRES ESTADOS MEZCLADOS: active, inactive y under_repair. La baja de un accesorio es lógica, así que lo dado de baja SIGUE APARECIENDO aquí con status "inactive"; para quedarse solo con lo disponible hay que enviar status=active. Es intencionado: una pantalla de administración necesita ver lo que dio de baja para poder reactivarlo.

        ATENCIÓN — UNA FILA ES UNA UNIDAD FÍSICA: no hay quantity. Si hay cuatro llantas iguales, aquí salen CUATRO elementos con cuatro códigos distintos y el mismo tipo de nombre no es posible —el name es único—, así que cada unidad lleva su propio nombre y su propio código. Contar existencias es contar filas o leer el total del sobre paginado.

        ATENCIÓN — cada elemento trae su currentValue RECALCULADO EN ESTA MISMA RESPUESTA: no está guardado en ninguna columna. Dos llamadas idénticas en días distintos devuelven valores distintos con la tabla intacta, y eso NO es un bug. Como el campo no existe en la base, NO se puede filtrar ni ordenar por él: quien necesite la lista ordenada por valor debe ordenarla en el cliente sobre la página que ya recibió.

        El orden es fijo y no configurable: id ASC, es decir, el orden en que se dieron de alta. No hay sortBy ni sortDir.

        Los filtros —status, search y limit— se combinan entre sí y son TOLERANTES: un status que no es uno de los tres valores del enum, un search en blanco y un limit no numérico se ignoran en silencio y la lectura devuelve 200, NUNCA 422. Un filtro sin coincidencias devuelve 200 con data vacío, tampoco 404. No hay filtro por rango de precio, por valor depreciado, por fecha de compra ni por autor del alta: cualquier otro query param se ignora.

        La forma de la respuesta depende del parámetro limit: sin limit se devuelven todos los registros y el sobre NO trae total, currentPage ni lastPage; con un limit numérico se devuelve el sobre paginado con esos tres campos APLANADOS EN LA RAÍZ, no bajo meta.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Accessories'],
        parameters: [
            new OA\Parameter(
                name: 'status',
                description: 'Filtra por estado. Es un ENUM DE TRES VALORES, no un booleano: los valores admitidos son active, inactive y under_repair, en minúsculas y tal cual. Cualquier otro valor —status=1, status=true, status=ACTIVE o status=lo-que-sea— NO resuelve al enum y se IGNORA sin error, devolviendo los tres estados mezclados, igual que si se omitiera: este filtro nunca provoca un 422. Solo admite un valor: no hay forma de pedir "active y under_repair" a la vez.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', enum: ['active', 'inactive', 'under_repair'], example: 'active'),
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Búsqueda parcial sobre el nombre Y el código a la vez (LIKE %TERM% sobre los dos, unidos por OR y agrupados para no saltarse el filtro de status). El término se normaliza como un nombre —recorte, colapso de espacios y mayúsculas— y tanto name como code están siempre en mayúsculas, así que la búsqueda es insensible a mayúsculas: search=gato y search=GATO devuelven ambas GATO HIDRÁULICO, y search=acc-00 encuentra ACC-0042 por su código. ATENCIÓN — el término colapsa sus espacios internos, así que buscar "A 100" busca en realidad "A 100" ya colapsado y puede no encontrar un código con espacios múltiples. En blanco o solo espacios se ignora y se devuelven todos. NO cubre la descripción ni el autor del alta.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string', example: 'gato'),
            ),
            new OA\Parameter(
                name: 'limit',
                description: 'Tamaño de página. Su presencia es lo que activa la paginación. Si se omite, o si no es numérico (por ejemplo limit=abc), se devuelven todos los registros sin error y sin metadatos de paginación —que es lo que quiere un selector de accesorios—. Si es numérico se ACOTA al rango [10, 100]: limit=5 devuelve páginas de 10 y limit=500 devuelve páginas de 100. Se combina con status y search.',
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
                description: 'Accesorios obtenidos correctamente. Sin limit se devuelve AccessoryListResponse; con limit numérico, PaginatedAccessoryListResponse, con total, currentPage y lastPage aplanados en la raíz del sobre. Una tabla vacía o un filtro sin coincidencias devuelven 200 con data vacío, nunca 404. Cada elemento trae su currentValue calculado para el día de hoy.',
                content: new OA\JsonContent(
                    oneOf: [
                        new OA\Schema(ref: '#/components/schemas/AccessoryListResponse'),
                        new OA\Schema(ref: '#/components/schemas/PaginatedAccessoryListResponse'),
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
    public function index(Request $request, AccessoryServiceInterface $accessoryService)
    {
        try {
            $accessories = $accessoryService->getAccessories($this->filters($request));

            $data = $accessories instanceof LengthAwarePaginator
                ? new PaginatedResource($accessories, AccessoryResource::class)
                : AccessoryResource::collection($accessories);

            return ResponseHandler::success($data, 'Accesorios obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/accessories',
        operationId: 'storeAccessory',
        summary: 'Registrar un accesorio',
        description: <<<'TEXT'
        Da de alta UNA UNIDAD FÍSICA del inventario nacional. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403, aunque los tres sí puedan leer los accesorios. No lleva carrier.required, porque el accesorio no pertenece a ninguna empresa ni se asigna a ningún vehículo.

        ATENCIÓN — NO HAY CAMPO quantity: cada llamada crea EXACTAMENTE UNA fila. Para registrar cuatro llantas iguales hay que hacer CUATRO altas, cada una con su propio código; no existe alta masiva y mandar una cantidad en el cuerpo se ignora sin error.

        El cuerpo acepta name, code, description, price, purchaseDate y annualDepreciation; obligatorios todos menos description. El status NO se envía —el accesorio nace siempre "active" y mandarlo se descarta—, así que no se puede crear uno ya dado de baja o ya en reparación: para eso está el PATCH. El registeredBy tampoco: se toma del usuario autenticado y se devuelve resuelto como el NOMBRE de esa persona. El currentValue tampoco: es derivado y de solo salida, y mandarlo se IGNORA EN SILENCIO.

        ATENCIÓN — name y code se NORMALIZAN CON REGLAS DISTINTAS. El name se recorta, se le COLAPSAN los espacios internos y se pasa a mayúsculas: enviar "gato  hidráulico" crea "GATO HIDRÁULICO". El code se recorta y se pasa a mayúsculas pero NO se le colapsan los espacios: "A 100" y "A100" son dos códigos DISTINTOS y los dos se aceptan a la vez. El cliente debe pintar el name y el code de la respuesta, no los que tecleó el usuario. Como la normalización ocurre ANTES de las reglas unique, enviar "gato hidráulico" existiendo "GATO HIDRÁULICO" devuelve 422, no 201 ni 500.

        LOS DOS DUPLICADOS POSIBLES SE RESPONDEN IGUAL, con 422 desde el FormRequest: "Ya existe un accesorio con ese nombre" y "Ya existe un accesorio con ese código". Aquí no hay la asimetría 422/400 de Locations. El service repite esas dos comprobaciones con esos mismos mensajes como 400, pero por HTTP nunca se ven: el FormRequest corta antes; solo aparecen en una llamada directa al service.

        ATENCIÓN — LA UNICIDAD DEL CÓDIGO ES GLOBAL Y CIEGA AL STATUS: un accesorio dado de baja NO libera su código, porque la unidad retirada conserva el número escrito en su etiqueta. Es la diferencia deliberada con la placa de un vehículo, que un status inactive sí libera.

        El día del alta, si purchaseDate es hoy, currentValue viene exactamente igual a price: todavía no ha transcurrido ni un día de depreciación.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreAccessoryRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Accessories'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Accesorio registrado correctamente, con status "active", el name y el code ya normalizados en mayúsculas, price y annualDepreciation como cadenas de dos decimales, purchaseDate en d-m-Y, createdAt en d-m-Y h:i:s A, registeredBy con el nombre del administrador autenticado y currentValue ya calculado —igual a price si la compra fue hoy—.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Accesorio registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Accessory'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida: el nombre o el código ya están ocupados por otro accesorio (Ya existe un accesorio con ese nombre / Ya existe un accesorio con ese código). ATENCIÓN — por HTTP este 400 NO SE ALCANZA: las reglas unique del FormRequest cazan los dos duplicados antes y responden 422 con esos mismos mensajes. El service repite la comprobación para que una llamada directa —sin pasar por el FormRequest— dé un error de negocio y no un 500 contra el índice único de la tabla. Se documenta por completitud del contrato.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí, aunque los tres sí puedan leer los accesorios—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos. Casos típicos: falta el name o ya existe un accesorio con ese nombre una vez normalizado —incluido mandar "gato hidráulico" existiendo "GATO HIDRÁULICO"— (El nombre del accesorio es obligatorio / Ya existe un accesorio con ese nombre); falta el code o ya lo ocupa otro accesorio, INCLUIDO UNO DADO DE BAJA (El código del accesorio es obligatorio / Ya existe un accesorio con ese código); el price falta, no es numérico, es 0 o supera el máximo (El precio es obligatorio / El precio debe ser numérico / El precio debe ser mayor a 0 / El precio no puede superar 99999999.99); la purchaseDate falta, no es una fecha o es FUTURA (La fecha de compra es obligatoria / La fecha de compra debe ser una fecha válida / La fecha de compra no puede ser futura); la annualDepreciation falta, no es numérica o se sale de [0, 100] (El porcentaje de depreciación anual es obligatorio / El porcentaje de depreciación anual no puede ser negativo / El porcentaje de depreciación anual no puede superar 100); o la descripción no es texto o supera los 1000 caracteres.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreAccessoryRequest $request, AccessoryServiceInterface $accessoryService)
    {
        try {
            $accessory = $accessoryService->createAccessory($request->validated(), auth('api')->user());

            return ResponseHandler::success(new AccessoryResource($accessory), 'Accesorio registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/accessories/{accessory}',
        operationId: 'showAccessory',
        summary: 'Obtener un accesorio por id',
        description: <<<'TEXT'
        Devuelve un accesorio concreto —en cualquiera de sus tres estados— con su valor depreciado a día de hoy y el nombre del administrador que lo capturó.

        Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa. No hay ámbito por empresa ni por vehículo, así que no existe el 403 por recurso ajeno que sí tienen Vehicles o Carriers: o el id existe y se devuelve, o es 404.

        Un accesorio con status "inactive" o "under_repair" se obtiene con toda normalidad: la baja es lógica y no oculta la fila en ninguna lectura. El campo status es lo que distingue si está disponible.

        ATENCIÓN — el currentValue se CALCULA EN ESTA MISMA RESPUESTA y no está guardado en ninguna columna: pedir el mismo id mañana devuelve un número menor sin que nadie haya editado nada. No es un bug ni una condición de carrera; es la depreciación lineal contada por días.

        No hay consulta por code ni por nombre: para buscar por cualquiera de los dos está el filtro search del listado.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Accessories'],
        parameters: [
            new OA\Parameter(
                name: 'accessory',
                description: 'Identificador numérico del accesorio (accessories.id). NO es el code: el código es el identificador que teclea el usuario, es editable y no existe consulta por él —para eso está el filtro search del listado—. El id, en cambio, no cambia nunca y sobrevive tanto a la baja lógica como a un cambio de código.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Accesorio obtenido correctamente. Puede estar en cualquiera de los tres estados: el campo status lo distingue. price, annualDepreciation y currentValue vuelven como cadenas de dos decimales; purchaseDate en d-m-Y y createdAt en d-m-Y h:i:s A, no ISO 8601. No hay updatedAt.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Accesorio obtenido correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Accessory'),
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
                description: 'No existe ninguna fila con ese id. Un accesorio dado de baja o en reparación NO cae aquí: sigue existiendo y se devuelve con 200. El mensaje devuelto es: El accesorio no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $accessory, AccessoryServiceInterface $accessoryService)
    {
        try {
            $found = $accessoryService->getAccessoryById($accessory);

            return ResponseHandler::success(new AccessoryResource($found), 'Accesorio obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/accessories/{accessory}',
        operationId: 'updateAccessory',
        summary: 'Actualizar un accesorio',
        description: <<<'TEXT'
        Modifica el nombre, el código, la descripción, el precio, la fecha de compra, el porcentaje de depreciación, el estado o cualquier combinación de ellos. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo: el PUT no reemplaza el recurso completo.

        Todos los campos son opcionales y solo se toca lo que venga: un PATCH que solo manda description no altera el nombre, el código ni el precio. Un CUERPO VACÍO responde 200 como no-op, devolviendo el accesorio sin cambios, en vez de 422.

        ATENCIÓN — ESTE ENDPOINT ES LA ÚNICA VÍA PARA CAMBIAR EL ESTADO: NO existe PATCH /api/accessories/{accessory}/toggle-status, al contrario que en Products, Zones y Locations. Con tres valores no hay nada que invertir, así que el estado se fija explícitamente mandando status con "active", "inactive" o "under_repair". No hay reglas de transición: cualquier estado pasa a cualquier otro —de "inactive" directamente a "under_repair", sin escala en "active"— y reenviar el actual es 200 y no-op. Con status "inactive" se da de baja igual que con el DELETE; con "active" se reactiva.

        El name se NORMALIZA igual que en el alta —recorte, colapso de espacios y mayúsculas— y el code también, pero SIN colapsar los espacios internos. Las dos unicidades se revalidan IGNORANDO la propia fila, así que reenviar su mismo nombre o su mismo código responde 200 y usar el de otro accesorio responde 422.

        ATENCIÓN — EL code ES EDITABLE: corregir un código mal tecleado conserva la fila, su id y su historial, que es justo el motivo de permitirlo. Pero su unicidad sigue siendo GLOBAL Y CIEGA AL STATUS: no se puede reutilizar el código que ocupa un accesorio dado de baja, porque un inactive nunca libera el suyo.

        ATENCIÓN — EDITAR price, purchaseDate O annualDepreciation CAMBIA EL currentValue de la siguiente lectura, porque los tres son sus entradas y el valor se recalcula en cada respuesta. No hay bitácora de ninguno de los tres: el valor anterior se pierde sin rastro y la nueva depreciación se aplica como si siempre hubiera sido así. Mandar currentValue en el cuerpo se IGNORA EN SILENCIO, sin 422 y sin aviso: es un campo de solo salida.

        La description acepta null para borrarla —se distingue de omitir la clave, que la deja como está—; ningún otro campo acepta null.

        El registeredBy no se reescribe: sigue apuntando a quien dio de alta el accesorio, aunque lo edite otro administrador. El createdAt tampoco cambia, y el updatedAt existe en la base pero el recurso NO lo expone, así que por la API no hay forma de saber cuándo se editó por última vez.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateAccessoryRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Accessories'],
        parameters: [
            new OA\Parameter(
                name: 'accessory',
                description: 'Identificador numérico del accesorio (accessories.id). Es también la fila que se ignora al revalidar la unicidad del nombre y la del código, de modo que reenviar los suyos propios no choca consigo misma. El id NO cambia al corregir el code: el código es editable, el id no.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Accesorio actualizado correctamente. data trae la fila ya modificada, con el name y el code normalizados en mayúsculas, el mismo registeredBy, el mismo createdAt y el currentValue recalculado con los valores nuevos. Un cuerpo vacío también responde 200, con el accesorio intacto.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Accesorio actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Accessory'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida: el nombre o el código enviados ya los ocupa OTRO accesorio (Ya existe un accesorio con ese nombre / Ya existe un accesorio con ese código). ATENCIÓN — por HTTP este 400 NO SE ALCANZA: las reglas unique del FormRequest, que ya ignoran la propia fila, cazan los dos duplicados antes y responden 422 con esos mismos mensajes. El service repite la comprobación para que una llamada directa dé un error de negocio y no un 500 contra el índice único. Se documenta por completitud del contrato.',
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
                description: 'No existe ninguna fila con ese id. Un accesorio dado de baja NO cae aquí: se puede editar y reactivar con normalidad. El mensaje devuelto es: El accesorio no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'El name ya lo usa otro accesorio (Ya existe un accesorio con ese nombre), no es texto o supera los 255 caracteres; el code ya lo usa otro accesorio —INCLUIDO UNO DADO DE BAJA, porque un inactive no libera su código— (Ya existe un accesorio con ese código), no es texto o supera los 255 caracteres; el price no es numérico, es 0 o supera el máximo (El precio debe ser mayor a 0 / El precio no puede superar 99999999.99); la purchaseDate no es una fecha o es futura (La fecha de compra no puede ser futura); la annualDepreciation no es numérica o se sale de [0, 100]; la descripción no es texto o supera los 1000 caracteres; o el status no es uno de los tres valores del enum (El estado debe ser active, inactive o under_repair). Un cuerpo vacío NO produce 422, y reenviar el propio nombre, el propio código o el propio estado tampoco.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateAccessoryRequest $request, int $accessory, AccessoryServiceInterface $accessoryService)
    {
        try {
            $updated = $accessoryService->updateAccessory($request->validated(), $accessory);

            return ResponseHandler::success(new AccessoryResource($updated), 'Accesorio actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/accessories/{accessory}',
        operationId: 'destroyAccessory',
        summary: 'Dar de baja un accesorio',
        description: <<<'TEXT'
        ATENCIÓN — este DELETE NO BORRA NADA: es una BAJA LÓGICA que se limita a poner status en "inactive". La fila sigue existiendo en la base, GET /api/accessories/{accessory} la sigue devolviendo con 200 y SIGUE APARECIENDO en GET /api/accessories sin filtros —para excluirla hay que pedir status=active—. Es lo contrario del DELETE de FuelPrices, que borra de verdad, y del de FreightRates, que es un soft delete no idempotente. El motivo es que una unidad retirada sigue siendo historial de inventario: su precio de compra y su depreciación son datos contables que no deben desaparecer.

        Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        Es IDEMPOTENTE: repetir la llamada sobre un accesorio ya dado de baja responde 200 LAS DOS VECES y lo deja igual, no 400 ni 404. Dar de baja algo que ya lo está no es un error del cliente, porque el resultado que pidió ya se cumple. Sobre uno en "under_repair" también funciona y lo deja en "inactive": no comprueba el estado anterior.

        Es REVERSIBLE: el accesorio se reactiva con PATCH /api/accessories/{accessory} y status "active". NO existe /toggle-status en este dominio —con tres estados un interruptor no significa nada—, así que el PATCH es la única vía. No hay borrado real por ninguna vía.

        ATENCIÓN — LA BAJA NO LIBERA EL CÓDIGO: a diferencia de la placa de un vehículo, que un status inactive sí libera, el code de un accesorio dado de baja sigue ocupado PARA SIEMPRE, y otra alta que lo pida recibe 422 con "Ya existe un accesorio con ese código". Tampoco libera el name. Si se retira una unidad y entra otra en su lugar, hay que darle un código nuevo.

        El currentValue se sigue calculando y devolviendo en un accesorio dado de baja: la depreciación no se congela con la baja, así que su valor sigue bajando cada día hasta llegar al piso de 0.00.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Accessories'],
        parameters: [
            new OA\Parameter(
                name: 'accessory',
                description: 'Identificador numérico del accesorio (accessories.id). Sigue siendo válido después de la baja: el id nunca desaparece, la fila se sigue consultando y su código sigue ocupado.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 7),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Accesorio dado de baja correctamente. data devuelve la fila ya con status "inactive"; la fila sigue existiendo, sigue siendo consultable por id y sigue apareciendo en el listado sin filtros. Repetir el DELETE vuelve a responder 200 con el mismo cuerpo —salvo el currentValue, que se recalcula—: es idempotente.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Accesorio dado de baja correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/Accessory'),
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
                description: 'No existe ninguna fila con ese id. Un accesorio ya dado de baja NO cae aquí: la baja es idempotente y responde 200. El mensaje devuelto es: El accesorio no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $accessory, AccessoryServiceInterface $accessoryService)
    {
        try {
            $deleted = $accessoryService->deleteAccessory($accessory);

            return ResponseHandler::success(new AccessoryResource($deleted), 'Accesorio dado de baja correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters from the query string.
     *
     * @return array{status: string|null, search: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $this->queryString($request, 'status'),
            'search' => $this->queryString($request, 'search'),
            'limit' => $this->queryString($request, 'limit'),
        ];
    }

    /**
     * Read a query param only when it arrived as a string.
     *
     * An array param (`?status[]=active`) would blow up the tolerant filters, which
     * expect a scalar; treating it as absent keeps the listing answering 200.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
