# SPEC 24 — Viajes de exportación

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 03, SPEC 04, SPEC 15, SPEC 16, SPEC 20, SPEC 21, SPEC 22, SPEC 23
> **Fecha:** 2026-08-27
> **Objetivo:** Publicar el dominio `Trip` —el viaje que enlaza cliente, naviera, punto de partida y puerto de destino—, con alta y edición exclusivas del administrador, asignación de piloto y vehículo por el transportista, arranque y cierre por el piloto asignado, y un ámbito de lectura derivado de quién asignó.

Es la spec con **más dependencias del proyecto**: consume ocho dominios ya publicados y es la **primera tabla que apunta a `clients` y a `shipping_lines`**, dos catálogos que SPEC 22 y SPEC 23 publicaron declarando explícitamente que nada colgaba de ellos.

Rompe tres patrones del proyecto a la vez, y por eso conviene tenerlos delante desde el principio:

1. **El `PATCH` deja de ser el único camino de escritura.** Tres rutas fijas de acción —`/assignment`, `/start`, `/finish`— cada una con su rol, su guarda y su efecto sobre `status`. Hasta hoy solo existían `toggle-status` y `deactivate`, que no cambiaban de dueño.
2. **El ámbito de lectura no lo da la empresa dueña del recurso, sino quién actuó sobre él.** Un viaje no tiene `carrier_id`: nace de nadie, se hace visible para una empresa cuando esa empresa lo toma, y para el piloto solo cuando queda asignado a él. Es el primer dominio del proyecto con ámbito **adquirido**, no heredado.
3. **Modifica el `DELETE` de dos dominios publicados.** Borrar un cliente o una naviera con viajes colgando pasa a responder 400. Es la primera vez que una spec cambia el comportamiento de otra ya cerrada.

**Lo que esta spec no es:** un módulo de logística. No hay ruta en tiempo real, no hay costos, no hay documentos adjuntos, no hay notificaciones y no hay máquina de estados que impida retroceder.

---

## Alcance

**Dentro:**

- **Tabla nueva `trips`** con 19 columnas de negocio y relación, `timestamps` y `deleted_at`. Es la tabla con **más claves foráneas del proyecto**: `client_id`, `shipping_line_id`, `departure_point_id`, `location_id`, `pilot_id`, `vehicle_id`, `assigned_by` y `registered_by`.
- **Enum nuevo `App\Enums\TripStatus`** con **tres** casos: `pending`, `in_route`, `finished`. Sin `cancelled`.
- **Dominio `Trip` completo** en su subcarpeta, con la cadena de capas del proyecto: `TripServiceInterface`, `TripService`, `TripProvider`, `StoreTripRequest`, `UpdateTripRequest`, `AssignTripRequest`, `TripResource` y `TripController`.
- **Ocho rutas bajo `/api/trips`**, con las tres fijas **antes** del `apiResource('/')->parameters(['' => 'trip'])`:

  | Ruta | Rol | Efecto |
  |---|---|---|
  | `PATCH /{trip}/assignment` | `carrier` + `carrier.required` | Fija `pilot_id`, `vehicle_id` y `assigned_by` |
  | `PATCH /{trip}/start` | `pilot` asignado | `start_date = now()`, `status = in_route` |
  | `PATCH /{trip}/finish` | `pilot` asignado | `end_date = now()`, `status = finished` |
  | `GET /` | cualquier autenticado | Listado, acotado por ámbito |
  | `POST /` | `administrator` | Alta |
  | `GET /{trip}` | cualquier autenticado | Detalle, 403 fuera de ámbito |
  | `PATCH /{trip}` | `administrator` | Edición general |
  | `DELETE /{trip}` | `administrator` | Baja lógica |

- **Ámbito de lectura por rol**, aplicado igual en `index` y en `show`:
  - `administrator` y `manager`: **todos** los viajes.
  - `carrier`: los `pending` **sin piloto ni vehículo** (la bolsa de viajes disponibles) **más** los asignados por su propia empresa.
  - `pilot`: **solo** aquellos donde `pilot_id` es él. No ve la bolsa.
- **Ámbito adquirido por empresa, guardado por usuario.** `assigned_by` almacena el **id del usuario** que asignó; el filtro compara la **empresa** de ese usuario contra la del que consulta (`User::currentCarrier()`), de modo que un viaje no queda huérfano de vista si su asignador se da de baja. La misma regla gobierna la reasignación: puede tocarlo cualquier usuario de la empresa que asignó, no solo la persona exacta.
- **`status` nace en `pending`** y **no se acepta en el `POST`**. Se mueve por dos vías: automáticamente en `/start` y `/finish`, o a mano en el `PATCH` general, que solo alcanza el `administrator`.
- **`SoftDeletes`**, cuarto dominio del proyecto que lo usa tras `FreightRate`, `Client` y `ShippingLine`, con el mismo contrato: el listado y el `show` excluyen las borradas, y `PATCH` o `DELETE` sobre una borrada responden **400 «El viaje ya fue eliminado»**.
- **Validaciones cruzadas al escribir**, cada una con su 400 y su mensaje en español:
  - `location_id` debe ser de tipo **`port`** y tener `status = true`.
  - `departure_point_id` debe tener `status = true`.
  - `client_id` y `shipping_line_id` **no pueden estar borrados** (`SoftDeletes` de SPEC 22 y 23).
  - `pilot_id` debe ser un usuario con rol **`pilot`** vinculado a una empresa.
  - `vehicle_id` debe tener `status = active`; `inactive` y `under_repair` se rechazan.
  - Piloto y vehículo deben ser de **la misma empresa**.
  - `ship_date >= recolection_date`, y ambas **en el futuro** respecto al momento del alta.
- **Cambio incompatible sobre SPEC 22 y SPEC 23:** `ClientService::destroy()` y `ShippingLineService::destroy()` ganan una guarda que responde **400** si el cliente o la naviera tienen viajes (incluidos los borrados). Es la única modificación a dominios publicados.
- **Normalización asimétrica**, con el precedente de SPEC 18: `order` y `container` van a **MAYÚSCULAS** con espacios colapsados; `destination`, `transport` y `observations` llevan **solo `trim`**, tal como se teclean.
- **`polyline` obligatoria** en el alta **y** en el `PATCH` general. La manda el frontend, resuelta con `GET /api/places/directions` (SPEC 16); **la API nunca llama a Google**, igual que `Location` con su `googlePlaceId`.
- **`TripResource` con ids planos más su nombre al lado** (`clientId` + `clientName`, `vehicleId` + `vehiclePlate`, …), más `polyline` y `points` decodificados con el `PolylineDecoder` que ya existe.
- **Listado** con filtros tolerantes `status`, `clientId`, `shippingLineId`, `locationId`, `pilotId`, `vehicleId`, `dateFrom`/`dateTo` (sobre `recolection_date`) y `search` (`LIKE` sobre `order` y `container`); orden fijo **`recolection_date desc, id desc`** y paginación **opt-in por `limit`** acotada a `[10, 100]`.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.
- Resumen de integración para el frontend en `references/trips-api.md`.

**Fuera de alcance (para specs futuras):**

- **Máquina de estados y retroceso.** El `PATCH` del administrador acepta cualquiera de los tres valores sin comprobar el orden: puede llevar un viaje de `finished` a `pending` dejando `start_date` y `end_date` puestos. Está decidido a propósito y es el hueco más grande que deja la spec.
- **Cancelar un viaje.** No hay `TripStatus::Cancelled`. Un viaje que no se hará se borra.
- **Desasignar.** Una vez asignado, `pilot_id` y `vehicle_id` no vuelven a `null` por ninguna vía. Se pueden **cambiar** —mientras el viaje siga `pending` y solo desde la empresa que asignó—, pero el viaje no regresa nunca a la bolsa.
- **Que el administrador asigne.** `/assignment` es exclusiva del `carrier`. Un administrador no puede poner piloto ni vehículo por ninguna ruta, y el `PATCH` general **no acepta esos dos campos**.
- **Restaurar un viaje borrado.** Sin `restore`, sin `?trashed=true`, como en SPEC 22 y 23.
- **Solapamiento de piloto o vehículo.** Un mismo piloto puede estar en dos viajes con fechas que se pisan. No se valida.
- **`carrier_id` propio en `trips`.** El vínculo con la empresa se deriva siempre de `assigned_by`; no hay columna directa.
- **Bitácora de cambios.** Editar un viaje o reasignarlo pisa el valor anterior sin dejar rastro. No hay tabla de historial, ni de quién movió el `status`, ni cuándo.
- **Costos, tarifas y facturación.** El viaje no cotiza nada y no toca `freight_rates`. `GET /api/freight-rates/quote` no cambia de forma.
- **Archivos adjuntos.** No hay carta de porte, ni foto de contenedor, ni nada que suba al bucket.
- **Notificaciones.** Nadie recibe correo ni push cuando un viaje se publica, se asigna o se cierra.
- **Seguimiento en tiempo real.** `polyline` es la ruta prevista y se guarda una sola vez; no hay posición del vehículo ni recálculo.
- **Recalcular la polilínea desde el backend.** Si el `PATCH` cambia `location_id` o `departure_point_id`, la polilínea guardada queda obsoleta y la API no dice nada: remandarla es responsabilidad del frontend.
- **Filtro por `assigned_by` o por empresa asignataria.** El administrador no puede listar «los viajes de la empresa X»; el ámbito lo aplica el rol, no un parámetro.
- **Unicidad de `order` o `container`.** Dos viajes pueden compartir ambos.

---

## Modelo de datos

Esta spec crea **una tabla, un enum, un modelo y una factory**, y toca **dos services ya publicados** (`ClientService`, `ShippingLineService`).

### 1. Enum `App\Enums\TripStatus`

```php
enum TripStatus: string
{
    case Pending = 'pending';
    case InRoute = 'in_route';
    case Finished = 'finished';
}
```

Sin `label()`: el valor sale **crudo y en inglés** por el Resource, como `LocationType` en SPEC 21. Traducirlo es cosa del frontend.

### 2. Tabla `trips`

```php
Schema::create('trips', function (Blueprint $table) {
    $table->id();

    /** Referencia comercial del viaje. MAYÚSCULAS, espacios colapsados, NO única. */
    $table->string('order');

    /** Catálogos obligatorios. Sin cascade: borrarlos con viajes colgando se frena en el service. */
    $table->foreignId('client_id')->constrained('clients');
    $table->foreignId('shipping_line_id')->constrained('shipping_lines');
    $table->foreignId('departure_point_id')->constrained('departure_points');
    /** Solo locations de tipo `port` y activas; la regla vive en el service, no en el esquema. */
    $table->foreignId('location_id')->constrained('locations');

    /** Destino final en el extranjero. Texto libre, solo trim: no lo respalda ningún catálogo. */
    $table->string('destination');
    /** MAYÚSCULAS, espacios colapsados, NO único. */
    $table->string('container');
    /** Medio o empresa de transporte, tal como se teclea. */
    $table->string('transport');

    /** Planificado en el alta, siempre a futuro y con hora. */
    $table->timestamp('recolection_date');
    $table->timestamp('ship_date');
    /** Ejecución real: los pone el servidor en /start y /finish, nunca el body. */
    $table->timestamp('start_date')->nullable();
    $table->timestamp('end_date')->nullable();

    /** Polilínea codificada de Google, tal como la manda el front. Puede pasar de 255. */
    $table->text('polyline');
    $table->text('observations');

    $table->string('status')->default(TripStatus::Pending->value);

    /** Los tres nullables del dominio: un viaje nace sin dueño operativo. */
    $table->foreignId('pilot_id')->nullable()->constrained('users');
    $table->foreignId('vehicle_id')->nullable()->constrained('vehicles');
    $table->foreignId('assigned_by')->nullable()->constrained('users');

    $table->foreignId('registered_by')->constrained('users');

    $table->timestamps();
    $table->softDeletes();

    /** El orden fijo del listado y las dos columnas del filtro de ámbito. */
    $table->index('recolection_date');
    $table->index('assigned_by');
    $table->index('pilot_id');
});
```

- **Ningún índice único.** Ni `order` ni `container` lo son, a diferencia de todos los catálogos anteriores: el mismo contenedor puede repetirse entre viajes.
- **Cinco nullables además de `deleted_at`**: `start_date`, `end_date`, `pilot_id`, `vehicle_id` y `assigned_by`. Los tres últimos son el estado "sin tomar" y se llenan **juntos** o no se llenan: no existe un viaje con piloto y sin `assigned_by`.
- **Tres índices explícitos**, primera desviación del «ningún índice extra» de SPEC 23. Se justifica porque es la primera tabla transaccional del proyecto: el orden es fijo por `recolection_date` y el ámbito filtra siempre por `assigned_by` o por `pilot_id`. Postgres no indexa las claves foráneas por su cuenta.
- **Ninguna FK lleva `cascade`.** Borrar un cliente o una naviera con viajes se frena antes, en el service, con un 400 en español.
- `status` se guarda como `string` con `default`, como el resto de enums del proyecto.

### 3. Modelo `Trip`

```php
#[Fillable([
    'order', 'client_id', 'shipping_line_id', 'departure_point_id', 'location_id',
    'destination', 'container', 'transport',
    'recolection_date', 'ship_date', 'start_date', 'end_date',
    'polyline', 'observations', 'status',
    'pilot_id', 'vehicle_id', 'assigned_by', 'registered_by',
])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory, SoftDeletes;

    /** Ocho relaciones: client, shippingLine, departurePoint, location, pilot, vehicle, assignedBy, registeredBy. */

    /** Trim + colapsar espacios interiores + MAYÚSCULAS. Compartida por `order` y `container`. */
    public static function normalizeReference(string $value): string;

    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'recolection_date' => 'datetime',
            'ship_date' => 'datetime',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }
}
```

- **Ocho `belongsTo`**, el modelo con más relaciones del proyecto. `pilot()`, `assignedBy()` y `registeredBy()` apuntan las tres a `User` con claves distintas.
- **Una sola función de normalización para dos campos**, contra la costumbre del proyecto de repetirla por campo. Aquí `order` y `container` siguen exactamente la misma regla y son del mismo modelo, así que duplicarla no protegería de nada.
- `destination`, `transport` y `observations` **no tienen normalización**: solo el `trim` que aplica el FormRequest. Asimetría deliberada, con el precedente del `value` de SPEC 18.

### 4. Salida — `TripResource`

**31 claves**, en camelCase. El Resource más grande del proyecto:

```json
{
  "id": 1,
  "order": "ORD-2026-0148",
  "status": "pending",
  "clientId": 3, "clientName": "AGROEXPORT S.A.",
  "shippingLineId": 2, "shippingLineName": "MAERSK LINE",
  "departurePointId": 5, "departurePointName": "PLANTA SAN JUAN",
  "locationId": 9, "locationName": "PUERTO QUETZAL",
  "destination": "Rotterdam, Países Bajos",
  "container": "MSKU 483920 1",
  "transport": "Rastra 40 pies",
  "recolectionDate": "02-09-2026 06:00:00 AM",
  "shipDate": "04-09-2026 11:30:00 PM",
  "startDate": null,
  "endDate": null,
  "polyline": "_p~iF~ps|U_ulLnnqC_mqNvxq`@",
  "points": [[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]],
  "observations": "Carga refrigerada a -2 °C.",
  "pilotId": null, "pilotName": null,
  "vehicleId": null, "vehiclePlate": null,
  "assignedById": null, "assignedByName": null,
  "registeredByName": "Roberto Santizo",
  "createdAt": "27-08-2026 09:14:03 AM",
  "updatedAt": "27-08-2026 09:14:03 AM",
  "deletedAt": null
}
```

- **Las cuatro fechas salen en `d-m-Y h:i:s A`**, como el resto del proyecto, no en ISO 8601.
- **`points` es el segundo campo calculado en lectura del proyecto**, tras el `currentValue` de SPEC 17: se decodifica con `App\Services\Place\PolylineDecoder::decode()` en el propio Resource, sin columna y sin caché.
- Los seis pares `id`/`name` de relación salen **planos**, nunca como objeto anidado. El service carga las ocho relaciones con `with()` para que el listado no haga N+1.
- `deletedAt` es `null` en siete de los ocho endpoints; solo la respuesta del `DELETE` lo trae con valor.

### 5. Factory

`TripFactory` con `registered_by` apoyado en `User::factory()->state(['role' => UserRole::Administrator])`, los cuatro catálogos vía sus propias factories (`Location` en estado `port` y activa), fechas futuras coherentes (`ship_date` después de `recolection_date`) y una polilínea codificada fija. Cuatro estados: `assigned()` (piloto, vehículo y `assigned_by` de una misma empresa), `inRoute()`, `finished()` y `trashed()`.

### 6. Cambio sobre SPEC 22 y SPEC 23

Ninguna columna cambia en `clients` ni en `shipping_lines`. Solo se añade una guarda en cada `destroy()`:

```php
/** En ClientService::destroy() y ShippingLineService::destroy(), antes de delete(). */
if ($model->trips()->withTrashed()->exists()) {
    throw new BadRequestError('No se puede eliminar: tiene viajes asociados.');
}
```

Los dos modelos ganan una relación `trips(): HasMany` que hoy no tienen. **La comprobación mira también los viajes borrados**: si no, borrar el viaje y luego el cliente dejaría una FK apuntando a una fila invisible.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo.

1. **Enum, migración, modelo y factory.** `php artisan make:enum TripStatus` (o a mano en `app/Enums/`) con los tres casos; `php artisan make:model Trip -mf`. Rellenar la migración con las columnas, las ocho FK sin `cascade`, los tres índices y `softDeletes()`; el modelo con su `#[Fillable]`, el trait, `normalizeReference()`, `casts()` y las ocho relaciones `belongsTo`; la factory con sus cuatro estados. Correr `php artisan migrate`. Verificación: `php artisan tinker --execute 'Trip::factory()->assigned()->create();'` no revienta.

2. **Contrato.** `app/Interfaces/Trip/TripServiceInterface.php` con **ocho** métodos, PHPDoc de array shapes y `@throws`:

   ```php
   public function getTrips(User $user, array $filters): LengthAwarePaginator|Collection;
   public function getTripById(User $user, int $id): Trip;
   public function create(User $user, array $data): Trip;
   public function update(int $id, array $data): Trip;
   public function destroy(int $id): Trip;
   public function assign(User $user, int $id, array $data): Trip;
   public function start(User $user, int $id): Trip;
   public function finish(User $user, int $id): Trip;
   ```

   Los cinco métodos que reciben `User` lo hacen porque **el ámbito o la autoría dependen de quién llama**; `update()` y `destroy()` no lo necesitan: solo los alcanza el administrador, que ve todo.

3. **Service, guardas privadas.** `app/Services/Trip/TripService.php` con `resolvePerPage()` y el bloque de comprobaciones, cada una con su `BadRequestError` en español:
   - `resolveWritableTrip(int $id): Trip` — `withTrashed()`; 404 si no existe, **400 «El viaje ya fue eliminado»** si tiene `deleted_at`. Guarda común de `update`, `destroy`, `assign`, `start` y `finish`.
   - `ensureCatalogsAreUsable(array $data): void` — cliente y naviera no borrados, `location` de tipo `port` y activa, `departurePoint` activo.
   - `ensureCrewIsAssignable(int $pilotId, int $vehicleId): void` — el usuario tiene rol `pilot` y empresa, el vehículo está `active`, y **las dos empresas coinciden**.
   - `ensureUserCanSeeTrip(User $user, Trip $trip): void` — la matriz de ámbito del alcance, lanzando `ForbiddenError`.

   Verificación: Unit test del service sobre estas cuatro, sin tocar HTTP.

4. **Service, lectura.** `getTrips()` con `with()` de las ocho relaciones, el **filtro de ámbito por rol aplicado antes que los filtros del usuario**, los ocho filtros tolerantes, orden `recolection_date desc, id desc` y paginación opt-in; `getTripById()`, que resuelve **sin** `withTrashed()` (una borrada es 404) y luego pasa por `ensureUserCanSeeTrip()`. `#[Override]` en cada método.

5. **Service, escritura del administrador.** `create()` —normaliza `order` y `container`, valida catálogos, fuerza `status = pending` y `registered_by = $user->id`, y **descarta `pilot_id`, `vehicle_id` y `assigned_by` si llegan**—; `update()` con `isset`, revalidando catálogos aunque solo cambie una fecha; `destroy()` sobre el modelo ya resuelto. Todo devuelve el modelo con las relaciones cargadas.

6. **Service, las tres acciones.** El paso con más reglas nuevas:
   - `assign()` — 403 si el que llama no es de la empresa que puede tocar el viaje (bolsa libre para cualquiera; ya asignado, solo la empresa de `assigned_by`); **400 si el viaje no está `pending`**; valida la tripulación; escribe los tres campos **en una transacción con `lockForUpdate`**, para que dos transportistas no tomen el mismo viaje a la vez.
   - `start()` — 403 si el que llama no es el `pilot_id` del viaje; **400 si `start_date` ya tiene valor**; escribe `start_date = now()` y `status = in_route`.
   - `finish()` — 403 igual que `start`; **400 si `end_date` ya tiene valor** y **400 si `start_date` es `null`**; escribe `end_date = now()` y `status = finished`.

7. **Provider.** `app/Providers/Trip/TripProvider.php` con el `bind(TripServiceInterface::class, TripService::class)`, registrado en `bootstrap/providers.php`.

8. **FormRequests**, los tres con `messages()` en español y `prepareForValidation()` que normaliza `order` y `container`:
   - `StoreTripRequest` — los doce campos obligatorios (`order`, cuatro FK, `destination`, `container`, `transport`, `recolectionDate`, `shipDate`, `polyline`, `observations`), con `exists:` en las FK, `date|after:now` en las dos fechas y `after_or_equal:recolectionDate` en `shipDate`. **No acepta `status`, `pilotId`, `vehicleId`, `assignedBy` ni `registeredBy`**: mandarlos se descarta sin error.
   - `UpdateTripRequest` — los mismos campos como `sometimes|required`, **más `status`** (`Rule::enum(TripStatus::class)`), **menos `pilotId` y `vehicleId`**.
   - `AssignTripRequest` — exactamente dos campos, los dos `required`: `pilotId` y `vehicleId`.

   `/start` y `/finish` **no llevan FormRequest**: no tienen cuerpo.

9. **Resource, controller y rutas.** `TripResource` con las 31 claves, decodificando `points` con `PolylineDecoder`; `TripController` con **ocho** métodos, `try/catch` → `ResponseHandler` y el service inyectado por parámetro de método; `routes/trips.php` con prefijo `trips`, `name('trips.')`, `jwt.auth` en el grupo, **las tres rutas fijas antes** del `apiResource('/')->parameters(['' => 'trip'])`, y `role:` por ruta (`administrator` en `store/update/destroy`, `carrier` + `carrier.required` en `assignment`, `pilot` en `start` y `finish`); `require` en `routes/api.php`. Verificación: `php artisan route:list --path=trips` muestra **ocho** rutas y `/{trip}/assignment` aparece **antes** que `/{trip}`.

10. **Cambio sobre SPEC 22 y SPEC 23.** Añadir `trips(): HasMany` a `Client` y a `ShippingLine`, y la guarda `withTrashed()->exists()` → 400 en los dos `destroy()`. Verificación: `php artisan test --compact --filter='Client|ShippingLine'` sigue en verde salvo por los casos que ahora cambian de significado, que hay que actualizar en el mismo commit.

11. **Tests.** Disparar el agente `feature-tests` sobre `Trip`. Feature test de los ocho endpoints y los cuatro roles, la matriz de ámbito completa (bolsa visible / viaje tomado invisible para otra empresa / piloto solo los suyos), las validaciones cruzadas una por una, el 400 de la doble asignación y del doble `start`, el `finish` sin `start`, el 404 del `show` de un borrado y el 400 del segundo `DELETE`; Unit test del service. Correr `php artisan test --compact --filter=Trip`.

12. **Regresión.** `php artisan test --compact` entera en verde.

13. **Documentación.** Disparar el agente `endpoint-docs` sobre `Trip` y regenerar `storage/api-docs/api-docs.json`.

14. **Cierre.** `vendor/bin/pint --dirty --format agent` y escribir `references/trips-api.md`, abriendo con la matriz de ámbito por rol —que es lo que el frontend no puede deducir del contrato— y con la advertencia de que la polilínea no se recalcula sola.

---

## Criterios de aceptación

**Estructura**

- [ ] Existe la tabla `trips` con las 19 columnas de negocio y relación, `timestamps` y `deleted_at`; `start_date`, `end_date`, `pilot_id`, `vehicle_id` y `assigned_by` son las únicas nullables además de `deleted_at`.
- [ ] **Ningún índice único** en la tabla; existen los tres índices en `recolection_date`, `assigned_by` y `pilot_id`.
- [ ] Ninguna FK lleva `cascade`.
- [ ] `App\Enums\TripStatus` tiene exactamente **tres** casos y **no** declara `label()`.
- [ ] `php artisan route:list --path=trips` muestra exactamente **ocho** rutas, con `/{trip}/assignment`, `/{trip}/start` y `/{trip}/finish` **antes** de `/{trip}`.
- [ ] `TripServiceInterface` tiene **ocho** métodos.
- [ ] `clients` y `shipping_lines` **no cambiaron de columnas**; sus modelos ganaron `trips()` y nada más.

**Roles**

- [ ] Sin token, las ocho rutas responden **401** con el sobre habitual.
- [ ] `POST`, `PATCH /{trip}` y `DELETE` responden **403** para `carrier`, `manager` y `pilot`.
- [ ] `PATCH /{trip}/assignment` responde **403** para `administrator`, `manager` y `pilot`; un `carrier` **sin empresa** recibe 403 por `carrier.required`.
- [ ] `PATCH /{trip}/start` y `/finish` responden **403** para `administrator`, `carrier` y `manager`.
- [ ] Los cuatro roles autenticados obtienen **200** en `GET /api/trips`.

**Ámbito de lectura**

- [ ] Un `administrator` y un `manager` ven **todos** los viajes, asignados o no.
- [ ] Un `carrier` ve los `pending` con `pilot_id` y `vehicle_id` en `null`.
- [ ] En cuanto la empresa A asigna un viaje, ese viaje **desaparece** del listado de la empresa B y sigue en el de la A.
- [ ] Un segundo usuario de la empresa A **sí** ve el viaje que asignó su compañero.
- [ ] Un `pilot` ve **solo** los viajes donde `pilot_id` es él; la bolsa de viajes sin asignar **no** aparece en su listado.
- [ ] `GET /api/trips/{trip}` de un viaje fuera del ámbito responde **403**, no 404.
- [ ] El ámbito se aplica **antes** que los filtros: `?status=pending` desde la empresa B no revela los viajes tomados por la A.

**Alta**

- [ ] `POST` con los doce campos responde **201**, con `status = pending`, `registered_by` igual al usuario autenticado y `pilot_id`, `vehicle_id` y `assigned_by` en `null`.
- [ ] Mandar `status`, `pilotId`, `vehicleId`, `assignedBy` o `registeredBy` en el body **no cambia nada** y no da error.
- [ ] Falta cualquiera de los doce → **422**.
- [ ] `order` y `container` se guardan en MAYÚSCULAS con espacios colapsados; `destination`, `transport` y `observations` conservan sus mayúsculas y minúsculas tal cual, con solo `trim`.
- [ ] Dos viajes pueden tener el mismo `order` y el mismo `container`: no hay 400 ni 422.
- [ ] `recolectionDate` o `shipDate` en el **pasado** → **422**.
- [ ] `shipDate` anterior a `recolectionDate` → **422**.
- [ ] `locationId` de una location de tipo **`destination`** → **400**; de un puerto **inactivo** → **400**.
- [ ] `departurePointId` inactivo → **400**.
- [ ] `clientId` o `shippingLineId` de una fila **borrada** → **400** (el `exists:` de Laravel no las ve; la guarda del service sí).
- [ ] `polyline` ausente → **422**.

**Edición del administrador**

- [ ] `PATCH` con cuerpo vacío responde **200** sin cambiar nada.
- [ ] `PATCH` **no acepta** `pilotId` ni `vehicleId`: mandarlos se ignora con 200 y el viaje conserva su asignación.
- [ ] `PATCH` con `status` lo cambia, incluso hacia atrás: un `finished` puede volver a `pending` conservando `start_date` y `end_date`.
- [ ] `PATCH` con un `status` fuera del enum → **422**.
- [ ] `PATCH` revalida catálogos aunque solo cambie una fecha: apuntar a un puerto que se desactivó → **400**.
- [ ] `PATCH` **no reescribe** `registered_by` ni `assigned_by`.

**Asignación**

- [ ] `PATCH /{trip}/assignment` con `pilotId` y `vehicleId` válidos responde **200** y escribe los tres campos, con `assigned_by` igual al usuario autenticado.
- [ ] Falta cualquiera de los dos → **422**: no se puede asignar solo piloto o solo vehículo.
- [ ] Mandar `null` en cualquiera de los dos → **422**: no existe la desasignación.
- [ ] El piloto debe tener rol `pilot`: un `carrier` o un `manager` como `pilotId` → **400**.
- [ ] Un vehículo `inactive` o `under_repair` → **400**.
- [ ] Piloto y vehículo de **empresas distintas** → **400**.
- [ ] Un `carrier` de la empresa B sobre un viaje ya asignado por la A → **403**.
- [ ] Un `carrier` de la empresa A **reasigna** su propio viaje mientras siga `pending`: responde **200** y `assigned_by` se reescribe.
- [ ] Reasignar un viaje `in_route` o `finished` → **400**.
- [ ] Dos asignaciones simultáneas sobre el mismo viaje: solo una gana; la otra recibe **403** o **400**, nunca dos escrituras.

**Inicio y cierre**

- [ ] `PATCH /{trip}/start` desde el piloto asignado responde **200**, deja `start_date` con la hora del **servidor** y `status = in_route`.
- [ ] `start` desde un piloto que **no** es el asignado → **403**.
- [ ] `start` sobre un viaje que ya tiene `start_date` → **400**.
- [ ] `PATCH /{trip}/finish` desde el piloto asignado responde **200**, deja `end_date` y `status = finished`.
- [ ] `finish` sobre un viaje **sin `start_date`** → **400**.
- [ ] `finish` sobre un viaje que ya tiene `end_date` → **400**.
- [ ] Ninguna de las dos rutas acepta fecha en el body: mandarla no la usa.

**Listado**

- [ ] Devuelve los viajes ordenados por `recolection_date` descendente y, a igualdad, por `id` descendente.
- [ ] **No incluye los borrados**, ni con filtro ni sin él.
- [ ] Cada uno de los ocho filtros funciona, y **un valor inválido se ignora** en vez de vaciar el listado o dar 422.
- [ ] `?search=` busca en `order` **y** en `container`, insensible a mayúsculas por normalización del término.
- [ ] Sin `limit` devuelve la colección completa; con `limit` pagina y `total`, `currentPage` y `lastPage` salen **en la raíz** del sobre; `limit=1` se acota a 10 y `limit=500` a 100.
- [ ] Un listado de 100 viajes **no hace N+1**: las ocho relaciones vienen con `with()`.

**Baja**

- [ ] `DELETE` responde **200**, deja `deleted_at` y el viaje desaparece del listado y del `show`.
- [ ] Un segundo `DELETE` responde **400** con «El viaje ya fue eliminado»; un id inexistente responde **404**.
- [ ] `PATCH`, `/assignment`, `/start` y `/finish` sobre un viaje borrado responden **400**, no 404.
- [ ] La fila **sigue en la base**: `Trip::withTrashed()->find($id)` la encuentra.
- [ ] **No existe ninguna forma de restaurarlo por API.**

**Impacto sobre SPEC 22 y SPEC 23**

- [ ] `DELETE /api/clients/{client}` de un cliente **con viajes** responde **400**, incluso si esos viajes están borrados.
- [ ] `DELETE /api/shipping-lines/{shippingLine}` se comporta igual.
- [ ] Un cliente o una naviera **sin** viajes se sigue borrando con **200**, exactamente como antes.
- [ ] El resto del contrato de SPEC 22 y SPEC 23 —listado, filtros, unicidad, 400 del segundo `DELETE`— queda **intacto**.

**Salida**

- [ ] `TripResource` devuelve exactamente las **31** claves acordadas, en camelCase.
- [ ] Las seis relaciones salen como par `id` + nombre plano, nunca como objeto anidado.
- [ ] `points` trae los pares `[lat, lng]` decodificados de `polyline`, y coincide con lo que devuelve `GET /api/places/directions` para la misma cadena.
- [ ] Las cuatro fechas salen en `d-m-Y h:i:s A`; `startDate` y `endDate` salen `null` mientras no ocurran.
- [ ] `status` sale con el **valor crudo del enum en inglés**, sin traducir.
- [ ] `deletedAt` sale `null` en los siete endpoints que no son el `DELETE`.

**Cierre**

- [ ] `php artisan test --compact` pasa entera.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `storage/api-docs/api-docs.json` incluye las ocho rutas y existe `references/trips-api.md`.

---

## Decisiones tomadas y descartadas

**1. El ámbito se adquiere, no se hereda.**
Se descartó darle a `trips` una columna `carrier_id` propia. Un viaje lo publica el administrador antes de saber quién lo hará, así que al nacer no pertenece a nadie; la empresa entra en escena cuando toma el viaje. Guardar `carrier_id` obligaría a inventarle un dueño desde el minuto cero o a dejarlo nullable, que es exactamente lo que ya hace `assigned_by` con menos ambigüedad.

**2. `assigned_by` guarda el usuario, pero el filtro compara la empresa.**
Se valoró filtrar por `assigned_by = auth()->id()` literal, que es lo primero que uno escribe. Se descartó porque deja el viaje visible para una sola persona: si esa persona se va de la empresa, el viaje queda huérfano de vista y solo el administrador puede rescatarlo. Guardando el usuario se conserva la auditoría; comparando la empresa se conserva la operación. Precedente del filtro por empresa: `resolveScopedCarrierId()` en `Vehicle`.

**3. El piloto no ve la bolsa de viajes sin asignar.**
Se valoró darle la misma vista que al transportista, para que viera lo que se viene. Se descartó: el piloto no elige viajes, se los asignan, y mostrarle trabajo que no puede tomar solo genera preguntas. Su listado es su agenda.

**4. Tres rutas fijas de acción en vez de meterlo todo en el `PATCH`.**
Se descartó un `PATCH` único que aceptara `status`, `pilotId`, `vehicleId`, `startDate` y `endDate` según el rol. Habría concentrado cinco autorizaciones distintas dentro de un solo método y hecho invisible desde las rutas quién puede hacer qué. Con tres rutas, cada acción tiene su rol en el archivo de rutas y su guarda en el service. El precedente débil es `toggle-status`; este dominio lo lleva mucho más lejos.

**5. `/start` y `/finish` ponen la fecha con `now()` del servidor.**
Se descartó recibirla en el body. Son marcas de ejecución real: aceptarlas del cliente permitiría a un piloto declarar que arrancó tres horas antes, y como no hay bitácora nadie lo notaría. El coste asumido es que un piloto sin señal no puede registrar el arranque a la hora en que ocurrió.

**6. El administrador no puede asignar.**
Se valoró darle `/assignment` también a él, que es lo natural en casi cualquier sistema. Se descartó por decisión explícita del negocio: asignar es el acto por el que una empresa **toma** un viaje, y si el administrador pudiera hacerlo estaría decidiendo por el transportista. La consecuencia es real y se acepta: **si ninguna empresa toma un viaje, nadie puede desatascarlo por API**.

**7. No se puede desasignar.**
Se descartó aceptar `null` en `/assignment`. Devolver un viaje a la bolsa es una operación con preguntas propias —qué pasa si ya arrancó, quién puede hacerlo, qué pasa con `assigned_by`— que este dominio no necesita responder hoy. Cambiar de piloto o de vehículo sí se puede, mientras el viaje siga `pending`.

**8. Reasignar solo mientras el viaje esté `pending`.**
Una vez que el piloto dio `/start`, cambiarle el piloto al viaje dejaría un `start_date` puesto por alguien que ya no aparece en el registro. Congelar la tripulación al arrancar es la forma barata de que el dato siga significando algo, dado que no hay bitácora.

**9. `status` editable a mano por el administrador, sin reglas de transición.**
Es la decisión más incómoda de la spec y se toma con el hueco a la vista. Un `finished` puede volver a `pending` conservando sus dos fechas de ejecución, y el resultado es un estado que se contradice con sus propios datos. Se acepta porque la alternativa —una máquina de estados con transiciones válidas— es la mitad de otra spec, y porque sin esa puerta un viaje mal cerrado no tendría arreglo. El proyecto ya movió estados libremente en SPEC 17.

**10. Sin `cancelled`.**
Se valoró el cuarto caso del enum. Se descartó porque un viaje cancelado y uno borrado serían indistinguibles para el usuario, y ya hay `SoftDeletes`. Si mañana hace falta distinguir «no se hizo» de «nunca existió», es un caso más en el enum y una migración de nada.

**11. Borrar un cliente o una naviera con viajes se bloquea con 400.**
Se descartó la alternativa del proyecto en `FreightRate`, que exige catálogos activos al escribir pero nunca frena el borrado. Aquí no vale: `Client` y `ShippingLine` borran con `SoftDeletes`, así que la fila desaparece de la API pero la FK sigue apuntando a ella y el viaje se quedaría mostrando el nombre de un cliente que nadie puede consultar. Es el precio de ser el primer consumidor de dos catálogos que se publicaron sin ninguno.

**12. La guarda mira también los viajes borrados (`withTrashed()`).**
Sin eso, borrar el viaje y después el cliente dejaría la FK del viaje borrado apuntando a una fila borrada, y restaurar cualquiera de los dos —a mano, en base— produciría datos rotos. Es una comprobación más estricta de lo que parece necesario, a propósito.

**13. `location_id` valida el tipo `port` en el service, no en el esquema.**
Se descartó una FK compuesta o un `check` en base. SPEC 21 dejó `type` libremente editable por `PATCH`, así que un puerto puede dejar de serlo en cualquier momento y una restricción en base convertiría ese `PATCH` en un error de integridad. Con la regla en el service, reclasificar un puerto es legal y los viajes viejos se quedan como están.

**14. La polilínea la manda el frontend y no se recalcula nunca.**
Se descartó que `TripService` inyectara `PlaceServiceInterface` y llamara a `computeRoutes` al crear el viaje. Cada llamada a Google se paga, el front ya la hizo para pintar el mapa, y el criterio del proyecto está fijado desde SPEC 15: la API no llama a Google, recibe el resultado ya resuelto. El coste asumido es que un `PATCH` que cambie el destino deja una polilínea mentirosa y la API no se entera.

**15. `points` se decodifica en el Resource, sin columna y sin caché.**
Se valoró guardar los pares decodificados en una columna `json`. Se descartó: sería el mismo dato dos veces y podría desincronizarse. Precedente exacto: el `currentValue` de SPEC 17, que también es campo calculado en lectura.

**16. Una sola `normalizeReference()` para `order` y `container`.**
Va contra la costumbre del proyecto, que repite `normalizeName()` en seis modelos a propósito. La razón de aquella repetición es que son **modelos distintos** que pueden divergir; aquí son dos campos del **mismo** modelo con la misma regla, y duplicarla no protegería de nada.

**17. `observations` obligatorio.**
Se valoró dejarlo nullable, que es lo habitual para un campo de notas. Se mantiene obligatorio por decisión explícita: si el alta la hace el administrador y el viaje lo ejecuta otra empresa, las observaciones son el único canal de instrucciones que hay.

**18. Tres índices explícitos, rompiendo el «ningún índice extra» de los catálogos.**
Los catálogos anteriores tienen decenas de filas y se ordenan por `id`. Esta es la primera tabla que crece sin techo y cuyo ámbito filtra en **toda** consulta por `assigned_by` o `pilot_id`. `search` sigue siendo `LIKE %term%` y no se indexa, porque un B-tree no lo aprovecharía.

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Un viaje que nadie toma se queda atascado para siempre.** El administrador no puede asignar (decisión 6) y no puede desasignar (decisión 7). Si ninguna empresa lo toma, o si lo toma la equivocada y no lo suelta, la única salida por API es **borrar el viaje y crearlo de nuevo**. | Está asumido y es consecuencia directa de dos decisiones del negocio. `references/trips-api.md` debe abrir diciéndolo. La spec que añada la desasignación es la que lo resuelve. |
| **El `status` puede contradecir a las fechas.** El `PATCH` del administrador mueve el estado sin tocar `start_date` ni `end_date`, así que existen combinaciones imposibles: `pending` con las dos fechas puestas, `finished` sin ninguna. | Aceptado a propósito (decisión 9). El frontend debe pintar el relato desde las **fechas**, no desde el `status`, cuando los dos se contradigan. |
| **Dos transportistas toman el mismo viaje a la vez.** Sin control de concurrencia, dos `PATCH /assignment` simultáneos sobre un viaje libre podrían pisarse y dejar `assigned_by` de uno con el piloto del otro. | El paso 6 del plan lo resuelve con `DB::transaction` + `lockForUpdate`, y el criterio de aceptación lo verifica. Mismo patrón que `FuelPrice` al rotar el vigente. |
| **La polilínea miente después de un `PATCH`.** Cambiar `location_id` o `departure_point_id` no invalida la `polyline` guardada, y el mapa del viaje queda dibujando una ruta que ya no corresponde. Nada lo detecta. | Documentado en `references/trips-api.md`: quien cambie el destino debe volver a llamar a `/places/directions` y mandar la polilínea nueva en el mismo `PATCH`. |
| **Decodificar 100 polilíneas por página.** `points` se calcula en el Resource, así que un listado con `limit=100` decodifica cien polilíneas en cada petición. Cada una puede traer cientos de pares. | Se mide antes de optimizar. Si molesta, la salida es que `points` viaje solo en el `show` y no en el `index` — cambio de forma menor que no toca la base. |
| **Ocho relaciones cargadas siempre.** El `with()` del listado trae ocho `belongsTo` aunque el frontend solo pinte tres columnas. | Es preferible al N+1, que es el riesgo real. Ninguna de las ocho es `hasMany`, así que el coste es acotado. |
| **La copia mal hecha desde `Client` o `ShippingLine`.** El dominio se escribirá mirando a los anteriores, y ahí es donde se cuela un mensaje que dice «la naviera» dentro del service de viajes. | El Feature test debe afirmar sobre los **mensajes en español literales**, y el paso 12 corre la suite entera. |
| **El cambio en SPEC 22 y 23 rompe tests existentes.** Los tests de `Client` y `ShippingLine` que borran una fila pueden empezar a fallar si sus factories acaban creando viajes. | El paso 10 lo aísla en su propio commit y exige correr `--filter='Client\|ShippingLine'` antes de seguir. |

---

## Lo que **no** entra en esta spec

- Máquina de estados, transiciones válidas y prohibición de retroceder.
- Cancelar un viaje (`TripStatus::Cancelled`).
- Desasignar un viaje o devolverlo a la bolsa.
- Que el administrador asigne piloto o vehículo.
- Restaurar un viaje borrado.
- Validar solapamientos de piloto o de vehículo entre viajes.
- `carrier_id` propio en `trips`.
- Bitácora de cambios, de asignaciones o de movimientos de estado.
- Costos, tarifas o facturación del viaje.
- Archivos adjuntos, notificaciones y seguimiento en tiempo real.
- Recalcular la polilínea desde el backend.
- Filtrar por empresa asignataria desde el rol administrador.

Cada uno de ellos, si llega, va en su propia spec.
