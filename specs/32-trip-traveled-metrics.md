# SPEC 32 — Distancia y tiempo reales del viaje

> **Estado:** Implementado
> **Depende de:** SPEC 24, SPEC 26, SPEC 27 (trip-timeouts), SPEC 28, SPEC 30
> **Fecha:** 2026-09-21
> **Objetivo:** Al finalizar un viaje (`PATCH /api/trips/{trip}/finish`), calcular y persistir en `trips` la distancia real recorrida (`traveled_kilometers`, suma Haversine del rastro de `trip_positions`) y la duración real (`traveled_hours`, `end_date − start_date`), y exponerlas en `TripResource` y `TripListResource`.

Es la **quinta spec aditiva sobre `trips`** tras las dos SPEC 27, SPEC 28 y SPEC 30, y cierra el espejo que SPEC 30 dejó a medias: el viaje ya guardaba la ruta prevista con sus dos números (`polyline`, `estimated_kilometers`, `estimated_hours`) y la ruta real solo como línea (`traveled_polyline`). Esta spec le pone a la ruta real sus dos números, con la misma escala y el mismo formato que los estimados, para que el frontend pueda mostrar «estimado 104 km · 1.75 h — real 111 km · 2.10 h» sin calcular nada. **No los compara**: el desvío, el retraso y cualquier alerta siguen fuera.

Depende de SPEC 24 por `finish()` y los dos Resources, de SPEC 26 por `trip_positions`, de SPEC 27 (paradas) por `DistanceCalculator::metersBetween()`, de SPEC 28 por `encodeTraveledRoute()` —la lista de puntos que ya carga es la fuente de la distancia— y de SPEC 30 por los tipos de columna y el formato de salida de `estimated_*`.

---

## Alcance

**Dentro:**

- Migración aditiva sobre `trips`: `traveled_kilometers` `decimal(8,2)` y `traveled_hours` `decimal(6,2)`, ambas `nullable`, sin default, sin índice y sin backfill.
- `Trip` gana las dos columnas en `#[Fillable]`; sin cast (salen formateadas por el Resource, como `estimated_*`).
- `TripService::finish()` escribe las dos en el **mismo `update()`** que ya fija `end_date`, `status` y `traveled_polyline`:
  - `traveled_kilometers`: suma de `DistanceCalculator::metersBetween()` entre cada par de puntos consecutivos del rastro, en orden `recorded_at asc, id asc`, **sin filtrar por umbral**, dividida entre 1000 y redondeada a dos decimales. Con cero o un punto → `0.00`.
  - `traveled_hours`: `end_date − start_date` en horas decimales, redondeado a dos decimales.
- La lista de puntos se carga **una sola vez** por `/finish` y alimenta tanto `PolylineEncoder::encode()` como la suma de distancia (refactor interno de `encodeTraveledRoute()`).
- `TripResource` pasa de 40 a **42 claves**: `traveledKilometers` y `traveledHours` justo después de `traveledPoints` y antes de `observations`, como string de dos decimales o `null`.
- `TripListResource` pasa de 17 a **19 claves**: las mismas dos, después de `estimatedHours` y antes de `observations`.
- `TripFactory`: sin cambios en el estado por defecto (las columnas nacen `null`); el estado `finished()` gana valores para las dos.
- Anotaciones OpenAPI en los dos Resources y regeneración de `storage/api-docs/api-docs.json`.
- Tests: Feature en `TripTest` (`/finish` persiste las dos, las claves nuevas en detalle y listado, `null` antes de finalizar) y Unit en `TripServiceTest` (suma de distancia con puntos conocidos, `0.00` con cero y con un punto, horas a partir de `start_date`/`end_date` viajando en el tiempo).
- `references/trips-api.md` actualizado.

**Fuera de alcance (para otras specs):**

- Backfill de los viajes ya `finished`: quedan en `null` en ambas columnas.
- Filtrar el ruido GPS (segmentos por debajo de `MOVEMENT_THRESHOLD_METERS`), simplificar el rastro o descontar las paradas de `trip_timeouts` del tiempo.
- Comparación entre estimado y real en el servidor: desvío, retraso, porcentaje, ETA o alertas.
- Filtros u orden por distancia o tiempo real en `GET /api/trips`.
- `TripInRouteResource` del dashboard y cualquier agregado de kilómetros u horas en `/api/dashboard`.
- El payload de `TripPositionUpdated`, `TripPositionResource` y `GET /api/trips/current` (sigue devolviendo `TripListResource`, así que las claves salen, pero siempre en `null` por ser `in_route`).
- Cambios en `app/Ai/`: la tool `trip` deja pasar los dos escalares sin recortar.
- Cualquier ruta nueva y cualquier campo nuevo en un body.

---

## Modelo de datos

Dos columnas nuevas en una tabla existente y ninguna tabla ni clase nueva.

### Migración aditiva sobre `trips`

```php
// database/migrations/2026_09_21_xxxxxx_add_traveled_metrics_to_trips_table.php
Schema::table('trips', function (Blueprint $table) {
    $table->decimal('traveled_kilometers', 8, 2)->nullable()->after('traveled_polyline');
    $table->decimal('traveled_hours', 6, 2)->nullable()->after('traveled_kilometers');
});
```

- Mismos tipos que `estimated_kilometers`/`estimated_hours` (SPEC 30): estimado y real comparten escala, tope y formato de salida.
- `nullable` y **sin default**: `null` significa «el viaje no ha terminado» o «terminó antes de SPEC 32». A diferencia de `traveled_polyline`, **no** significa «terminó sin puntos»: ese caso es `0.00`.
- Sin índice: nadie filtra ni ordena por ellas.

### `Trip`

- `traveled_kilometers` y `traveled_hours` entran en `#[Fillable]`, junto a `traveled_polyline`.
- Sin cast: la columna llega como string desde Postgres y el Resource la formatea con `number_format(…, 2, '.', '')`.
- Ninguna relación nueva; `Trip` sigue sin `positions()`.

### Cálculo en `TripService::finish()`

```php
$trip->update([
    'end_date' => $endDate = now(),
    'status' => TripStatus::Finished,
    'traveled_polyline' => …,           // PolylineEncoder::encode($points) ?: null
    'traveled_kilometers' => …,         // round(metros / 1000, 2)
    'traveled_hours' => …,              // round($trip->start_date->diffInSeconds($endDate) / 3600, 2)
]);
```

| Columna | Fuente | Regla |
|---|---|---|
| `traveled_kilometers` | La misma lista `[lat, lng]` (8 decimales) que ya carga `encodeTraveledRoute()`, orden `recorded_at asc, id asc` | `Σ DistanceCalculator::metersBetween(p[i], p[i+1])` para todo `i`, sin umbral ni filtro; `/ 1000`; `round(…, 2)`. Cero o un punto → `0.00` |
| `traveled_hours` | `start_date` (garantizado no-null por la guarda de `finish()`) y el mismo `now()` que se escribe en `end_date` | `round(segundos / 3600, 2)` |

- Los puntos se cargan **una vez**: `encodeTraveledRoute()` deja de devolver solo la cadena; un único método privado lee `trip_positions` y devuelve las dos cosas (la polilínea y los kilómetros), o la lista se carga en `finish()` y se pasa a dos helpers puros. La forma exacta la decide la implementación; el contrato es **una sola consulta** a `trip_positions` por `/finish`.
- Ningún body acepta las dos columnas: son dato derivado, como `traveled_polyline` y `end_date`. Mandarlas en cualquier `PATCH` se ignora en silencio.

### `TripResource` (42 claves)

Dos claves nuevas entre `traveledPoints` y `observations`:

| Clave | Tipo | Valor |
|---|---|---|
| `traveledKilometers` | `string \| null` | `number_format($this->traveled_kilometers, 2)` o `null` |
| `traveledHours` | `string \| null` | `number_format($this->traveled_hours, 2)` o `null` |

Orden final: `…, points, estimatedKilometers, estimatedHours, traveledPolyline, traveledPoints, traveledKilometers, traveledHours, observations, …`.

### `TripListResource` (19 claves)

Las mismas dos claves, con el mismo formato, entre `estimatedHours` y `observations`:

`…, startDate, endDate, estimatedKilometers, estimatedHours, traveledKilometers, traveledHours, observations, pilotName, …`.

### `TripFactory`

- `definition()` no cambia: las dos columnas nacen `null`.
- El estado `finished()` gana `traveled_kilometers` (`fake()->randomFloat(2, 1, 700)`) y `traveled_hours` (`fake()->randomFloat(2, 0.1, 14)`), sin estado nuevo.

---

## Plan de implementación

Cada paso deja la suite verde y la API funcionando.

1. **Migración.** `php artisan make:migration add_traveled_metrics_to_trips_table --table=trips`: las dos columnas `decimal` nullable del modelo de datos. Migrar en local y en la base de tests.
2. **Modelo y factory.** Añadir `traveled_kilometers` y `traveled_hours` al `#[Fillable]` de `Trip`, un párrafo al PHPDoc de la clase (de dónde salen, que se escriben solo en `/finish` y qué significa `null` frente a `0.00`) y los dos valores al estado `finished()` de `TripFactory`. Correr `TripTest` y `TripServiceTest`: siguen verdes porque nada los lee todavía.
3. **`TripService::finish()`.** Refactorizar `encodeTraveledRoute()` para que la lista de puntos se cargue una sola vez y alimente la polilínea y la suma de distancia (`DistanceCalculator::metersBetween()` entre consecutivos, `/ 1000`, `round(…, 2)`); calcular `traveled_hours` con `start_date` y el mismo `now()` de `end_date`; meter las dos en el `update()` existente. En `tests/Unit/TripServiceTest.php`: tres puntos con distancia conocida → kilómetros esperados; cero puntos y un punto → `0.00`; `travelTo()` para fijar `start_date` y el `now()` del finish → horas esperadas (p. ej. 2 h 30 min → `2.50`); un segundo finish tras devolver el viaje a `in_route` recalcula y sobrescribe.
4. **Resources.** `TripResource`: las dos claves entre `traveledPoints` y `observations`, con `#[OA\Property]` y texto del schema a 42 claves. `TripListResource`: entre `estimatedHours` y `observations`, schema a 19 claves. En `TripTest`: `PATCH /{trip}/finish` devuelve las dos como string de dos decimales; `GET /{trip}` de un viaje `pending`/`in_route` devuelve `null`; `GET /trips` las trae en cada elemento; `GET /trips/current` las trae en `null`. Ajustar los tests que cuentan claves de los dos Resources y comprobar que `TripInRouteResource` no cambia.
5. **Documentación y cierre.** `php artisan l5-swagger:generate`; `vendor/bin/pint --dirty --format agent`; suite completa `php artisan test --compact`.
6. **Referencia para el frontend.** Actualizar `references/trips-api.md`: las dos claves nuevas en detalle y listado, que se calculan solo en `/finish`, la diferencia entre `null` y `0.00`, y que la distancia es suma cruda del rastro (sin filtrar ruido GPS) y las horas tiempo bruto (sin descontar paradas).

---

## Criterios de aceptación

- [x] `trips` tiene `traveled_kilometers` (`decimal(8,2)`, nullable) y `traveled_hours` (`decimal(6,2)`, nullable), y las filas anteriores a la migración quedan en `null`.
- [x] `PATCH /api/trips/{trip}/finish` con tres o más puntos persiste `traveled_kilometers` igual a la suma Haversine de los segmentos consecutivos en `recorded_at asc, id asc`, en kilómetros con dos decimales.
- [x] `PATCH /api/trips/{trip}/finish` con cero o con un punto persiste `traveled_kilometers = 0.00`, no `null`.
- [x] `PATCH /api/trips/{trip}/finish` persiste `traveled_hours = round((end_date − start_date) / 3600, 2)` usando el mismo `now()` que escribe en `end_date`.
- [x] `/finish` ejecuta **una sola consulta** a `trip_positions` para polilínea y distancia.
- [x] `PATCH /api/trips/{trip}`, `/assignment` y `/start` no tocan las dos columnas; mandarlas en cualquier body se ignora con 200.
- [x] `GET /api/trips/{trip}` devuelve 42 claves, con `traveledKilometers` y `traveledHours` inmediatamente después de `traveledPoints` y antes de `observations`, como string de dos decimales (`"111.40"`, `"2.10"`).
- [x] `GET /api/trips` y `GET /api/trips/current` devuelven 19 claves por viaje, con las dos nuevas entre `estimatedHours` y `observations`, en el mismo formato.
- [x] Un viaje `pending` o `in_route` devuelve `null` en las dos claves en ambos Resources.
- [x] `TripInRouteResource` (dashboard), `TripPositionResource` y el payload de `TripPositionUpdated` no cambian de forma.
- [x] `TripFactory::finished()` genera valores válidos para las dos columnas y `definition()` las deja en `null`.
- [x] `storage/api-docs/api-docs.json` documenta las dos claves nuevas en los dos Resources.
- [x] `php artisan test --compact` pasa completo y `vendor/bin/pint --dirty --test` no reporta cambios en los archivos tocados.

---

## Decisiones

- **Sí:** calcular las dos en el servidor, solo en `/finish`. Al revés que `estimated_*` (SPEC 30), que las manda el frontend: aquí la fuente —`trip_positions`, `start_date`, `end_date`— la tiene la API y nadie más; el front no podría calcularlas sin bajarse el rastro entero.
- **Sí:** distancia desde los puntos de `trip_positions` (8 decimales), no desde `traveled_polyline` decodificada (5 decimales). La lista ya está cargada en `encodeTraveledRoute()`; decodificar la cadena recién codificada sería dar una vuelta para perder un metro por punto.
- **Sí:** Haversine con `DistanceCalculator::metersBetween()` de SPEC 27, sin clase nueva. Ya existe, ya está testeado, y a la escala de un viaje el error esférico es despreciable. Sin PostGIS: `trip_positions` no tiene columna geográfica.
- **Sí:** suma **cruda** de todos los segmentos, sin umbral. Fiel al rastro, con la misma regla que `traveled_polyline` (SPEC 28: sin colapsar ni simplificar). El ruido GPS de un camión parado infla la cifra; se acepta como dato bruto y se declara en `references/`.
- **No:** ignorar segmentos por debajo de `MOVEMENT_THRESHOLD_METERS`. Coherente con las paradas, pero mezcla dos preguntas («¿se movió?» y «¿cuánto recorrió?») y oculta que el dato es bruto. Si hace falta, es un cambio de una línea en otra spec.
- **Sí:** horas brutas `end_date − start_date`, sin descontar `trip_timeouts`. Es «cuánto duró el viaje», la pregunta espejo de `estimated_hours`; el tiempo neto en movimiento sería otro número con otro nombre.
- **Sí:** `0.00` con cero o un punto, no `null`. `null` queda con un solo significado —«no ha terminado / anterior a SPEC 32»—, a diferencia de `traveled_polyline`, donde SPEC 28 aceptó el triple `null` porque una cadena vacía no es una polilínea útil; `0.00` sí es una distancia legítima.
- **Sí:** `decimal(8,2)`/`(6,2)` y string de dos decimales, los mismos tipos y formato de `estimated_*`. Estimado y real se comparan a ojo en la misma escala; cambiar la unidad o la precisión entre los dos obligaría al front a convertir.
- **Sí:** nombres `traveled_kilometers`/`traveled_hours`, el adjetivo de `traveled_polyline` con la unidad de `estimated_*`. Las cuatro claves se leen en pareja.
- **Sí:** en `TripResource` **y** en `TripListResource`, como SPEC 30. Dos escalares baratos; en el listado sirven para «estimado vs real» por fila sin pedir el detalle.
- **No:** en `TripInRouteResource` del dashboard. Un `in_route` siempre las tiene en `null`; no aportan nada allí.
- **Sí:** mismo `update()` que `end_date`, `status` y `traveled_polyline`, sin transacción nueva. Precedente de SPEC 28: un UPDATE atómico basta.
- **Sí:** una sola consulta a `trip_positions` por `/finish`. La lista ya se carga para la polilínea; leerla dos veces sería duplicar la consulta más pesada de la ruta.
- **Sí:** cada `/finish` recalcula y sobrescribe, como `traveled_polyline`. El hueco de la máquina de estados es de SPEC 24.
- **No:** backfill de los viajes ya `finished`. Precedente de SPEC 21/25/27/28/30; sería un comando en otra spec (tendría el rastro y las fechas, así que es posible).
- **No:** aceptar las dos columnas en ningún body. Dato derivado, como `traveled_polyline`, `start_date` y `end_date`.
- **No:** desvío, retraso o porcentaje calculado en servidor. Con los cuatro números en la fila, el front lo saca con una resta; si algún día hace falta una alerta, es otra spec.
- **Sí:** dejar que la tool `trip` del asistente exponga los dos escalares sin tocar `app/Ai/`. Recorta polilíneas e imágenes porque pesan; dos números no.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Ruido GPS de un camión parado horas (jitter de 2-4 m cada 15 s) infla `traveled_kilometers` en cientos de metros o algún kilómetro | Decisión explícita: el dato es bruto, como la polilínea, y `references/trips-api.md` lo dice. El umbral de 5 m ya existe en `DistanceCalculator`; aplicarlo es una línea en otra spec si la cifra resulta inútil en producción. |
| Un salto GPS espurio (un punto a 50 km del anterior por error del dispositivo) suma 100 km falsos en un solo par de segmentos | Mismo trato que le da SPEC 28 al rastro: la API no filtra. El rastro exacto sigue en `trip_positions` y `traveled_polyline` para auditarlo; filtrar outliers es de otra spec. |
| Rastro de ~3 000 puntos: la suma Haversine corre en PHP en el `/finish` | Es O(n) sobre una lista ya en memoria; 3 000 llamadas a `metersBetween()` son milisegundos. Sin consulta extra por la decisión de una sola lectura. |
| El `PATCH` del administrador devuelve un viaje `finished` a `pending` y limpia nada: `traveled_kilometers`/`traveled_hours` quedan como historial huérfano junto a `end_date` | Hueco declarado de SPEC 24 (sin máquina de estados); esta spec no lo abre ni lo cierra. El siguiente `/finish` sobrescribe las dos. |
| Viajes anteriores a SPEC 32 con `null` en el listado junto a viajes nuevos con `0.00` | El front trata `null` como «sin dato» y `0.00` como «terminó sin recorrido medible». Sin backfill por decisión. |

---

## Lo que **no** entra en esta spec

- Backfill de los viajes ya finalizados.
- Filtrar ruido GPS, outliers o simplificar el rastro antes de sumar.
- Descontar las paradas de `trip_timeouts` del tiempo.
- Desvío, retraso, porcentaje, ETA o alertas calculadas en servidor.
- Filtros u orden por distancia o tiempo real en `GET /api/trips`.
- `TripInRouteResource` del dashboard y cualquier agregado en `/api/dashboard`.
- El payload de `TripPositionUpdated` y `TripPositionResource`.
- Cambios en `app/Ai/`.
- Rutas nuevas o campos nuevos en cualquier body.

Cada una de ellas, si llega, va en su propia spec.
