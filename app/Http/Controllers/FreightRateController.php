<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\FreightRate\QuoteFreightRateRequest;
use App\Http\Requests\FreightRate\StoreFreightRateRequest;
use App\Http\Requests\FreightRate\UpdateFreightRateRequest;
use App\Http\Resources\FreightRate\FreightQuoteResource;
use App\Http\Resources\FreightRate\FreightRateResource;
use App\Interfaces\FreightRate\FreightRateServiceInterface;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'FreightRates',
    description: 'Tarifas de flete por zona, producto y tipo de combustible: cuánto cuesta la libra hasta un punto del mapa. La tarifa NO pertenece a ninguna empresa transportista —es un dato de Legumex, común a todos—, por eso NINGUNA ruta lleva el middleware carrier.required y el recurso no expone carrierId. Los seis endpoints exigen token JWT (Authorization: Bearer {token}) y el reparto de permisos es asimétrico: el LISTADO y la COTIZACIÓN están abiertos a los cuatro roles —administrator, carrier, pilot y manager—, incluso a un carrier que todavía no ha registrado su empresa; el DETALLE por id y toda la ESCRITURA (alta, edición y baja) son exclusivos del administrator (middleware role:administrator) y los otros tres roles reciben 403 — el detalle también, porque quien no administra tarifas cotiza con /quote en vez de leer la fila. UNIDADES FIJAS DEL DOMINIO, no configurables: todo el dinero va en QUETZALES (GTQ), el fuelMin en GTQ POR GALÓN, el pricePerPound en GTQ POR LIBRA con seis decimales, el peso en LIBRAS y el total en GTQ con dos decimales; no hay moneda alternativa, kilos, litros, IVA ni redondeo comercial. BANDA ABIERTA: cada tarifa rige DESDE su fuelMin HACIA ARRIBA hasta que exista otra banda más alta del mismo par, así que la cotización NUNCA falla por el precio del combustible — y, como contrapartida, una banda vieja se sigue aplicando en silencio hasta que alguien cotice una superior. EL PRECIO DEL COMBUSTIBLE NUNCA VIAJA EN LA PETICIÓN: sale siempre del FuelPrice con status active de ese tipo. LA COTIZACIÓN NO PERSISTE NADA: es una consulta pura. Dos rupturas respecto al resto del proyecto: el listado NO PAGINA (no hay limit, ni total, currentPage o lastPage) y el DELETE es un SOFT DELETE REAL Y NO IDEMPOTENTE, donde el segundo intento responde 400 en vez de 404 o 200, sin restore ni toggle-status.',
)]
class FreightRateController extends Controller
{
    #[OA\Get(
        path: '/api/freight-rates',
        operationId: 'indexFreightRates',
        summary: 'Listar tarifas de flete',
        description: <<<'TEXT'
        Devuelve la tabla de precios completa: todas las tarifas vivas, con su zona y su producto ya resueltos por nombre. Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier sin empresa, porque la tarifa es un dato nacional y no está acotada por transportista. Todos ven exactamente las mismas filas.

        ATENCIÓN — ESTE LISTADO NO PAGINA NUNCA, al contrario que Carriers, Vehicles, FuelPrices, Products y Zones. No existe el parámetro limit, no hay PaginatedResource y el sobre NO trae total, currentPage ni lastPage: enviar limit=10 devuelve igualmente la colección entera y no produce error. Es deliberado: la tabla de tarifas se lee como una tabla de precios completa, no como un histórico que se navega.

        El ÚNICO filtro es zoneId, y es TOLERANTE: un zoneId no numérico (por ejemplo zoneId=abc) se ignora en silencio y devuelve el listado completo, sin 422; un zoneId numérico de una zona que no existe devuelve 200 con data vacío, nunca 404. Cualquier otro query param se ignora: no hay filtro por productId ni por fuelType, ni búsqueda, ni rango de fechas, ni orden configurable.

        El orden es fijo: fuelType ASC y, dentro de cada tipo, fuelMin ASC — que es exactamente el orden en que se leen las bandas de un par.

        Las tarifas ELIMINADAS no aparecen: el DELETE es soft delete y saca la fila del listado de verdad, al contrario que la baja lógica de Zones y Products, que las deja visibles con status false.

        Cuidado con el volumen sin filtro: el número de filas es zonas × productos × tipos de combustible × bandas. Con 15 zonas, 40 productos y tres bandas de diésel ya son 1 800 filas en una sola respuesta sin paginar; zoneId es la única mitigación disponible.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['FreightRates'],
        parameters: [
            new OA\Parameter(
                name: 'zoneId',
                description: 'Acota el listado a las tarifas de una zona (zones.id). Es el único filtro que existe, y existe porque las tarifas se pintan dentro de la vista de la zona. Es TOLERANTE: un valor no numérico se ignora sin error y devuelve todas las tarifas; un id numérico sin coincidencias devuelve 200 con data vacío. No comprueba que la zona exista ni que esté activa, así que no produce 404 ni 422 por sí solo.',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', example: 3),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tarifas obtenidas correctamente. Siempre la colección completa —sin metadatos de paginación—, ordenada por fuelType ASC y fuelMin ASC. Una tabla vacía o un zoneId sin coincidencias devuelven 200 con data vacío, nunca 404.',
                content: new OA\JsonContent(ref: '#/components/schemas/FreightRateListResponse'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function index(Request $request, FreightRateServiceInterface $freightRateService)
    {
        try {
            /** Nunca pagina: la tabla de precios se lee entera. */
            $rates = $freightRateService->getFreightRates([
                'zoneId' => $this->queryString($request, 'zoneId'),
            ]);

            return ResponseHandler::success(
                FreightRateResource::collection($rates),
                'Tarifas obtenidas correctamente',
                200,
            );
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/freight-rates/quote',
        operationId: 'quoteFreightRate',
        summary: 'Cotizar un flete hasta un punto',
        description: <<<'TEXT'
        Responde "cuánto cuesta la libra hasta este punto" y, si se envían las libras, cuánto cuesta el flete completo. Es el endpoint que debe usar cualquier pantalla que cotice: evita listar la tabla de precios y elegir la banda a mano.

        Solo exige token: puede llamarlo cualquier usuario autenticado de los cuatro roles, incluido un carrier sin empresa. La ruta está declarada ANTES del recurso, así que /quote nunca se confunde con el id de una tarifa.

        NO PERSISTE NADA. Es una consulta pura: ninguna fila se crea, se edita ni se marca, el número de filas de freight_rates no cambia y la respuesta no es una reserva ni bloquea el precio.

        ATENCIÓN — EL PRECIO DEL COMBUSTIBLE NUNCA VIAJA EN LA PETICIÓN. Aquí solo se manda el TIPO (fuelType); el importe sale SIEMPRE del FuelPrice con status active de ese tipo, y cualquier precio que el cliente incluya en la query se ignora por completo, por ningún nombre. No hay simulación con un precio hipotético.

        Del punto solo se deriva la ZONA que lo contiene, resuelta contra los polígonos activos. No hay distancia real, kilometraje, geocodificación de direcciones ni cálculo de ruta, y no se puede mandar zoneId en vez del punto: resolver la zona es precisamente lo que se le pide al sistema.

        La banda se elige así: de las tarifas VIVAS del par (zona + producto + fuelType), la de mayor fuelMin que sea MENOR O IGUAL que el precio vigente —el límite es inclusivo—; si el combustible vigente está por debajo de TODAS, se aplica la de menor fuelMin. Este paso NUNCA falla. Con bandas desde 28.00 y desde 35.00: diésel a 40 aplica la de 35, a 35 exacto aplica la de 35, a 30 la de 28 y a 25 también la de 28, sin error. La contrapartida es el riesgo más caro del dominio: una banda vieja se sigue aplicando EN SILENCIO —currentFuelPrice 60.00 con appliedFuelMin 28.00 responde 200 con un número que ya no cubre el costo—, y la distancia entre esos dos campos es la ÚNICA señal disponible.

        EL TOTAL AUTORITATIVO ES EL DE LA API: se multiplica por el pricePerPound de seis decimales y se redondea SOLO al final. Recalcularlo en pantalla con la tarifa redondeada a dos decimales da 20 250.00 en vez de 20 435.40 sobre 45 000 libras, 185 quetzales de diferencia en un solo flete.

        A diferencia del listado, aquí NADA es tolerante: lat=200, lng=500, un fuelType fuera del enum o un productId ausente devuelven 422, no se ignoran. Y hay CUATRO fallos de negocio con mensajes distintos entre sí —404 por punto fuera de zona, y tres 400 por producto inactivo, por combustible sin precio vigente y por par sin tarifa—, pensados para que el cliente sepa exactamente qué falta.

        EJEMPLO COMPLETO: 45 000 libras de brócoli con destino en la zona norte y el diésel vigente a 40.00 devuelven currentFuelPrice 40.00, appliedFuelMin 35.00, pricePerPound 0.454120, pounds 45000.00 y total 20435.40.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['FreightRates'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/quoteLatQuery'),
            new OA\Parameter(ref: '#/components/parameters/quoteLngQuery'),
            new OA\Parameter(ref: '#/components/parameters/quoteProductIdQuery'),
            new OA\Parameter(ref: '#/components/parameters/quoteFuelTypeQuery'),
            new OA\Parameter(ref: '#/components/parameters/quotePoundsQuery'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cotización obtenida correctamente. data trae la tarifa aplicada junto con el precio de combustible vigente (currentFuelPrice), la banda elegida (appliedFuelMin) y, solo si se enviaron pounds, el total; sin pounds, esos dos últimos campos viajan null y el resto es idéntico. No se ha creado ni modificado ninguna fila.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Cotización obtenida correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FreightQuote'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Fallo de negocio; son TRES casos con mensajes distintos, comprobados en este orden: el producto existe pero tiene status false (El producto seleccionado no está activo); el tipo de combustible no tiene ninguna fila con status active, aunque haya tarifas cotizadas (No existe un precio vigente para el combustible indicado); o el par zona+producto no tiene ninguna tarifa VIVA de ese fuelType, incluido el caso de que la única que había se eliminó (No existe tarifa cotizada para ese producto en esa zona). Ninguno de los tres se debe a un valor mal formado: eso sería 422.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El punto (lat, lng) no cae dentro de ninguna zona ACTIVA: una zona dada de baja que contuviera el punto no cuenta. Es el único 404 de la cotización, y llega antes que las tres comprobaciones de 400. El mensaje devuelto es: El punto indicado no pertenece a ninguna zona registrada',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Parámetros inválidos de la query. ATENCIÓN — a diferencia del listado de zonas, aquí NADA se ignora: falta lat, lng, productId o fuelType; lat=200 o lng=500 están fuera de rango; fuelType=gasolina no pertenece al enum; el productId no existe en la tabla (El producto seleccionado no existe — un producto que existe pero está inactivo es 400, no 422); o pounds no es numérico, es cero o supera 99999999.99.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function quote(QuoteFreightRateRequest $request, FreightRateServiceInterface $freightRateService)
    {
        try {
            $quote = $freightRateService->quote($request->validated());

            return ResponseHandler::success(new FreightQuoteResource($quote), 'Cotización obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/freight-rates',
        operationId: 'storeFreightRate',
        summary: 'Cotizar una tarifa de flete',
        description: <<<'TEXT'
        Da de alta una banda: el precio por libra de un producto hasta una zona, a partir de cierto precio de combustible. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403, aunque los tres sí puedan listar y cotizar. No lleva carrier.required, porque la tarifa no pertenece a ninguna empresa.

        Los cinco campos del cuerpo son obligatorios. El registeredBy NO se acepta: sale del usuario autenticado y se devuelve resuelto en registeredByName, así que mandar otro en el cuerpo no tiene ningún efecto. Tampoco se manda ningún precio de combustible vigente: la tarifa guarda un fuelMin y no referencia ninguna fila de fuel_prices, de modo que publicar un FuelPrice nuevo no deja obsoleta ninguna tarifa ni obliga a recapturarlas.

        ATENCIÓN — el fuelMin es un MÍNIMO, no un rango: la banda queda ABIERTA hacia arriba hasta que exista otra banda más alta del mismo par. No hay fuelMax y no se pueden dejar huecos. Cotizar una sola banda ya basta para que la cotización responda siempre.

        La unicidad es de la banda entera (zoneId, productId, fuelType, fuelMin) y solo mira las filas VIVAS: repetirla es 400, pero el mismo fuelMin para otro fuelType, otra zona u otro producto se acepta, y tras un DELETE esa misma banda vuelve a estar libre y se puede recotizar con 201.

        El pricePerPound se guarda con SEIS decimales y vuelve íntegro: 0.454120 no se redondea a 0.45 en ningún punto.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreFreightRateRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['FreightRates'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Tarifa registrada correctamente. data trae la fila ya creada, con la zona y el producto resueltos por nombre, el pricePerPound con sus seis decimales intactos, el fuelMin con dos decimales y registeredByName el del administrador autenticado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Tarifa registrada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FreightRate'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Regla de negocio incumplida, nunca un valor mal formado. Tres mensajes posibles: la zona existe pero tiene status false (La zona seleccionada no está activa); el producto existe pero tiene status false (El producto seleccionado no está activo); o ya hay una tarifa VIVA con ese mismo (zoneId, productId, fuelType, fuelMin) (Ya existe una tarifa para esa zona, ese producto y ese combustible desde ese precio). Ojo con la frontera: id inexistente es 422, id existente pero inactivo es 400.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator —un carrier, un pilot o un manager caen aquí, aunque los tres sí puedan listar tarifas y cotizar—. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos del cuerpo. Casos típicos: falta alguno de los cinco campos obligatorios; el zoneId o el productId NO EXISTEN en su tabla (La zona seleccionada no existe / El producto seleccionado no existe) —que una zona o un producto estén inactivos es 400, no 422—; el fuelType no pertenece al enum; el fuelMin no es numérico o sale de [0.01, 999999.99]; o el pricePerPound no es numérico o sale de [0.000001, 999999.999999].',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function store(StoreFreightRateRequest $request, FreightRateServiceInterface $freightRateService)
    {
        try {
            $rate = $freightRateService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new FreightRateResource($rate), 'Tarifa registrada correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/freight-rates/{freightRate}',
        operationId: 'showFreightRate',
        summary: 'Obtener una tarifa de flete por id',
        description: <<<'TEXT'
        Devuelve una tarifa concreta, con su zona, su producto y el nombre del administrador que la capturó.

        Es EXCLUSIVO del rol administrator (middleware role:administrator), a diferencia del detalle de FuelPrices y de Zones, que está abierto: un carrier, un pilot o un manager reciben 403 aunque sí puedan listar tarifas. El motivo es que quien no administra tarifas no necesita la fila: usa GET /api/freight-rates/quote.

        ATENCIÓN — una tarifa ELIMINADA no devuelve 404: devuelve 400 con "La tarifa ya fue eliminada". La fila se resuelve con las borradas a la vista, así que el 404 queda reservado a los ids que NUNCA existieron. Es lo contrario de Zones y Products, donde la baja es lógica y el detalle sigue respondiendo 200.

        No hay ámbito por empresa, así que no existe el 403 por recurso ajeno que sí tienen Vehicles o Carriers: el 403 aquí es siempre por rol.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['FreightRates'],
        parameters: [
            new OA\Parameter(
                name: 'freightRate',
                description: 'Identificador numérico de la tarifa (freight_rates.id). No es la zona ni el producto: no existe consulta por par, para eso está el filtro zoneId del listado, ni consulta por punto, para eso está /quote. El id de una tarifa eliminada sigue siendo un id válido, pero responde 400 en vez de 200.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tarifa obtenida correctamente. Solo se devuelven tarifas VIVAS: una eliminada responde 400.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Tarifa obtenida correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FreightRate'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'La fila existe pero ya fue eliminada (soft delete). No hay restore: si la banda hace falta otra vez, se recotiza con un POST. El mensaje devuelto es: La tarifa ya fue eliminada',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'El rol del usuario autenticado no es administrator. A diferencia del listado y de la cotización, este detalle NO está abierto a carrier, pilot ni manager. El mensaje del middleware role es: No tienes permisos para acceder a este recurso',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'No existe ninguna fila con ese id, ni viva ni eliminada: es un id que nunca existió. Una tarifa eliminada NO cae aquí, cae en el 400. El mensaje devuelto es: La tarifa no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function show(int $freightRate, FreightRateServiceInterface $freightRateService)
    {
        try {
            $rate = $freightRateService->getFreightRateById($freightRate);

            return ResponseHandler::success(new FreightRateResource($rate), 'Tarifa obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Patch(
        path: '/api/freight-rates/{freightRate}',
        operationId: 'updateFreightRate',
        summary: 'Actualizar una tarifa de flete',
        description: <<<'TEXT'
        Corrige la zona, el producto, el tipo de combustible, el fuelMin, el pricePerPound o cualquier combinación de ellos. Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403. La ruta acepta PATCH y PUT indistintamente y en ambos casos el comportamiento es el mismo: el PUT no reemplaza el recurso completo.

        Todos los campos son opcionales y solo se toca lo que venga: un PATCH que solo manda pricePerPound no altera fuelMin, zoneId, productId ni fuelType. Un CUERPO VACÍO responde 200 como NO-OP, devolviendo la tarifa sin cambios, en vez de 422.

        ATENCIÓN — LAS DOS REGLAS DE NEGOCIO MIRAN EL PAR COMPLETO, no solo lo enviado: lo que no llega se toma de la propia fila. Por eso un PATCH sobre una tarifa cuya ZONA O PRODUCTO SE DESACTIVÓ DESPUÉS responde 400 aunque solo cambie el precio: las tarifas de ese par quedan CONGELADAS hasta reactivarlo. Es el comportamiento decidido, no un accidente —editar la tarifa de algo que no se puede cotizar es trabajo perdido— y el mensaje dice cuál de los dos está inactivo. El DELETE, en cambio, SÍ funciona en ese caso: borrar nunca se bloquea.

        La unicidad de la banda se revalida ignorando la propia fila, así que reenviar su mismo fuelMin responde 200 y moverlo a un valor que ya ocupa otra tarifa viva del mismo par responde 400. Moverlo a un valor libre responde 200.

        Sobre una tarifa YA ELIMINADA responde 400 (La tarifa ya fue eliminada), no 404: no hay forma de editar ni de resucitar una fila borrada. Un id que nunca existió sí es 404.

        El registeredBy no se reescribe: sigue apuntando a quien dio de alta la tarifa aunque la edite otro administrador. El createdAt tampoco cambia; el updatedAt sí. Del pricePerPound anterior NO queda ningún rastro: el PATCH sobrescribe sin auditoría.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateFreightRateRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['FreightRates'],
        parameters: [
            new OA\Parameter(
                name: 'freightRate',
                description: 'Identificador numérico de la tarifa (freight_rates.id). Es también la fila que se ignora al revalidar la unicidad de la banda, de modo que reenviar su propio fuelMin no choca consigo misma.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tarifa actualizada correctamente. data trae la fila ya modificada, releída con su zona y su producto resueltos, el mismo registeredByName, el mismo createdAt y el updatedAt refrescado. Un cuerpo vacío también responde 200, con la tarifa intacta.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Tarifa actualizada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FreightRate'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Cuatro mensajes posibles: la tarifa ya fue eliminada (La tarifa ya fue eliminada); la zona del par —enviada o heredada de la fila— tiene status false (La zona seleccionada no está activa); el producto del par tiene status false (El producto seleccionado no está activo); o el nuevo fuelMin ya lo ocupa otra tarifa viva del mismo par (Ya existe una tarifa para esa zona, ese producto y ese combustible desde ese precio). Los dos del medio saltan aunque el cuerpo solo traiga pricePerPound.',
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
                description: 'No existe ninguna fila con ese id, ni viva ni eliminada. Una tarifa eliminada NO cae aquí, cae en el 400. El mensaje devuelto es: La tarifa no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos del cuerpo: el zoneId o el productId no existen en su tabla (La zona seleccionada no existe / El producto seleccionado no existe) —que estén inactivos es 400—; el fuelType no pertenece al enum; el fuelMin no es numérico o sale de [0.01, 999999.99]; o el pricePerPound no es numérico o sale de [0.000001, 999999.999999]. Un cuerpo vacío NO produce 422.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function update(UpdateFreightRateRequest $request, int $freightRate, FreightRateServiceInterface $freightRateService)
    {
        try {
            $rate = $freightRateService->update($freightRate, $request->validated());

            return ResponseHandler::success(new FreightRateResource($rate), 'Tarifa actualizada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Delete(
        path: '/api/freight-rates/{freightRate}',
        operationId: 'destroyFreightRate',
        summary: 'Eliminar una tarifa de flete',
        description: <<<'TEXT'
        ATENCIÓN — este DELETE es un SOFT DELETE REAL: marca deleted_at y la fila DESAPARECE de GET /api/freight-rates y de la cotización de inmediato. Es lo contrario de la baja lógica de Zones y Products, donde la fila sigue apareciendo en el listado con status false. La fila sigue existiendo en base, pero no hay ningún campo status en el recurso y ninguna lectura la devuelve.

        Es EXCLUSIVO del rol administrator (middleware role:administrator): un carrier, un pilot o un manager reciben 403.

        NO ES IDEMPOTENTE, al contrario que el DELETE de Zones y Products: un SEGUNDO DELETE sobre la misma tarifa responde 400 con "La tarifa ya fue eliminada", nunca 200 ni 404. Se considera información útil, no un error del cliente. El 404 queda reservado a los ids que nunca existieron.

        NO HAY RESTORE ni /toggle-status: una tarifa eliminada no se resucita por ninguna vía. Si la banda hace falta otra vez, se recotiza con un POST — y funciona, porque la unicidad solo mira filas vivas y el borrado LIBERA ese (zoneId, productId, fuelType, fuelMin) para volver a usarlo.

        A diferencia del PATCH, este DELETE SÍ funciona sobre una tarifa cuya zona o producto se desactivó después: borrar nunca se bloquea por el estado del par.

        Si era la única tarifa del par, la cotización de ese producto en esa zona pasa a responder 400 (No existe tarifa cotizada para ese producto en esa zona).
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['FreightRates'],
        parameters: [
            new OA\Parameter(
                name: 'freightRate',
                description: 'Identificador numérico de la tarifa (freight_rates.id). Sigue existiendo en base después del borrado, pero deja de ser utilizable: cualquier GET, PATCH o DELETE posterior sobre él responde 400.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer', example: 1),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tarifa eliminada correctamente. data devuelve la fila tal como quedó, ya marcada como eliminada —el recurso no expone deleted_at—, para que el cliente confirme qué banda quitó sin releer el listado. A partir de aquí la tarifa no aparece en GET /api/freight-rates ni participa en ninguna cotización.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Tarifa eliminada correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/FreightRate'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'La tarifa ya estaba eliminada: es el SEGUNDO DELETE, que aquí NO es idempotente y no responde 200 ni 404. El mensaje devuelto es: La tarifa ya fue eliminada',
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
                description: 'No existe ninguna fila con ese id, ni viva ni eliminada: es un id que nunca existió. Repetir el DELETE sobre una tarifa ya borrada NO cae aquí, cae en el 400. El mensaje devuelto es: La tarifa no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function destroy(int $freightRate, FreightRateServiceInterface $freightRateService)
    {
        try {
            $deleted = $freightRateService->destroy($freightRate);

            return ResponseHandler::success(new FreightRateResource($deleted), 'Tarifa eliminada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read a query parameter only when it arrived as a string.
     *
     * An array in the query string —zoneId[]=1— would otherwise reach the service as an
     * array and blow up a comparison that expects a scalar.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
