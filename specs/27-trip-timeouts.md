# SPEC 27 — Paradas del viaje (trip timeouts)

> **Estado:** Implementado
> **Depende de:** SPEC 24, SPEC 26
> **Fecha:** 2026-09-10
> **Objetivo:** Detectar automáticamente las paradas del camión al escribir cada posición del rastro —un punto a menos de 5 m del anterior abre un `trip_timeout` con la hora del primer punto del reposo, y el primer punto a 5 m o más del ancla lo cierra—, exponerlas en `GET /api/trips/{trip}/timeouts` y cerrar la parada abierta cuando el piloto finaliza el viaje.

## Por qué esta spec existe

1. **Es la primera spec que deriva datos nuevos de un dato ya existente**, sin que el cliente mande nada: el rastro de SPEC 26 ya contenía las paradas, pero solo implícitamente —había que recorrer los puntos uno a uno para encontrarlas—. Aquí se materializan en su propia tabla en el momento de escribir el punto.
2. **Es la primera escritura del proyecto que no la pide nadie.** Ningún endpoint crea un `trip_timeout`: nacen como efecto lateral del `POST /api/trips/{trip}/positions`, y el `PATCH /{trip}/finish` cierra el que esté abierto. No hay `POST`, ni `PATCH`, ni `DELETE` de una parada.
3. **Es la primera vez que `TripService` escribe en una tabla de otro dominio.** `finish()` cierra la parada abierta tocando el modelo `TripTimeout` directamente, sin inyectar su contrato: hacerlo por contrato crearía una dependencia circular en el contenedor, porque `TripTimeoutService` ya inyecta `TripServiceInterface` para resolver el ámbito de lectura.

---

## Alcance

**Dentro:**

- **Tabla nueva `trip_timeouts`** con `id`, `trip_id`, `pilot_id`, `start_position_id`, `end_position_id` (nullable), `latitude` `decimal(10,8)`, `longitude` `decimal(11,8)`, `started_at`, `ended_at` (nullable) y `timestamps`. Las coordenadas son las del **ancla** (el primer punto del reposo), duplicadas a propósito para que el mapa pinte el pin de la parada sin un segundo viaje a la base. Sin `status`, sin `deleted_at` y sin `registered_by`: el autor es `pilot_id`, como en `trip_positions`.
- **Modelo `TripTimeout` con su factory**, con `casts()` (`started_at` y `ended_at` a `datetime`, las dos coordenadas a `decimal:8`), sin `SoftDeletes` y sin normalización. Relaciones `trip()`, `pilot()`, `startPosition()` y `endPosition()`; **`Trip` no gana `timeouts()`** y **`TripPosition` no gana nada**, igual que `Trip` no ganó `positions()` en SPEC 26.
- **Dominio `TripTimeout`** en su subcarpeta, con la cadena de capas del proyecto: `TripTimeoutServiceInterface`, `TripTimeoutService`, `TripTimeoutProvider` (registrado en `bootstrap/providers.php`), `TripTimeoutResource` y `TripTimeoutController`. **Sin FormRequest**: el `GET` no tiene cuerpo ni query param obligatorio, y el índice lee `limit` con el mismo helper privado del controller de posiciones.
- **`TripTimeoutService` inyecta `TripServiceInterface` por constructor** para resolver el viaje y el ámbito de lectura sin duplicar la matriz de SPEC 24, exactamente como `TripPositionService`.
- **Contrato nuevo con dos métodos y ninguno más**: `getTimeouts(User $user, int $tripId, array $filters)` para la lectura y `trackPosition(TripPosition $position): void` para la detección, que es el único camino de escritura del dominio.
- **`App\Services\TripTimeout\DistanceCalculator`** con la fórmula de **Haversine** (radio 6 371 000 m) y la constante de los **5 metros**. Es el algoritmo que se prueba **sin base de datos y sin red**, con el precedente literal de `PolylineDecoder` (SPEC 16). **No se usa PostGIS**: la tabla no tiene columna geográfica y castear coordenadas en cada consulta no aporta nada a una comparación de dos puntos.
- **Detección al escribir el punto**, dentro de `TripPositionService::create()` y **solo en la rama del 201**: un punto descartado por el piso de 15 s de SPEC 26 no abre, no cierra y no toca nada. `TripPositionService` inyecta `TripTimeoutServiceInterface` por constructor, junto al `TripServiceInterface` que ya tenía.
- **Las dos reglas de la detección, en este orden:**
  - **Hay parada abierta** (`ended_at IS NULL`) para ese viaje → se mide la distancia del punto nuevo al **ancla** de la parada. A menos de 5 m, no pasa nada: la fila sigue abierta y **no se actualiza ninguna columna**. A 5 m o más, se cierra con `ended_at = recorded_at` del punto nuevo y `end_position_id` = su id.
  - **No hay parada abierta** → se mide la distancia del punto nuevo al **punto anterior** del viaje. A menos de 5 m se abre una parada cuyo ancla es el **punto anterior**: `started_at` es su `recorded_at`, `start_position_id` su id y las coordenadas las suyas. A 5 m o más, no pasa nada. Sin punto anterior (el primero del viaje) no hay nada que medir.
- **Un punto nunca cierra y abre en la misma petición**: el punto que cierra una parada es, por definición, un punto en movimiento.
- **Cierre por `PATCH /api/trips/{trip}/finish`**: `TripService::finish()` cierra la parada abierta del viaje —si la hay— con `ended_at = now()`, coherente con el `end_date = now()` que ya escribe, y **`end_position_id` queda `null`**. Ese `null` es lo que distingue «cerrada porque el camión se movió» de «cerrada porque el viaje terminó». Se hace tocando el modelo `TripTimeout` directamente, **sin inyectar su contrato y sin cambiar `TripServiceInterface`**, porque `TripTimeoutService` ya depende de `TripServiceInterface` y el contrato en sentido inverso cerraría un ciclo en el contenedor. Precedente: `TripPositionService` ya lee `Trip::withTrashed()->find()`.
- **Una ruta nueva y ninguna más**: `GET /api/trips/{trip}/timeouts`, declarada en `routes/trips.php` junto a las dos de posiciones, **antes** del `apiResource`, con `jwt.auth` a secas y sin `role:`.
- **El `GET` es calco del rastro de SPEC 26**: **403 a cualquier `pilot`**, incluido el asignado al viaje («El piloto emite y nada más»); `administrator` y `manager` alcanzan cualquier viaje; un `carrier`, los que asignó su empresa más la bolsa libre, y fuera de ámbito es **403, no 404**; viaje inexistente o borrado es **404**; un viaje sin paradas es **200 con `data` vacío, nunca 404**. Orden **`started_at asc, id asc`** y paginación **opt-in por `limit`** acotada a `[10, 100]`.
- **`TripTimeoutResource` con nueve claves**: `id`, `latitude`, `longitude` (las dos **string** de ocho decimales), `startedAt` y `endedAt` con el formato `d-m-Y h:i:s A` del resto del proyecto (`endedAt` es `null` mientras la parada siga abierta), `durationMinutes`, `pilotId`, `startPositionId` y `endPositionId`.
- **`durationMinutes` es campo calculado en lectura**, sin columna, con el precedente de `currentValue` (SPEC 17): `round(segundos / 60, 2)` —el mismo redondeo de dos decimales de `durationHours` (SPEC 16)— y **`null` mientras la parada esté abierta**, porque un valor medido contra `now()` cambiaría en cada lectura y no sería comparable.
- Índices: compuesto `(trip_id, started_at)` —que sirve al listado y a la búsqueda de la parada abierta— y FKs **sin `cascade`**, como en `trip_positions`.
- Tests Pest delegados al agente `feature-tests`, más el Unit de `DistanceCalculator`, y documentación Swagger al agente `endpoint-docs`.
- Resumen de integración para el frontend en `references/trip-timeouts-api.md`.

**Fuera de alcance (para specs futuras):**

- **Crear, editar o borrar una parada a mano.** No hay `POST`, ni `PATCH`, ni `DELETE`: la única escritura es el efecto lateral del `POST` de posiciones y el cierre del `finish`. Una parada mal detectada es historial, igual que una coordenada mala en SPEC 26.
- **Umbral mínimo de duración.** Se registra **toda** parada, aunque el punto siguiente la cierre a los 15 s. La API no decide qué parada importa; filtrar los semáforos es del frontend. Consecuencia asumida: un viaje urbano puede dejar decenas de filas.
- **Filtros y orden configurable.** No hay `?open=true`, ni `dateFrom`, ni `dateTo`, ni `minDurationMinutes`, ni `sortDir`. Cualquier query param que no sea `limit` o `page` se ignora.
- **Que el piloto lea sus propias paradas.** Queda fuera del `GET`, como quedó fuera del rastro y del canal.
- **Websocket.** No se emite nada al abrir ni al cerrar una parada. `TripPositionUpdated` sigue con sus **seis claves** intactas y no hay canal ni evento nuevos: quien mira el mapa se enterará de la parada al ver que el punto no se mueve, o pidiendo este `GET`.
- **Tocar `trips`, `TripResource` ni `TripListResource`.** El viaje no gana `openTimeoutId`, ni `totalStoppedMinutes`, ni `isStopped`, y la tabla no gana ni una columna.
- **Cerrar la parada por cualquier otra vía.** Ni el `PATCH` general del administrador que devuelve un viaje de `finished` a `pending`, ni el `DELETE` (baja lógica), ni el `PATCH /{trip}/assignment`, ni el `/start` tocan las paradas. Un viaje borrado conserva las suyas, igual que conserva su rastro.
- **Backfill del rastro ya existente.** Los viajes anteriores a esta spec no ganan paradas: nadie recorre sus `trip_positions` hacia atrás. Un viaje `in_route` en el momento del despliegue empieza a detectar paradas desde su siguiente punto.
- **Alertas por parada larga.** Nadie recibe correo ni push por un «lleva 40 min parado». No hay notificaciones, ni geocercas, ni distinción entre parada prevista y no prevista.
- **Motivo de la parada.** No hay `reason`, ni `type`, ni `notes`, ni catálogo de causas: ni el piloto ni nadie explica por qué se detuvo.
- **Tiempo total parado del viaje.** No se acumula en ninguna columna ni se expone en ninguna clave: sumar `durationMinutes` es del consumidor.
- **Tocar el piso de 15 s ni el `POST` de posiciones.** El cuerpo sigue siendo dos campos, las cuatro guardas siguen en el mismo orden, y el 200 del piso sigue sin escribir nada.
- **Telemetría.** Nada de velocidad, rumbo, precisión del GPS ni motor encendido. La parada se deduce solo de la distancia entre dos puntos.
- **Purga por antigüedad.** Las paradas no se borran nunca, como las posiciones.

---

## Modelo de datos

Esta spec crea **una tabla, un modelo, una factory y una clase de cálculo**, y **no toca ninguna tabla existente**: `trips` y `trip_positions` quedan exactamente como están, y ningún enum cambia. Tampoco hay enum nuevo: una parada solo tiene dos estados y los dice `ended_at` (`null` = abierta).

### 1. Tabla `trip_timeouts`

```php
Schema::create('trip_timeouts', function (Blueprint $table) {
    $table->id();

    /** Las tres FK van sin cascade, como en trip_positions: una parada es historial. */
    $table->foreignId('trip_id')->constrained();
    $table->foreignId('pilot_id')->constrained('users');

    /** El punto donde el camión ya estaba parado: el ancla contra la que se mide el cierre. */
    $table->foreignId('start_position_id')->constrained('trip_positions');

    /**
     * El punto que cerró la parada. Queda null cuando la cerró el finish del viaje,
     * y ese null es la única forma de distinguir las dos causas de cierre.
     */
    $table->foreignId('end_position_id')->nullable()->constrained('trip_positions');

    /** Coordenadas del ancla, duplicadas para que el mapa pinte el pin sin join. */
    $table->decimal('latitude', 10, 8);
    $table->decimal('longitude', 11, 8);

    $table->timestamp('started_at');
    $table->timestamp('ended_at')->nullable();

    $table->timestamps();

    /** Sirve al listado (started_at asc) y a la búsqueda de la parada abierta del viaje. */
    $table->index(['trip_id', 'started_at']);
});
```

Sin `status`, sin `deleted_at` y sin `registered_by`: el autor es `pilot_id`, nombrarlo dos veces sería mentir. `latitude`/`longitude` repiten los `decimal(10,8)`/`(11,8)` de `trip_positions` y de `locations`, así que salen como **string** de ocho decimales.

**La invariante «como mucho una parada abierta por viaje» vive solo en el código**, no en un índice único parcial: la abre y la cierra un único camino —`trackPosition()`— y el `finish` solo cierra.

### 2. Modelo `TripTimeout`

```php
#[Fillable([
    'trip_id', 'pilot_id', 'start_position_id', 'end_position_id',
    'latitude', 'longitude', 'started_at', 'ended_at',
])]
class TripTimeout extends Model
{
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
```

Relaciones `trip()`, `pilot()` (contra `users` por `pilot_id`), `startPosition()` y `endPosition()` (las dos contra `TripPosition`). **Ningún modelo existente gana una relación inversa**: ni `Trip::timeouts()` ni nada en `TripPosition`, para que nadie escriba un `with()` que arrastre decenas de filas en el listado de viajes.

### 3. `App\Services\TripTimeout\DistanceCalculator`

Dos constantes y un método estático, sin estado y sin dependencias:

```php
final class DistanceCalculator
{
    /** Radio medio de la Tierra en metros: la esfera basta a esta escala. */
    private const EARTH_RADIUS_METERS = 6_371_000;

    /** Distancia a partir de la cual se considera que el camión se movió. */
    public const MOVEMENT_THRESHOLD_METERS = 5;

    public static function metersBetween(
        float $fromLatitude, float $fromLongitude,
        float $toLatitude, float $toLongitude,
    ): float;
}
```

Haversine, no Vincenty ni `ST_Distance`: a 5 metros el error del modelo esférico es de milímetros. Se prueba en un Unit test **sin base de datos y sin red**, como `PolylineDecoder`.

### 4. Forma de la respuesta del `GET`

```json
{
  "statusCode": 200,
  "message": "Paradas obtenidas correctamente",
  "data": [
    {
      "id": 12,
      "latitude": "14.62820000",
      "longitude": "-90.52290000",
      "startedAt": "10-09-2026 08:14:00 AM",
      "endedAt": "10-09-2026 08:41:30 AM",
      "durationMinutes": 27.5,
      "pilotId": 7,
      "startPositionId": 340,
      "endPositionId": 451
    }
  ]
}
```

Una parada abierta sale con `"endedAt": null` y `"durationMinutes": null`. Una cerrada por el `finish`, con `endedAt` puesto y `"endPositionId": null`.

### 5. Máquina de la detección

Se evalúa **una sola vez por punto escrito**, después del piso de 15 s:

| Estado del viaje | Se mide contra | Distancia < 5 m | Distancia ≥ 5 m |
|---|---|---|---|
| Sin parada abierta, sin punto anterior | — | — | — (primer punto del viaje: nada que medir) |
| Sin parada abierta, con punto anterior | el **punto anterior** | **Abre** parada anclada en el punto anterior | Nada |
| Con parada abierta | el **ancla** de la parada | Nada (la fila no se actualiza) | **Cierra** con `recorded_at` y el id del punto nuevo |

---

## Plan de implementación

1. **Migración `create_trip_timeouts_table`.** Crear la tabla tal como queda en el modelo de datos, con sus tres FK sin `cascade`, la cuarta nullable y el índice compuesto. Verificación: `php artisan migrate` sobre la base de desarrollo y `php artisan migrate:rollback` vuelven sin error.

2. **Modelo `TripTimeout` y su factory.** `#[Fillable]`, `casts()` y las cuatro relaciones (`trip`, `pilot`, `startPosition`, `endPosition`). La factory genera una parada **cerrada** por defecto y expone un estado `open()` que deja `ended_at` y `end_position_id` en `null`. Verificación: un Unit test que crea una parada con la factory y con `open()` y comprueba los `casts`.

3. **`App\Services\TripTimeout\DistanceCalculator` + su Unit test.** Haversine, las dos constantes y el método estático. Verificación: `TripTimeoutDistanceTest` con casos conocidos —dos coordenadas idénticas dan 0 m, un grado de latitud da ~111 km, un desplazamiento de 0,00004° de latitud cae por debajo del umbral— **sin base de datos y sin red**.

4. **`TripTimeoutServiceInterface` + `TripTimeoutService` + `TripTimeoutProvider`**, registrado en `bootstrap/providers.php`. El service inyecta `TripServiceInterface` por constructor y trae los dos métodos del contrato, con su PHPDoc de array shapes y `#[Override]` en cada uno: `getTimeouts()` (veto al `pilot`, delegación del ámbito en `getTripById()`, orden `started_at asc, id asc`, `resolvePerPage()` con sus `MIN_PER_PAGE`/`MAX_PER_PAGE`) y `trackPosition()` con la máquina de la detección. Verificación: el Unit test del service, que llama a `trackPosition()` con posiciones creadas a mano y comprueba que abre, que no abre, que cierra y que no vuelve a abrir.

5. **`TripTimeoutResource`.** Las nueve claves, `durationMinutes` calculado con `round(segundos / 60, 2)` y `null` cuando `ended_at` es `null`, fechas en `d-m-Y h:i:s A`. Verificación: `TripTimeoutResourceTest` (Unit, como `AccessoryResourceTest`) con una parada cerrada y una abierta.

6. **`TripTimeoutController` con un solo método `index`, y la ruta.** `GET /api/trips/{trip}/timeouts` en `routes/trips.php`, junto a las dos de posiciones y **antes** del `apiResource`, con `jwt.auth` a secas y nombre `timeouts.index`. El controller repite el helper privado que lee `limit` y arma `PaginatedResource` o `collection`, como el de posiciones. Verificación: `php artisan route:list --path=api/trips` muestra la ruta en su sitio, y el endpoint devuelve las paradas sembradas a mano por cada rol.

7. **Cablear la detección en `TripPositionService::create()`.** Inyectar `TripTimeoutServiceInterface` por constructor junto al `TripServiceInterface` que ya está, y llamar a `trackPosition($position)` **solo después de escribir la fila** —en la misma rama donde hoy se emite el evento, y antes o después del `broadcastPosition()`, nunca en la rama del piso de 15 s—. Verificación: tres peticiones al `POST` con coordenadas cercanas y luego una lejana dejan una parada abierta y después cerrada, comprobable por el `GET`.

8. **Cerrar la parada abierta en `TripService::finish()`.** Después del `update()` que escribe `end_date` y `status`, buscar la parada abierta del viaje y cerrarla con `ended_at = now()`, dejando `end_position_id` en `null`. Sin tocar `TripServiceInterface` ni inyectar ningún contrato nuevo. Verificación: un viaje con parada abierta al que se le hace `PATCH /{trip}/finish` la deja cerrada y con `endPositionId` nulo.

9. **Tests Pest del dominio** (agente `feature-tests`): Feature del `GET` —los cuatro roles, el 403 al `pilot` asignado, el ámbito del `carrier`, el 404 del viaje inexistente y del borrado, el viaje sin paradas, el orden ascendente y el recorte de `limit` a `[10, 100]`— y Unit del service con la matriz de la detección. Verificación: `php artisan test --compact --filter=TripTimeout` en verde, y la suite completa sin regresiones en `TripPositionTest` ni `TripTest`.

10. **Swagger** (agente `endpoint-docs`): atributos `OA` del `Tag`, del `GET` y del schema del Resource, más regenerar `storage/api-docs/api-docs.json`. Verificación: `/api/documentation` muestra el endpoint con sus respuestas 200/401/403/404.

11. **`references/trip-timeouts-api.md`** con el resumen de integración para el frontend: cómo nacen las paradas, por qué no hay `POST`, qué significan `endedAt` y `endPositionId` nulos, y que no hay evento de websocket.

12. **`vendor/bin/pint --dirty --format agent`** antes de cerrar.

---

## Criterios de aceptación

**Detección al escribir el punto**

- [ ] El primer punto de un viaje no crea ninguna parada.
- [ ] Un punto escrito a menos de 5 m del punto anterior, sin parada abierta, crea una fila en `trip_timeouts` cuyo `started_at`, `start_position_id`, `latitude` y `longitude` son los del **punto anterior**, no los del punto nuevo.
- [ ] Un punto escrito a 5 m o más del punto anterior, sin parada abierta, no crea ninguna fila.
- [ ] Con una parada abierta, un punto a menos de 5 m del ancla no modifica ni una columna de la fila.
- [ ] Con una parada abierta, un punto a 5 m o más del ancla la cierra con `ended_at` igual al `recorded_at` de ese punto y `end_position_id` igual a su id.
- [ ] El punto que cierra una parada no abre otra en la misma petición.
- [ ] Una petición descartada por el piso de 15 s no crea, no cierra y no modifica ninguna parada.
- [ ] Un viaje nunca tiene dos paradas con `ended_at` nulo a la vez.
- [ ] Reportar una posición sigue respondiendo 201 (o 200 con el piso) y su cuerpo sigue siendo el `TripPositionResource` de cinco claves.

**Cierre por fin de viaje**

- [ ] `PATCH /api/trips/{trip}/finish` sobre un viaje con parada abierta la cierra con `ended_at = now()` y deja `end_position_id` en `null`.
- [ ] `PATCH /api/trips/{trip}/finish` sobre un viaje sin parada abierta responde igual que antes y no escribe nada en `trip_timeouts`.
- [ ] `PATCH /{trip}/assignment`, `PATCH /{trip}/start`, el `PATCH` general del administrador y el `DELETE` del viaje no modifican ninguna fila de `trip_timeouts`.
- [ ] Un viaje con baja lógica conserva sus paradas en la tabla.

**Lectura**

- [ ] `GET /api/trips/{trip}/timeouts` sin token responde 401.
- [ ] Un `pilot` recibe 403 «No tienes permisos para consultar las paradas de un viaje», **incluido el piloto asignado al viaje**.
- [ ] `administrator` y `manager` obtienen las paradas de cualquier viaje.
- [ ] Un `carrier` obtiene las paradas de un viaje que asignó su empresa y las de un viaje de la bolsa libre.
- [ ] Un `carrier` que pide un viaje asignado por otra empresa recibe **403**, no 404.
- [ ] Un viaje inexistente y un viaje borrado responden **404** con el mismo mensaje.
- [ ] Un viaje sin paradas responde **200** con `data` vacío.
- [ ] Las paradas salen ordenadas por `started_at` ascendente, con desempate por `id` ascendente.
- [ ] Sin `limit` se devuelven todas las paradas, sin metadatos de paginación.
- [ ] Con `limit=3` la página trae 10 elementos, y con `limit=500`, 100; `total`, `currentPage` y `lastPage` salen en la raíz del sobre.
- [ ] `limit=abc` devuelve el listado completo sin error.
- [ ] Cualquier otro query param (`open`, `dateFrom`, `sortDir`) se ignora sin error.

**Forma del recurso**

- [ ] Cada elemento trae exactamente nueve claves: `id`, `latitude`, `longitude`, `startedAt`, `endedAt`, `durationMinutes`, `pilotId`, `startPositionId`, `endPositionId`.
- [ ] `latitude` y `longitude` salen como string de ocho decimales.
- [ ] `startedAt` y `endedAt` salen con el formato `d-m-Y h:i:s A`.
- [ ] Una parada abierta sale con `endedAt` y `durationMinutes` en `null`.
- [ ] Una parada de 27 minutos y 30 segundos sale con `durationMinutes` igual a `27.5`.

**Lo que no debe cambiar**

- [ ] `TripResource` sigue con 34 claves y `TripListResource` con 15.
- [ ] El payload de `TripPositionUpdated` sigue con sus seis claves y no hay evento ni canal nuevos.
- [ ] `GET /api/trips/{trip}/positions` responde exactamente igual que antes.
- [ ] La suite completa pasa sin regresiones en `TripTest`, `TripPositionTest` ni `TripChannelTest`.

**Cálculo**

- [ ] `DistanceCalculator::metersBetween()` devuelve 0 para dos coordenadas idénticas.
- [ ] Su Unit test corre sin base de datos y sin red.

---

## Decisiones

- **Sí: medir el cierre contra el ancla de la parada**, no contra el punto anterior. Contra el punto anterior, un camión que avanza 4 m cada 15 s en tráfico lento figuraría como parado indefinidamente, con 400 m recorridos y la parada todavía abierta.
- **Sí: el ancla es el punto anterior, no el que detecta la parada.** «Está parado desde las 08:14» es el dato útil; «parada detectada a las 08:14:15» pierde el primer tramo del reposo y no sirve para decir «lleva 40 minutos parado».
- **Sí: tabla propia `trip_timeouts`.** Con columnas en `trip_positions` (`is_stop`, `stop_started_at`) el historial de paradas quedaría implícito y habría que reconstruirlo recorriendo el rastro entero, que es justo lo que esta spec viene a evitar.
- **Sí: duplicar `latitude`/`longitude` del ancla en la fila.** El mapa pinta el pin de la parada sin un segundo viaje a la base. La duplicación es segura porque una posición es inmutable desde SPEC 26: no hay `PATCH` que pueda desincronizar las dos copias.
- **Sí: `pilot_id` en la fila**, aunque se pudiera llegar por el viaje. Mismo argumento que en `trip_positions`: la asignación puede cambiar mientras el viaje siga `pending`, y la parada debe seguir diciendo quién conducía.
- **Sí: detectar solo cuando el punto se escribió.** Evaluar también en la rama del 200 abriría paradas a partir de peticiones que SPEC 26 decidió ignorar. Consecuencia asumida: la resolución mínima de una parada es el piso de 15 s.
- **Sí: Haversine en PHP, en su propia clase.** Es un algoritmo puro, se prueba sin base de datos y sin red —precedente literal de `PolylineDecoder` (SPEC 16)— y a 5 metros el error del modelo esférico es de milímetros.
- **No: PostGIS y `ST_Distance`.** La tabla no tiene columna geográfica y castear coordenadas en cada consulta no aporta nada a una comparación entre dos puntos que ya están en memoria. PostGIS sigue siendo requisito del proyecto por las zonas, no por esto.
- **No: Vincenty ni una fórmula elipsoidal.** Precisión irrelevante a esta escala y más código que mantener.
- **Sí: `TripService::finish()` cierra la parada tocando el modelo `TripTimeout` directamente.** `TripTimeoutService` inyecta `TripServiceInterface` para no duplicar la matriz de ámbito de SPEC 24; el contrato en sentido inverso cerraría un ciclo en el contenedor y Laravel entraría en recursión infinita al resolverlo. Precedente de tocar el modelo de otro dominio: `TripPositionService` ya lee `Trip::withTrashed()->find()`.
- **No: que el controller de `finish` orqueste los dos contratos** por parámetro de método, como hace `/places/directions` (SPEC 16). Evita el ciclo, pero mueve secuencia de negocio al controller, que en este proyecto no tiene ninguna.
- **No: que `TripTimeoutService` duplique el ámbito de SPEC 24** para librarse de la dependencia. Es exactamente lo que SPEC 26 evitó inyectando `TripServiceInterface`.
- **Sí: `ended_at = now()` al finalizar el viaje**, coherente con el `end_date = now()` que `finish()` ya escribe, en vez del `recorded_at` del último punto. La hora del cierre es la hora en que el piloto declaró el viaje terminado.
- **Sí: `end_position_id` nulo cuando cerró el `finish`.** Es la única forma de distinguir «cerrada porque el camión se movió» de «cerrada porque el viaje terminó», sin añadir una columna `close_reason` ni un enum.
- **Sí: `durationMinutes` calculado en lectura, sin columna.** Precedente de `currentValue` (SPEC 17): un dato derivado de dos timestamps no necesita almacenarse ni mantenerse sincronizado.
- **Sí: `durationMinutes` nulo mientras la parada esté abierta.** Medirlo contra `now()` daría un valor distinto en cada lectura, no comparable entre dos peticiones y engañoso en una captura de pantalla.
- **Sí: minutos con dos decimales**, con el `round(x, 2)` de `durationHours` y `distanceKilometers` (SPEC 16). Los segundos son demasiado fino para una parada y los minutos enteros perderían las paradas cortas que esta spec decidió registrar.
- **No: umbral mínimo de duración.** Toda parada se registra, semáforos incluidos: la API no decide qué parada importa. El coste asumido es el volumen en recorridos urbanos, y filtrar es del consumidor.
- **No: índice único parcial sobre `trip_id WHERE ended_at IS NULL`.** La invariante de una sola parada abierta la sostiene el único camino de escritura; un índice parcial añadiría un 500 difícil de leer si alguna vez se rompe, en vez de un dato raro pero legible.
- **No: evento de websocket al abrir o cerrar una parada.** `TripPositionUpdated` ya lleva el punto a quien mira el mapa, y un evento más obligaría a tocar el canal, el payload y el frontend para un dato que el `GET` resuelve.
- **No: `openTimeoutId`, `isStopped` ni `totalStoppedMinutes` en `TripResource`.** SPEC 26 ya decidió que el viaje no gana campos derivados del rastro, y sumar paradas es del consumidor.
- **No: motivo de la parada.** Sin `reason`, sin `type` y sin catálogo: nadie puede rellenarlos, porque el piloto solo manda coordenadas y la detección es automática.
- **No: backfill de los viajes ya existentes.** Recorrer el rastro histórico para materializar paradas pasadas es un comando aparte y una decisión aparte. Un `null` aquí significa «antes de SPEC 27», como un `pilotDpiImage` nulo significa «antes de SPEC 25».
- **No: `DELETE` ni `PATCH` de una parada.** Una parada mal detectada es historial, igual que una coordenada mala en SPEC 26.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Ruido del GPS abriendo paradas falsas.** Un receptor parado puede derivar varios metros entre lecturas; con el umbral en 5 m, un camión detenido puede cerrar y reabrir paradas cortas en cadena, y uno en marcha lenta puede no abrir ninguna. | El umbral vive en una constante única (`DistanceCalculator::MOVEMENT_THRESHOLD_METERS`), así que subirlo a 10 o 15 m es un cambio de una línea sin migración. Esta spec no inventa un filtro de suavizado: primero hay que ver datos reales. |
| **Volumen de filas en recorridos urbanos.** Sin umbral de duración, un viaje con muchos semáforos deja decenas de paradas de 15–30 s, y el `GET` sin `limit` las devuelve todas. | El índice `(trip_id, started_at)` sirve al listado, y la paginación opt-in está disponible. El aviso queda escrito en `references/trip-timeouts-api.md`: el frontend filtra por `durationMinutes`. |
| **Parada abierta que nunca se cierra.** Si el piloto deja de reportar y nadie finaliza el viaje, la fila se queda con `ended_at` nulo indefinidamente, y el `durationMinutes` nulo no dice cuánto llevaba. | Es el comportamiento elegido: `null` significa «seguía parado con el viaje en ruta». No hay job de cierre por inactividad, y el `finish` cierra lo que quede abierto. |
| **Invariante de una sola parada abierta sin respaldo en la base.** Si alguna vez se escribe desde otro camino —un comando, un seeder, una spec futura—, podrían convivir dos filas abiertas y `trackPosition()` cerraría solo una. | La escritura pasa por un único método del contrato y el `finish` solo cierra. La búsqueda de la parada abierta ordena por `started_at desc, id desc`, así que con dos abiertas se cerraría la más reciente y la anterior quedaría visible como dato raro, nunca como error 500. |
| **Reloj del servidor moviéndose hacia atrás.** Un `now()` anterior al `started_at` daría una duración negativa. | `recorded_at` y `started_at` los pone siempre el servidor, nunca el dispositivo, y `durationMinutes` se calcula en lectura sobre el valor absoluto de la diferencia, con el mismo criterio del `secondsSince()` de SPEC 26. |
| **Dependencia circular al cablear los services.** Si alguien «mejora» el paso 8 inyectando `TripTimeoutServiceInterface` en `TripService`, el contenedor entra en recursión infinita al resolver cualquier ruta de viajes. | La razón queda escrita en el PHPDoc de `finish()` y en la sección de decisiones. Un Feature test cualquiera del dominio de viajes revienta de inmediato si eso ocurre. |
| **Coste añadido a la petición del piloto.** Cada `POST` de posición suma dos consultas (buscar la parada abierta y, en su caso, abrir o cerrar) al camino crítico del piloto. | Las dos van por el índice `(trip_id, started_at)` y el piso de 15 s acota la frecuencia a cuatro peticiones por minuto y viaje. La detección va fuera del `try/catch` del broadcast: si falla, falla la petición, porque aquí el dato sí importa. |

---

## Lo que **no** está en esta spec

- Crear, editar o borrar una parada a mano.
- Umbral mínimo de duración y filtros del listado (`open`, `dateFrom`, `minDurationMinutes`).
- Que el piloto lea las paradas.
- Evento o canal de websocket para las paradas.
- Cualquier cambio en `trips`, `TripResource`, `TripListResource`, el payload de `TripPositionUpdated` o el piso de 15 s.
- Backfill de los viajes anteriores a esta spec.
- Alertas, geocercas y notificaciones por parada larga.
- Motivo de la parada y tiempo total parado del viaje.
- Telemetría (velocidad, rumbo, precisión, motor) y purga por antigüedad.

Cada una de esas, si llega, va en su propia spec.
