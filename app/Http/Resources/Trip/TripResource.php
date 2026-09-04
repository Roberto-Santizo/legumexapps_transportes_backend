<?php

namespace App\Http\Resources\Trip;

use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Services\Place\PolylineDecoder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * The trip as the API paints it: 34 keys in camelCase, the largest resource of the
 * project.
 *
 * The six relations go out **flat**, as an id plus its name side by side, never as a
 * nested object: `clientId` + `clientName`, `vehicleId` + `vehiclePlate`, and so on.
 * The vehicle takes a third key, `vehicleImage`, because the frontend paints the truck
 * next to the trip, and since SPEC 25 the pilot takes two more, `pilotDpiImage` and
 * `pilotLicenseImage`, prefixed because four entities live side by side in here. The service loads the eight of them
 * with `with()`, so painting a page of a hundred costs no extra query.
 *
 * The four dates use the project's own `d-m-Y h:i:s A` format —day-month-year with a
 * 12 hour clock and AM/PM—, not ISO 8601: parsing them as ISO fails. `startDate` and
 * `endDate` stay null until the pilot actually starts and closes the trip.
 *
 * `deletedAt` is null on six of the seven endpoints that paint it, because none of them
 * can reach a deleted trip. The exception is the response of the DELETE itself, which
 * paints the row that was just soft deleted.
 */
#[OA\Schema(
    schema: 'Trip',
    title: 'Viaje de exportación',
    description: <<<'TEXT'
    El viaje que enlaza cliente, naviera, punto de partida y puerto de destino. Son 32 CLAVES en camelCase —el recurso más grande del proyecto— y salen con la misma forma en SIETE de los ocho endpoints del dominio: el detalle, el alta, la edición, la baja, /assignment, /start y /finish. EL LISTADO NO USA ESTE ESQUEMA: GET /api/trips devuelve TripListItem, con solo 15 claves.

    ATENCIÓN — LAS SEIS RELACIONES SALEN PLANAS, NUNCA ANIDADAS: cada una es un par id + nombre puestos uno al lado del otro (clientId/clientName, shippingLineId/shippingLineName, departurePointId/departurePointName, locationId/locationName, pilotId/pilotName, vehicleId/vehiclePlate, assignedById/assignedByName), y de quien registró el viaje solo sale el nombre (registeredByName), sin id. DOS relaciones salen con CUATRO y TRES claves respectivamente: el piloto con pilotId, pilotName, pilotDpiImage y pilotLicenseImage, y el vehículo con vehicleId, vehiclePlate y vehicleImage. No hay objetos anidados: si se necesita el detalle completo de un cliente o de un vehículo hay que pedirlo a su propio dominio.

    ATENCIÓN — LAS SIETE FECHAS NO VIAJAN EN ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM), igual que en Clients, Locations, Departure Points y Shipping Lines. Por eso recolectionDate, shipDate, startDate, endDate, createdAt, updatedAt y deletedAt se documentan como string SIN format date-time: parsearlas como ISO falla.

    ATENCIÓN — EL status PUEDE CONTRADECIR A LAS FECHAS, Y EL RELATO LO CUENTAN LAS FECHAS. No hay máquina de estados: el PATCH del administrador acepta los tres valores sin comprobar el orden ni tocar las fechas, así que existen viajes finished sin startDate y viajes pending con startDate y endDate puestos (un finished devuelto a pending conserva sus dos fechas de ejecución). Cuando status y fechas se contradigan, el frontend debe pintar el relato desde startDate y endDate, no desde status.

    ATENCIÓN — EL ÁMBITO DE LECTURA ES ADQUIRIDO, NO HEREDADO. Un viaje NO tiene carrierId: nace de nadie y no hay columna de empresa dueña. La empresa entra en escena cuando toma el viaje, y el vínculo se deriva siempre de assignedById. Por eso administrator y manager ven todos los viajes; un carrier ve la bolsa —los pending con pilotId y vehicleId en null— más los asignados por su propia empresa; y un pilot ve SOLO aquellos donde pilotId es él, sin la bolsa.

    points es un CAMPO CALCULADO EN LECTURA, sin columna, sin job y sin caché: se decodifica de polyline en cada respuesta. La polilínea la manda el frontend y la API NUNCA LA RECALCULA.

    Las 34 claves salen siempre en este orden: id, order, status, clientId, clientName, shippingLineId, shippingLineName, departurePointId, departurePointName, locationId, locationName, destination, container, transport, recolectionDate, shipDate, startDate, endDate, polyline, points, observations, pilotId, pilotName, pilotDpiImage, pilotLicenseImage, vehicleId, vehiclePlate, vehicleImage, assignedById, assignedByName, registeredByName, createdAt, updatedAt y deletedAt.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico del viaje (trips.id). Es el valor que va en el parámetro {trip} de las seis rutas que lo nombran: detalle, actualización, baja, /assignment, /start y /finish. Sobrevive a la edición y a la reasignación, y sobrevive también al DELETE —la fila sigue en la base con su deleted_at puesto—, pero ese id deja de ser alcanzable: el GET responde 404 y las cinco rutas de escritura responden 400 «El viaje ya fue eliminado».',
            type: 'integer',
            example: 1,
        ),
        new OA\Property(
            property: 'order',
            description: 'Referencia comercial del viaje, SIEMPRE EN MAYÚSCULAS y con los espacios interiores COLAPSADOS a uno: enviar "  ord-2026   0148  " guarda y devuelve "ORD-2026 0148". ATENCIÓN — NO ES ÚNICA: dos viajes pueden compartir la misma orden y no hay ni 400 ni 422 por repetirla, al contrario que el code o el name de los catálogos. Es uno de los dos campos que barre el filtro search del listado.',
            type: 'string',
            maxLength: 255,
            example: 'ORD-2026-0148',
        ),
        new OA\Property(
            property: 'status',
            description: 'Estado del viaje, con el VALOR CRUDO DEL ENUM EN INGLÉS y sin traducir —traducirlo es cosa del frontend—. Solo hay TRES valores y NO existe cancelled: un viaje que no se hará se borra. Nace siempre en pending; /start lo lleva a in_route y /finish a finished. ATENCIÓN — NO HAY MÁQUINA DE ESTADOS: el PATCH del administrador acepta los tres valores sin comprobar el orden ni tocar las fechas, así que un finished puede volver a pending conservando startDate y endDate. Cuando el estado contradiga a las fechas, el relato lo cuentan las fechas.',
            type: 'string',
            enum: ['pending', 'in_route', 'finished'],
            example: 'pending',
        ),
        new OA\Property(
            property: 'clientId',
            description: 'Id del cliente exportador (clients.id, SPEC 22). Obligatorio en el alta y editable por el PATCH del administrador. ATENCIÓN — este dominio es el PRIMER CONSUMIDOR de Clients, y por eso DELETE /api/clients/{client} responde ahora 400 «No se puede eliminar el cliente porque tiene viajes asociados» cuando el cliente tiene viajes, INCLUIDOS LOS BORRADOS. Un cliente ya borrado tampoco puede apuntarse desde aquí: el exists: de Laravel no ve el borrado lógico y lo deja pasar, pero el service lo para con 400.',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'clientName',
            description: 'Razón social del cliente, resuelta desde la relación y SIEMPRE EN MAYÚSCULAS, tal como la guarda su propio catálogo. Sale plana, no dentro de un objeto client.',
            type: 'string',
            nullable: true,
            example: 'AGROEXPORTADORA DEL SUR',
        ),
        new OA\Property(
            property: 'shippingLineId',
            description: 'Id de la naviera (shipping_lines.id, SPEC 23). Obligatorio en el alta y editable por el PATCH. ATENCIÓN — igual que con el cliente, DELETE /api/shipping-lines/{shippingLine} responde ahora 400 «No se puede eliminar la naviera porque tiene viajes asociados» cuando tiene viajes, incluidos los borrados. Una naviera ya borrada pasa el exists: y la para el service con 400.',
            type: 'integer',
            example: 2,
        ),
        new OA\Property(
            property: 'shippingLineName',
            description: 'Nombre de la naviera, resuelto desde la relación y siempre en mayúsculas. Sale plano, no dentro de un objeto shippingLine.',
            type: 'string',
            nullable: true,
            example: 'MAERSK LINE',
        ),
        new OA\Property(
            property: 'departurePointId',
            description: 'Id del punto de partida (departure_points.id, SPEC 20), desde donde se recoge la carga. Debe estar ACTIVO al crear y en cada PATCH; si no, 400 «El punto de partida está inactivo».',
            type: 'integer',
            example: 5,
        ),
        new OA\Property(
            property: 'departurePointName',
            description: 'Nombre del punto de partida, resuelto desde la relación y siempre en mayúsculas. Sale plano, no anidado.',
            type: 'string',
            nullable: true,
            example: 'PLANTA SAN JUAN',
        ),
        new OA\Property(
            property: 'locationId',
            description: 'Id del PUERTO de destino (locations.id, SPEC 15 y SPEC 21). ATENCIÓN — no vale cualquier destino: la location tiene que ser de tipo port y estar activa. Un destino ordinario responde 400 «El destino seleccionado no es un puerto» y un puerto inactivo, 400 «El puerto de destino está inactivo». La regla vive en el service y no en el esquema, porque SPEC 21 dejó el type libremente editable: un puerto puede dejar de serlo en cualquier momento y los viajes viejos se quedan como están.',
            type: 'integer',
            example: 9,
        ),
        new OA\Property(
            property: 'locationName',
            description: 'Nombre del puerto de destino, resuelto desde la relación y siempre en mayúsculas. Sale plano, no anidado, y no vienen ni sus coordenadas ni su googlePlaceId: para eso está GET /api/locations/{location}.',
            type: 'string',
            nullable: true,
            example: 'PUERTO QUETZAL',
        ),
        new OA\Property(
            property: 'destination',
            description: 'Destino final en el extranjero. ATENCIÓN — ES TEXTO LIBRE Y NO LO RESPALDA NINGÚN CATÁLOGO: no es una location, no tiene id y no se puede filtrar por él. Se guarda TAL COMO SE TECLEA, con solo un trim: conserva mayúsculas, minúsculas y los espacios interiores, al contrario que order y container. Es la asimetría de normalización del dominio.',
            type: 'string',
            maxLength: 255,
            example: 'Rotterdam, Países Bajos',
        ),
        new OA\Property(
            property: 'container',
            description: 'Identificación del contenedor, SIEMPRE EN MAYÚSCULAS y con los espacios interiores COLAPSADOS a uno, igual que order. ATENCIÓN — NO ES ÚNICO: dos viajes pueden compartir el mismo contenedor, y también la misma orden, sin ningún error. Es el otro campo que barre el filtro search del listado.',
            type: 'string',
            maxLength: 255,
            example: 'MSKU 483920 1',
        ),
        new OA\Property(
            property: 'transport',
            description: 'Medio o empresa de transporte, tal como se teclea: solo trim, sin mayúsculas ni colapso de espacios. Es texto descriptivo y NO está relacionado con el vehículo asignado —vehicleId y vehiclePlate son otra cosa, y los escribe el transportista en /assignment—.',
            type: 'string',
            maxLength: 255,
            example: 'Rastra 40 pies',
        ),
        new OA\Property(
            property: 'recolectionDate',
            description: 'Fecha y hora PLANIFICADAS de recolección de la carga. Es la columna por la que ordena el listado (recolection_date DESC) y la que miran los filtros dateFrom y dateTo. En el alta tiene que ser FUTURA; en el PATCH ya no, porque editar un viaje arrancado no puede obligar a reprogramarlo. ATENCIÓN — formato propio d-m-Y h:i:s A, NO ISO 8601, por eso se documenta como string sin format date-time.',
            type: 'string',
            example: '02-09-2026 06:00:00 AM',
        ),
        new OA\Property(
            property: 'shipDate',
            description: 'Fecha y hora PLANIFICADAS de embarque. Nunca anterior a recolectionDate —y en el alta, además, futura—. Es una fecha prevista, no una marca de ejecución: no la mueven ni /start ni /finish. Mismo formato propio d-m-Y h:i:s A, tampoco ISO 8601.',
            type: 'string',
            example: '04-09-2026 11:30:00 PM',
        ),
        new OA\Property(
            property: 'startDate',
            description: 'Marca de EJECUCIÓN REAL del arranque. Es null hasta que el piloto asignado llama a PATCH /api/trips/{trip}/start, y entonces la pone el now() DEL SERVIDOR: no se acepta en ningún cuerpo, ni en el alta, ni en el PATCH, ni en la propia /start. ATENCIÓN — el PATCH del administrador no la borra ni la mueve, así que un viaje devuelto a pending la conserva. Mismo formato propio d-m-Y h:i:s A.',
            type: 'string',
            nullable: true,
            example: '02-09-2026 06:12:44 AM',
        ),
        new OA\Property(
            property: 'endDate',
            description: 'Marca de EJECUCIÓN REAL del cierre. Es null hasta que el piloto asignado llama a PATCH /api/trips/{trip}/finish, y la pone el now() del servidor. Nunca puede existir sin startDate: /finish sobre un viaje sin arrancar responde 400 «El viaje no ha sido iniciado». Tampoco la borra el PATCH del administrador. Mismo formato propio d-m-Y h:i:s A.',
            type: 'string',
            nullable: true,
            example: '05-09-2026 02:30:10 PM',
        ),
        new OA\Property(
            property: 'polyline',
            description: 'Polilínea codificada de Google con la ruta prevista del viaje, TAL COMO LA MANDÓ EL FRONTEND. ATENCIÓN — LA API NUNCA LLAMA A GOOGLE Y NUNCA LA RECALCULA: la resuelve el front con GET /api/places/directions (SPEC 16) y la envía en el alta y en cada PATCH, donde es obligatoria. Si un PATCH cambia locationId o departurePointId y no remanda la polilínea, la guardada queda OBSOLETA, el mapa dibuja una ruta que ya no corresponde y la API no avisa de nada. Es la ruta prevista, no seguimiento en tiempo real: no hay posición del vehículo.',
            type: 'string',
            example: 'ynzmDbpb_Ln@bAtEsC',
        ),
        new OA\Property(
            property: 'points',
            description: 'Los pares [lat, lng] decodificados de polyline. ATENCIÓN — ES UN CAMPO CALCULADO EN LECTURA, sin columna, sin job y sin caché: se decodifica en cada respuesta, también en cada elemento del listado. Coincide exactamente con los points que devuelve GET /api/places/directions para la misma cadena. Si polyline queda obsoleta tras un PATCH, points queda obsoleto con ella.',
            type: 'array',
            items: new OA\Items(type: 'array', items: new OA\Items(type: 'number', format: 'float')),
            example: [[28.64893, -68.17554], [28.64869, -68.17588], [28.64762, -68.17514]],
        ),
        new OA\Property(
            property: 'observations',
            description: 'Instrucciones del viaje. ES OBLIGATORIO a propósito, aunque sea un campo de notas: si el alta la hace el administrador y el viaje lo ejecuta otra empresa, las observaciones son el ÚNICO CANAL DE INSTRUCCIONES que existe en el dominio. Se guarda tal como se teclea, con solo trim: conserva mayúsculas, minúsculas y saltos de línea.',
            type: 'string',
            example: 'Carga refrigerada a -2 °C.',
        ),
        new OA\Property(
            property: 'pilotId',
            description: 'Id del usuario con rol pilot que conduce el viaje (users.id). Es null mientras nadie ha tomado el viaje —eso es la bolsa— y solo lo escribe PATCH /api/trips/{trip}/assignment, que es EXCLUSIVA DEL carrier: ni el administrador ni el PATCH general pueden ponerlo, y mandarlo en el PATCH se ignora en silencio con 200. ATENCIÓN — UNA VEZ PUESTO NO VUELVE NUNCA A null: no existe la desasignación. Se puede CAMBIAR, pero solo mientras el viaje siga pending y solo desde la empresa que asignó. Es también el campo que define el ámbito del piloto: solo ve los viajes donde este id es el suyo.',
            type: 'integer',
            nullable: true,
            example: 12,
        ),
        new OA\Property(
            property: 'pilotName',
            description: 'Nombre del piloto asignado, resuelto desde la relación. null mientras el viaje siga en la bolsa. Sale plano, no dentro de un objeto pilot.',
            type: 'string',
            nullable: true,
            example: 'Carlos Ramírez',
        ),
        new OA\Property(
            property: 'pilotDpiImage',
            description: 'URL pública y permanente de la foto del ANVERSO del DPI del piloto asignado, lista para usar como src. Va PREFIJADA con «pilot» igual que pilotId y pilotName porque aquí conviven cuatro entidades y un dpiImage suelto no diría de quién es. ATENCIÓN — HAY DOS MOTIVOS DISTINTOS PARA QUE VENGA null y el frontend no los distingue desde aquí: que el viaje siga en la bolsa (entonces pilotId y pilotName también son null) o que el piloto se registrara antes de SPEC 25 (entonces pilotId y pilotName sí traen valor). El viaje NO guarda copia del documento: la URL se resuelve desde el piloto, así que es la misma que devuelve GET /api/pilots. NO SALE EN EL LISTADO: TripListItem sigue con sus 15 claves.',
            type: 'string',
            nullable: true,
            example: 'https://bucket.s3.amazonaws.com/pilot-documents/9f3a2c1d-8b4e-4a70-9c21-5d6e7f801a2b.jpg',
        ),
        new OA\Property(
            property: 'pilotLicenseImage',
            description: 'URL pública y permanente de la foto del ANVERSO de la licencia del piloto asignado, con las mismas reglas que pilotDpiImage: las dos vienen siempre juntas o las dos en null, porque no existe una fila de documentos a medias. La API no sabe si la licencia está caducada: no se guarda ninguna fecha de vencimiento, y que el piloto no tenga documentos NO impide asignarle el viaje ni arrancarlo.',
            type: 'string',
            nullable: true,
            example: 'https://bucket.s3.amazonaws.com/pilot-documents/1c07f4d9-2e35-4b18-8a6f-3b9c0d1e2f34.png',
        ),
        new OA\Property(
            property: 'vehicleId',
            description: 'Id del vehículo asignado (vehicles.id). Mismas reglas que pilotId: null en la bolsa, lo escribe solo /assignment desde un carrier, y una vez puesto no vuelve a null. El vehículo tiene que estar active al asignarse —inactive y under_repair se rechazan con 400— y pertenecer a la MISMA EMPRESA que el piloto. Lo que le ocurra después al vehículo (que se desactive, por ejemplo) no toca al viaje ya asignado.',
            type: 'integer',
            nullable: true,
            example: 8,
        ),
        new OA\Property(
            property: 'vehiclePlate',
            description: 'PLACA del vehículo asignado, no su nombre: es el único par de relación cuyo segundo campo no se llama «Name». Viene siempre en mayúsculas, tal como la guarda Vehicles. null mientras el viaje siga en la bolsa.',
            type: 'string',
            nullable: true,
            example: 'P-1234ABC',
        ),
        new OA\Property(
            property: 'vehicleImage',
            description: 'URL pública y permanente de la imagen del vehículo asignado, lista para usar como src, resuelta desde la relación igual que en GET /api/vehicles/{vehicle}. Es el ÚNICO CASO DEL RECURSO EN QUE UNA RELACIÓN SALE CON TRES CLAVES —vehicleId, vehiclePlate y vehicleImage—, y sigue siendo plana: no hay ningún objeto vehicle anidado. La imagen es siempre un cuadrado de 800x800 px recortado desde el centro, en el formato original (jpg o png). ATENCIÓN — HAY DOS MOTIVOS DISTINTOS PARA QUE VENGA null y el frontend no los distingue desde aquí: que el viaje siga en la bolsa (entonces vehicleId y vehiclePlate también son null) o que el vehículo asignado no tenga imagen (entonces vehicleId y vehiclePlate sí traen valor). No es la clave interna del objeto: el cliente no debe derivarla ni componerla a mano. NO SALE EN EL LISTADO: TripListItem sigue con sus 15 claves y del vehículo solo pinta vehiclePlate.',
            type: 'string',
            nullable: true,
            example: 'https://bucket.s3.amazonaws.com/vehicles/9f1c2b7a-3d4e-4f10-9a2b-7c8d5e6f0a1b.png',
        ),
        new OA\Property(
            property: 'assignedById',
            description: 'Id del USUARIO que tomó el viaje (users.id), no de su empresa: la tabla no tiene carrier_id y el vínculo con la empresa se deriva siempre de aquí. ATENCIÓN — ES LA CLAVE DEL ÁMBITO ADQUIRIDO: se guarda el usuario para conservar la auditoría, pero TODAS las comprobaciones de ámbito comparan la EMPRESA de ese usuario, de modo que cualquier compañero de esa empresa ve y puede reasignar el viaje, y el viaje no queda huérfano de vista si quien lo tomó se va. Sale del usuario autenticado en /assignment, nunca del cuerpo, y el PATCH del administrador NO lo reescribe.',
            type: 'integer',
            nullable: true,
            example: 4,
        ),
        new OA\Property(
            property: 'assignedByName',
            description: 'Nombre del usuario que tomó el viaje, resuelto desde la relación. null mientras el viaje siga en la bolsa. No trae el nombre de la empresa: para eso están los endpoints de Carriers.',
            type: 'string',
            nullable: true,
            example: 'María López',
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del administrador que dio de alta el viaje. ATENCIÓN — de este autor SOLO SALE EL NOMBRE: no hay registeredById, al contrario que con assignedBy. No se envía en el cuerpo —sale siempre del usuario autenticado— y el PATCH NO lo reescribe, así que sigue apuntando a quien creó la fila aunque la haya editado otro administrador. No hay bitácora de cambios: lo más cercano a una auditoría es updatedAt.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de alta del viaje. Formato propio d-m-Y h:i:s A, NO ISO 8601, por eso se documenta como string sin format date-time. No cambia nunca y no tiene nada que ver con recolectionDate.',
            type: 'string',
            nullable: true,
            example: '27-08-2026 09:14:03 AM',
        ),
        new OA\Property(
            property: 'updatedAt',
            description: 'Fecha del último cambio de la fila, sea del PATCH del administrador, de la asignación, del /start o del /finish. Es lo más cercano a una auditoría que ofrece el dominio: no se guarda ningún valor anterior, ni quién movió el status, ni el historial de asignaciones. Un PATCH con el cuerpo vacío es un no-op y NO la mueve. Mismo formato propio d-m-Y h:i:s A.',
            type: 'string',
            nullable: true,
            example: '27-08-2026 10:02:51 AM',
        ),
        new OA\Property(
            property: 'deletedAt',
            description: 'Fecha del borrado lógico del viaje. ATENCIÓN — ES null EN SIETE DE LOS OCHO ENDPOINTS, porque ninguno de los otros alcanza un viaje borrado: LA ÚNICA RESPUESTA QUE LO DEVUELVE CON VALOR ES LA DEL PROPIO DELETE, que pinta la fila recién borrada. No sirve para descubrir viajes eliminados: el listado los excluye siempre y NO EXISTE NINGÚN PARÁMETRO —ni withTrashed, ni onlyTrashed, ni status— que los devuelva, ni endpoint /restore. Mismo formato propio d-m-Y h:i:s A.',
            type: 'string',
            nullable: true,
            example: '27-08-2026 11:40:22 AM',
        ),
    ],
    type: 'object',
)]
class TripResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `points` is the second computed read-only field of the project, after the
     * `currentValue` of an accessory: it is decoded from `polyline` on every read, with
     * no column, no job and no cache. Storing the decoded pairs would be the same data
     * twice, free to fall out of sync with the string it came from.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order' => $this->order,
            /** El valor crudo del enum, en inglés: traducirlo es cosa del frontend. */
            'status' => $this->status?->value,
            'clientId' => $this->client_id,
            'clientName' => $this->client?->name,
            'shippingLineId' => $this->shipping_line_id,
            'shippingLineName' => $this->shippingLine?->name,
            'departurePointId' => $this->departure_point_id,
            'departurePointName' => $this->departurePoint?->name,
            'locationId' => $this->location_id,
            'locationName' => $this->location?->name,
            'destination' => $this->destination,
            'container' => $this->container,
            'transport' => $this->transport,
            'recolectionDate' => $this->recolection_date?->format('d-m-Y h:i:s A'),
            'shipDate' => $this->ship_date?->format('d-m-Y h:i:s A'),
            'startDate' => $this->start_date?->format('d-m-Y h:i:s A'),
            'endDate' => $this->end_date?->format('d-m-Y h:i:s A'),
            'polyline' => $this->polyline,
            /** Los mismos pares que devuelve GET /api/places/directions para esta cadena. */
            'points' => PolylineDecoder::decode($this->polyline),
            'observations' => $this->observations,
            'pilotId' => $this->pilot_id,
            'pilotName' => $this->pilot?->name,
            /**
             * Las dos fotos del piloto, prefijadas porque el recurso mezcla cuatro entidades.
             * Se resuelven desde la relación, igual que `vehicleImage`: el viaje no guarda
             * copia de nada. Null en la bolsa y null si el piloto es anterior a SPEC 25.
             */
            'pilotDpiImage' => app(FileStorageServiceInterface::class)->url($this->pilot?->pilotDocument?->dpi_image),
            'pilotLicenseImage' => app(FileStorageServiceInterface::class)->url($this->pilot?->pilotDocument?->license_image),
            'vehicleId' => $this->vehicle_id,
            'vehiclePlate' => $this->vehicle?->plate,
            /**
             * La key guardada resuelta a URL absoluta, como en VehicleResource: localización
             * de servicio consciente, porque un JsonResource se instancia con `new`.
             */
            'vehicleImage' => app(FileStorageServiceInterface::class)->url($this->vehicle?->image),
            'assignedById' => $this->assigned_by,
            'assignedByName' => $this->assignedBy?->name,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
            'deletedAt' => $this->deleted_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
