# SPEC 26 — Seguimiento en tiempo real del viaje

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 24
> **Fecha:** 2026-09-07
> **Objetivo:** Publicar el seguimiento en vivo de un viaje: el piloto asignado reporta su posición por HTTP mientras el viaje está `in_route`, cada punto se guarda en la tabla nueva `trip_positions` y se emite por Reverb al canal privado `trips.{tripId}`, al que se suscribe cualquier usuario no piloto dentro del ámbito de lectura de SPEC 24.

Es la spec que **reabre lo que SPEC 24 cerró**: aquella declaró «Seguimiento en tiempo real» explícitamente fuera de alcance, con `polyline` como ruta prevista y nada más. Y rompe cuatro cosas del proyecto de golpe:

1. **Primera dependencia de infraestructura nueva desde el arranque.** `laravel/reverb` trae un **proceso permanente** (`php artisan reverb:start`) que hasta hoy no existía: el backend deja de ser solo PHP detrás de un servidor web. Sin ese proceso, la API sigue funcionando entera —los puntos se guardan igual— pero nadie recibe nada.
2. **Primera salida del proyecto que no es una petición HTTP.** Hasta hoy la API solo respondía o llamaba a Google; ahora **empuja** datos a clientes conectados.
3. **Primera ruta anidada del proyecto.** SPEC 14 y SPEC 18 evitaron `/api/vehicles/{vehicle}/expenses` y `/api/accessories/{accessory}/characteristics` a propósito; aquí `/api/trips/{trip}/positions` sí se anida, porque una posición sin su viaje no significa nada y `{trip}` ya es el parámetro del grupo.
4. **Primera autorización que no vive en un middleware ni en un service**, sino en un callback de `routes/channels.php` — y la primera que se ejecuta **fuera del ciclo de una ruta de la API**.

**Lo que esta spec no es:** un módulo de telemetría. No hay velocidad, ni rumbo, ni precisión del GPS, ni batería, ni geocercas, ni alertas por desvío, ni comparación contra la `polyline` prevista, ni ETA recalculado.

---

## Alcance

**Dentro:**

- **Cableado de `laravel/reverb`** —la dependencia **ya está instalada** en `composer.json`—, con `config/broadcasting.php` y `config/reverb.php` publicados y las claves `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` en `.env.example`. `BROADCAST_CONNECTION` pasa de `log` a `reverb` (en `phpunit.xml` **sigue en `null`**: la suite no levanta nada).
- **Ruta de autorización de canal montada a mano** en `bootstrap/app.php`: `->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['jwt.auth']])` → **`POST /api/broadcasting/auth`**. Sin esto no hay autorización posible: el proyecto no tiene sesión, y el `Broadcast::routes()` por defecto usa el guard `web`.
- **`routes/channels.php` nuevo** (el primero del proyecto), con **un solo canal**: `trips.{tripId}`, privado.
- **Tabla nueva `trip_positions`** con `id`, `trip_id`, `pilot_id`, `latitude` `decimal(10,8)`, `longitude` `decimal(11,8)`, `recorded_at` y `timestamps`. Sin `status`, sin `deleted_at`, sin `registered_by`.
- **Modelo `TripPosition` con su factory.** Con `casts()` (`recorded_at` a `datetime`, las dos coordenadas a `decimal`), sin `SoftDeletes` y sin normalización: no hay ni un campo de texto.
- **Dominio `TripPosition` completo** en su subcarpeta, con la cadena de capas de siempre: `TripPositionServiceInterface`, `TripPositionService`, `TripPositionProvider`, `StoreTripPositionRequest`, `TripPositionResource` y `TripPositionController`. **`TripPositionService` inyecta `TripServiceInterface` por constructor** para resolver el viaje y su ámbito sin duplicar la matriz de SPEC 24 (precedente: el `FreightRateService` que inyectaba `ZoneServiceInterface` hasta SPEC 15).
- **Dos rutas nuevas**, declaradas en `routes/trips.php` **antes** del `apiResource`, junto a `/assignment`, `/start` y `/finish`:

  | Ruta | Rol | Efecto |
  |---|---|---|
  | `POST /api/trips/{trip}/positions` | `pilot` asignado | Guarda el punto y lo emite al canal |
  | `GET /api/trips/{trip}/positions` | cualquier autenticado **menos `pilot`** | Rastro acumulado, orden `recorded_at asc` |

- **`POST` — cuerpo de dos campos**, `latitude` (`required|numeric|between:-90,90`) y `longitude` (`required|numeric|between:-180,180`), con `messages()` en español. **Un punto por petición**; `recorded_at` y `pilot_id` los pone el servidor, nunca el body.
- **Cuatro guardas del `POST`**, cada una con su código y su mensaje:
  - viaje borrado → **400 «El viaje ya fue eliminado»** (contrato de SPEC 24);
  - quien llama no es el `pilot_id` del viaje → **403**;
  - el viaje no está `in_route` → **400**;
  - **piso de 15 segundos**: si el último punto guardado tiene menos de 15 s, la petición responde **200 sin guardar nada y sin emitir**, devolviendo el último punto ya existente. Silencio deliberado, con el precedente del archivo ignorado de SPEC 19.
- **Evento `App\Events\Trip\TripPositionUpdated`** con **`ShouldBroadcastNow`** (sale dentro de la petición del piloto, sin worker), sobre `PrivateChannel('trips.'.$tripId)`, alias de broadcast **`.trip.position.updated`** y payload de **seis claves**: `tripId`, `latitude`, `longitude`, `recordedAt` (`d-m-Y h:i:s A`), `pilotId` y `pilotName`.
- **Autorización del canal `trips.{tripId}`**, con dos reglas y en este orden: el rol **no puede ser `pilot`**, y el usuario debe alcanzar el viaje según el ámbito de SPEC 24 — `administrator` y `manager` cualquiera, `carrier` los de su empresa más la bolsa libre. Se resuelve reusando `TripServiceInterface::getTripById()` en `try/catch`: si lanza (404 o 403), el callback devuelve `false` y Reverb responde 403.
- **`GET` con el mismo ámbito**, resuelto por el mismo camino: 404 si el viaje no existe o está borrado, **403 si el que consulta es `pilot`** o queda fuera de ámbito. Orden `recorded_at asc, id asc` y paginación **opt-in por `limit`** acotada a `[10, 100]`, como el resto del proyecto.
- **`TripPositionResource` con cinco claves**: `id`, `latitude`, `longitude` (las dos **string**, como en `Location` desde SPEC 15), `recordedAt` y `pilotId`.
- **Si Reverb está caído, el punto se guarda igual.** El fallo del broadcast no revienta la petición del piloto ni deja la fila sin escribir: se registra en el log y el `POST` responde 201.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.
- Resumen de integración para el frontend en `references/trip-positions-api.md`, incluyendo la configuración de Echo (`authEndpoint`, `Authorization: Bearer`, nombre del canal y del evento).

**Fuera de alcance (para specs futuras):**

- **Lote de puntos.** El `POST` acepta **uno**. Un piloto que estuvo sin señal pierde el tramo: cuando recupere red mandará su posición actual y el rastro tendrá un hueco. Decidido a propósito.
- **Borrado de cualquier tipo.** No hay `DELETE` de un punto, no hay purga por antigüedad, no hay `deleted_at` y el `DELETE` (baja lógica) del viaje **no toca** `trip_positions`. La FK va **sin `cascade`**. Un viaje de 6 h reportando cada 15 s deja ~1 440 filas y ahí se quedan.
- **Editar o corregir un punto.** No hay `PATCH`. Una coordenada mala es historial.
- **Telemetría.** Nada de `speed`, `heading`, `accuracy`, `altitude` ni `battery`. Solo dónde estuvo y cuándo.
- **Validar la posición contra algo.** No se comprueba que el punto caiga cerca de la `polyline`, ni dentro de Guatemala, ni que la distancia contra el punto anterior sea físicamente posible. Un piloto puede reportar coordenadas en Noruega y la API las guarda.
- **Alertas, geocercas y desvíos.** Nadie recibe correo ni push por nada. No hay «llegó al puerto», no hay «lleva 40 min parado».
- **ETA y recálculo de ruta.** La `polyline` de SPEC 24 no se toca ni se recalcula, y no se compara con el rastro real.
- **`TripResource` y `TripListResource` intactos.** El viaje no gana `lastLatitude`, `lastLongitude` ni `lastPositionAt`, y la tabla `trips` **no gana ni una columna**.
- **Canal de flota.** No hay `trips` global ni `carriers.{id}.trips`: para seguir tres viajes hay que suscribirse a tres canales.
- **Canal de presencia.** `trips.{tripId}` es `PrivateChannel`, no `PresenceChannel`: nadie sabe quién más está mirando el mapa.
- **Que el piloto escuche.** El piloto emite y nada más: queda fuera del canal **y** fuera del `GET`.
- **Client events / whisper.** Ningún cliente publica por el websocket; la única entrada es el `POST`.
- **Replay al conectar.** Reverb no reenvía lo perdido: quien se conecta a mitad de viaje pide el rastro con el `GET` y desde ahí escucha. Esa costura la cose el frontend.
- **Cola y worker.** `ShouldBroadcastNow` a propósito: esta spec no introduce ningún proceso de cola ni depende de que haya uno.
- **Rate limiting por IP o por token.** El único freno es el piso de 15 s por viaje.
- **Reportar sin viaje en ruta.** No existe «posición del piloto» suelta: sin un viaje `in_route` asignado, un piloto no tiene dónde mandar nada.

---

## Modelo de datos

Esta spec crea **una tabla, un modelo, una factory y un evento**, y **no toca ninguna tabla existente**: `trips` no gana ni una columna y ningún enum cambia.

### 1. Tabla `trip_positions`

```php
Schema::create('trip_positions', function (Blueprint $table) {
    $table->id();

    /**
     * El viaje al que pertenece el punto. Sin cascade, como toda FK del proyecto:
     * el DELETE del viaje es baja lógica y no debe llevarse el rastro por delante.
     */
    $table->foreignId('trip_id')->constrained('trips');

    /**
     * Quién lo reportó. Redundante hoy —siempre es el `pilot_id` del viaje—, pero la
     * asignación de SPEC 24 se puede cambiar mientras el viaje sigue `pending`, y el
     * rastro debe seguir diciendo quién conducía cuando se grabó cada punto.
     */
    $table->foreignId('pilot_id')->constrained('users');

    /** Misma precisión que `locations` (SPEC 15): milimétrica y sin flotantes. */
    $table->decimal('latitude', 10, 8);
    $table->decimal('longitude', 11, 8);

    /**
     * Lo pone el servidor con now(), nunca el dispositivo: no hay forma de mentir
     * sobre cuándo se estuvo dónde, y el orden del rastro es siempre el de llegada.
     */
    $table->timestamp('recorded_at');

    $table->timestamps();

    /**
     * El índice compuesto sirve a las dos únicas consultas del dominio: el listado
     * ordenado del rastro y el «último punto de este viaje» del piso de 15 segundos.
     */
    $table->index(['trip_id', 'recorded_at']);
});
```

- **Ninguna columna nullable** y **ningún índice único**: dos puntos idénticos en coordenadas son legítimos (un camión parado sigue reportando).
- **No hay `registered_by`.** Segunda tabla del proyecto sin él teniendo autor, tras `pilot_documents` (SPEC 25): aquí el autor es `pilot_id`, y llamarlo de las dos formas sería mentir.
- **`recorded_at` y `created_at` valen casi lo mismo hoy** —los separa el tiempo de la petición—, y aun así `recorded_at` existe por separado: es el dato de negocio, y si algún día se acepta la hora del dispositivo (lote offline, fuera de alcance), la columna ya está y `created_at` sigue siendo la hora de llegada.

### 2. Modelo `TripPosition`

```php
#[Fillable(['trip_id', 'pilot_id', 'latitude', 'longitude', 'recorded_at'])]
class TripPosition extends Model
{
    /** @use HasFactory<TripPositionFactory> */
    use HasFactory;

    /** @return BelongsTo<Trip, $this> */
    public function trip(): BelongsTo;

    /** @return BelongsTo<User, $this> */
    public function pilot(): BelongsTo;

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'recorded_at' => 'datetime',
        ];
    }
}
```

- **Sin `SoftDeletes`** y sin normalización: no hay un solo campo de texto de negocio.
- Los mismos `decimal:8` de `Location`, para que las coordenadas salgan como **string** por el Resource y no como float.
- **`Trip` no gana `positions(): HasMany`.** El acceso va siempre por el service, que consulta `TripPosition` directamente; añadir la relación invitaría a un `with('positions')` en el listado de viajes que traería miles de filas. Mismo criterio con el que SPEC 14 dejó `Vehicle` sin `expenses()`.

### 3. Salida — `TripPositionResource`

**Cinco claves**, en camelCase:

```json
{
  "id": 4821,
  "latitude": "14.62807400",
  "longitude": "-90.52255400",
  "recordedAt": "07-09-2026 08:14:03 AM",
  "pilotId": 12
}
```

- Sin `tripId`: quien pide el rastro ya lo tiene en la URL, y quien recibe el evento lo trae en el payload.
- `recordedAt` en `d-m-Y h:i:s A`, como el resto del proyecto.

### 4. Evento `App\Events\Trip\TripPositionUpdated`

**Primera clase bajo `app/Events/` del proyecto.**

```php
class TripPositionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TripPosition $position, public string $pilotName) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('trips.'.$this->position->trip_id)];
    }

    /** Alias con punto inicial: el cliente escucha '.trip.position.updated', sin namespace PHP. */
    public function broadcastAs(): string
    {
        return 'trip.position.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'tripId' => $this->position->trip_id,
            'latitude' => $this->position->latitude,
            'longitude' => $this->position->longitude,
            'recordedAt' => $this->position->recorded_at?->format('d-m-Y h:i:s A'),
            'pilotId' => $this->position->pilot_id,
            'pilotName' => $this->pilotName,
        ];
    }
}
```

- **`ShouldBroadcastNow`, no `ShouldBroadcast`**: sin worker de cola, un evento encolado no llegaría nunca.
- **`broadcastWith()` explícito**: sin él viajaría el modelo entero serializado, con `created_at`, `updated_at` y lo que se añada después. El payload es contrato.
- **`pilotName` se pasa por constructor**, no se lee desde `$position->pilot->name`: el service ya tiene el viaje con su piloto cargado y así el evento no dispara una consulta extra por punto.

### 5. Canal — `routes/channels.php`

```php
/**
 * Único canal del proyecto. Dos reglas, en este orden: el piloto nunca escucha
 * —emite y ya—, y el resto solo alcanza los viajes que ya podría ver por HTTP.
 *
 * El ámbito no se reescribe aquí: se delega en getTripById(), que lanza 404 fuera
 * del alcance y 403 fuera de la empresa. Duplicar la matriz de SPEC 24 sería la
 * forma más rápida de que el canal y el endpoint dejen de decir lo mismo.
 */
Broadcast::channel('trips.{tripId}', function (User $user, int $tripId): bool {
    if ($user->role === UserRole::Pilot) {
        return false;
    }

    try {
        app(TripServiceInterface::class)->getTripById($user, $tripId);

        return true;
    } catch (Throwable) {
        return false;
    }
});
```

- El callback recibe el usuario que resolvió `jwt.auth` en `POST /api/broadcasting/auth`, **no** el guard `web`.
- Devuelve `bool`, no un array: al no ser canal de presencia, no hay datos del suscriptor que compartir.

### 6. Factory

`TripPositionFactory` apoyada en `Trip::factory()->inRoute()` para `trip_id`, con `pilot_id` tomado del viaje, coordenadas dentro de Guatemala (`fake()->latitude(13.7, 17.8)`, `fake()->longitude(-92.2, -88.2)`) y `recorded_at` en `now()`. **Sin estados**: no hay variantes que representar.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo.

1. **Cableado del broadcasting.** La dependencia `laravel/reverb` ya está instalada, así que el paso arranca en `php artisan install:broadcasting --no-interaction` (publica `config/broadcasting.php`, `config/reverb.php` y crea `routes/channels.php`; **no** se instala nada de frontend: este backend no tiene `resources/js`). Añadir las seis claves `REVERB_*` a `.env.example` con `BROADCAST_CONNECTION=reverb`, y dejar **`phpunit.xml` con `BROADCAST_CONNECTION=null`** como está hoy. En `bootstrap/app.php`, sustituir lo que haya publicado el instalador por:

   ```php
   ->withBroadcasting(
       __DIR__.'/../routes/channels.php',
       ['prefix' => 'api', 'middleware' => ['jwt.auth']],
   )
   ```

   y escribir en `routes/channels.php` el callback de `trips.{tripId}` de la sección anterior. Nota en el README: el seguimiento en vivo exige **`php artisan reverb:start`** corriendo; sin él la API responde igual y los puntos se guardan, pero nadie recibe nada.
   **Verificación:** `php artisan route:list --path=broadcasting` muestra `POST api/broadcasting/auth` con `jwt.auth`, y `php artisan reverb:start` arranca sin error.

2. **Migración, modelo y factory.** `php artisan make:model TripPosition -mf`. Rellenar la migración con las cinco columnas, las dos FK sin `cascade` y el índice compuesto `['trip_id', 'recorded_at']`; el modelo con su `#[Fillable]`, `casts()` y las dos relaciones `belongsTo`; la factory apoyada en `Trip::factory()->inRoute()`, sin estados. Correr `php artisan migrate`.
   **Verificación:** `php artisan tinker --execute 'TripPosition::factory()->create();'` no revienta.

3. **Evento.** `php artisan make:event Trip/TripPositionUpdated` con `ShouldBroadcastNow`, `broadcastOn()` sobre `PrivateChannel('trips.'.$tripId)`, `broadcastAs()` devolviendo `trip.position.updated` y `broadcastWith()` con las seis claves. Es la primera clase bajo `app/Events/`.
   **Verificación:** Unit test que construye el evento con una `TripPosition` de factory y comprueba el nombre del canal, el alias y las seis claves exactas del payload — sin red y sin Reverb.

4. **Contrato.** `app/Interfaces/TripPosition/TripPositionServiceInterface.php` con **dos** métodos, PHPDoc de array shapes y `@throws`:

   ```php
   public function getPositions(User $user, int $tripId, array $filters): LengthAwarePaginator|Collection;

   public function create(User $user, int $tripId, array $data): TripPosition;
   ```

   Los dos reciben `User` porque el ámbito y la autoría dependen siempre de quién llama; ninguno recibe un `Trip`, sino su id: el viaje lo resuelve el service.

5. **Service, guardas privadas.** `app/Services/TripPosition/TripPositionService.php`, con `TripServiceInterface` inyectado **por constructor**, su `resolvePerPage()` con `MIN_PER_PAGE`/`MAX_PER_PAGE` y la constante `MIN_SECONDS_BETWEEN_POSITIONS = 15`:
   - `resolveReportableTrip(User $user, int $tripId): Trip` — consulta `Trip::withTrashed()`; **404** si no existe, **400 «El viaje ya fue eliminado»** si tiene `deleted_at`, **403** si `pilot_id` no es el que llama, **400** si el `status` no es `in_route`. Ese orden es contrato.
   - `resolveTrackedTrip(User $user, int $tripId): Trip` — **403** si el rol es `pilot`; en otro caso delega en `TripServiceInterface::getTripById()`, que ya lanza 404 fuera de alcance y 403 fuera de empresa.
   - `lastPositionFor(int $tripId): ?TripPosition` — el último punto por `recorded_at desc, id desc`, que alimenta el piso de 15 s.

   **Verificación:** Unit test del service sobre las tres, sin tocar HTTP.

6. **Service, los dos métodos públicos.** `#[Override]` en ambos:
   - `getPositions()` — resuelve el viaje con `resolveTrackedTrip()`, consulta por `trip_id`, orden `recorded_at asc, id asc` y paginación opt-in. **Sin filtros**: no hay `dateFrom`/`dateTo` ni nada más.
   - `create()` — resuelve con `resolveReportableTrip()`; si `lastPositionFor()` devuelve un punto de hace menos de 15 s, **devuelve ese punto tal cual, sin escribir y sin emitir**; si no, crea la fila con `recorded_at = now()` y `pilot_id` del usuario, y dispara `TripPositionUpdated` con el nombre del piloto. El `event()` va **envuelto en `try/catch`** que registra en el log y sigue: Reverb caído no puede tumbar el `POST` ni dejar la fila sin escribir.

7. **Provider.** `app/Providers/TripPosition/TripPositionProvider.php` con el `bind(TripPositionServiceInterface::class, TripPositionService::class)`, registrado en `bootstrap/providers.php`.

8. **FormRequest, Resource, Controller y rutas.**
   - `StoreTripPositionRequest` con `latitude` y `longitude` obligatorios y sus `messages()` en español. **No hay FormRequest de índice**: a diferencia de SPEC 14 y SPEC 18, el id del viaje viaja en la URL, no en un query param, así que no hay nada obligatorio que validar.
   - `TripPositionResource` con las cinco claves.
   - `TripPositionController` con `index` y `store`, `try/catch` → `ResponseHandler` y el service **inyectado por parámetro de método**. `store` responde **201** con el punto —también cuando el piso de 15 s lo descartó, devolviendo el último—, e `index` envuelve el listado paginado en `PaginatedResource`.
   - En `routes/trips.php`, las dos rutas **antes** del `apiResource`, junto a las otras tres fijas: `POST /{trip}/positions` con `role:pilot`, `GET /{trip}/positions` sin `role:` (el ámbito lo aplica el service). Ninguna lleva `carrier.required`.

   **Verificación:** `php artisan route:list --path=trips` muestra **diez** rutas y las dos nuevas aparecen **antes** de `trips/{trip}`.

9. **Cierre.** `vendor/bin/pint --dirty --format agent`, tests Pest con el agente `feature-tests` (Feature de los dos endpoints con la matriz de roles y las cuatro guardas del `POST` + `Event::fake()` para el broadcast; Unit del service, del evento y del callback del canal — **nada sale a la red y Reverb nunca se levanta**), Swagger con el agente `endpoint-docs`, y `references/trip-positions-api.md` con la configuración de Echo para el frontend.

---

## Criterios de aceptación

**Infraestructura**

- [x] `composer.json` incluye `laravel/reverb`; existen `config/broadcasting.php` y `config/reverb.php`, y `.env.example` trae las seis claves `REVERB_*` con `BROADCAST_CONNECTION=reverb`.
- [x] `phpunit.xml` mantiene `BROADCAST_CONNECTION=null`; la suite completa pasa sin levantar Reverb ni salir a la red.
- [x] `php artisan route:list --path=broadcasting` muestra `POST api/broadcasting/auth` con middleware `jwt.auth`.
- [x] `POST /api/broadcasting/auth` sin `Authorization` responde **401** con el sobre habitual.
- [x] `php artisan route:list --path=trips` muestra **diez** rutas, con `trips/{trip}/positions` **antes** de `trips/{trip}`.

**Reportar posición — `POST /api/trips/{trip}/positions`**

- [x] El piloto asignado, con el viaje `in_route`, recibe **201** y la fila queda en `trip_positions` con `recorded_at` puesto por el servidor.
- [x] `recorded_at` y `pilot_id` **no se pueden fijar desde el body**: mandarlos no cambia nada.
- [x] Un `pilot` que no es el `pilot_id` del viaje recibe **403**; un `administrator`, `carrier` o `manager` recibe **403** por el middleware `role:pilot`.
- [x] Viaje `pending` → **400**; viaje `finished` → **400**; viaje con `deleted_at` → **400 «El viaje ya fue eliminado»**; id inexistente → **404**.
- [x] `latitude` fuera de `[-90, 90]` o `longitude` fuera de `[-180, 180]` → **422**, con mensaje en español. Cuerpo vacío → **422**.
- [x] Un segundo punto **antes de 15 s** responde **200**, no crea fila (`trip_positions` sigue con el mismo `count()`) y **no dispara el evento**; la respuesta trae el punto anterior.
- [x] Un segundo punto **pasados 15 s** crea fila y dispara el evento.
- [x] Con el broadcast fallando (driver que lanza), el `POST` sigue respondiendo **201** y la fila queda escrita.

**Evento y canal**

- [x] `TripPositionUpdated` implementa `ShouldBroadcastNow`, emite en `private-trips.{tripId}` y su `broadcastAs()` devuelve `trip.position.updated`.
- [x] `broadcastWith()` devuelve **exactamente seis claves**: `tripId`, `latitude`, `longitude`, `recordedAt` (`d-m-Y h:i:s A`), `pilotId`, `pilotName`.
- [x] El callback de `trips.{tripId}` devuelve `false` para cualquier `pilot`, **incluido el asignado a ese viaje**.
- [x] Devuelve `true` para `administrator` y `manager` en cualquier viaje; `true` para un `carrier` sobre un viaje que asignó su empresa o sobre uno `pending` sin tripulación; **`false`** para un `carrier` sobre un viaje asignado por otra empresa.
- [x] Devuelve `false` para un `tripId` inexistente o borrado.

**Consultar el rastro — `GET /api/trips/{trip}/positions`**

- [x] `administrator`, `manager` y el `carrier` dentro de ámbito reciben **200** con los puntos ordenados por `recorded_at` **ascendente**.
- [x] Cualquier `pilot` recibe **403**, incluido el asignado al viaje.
- [x] Un `carrier` fuera de ámbito recibe **403**; un id inexistente o borrado, **404**.
- [x] Sin `limit` devuelve la colección completa; con `limit` numérico pagina acotado a `[10, 100]` y `total`/`currentPage`/`lastPage` salen en la **raíz** del sobre.
- [x] Un viaje sin puntos devuelve **lista vacía con 200**, no 404.
- [x] Cada elemento trae **cinco claves** y `latitude`/`longitude` salen como **string** de ocho decimales.

**Lo que no debe cambiar**

- [x] `TripResource` sigue con **35 claves** y `TripListResource` con las suyas; la tabla `trips` no gana ninguna columna.
- [x] Los ocho endpoints de SPEC 24 responden exactamente igual que antes de esta spec.
- [x] `DELETE /api/trips/{trip}` sigue siendo baja lógica y **no borra** ninguna fila de `trip_positions`.
- [x] `Trip` no tiene relación `positions()`.
- [x] No existe ninguna ruta que borre o edite una posición.

**Cierre**

- [x] `vendor/bin/pint --dirty --format agent` sale limpio.
- [x] `php artisan test --compact` pasa entero.
- [x] `storage/api-docs/api-docs.json` documenta los dos endpoints nuevos.
- [x] Existe `references/trip-positions-api.md` con el canal, el alias del evento, el payload y la configuración de Echo (`authEndpoint` + `Authorization: Bearer`).

---

## Decisiones tomadas y descartadas

**Reverb, no Pusher ni Soketi.** Se eligió el servidor first-party: sin coste por conexión ni por mensaje, sin un tercero en medio de un backend interno, y es lo que Laravel 13 asume por defecto. El precio aceptado es un **proceso permanente que antes no existía** — hasta hoy desplegar era subir PHP detrás de un servidor web. Descartado Pusher (SaaS de pago para tráfico que se queda dentro de la empresa) y Soketi/Ably (mismo trabajo de infraestructura sin ser first-party).

**La posición entra por HTTP, no por el websocket.** El piloto hace `POST` cada cierto tiempo y el websocket es solo **salida**. Así la ingesta conserva JWT, FormRequest, `ResponseHandler` y la cadena de capas del proyecto, y «las posiciones se van guardando» es una consecuencia natural, no un oyente aparte. Descartados los *client events* (whisper): el servidor no vería el mensaje salvo suscribiéndose a su propio canal, y la validación quedaría en manos del cliente.

**`ShouldBroadcastNow`, no `ShouldBroadcast`.** El proyecto tiene `QUEUE_CONNECTION=database` pero **ningún worker declarado**; un evento encolado no llegaría nunca. El coste son unos milisegundos extra en el `POST` del piloto. Si algún día hay worker, cambiar la interfaz es una línea.

**Reverb caído no rompe nada.** El `event()` va en `try/catch`: se pierde el aviso en vivo, no el dato. La alternativa —dejar que la excepción suba y responder 500— convertiría una caída del websocket en pérdida de rastro, que es justo lo contrario de lo que pide la funcionalidad.

**El ámbito del canal no se reescribe: se delega en `getTripById()`.** Duplicar la matriz de SPEC 24 en `routes/channels.php` sería la forma más rápida de que el canal y el endpoint dejen de decir lo mismo tras la primera spec que toque el ámbito. El precio es un `try/catch` sobre excepciones usadas como booleano, que es feo y está asumido.

**El piloto emite y nada más.** Fuera del canal y fuera del `GET`. Su app ya conoce su propia posición y no gana nada escuchando el eco. Descartado meterlo en el canal solo para su viaje: es una regla más en el único sitio donde un fallo se traduce en fuga de datos.

**`recorded_at` lo pone el servidor.** Se descartó aceptarlo del dispositivo. Fiel al reloj del piloto, sí, pero falsificable, y obligaría a ordenar el rastro por un valor que puede llegar desordenado. La consecuencia aceptada: un tramo sin señal se pierde entero en vez de llegar tarde y completo.

**Piso de 15 segundos con 200, no con 400.** La app del piloto reintenta cuando la red va mal; devolverle un error por reintentar la empujaría a lógica defensiva propia. Se responde 200 con el último punto y no se escribe nada. Precedente literal: el archivo ignorado en silencio de un gasto con `is_invoiced=false` (SPEC 19). Descartado no poner piso: un envío cada 5 s en un viaje de 6 h son ~4 300 filas por viaje sin ganar precisión útil.

**Ruta anidada, contra el precedente de SPEC 14 y SPEC 18.** Allí se evitó `/api/vehicles/{vehicle}/expenses` y el vínculo viajó en el body y en un query param obligatorio. Aquí se anida porque `{trip}` **ya es el parámetro del grupo** —`/start`, `/finish` y `/assignment` viven ahí— y porque una posición sin viaje no es nada: no hay listado global de posiciones que tenga sentido. El coste de la incoherencia se paga a cambio de no inventar un tercer `vehicleId`-obligatorio-en-query.

**Un punto por petición.** El lote offline queda fuera. Aceptar un array obligaría a decidir qué pasa si tres de veinte puntos son inválidos —¿422 entero, o guardar los buenos?— y a reabrir la decisión de `recorded_at`. Es una spec propia, no un parámetro extra.

**Nada se borra, nunca.** Sin `DELETE`, sin purga, sin `SoftDeletes` y sin `cascade`. El rastro es historial, y ~1 440 filas por viaje son baratas. Cuando la tabla estorbe, se archiva fuera de la aplicación.

**`TripResource` intacto.** Se descartó añadir `lastLatitude`/`lastLongitude`/`lastPositionAt`: el listado de viajes haría una consulta por fila para calcularlas, y quien pinta un mapa ya pide el rastro con el `GET`. El viaje sigue con las 35 claves que le dejó SPEC 25.

**`pilot_id` en la tabla aunque hoy sea derivable.** SPEC 24 permite cambiar la asignación mientras el viaje sigue `pending`; guardar quién reportó cada punto hace que el rastro siga siendo cierto después de una reasignación.

**Sin `Trip::positions()`.** La relación invitaría a un `with('positions')` que traería miles de filas en el listado de viajes. Mismo criterio con el que SPEC 14 dejó `Vehicle` sin `expenses()`.

**`PrivateChannel`, no `PresenceChannel`.** Nadie necesita saber quién más está mirando el mapa, y presencia obligaría a devolver datos del suscriptor desde el callback.

**Ninguna validación geográfica.** No se comprueba que el punto caiga cerca de la `polyline`, ni dentro de Guatemala, ni que el salto contra el punto anterior sea físicamente posible. Es lo que abriría la puerta a alertas por desvío, y eso es otra spec entera.

---

## Riesgos identificados

**El proceso de Reverb es infraestructura nueva y nadie la vigila.** Si `reverb:start` no está corriendo —despliegue sin supervisor, servidor reiniciado, proceso muerto—, la API responde 201 a cada `POST`, los puntos se guardan y **el mapa del administrador simplemente no se mueve**, sin ningún error visible. El fallo es silencioso por diseño (`try/catch` en el `event()`); la única señal es el log. Mitigación mínima: dejarlo escrito en el README y anotarlo en `references/trip-positions-api.md`, para que el frontend sepa que «no llega nada» es un síntoma de servidor, no de código.

**El puerto de Reverb tiene que estar abierto y llegar al navegador.** El backend deja de bastarse con el puerto del servidor web: `REVERB_HOST`/`REVERB_PORT` deben ser alcanzables desde el cliente, y si la aplicación va por HTTPS el websocket tiene que ir por `wss` detrás de un proxy. Es la parte de la spec que **no se puede verificar con tests** y donde es más probable que se pierda una tarde.

**La autorización del canal es el único sitio del proyecto donde una excepción se usa como booleano.** Si algún día `getTripById()` empieza a lanzar por un motivo nuevo —una conexión de base de datos caída, por ejemplo—, el callback devolverá `false` y el usuario verá un 403 en vez de un error real. Se acepta a cambio de no duplicar la matriz de ámbito, pero conviene tenerlo presente al depurar un «no me deja suscribirme».

**Crecimiento de la tabla sin freno.** ~1 440 filas por viaje de 6 h y ninguna política de borrado. Con decenas de viajes diarios, `trip_positions` será en pocos meses la tabla más grande del proyecto. El índice compuesto cubre las dos consultas que existen hoy, así que el riesgo no es de rendimiento inmediato sino de tamaño; el día que estorbe habrá que archivar fuera de la aplicación.

**El `GET` sin `limit` devuelve el rastro entero.** La paginación es opt-in en todo el proyecto y aquí se mantiene por coherencia, pero es el primer listado donde «entero» puede significar miles de elementos. Un frontend que pinte el mapa sin `limit` sobre un viaje largo se traerá todo de golpe. Está documentado en la referencia, no forzado por la API.

**Huecos en el rastro y ninguna forma de saberlo.** Sin lote offline, un tramo sin señal desaparece: la línea del mapa saltará en recta entre dos puntos lejanos y nada indicará que faltó cobertura. El frontend no puede distinguir «el camión no se movió» de «el camión no reportó».

**Un piloto puede reportar cualquier coordenada.** No hay validación geográfica: basta con su token y un viaje `in_route` propio para escribir puntos arbitrarios. Es riesgo de dato falso, no de fuga: no toca ningún otro viaje ni ninguna otra empresa.

**El piso de 15 s responde 200 a algo que no se guardó.** Es la decisión con más probabilidad de confundir a quien integre: la respuesta es indistinguible de un alta salvo porque el `id` y el `recordedAt` son los del punto anterior. Debe quedar explícito en la referencia del frontend.
