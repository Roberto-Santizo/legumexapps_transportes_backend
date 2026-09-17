# SPEC 30 — Distancia y tiempo estimados del viaje

> **Estado:** Aprobado
> **Depende de:** SPEC 16, SPEC 24, SPEC 28
> **Fecha:** 2026-09-17
> **Objetivo:** Guardar en `trips` la distancia (`estimated_kilometers`) y la duración (`estimated_hours`) estimadas de la ruta prevista, enviadas por el frontend junto a `polyline` en el `POST` y el `PATCH` general, y exponerlas en `TripResource` y `TripListResource`.

Es la **cuarta spec aditiva sobre `trips`** tras las dos SPEC 27 y SPEC 28, y completa la ruta prevista de SPEC 24: hasta hoy el viaje guardaba la línea que devolvió `GET /api/places/directions` pero tiraba la distancia y la duración de esa misma respuesta. Los dos números llegan del frontend, ya resueltos, en el mismo cuerpo que la polilínea: **la API sigue sin llamar a Google**.

Depende de SPEC 16 por el origen de los valores (`distanceKilometers`/`durationHours` de `/places/directions`), de SPEC 24 por `Trip`, sus FormRequests y sus dos Resources, y de SPEC 28 por la posición de las claves nuevas junto a `points` y `traveledPolyline`.

---

## Alcance

**Dentro:**

- Migración aditiva sobre `trips`: `estimated_kilometers` `decimal(8,2)` y `estimated_hours` `decimal(6,2)`, ambas `nullable`, sin default, sin índice y sin backfill.
- `Trip` gana las dos columnas en `#[Fillable]`; sin cast (salen formateadas por el Resource, como `gallons` en `TripFuel`).
- `StoreTripRequest`: `estimatedKilometers` y `estimatedHours` **obligatorios**. El `POST` pasa de 12 a **14 campos** — cambio incompatible sin periodo de gracia, como SPEC 13 y SPEC 21.
- `UpdateTripRequest`: los tres campos de la ruta (`polyline`, `estimatedKilometers`, `estimatedHours`) van **juntos o ninguno** — `required_with` cruzado entre los tres. Mandar uno solo es 422.
- Reglas comunes: `numeric`, `min:0`, `max:999999.99` (km) / `max:9999.99` (h), con mensajes en español.
- `TripService`: los dos campos entran en `create()` y en `UPDATABLE_FIELDS`, sin normalización ni validación cruzada con `polyline`.
- `TripResource` pasa de 37 a **39 claves**: `estimatedKilometers` y `estimatedHours` justo después de `points` y antes de `traveledPolyline`, como string de dos decimales o `null`.
- `TripListResource` pasa de 15 a **17 claves**: las mismas dos, después de `endDate` y antes de `observations`.
- `TripFactory` genera los dos valores.
- Anotaciones OpenAPI en los dos FormRequests y los dos Resources; regeneración de `storage/api-docs/api-docs.json`.
- Tests: Feature en `TripTest` (validación del `POST`, el `required_with` del `PATCH`, las claves nuevas en detalle y listado) y Unit en `TripServiceTest` (persistencia en `create`/`update`).
- `references/trips-api.md` actualizado.

**Fuera de alcance (para otras specs):**

- Backfill de los viajes existentes: quedan en `null` en ambas columnas.
- Que la API calcule, recalcule o valide los dos números contra `polyline`, contra el par punto de partida / puerto, o llamando a Google.
- Filtros u orden por distancia o tiempo en `GET /api/trips`.
- Comparación con el recorrido real (`traveled_polyline`, `trip_positions`): distancia recorrida, desvío, retraso, ETA.
- `TripInRouteResource` del dashboard (SPEC 29) y cualquier agregado de kilómetros u horas en `/api/dashboard`.
- El payload de `TripPositionUpdated` y `TripPositionResource`.
- Cualquier ruta nueva.

---

## Modelo de datos

Dos columnas nuevas en una tabla existente y ninguna tabla nueva.

### Migración aditiva sobre `trips`

```php
// database/migrations/2026_09_17_xxxxxx_add_estimates_to_trips_table.php
Schema::table('trips', function (Blueprint $table) {
    $table->decimal('estimated_kilometers', 8, 2)->nullable()->after('polyline');
    $table->decimal('estimated_hours', 6, 2)->nullable()->after('estimated_kilometers');
});
```

- `decimal`, no `float`: mismo criterio que las coordenadas y el dinero del proyecto. `8,2` admite hasta `999999.99` km y `6,2` hasta `9999.99` h; los `max` del FormRequest coinciden con esos topes para que un desbordamiento sea 422 y no 500.
- `nullable` y **sin default**: `null` significa «viaje anterior a SPEC 30». Por la API no se puede crear ni editar un viaje que quede en `null`, así que a partir de la migración solo lo son las filas viejas.
- Sin índice: nadie filtra ni ordena por ellas.

### `Trip`

- `estimated_kilometers` y `estimated_hours` entran en `#[Fillable]`, junto a `polyline`.
- Sin cast: la columna llega como string desde Postgres y el Resource la formatea con `number_format(…, 2, '.', '')`, igual que `gallons` en `TripFuel`.
- Ninguna relación nueva.

### Cuerpo del `POST` y del `PATCH`

| Campo | `POST` | `PATCH` | Reglas comunes |
|---|---|---|---|
| `polyline` | `required` | `sometimes`, `required_with:estimatedKilometers,estimatedHours` | `string` |
| `estimatedKilometers` | `required` | `sometimes`, `required_with:polyline,estimatedHours` | `numeric`, `min:0`, `max:999999.99` |
| `estimatedHours` | `required` | `sometimes`, `required_with:polyline,estimatedKilometers` | `numeric`, `min:0`, `max:9999.99` |

- En el `PATCH` los tres viajan **juntos o ninguno**: un cuerpo con solo `polyline` —válido hasta hoy— pasa a ser 422.
- Sin normalización: el valor se guarda tal cual y Postgres lo redondea a dos decimales al persistir.
- Mensajes en español: «La distancia estimada es obligatoria», «La distancia estimada debe ser un número», «La distancia estimada no puede ser negativa», «La distancia estimada supera el máximo permitido», y los equivalentes de «La duración estimada …». Para el `required_with`: «Si se envía la ruta deben enviarse también la distancia y la duración estimadas» (y sus dos espejos).

### `TripResource` (39 claves)

Dos claves nuevas entre `points` y `traveledPolyline`:

| Clave | Tipo | Valor |
|---|---|---|
| `estimatedKilometers` | `string \| null` | `number_format($this->estimated_kilometers, 2)` o `null` |
| `estimatedHours` | `string \| null` | `number_format($this->estimated_hours, 2)` o `null` |

Orden final: `…, polyline, points, estimatedKilometers, estimatedHours, traveledPolyline, traveledPoints, observations, …`.

### `TripListResource` (17 claves)

Las mismas dos claves, con el mismo formato, entre `endDate` y `observations`:

`…, startDate, endDate, estimatedKilometers, estimatedHours, observations, pilotName, …`.

### `TripFactory`

Gana `estimated_kilometers` (`fake()->randomFloat(2, 1, 600)`) y `estimated_hours` (`fake()->randomFloat(2, 0.1, 12)`), sin estado nuevo.

---

## Plan de implementación

Cada paso deja la suite verde y la API funcionando.

1. **Migración.** `php artisan make:migration add_estimates_to_trips_table --table=trips`: las dos columnas `decimal` nullable del modelo de datos. Migrar en local y en la base de tests.
2. **Modelo y factory.** Añadir `estimated_kilometers` y `estimated_hours` al `#[Fillable]` de `Trip`, un párrafo al PHPDoc de la clase (de dónde vienen, que viajan con `polyline` y qué significa `null`) y los dos valores a `TripFactory`. Correr `TripTest` y `TripServiceTest`: siguen verdes porque nada los lee todavía.
3. **`TripService`.** Incluir los dos campos en el array de `create()` y en `UPDATABLE_FIELDS`. En `tests/Unit/TripServiceTest.php`: `create` los persiste; `update` con los tres campos de la ruta los reescribe; `update` sin ellos no los toca.
4. **FormRequests.** `StoreTripRequest`: los dos campos `required` + reglas comunes, mensajes en español y schema OA (12 → 14 obligatorios). `UpdateTripRequest`: `sometimes` + `required_with` cruzado en los tres campos de la ruta, mensajes y schema OA. En el mismo paso, actualizar los cuerpos de `TripTest` que hacen `POST` o mandan `polyline` en el `PATCH`, y añadir los casos: `POST` sin cada campo → 422; valor negativo, no numérico y por encima del `max` → 422; `PATCH` con solo `polyline`, con solo `estimatedHours` y con dos de los tres → 422; con los tres → 200.
5. **Resources.** `TripResource`: las dos claves entre `points` y `traveledPolyline`, con `#[OA\Property]` y texto del schema a 39 claves. `TripListResource`: entre `endDate` y `observations`, schema a 17 claves. En `TripTest`: `GET /{trip}` devuelve las dos como string de dos decimales; un viaje creado por factory con las columnas en `null` devuelve `null`; `GET /trips` y `GET /trips/current` las traen en cada elemento. Ajustar los tests que cuentan claves de los dos Resources y comprobar que `TripInRouteResource` no cambia.
6. **Documentación y cierre.** `php artisan l5-swagger:generate`; `vendor/bin/pint --dirty --format agent`; suite completa `php artisan test --compact`.
7. **Referencia para el frontend.** Actualizar `references/trips-api.md`: los dos campos nuevos del `POST`, la regla «juntos o ninguno» del `PATCH`, las claves nuevas en detalle y listado, y que vienen de `distanceKilometers`/`durationHours` de `GET /api/places/directions`.

---

## Criterios de aceptación

- [ ] `trips` tiene `estimated_kilometers` (`decimal(8,2)`, nullable) y `estimated_hours` (`decimal(6,2)`, nullable), y las filas anteriores a la migración quedan en `null`.
- [ ] `POST /api/trips` sin `estimatedKilometers` o sin `estimatedHours` responde 422 con el mensaje en español del campo que falta.
- [ ] `POST /api/trips` con `estimatedKilometers` o `estimatedHours` negativo, no numérico o por encima del `max` responde 422; con `0` responde 201.
- [ ] `POST /api/trips` con los 14 campos responde 201 y persiste los dos valores.
- [ ] `PATCH /api/trips/{trip}` con solo `polyline`, con solo uno de los dos números, o con dos de los tres campos de la ruta responde 422.
- [ ] `PATCH /api/trips/{trip}` con los tres campos de la ruta responde 200 y reescribe los tres; sin ninguno de los tres responde 200 y no los toca.
- [ ] `GET /api/trips/{trip}` devuelve 39 claves, con `estimatedKilometers` y `estimatedHours` inmediatamente después de `points` y antes de `traveledPolyline`, como string de dos decimales (`"104.32"`, `"1.75"`).
- [ ] `GET /api/trips` y `GET /api/trips/current` devuelven 17 claves por viaje, con las dos nuevas entre `endDate` y `observations`, en el mismo formato.
- [ ] Un viaje con las columnas en `null` devuelve `null` en las dos claves en ambos Resources.
- [ ] `PATCH /{trip}/assignment`, `/start` y `/finish` no tocan las dos columnas.
- [ ] `TripInRouteResource` (dashboard), `TripPositionResource` y el payload de `TripPositionUpdated` no cambian de forma.
- [ ] `TripFactory` genera valores válidos para las dos columnas.
- [ ] `storage/api-docs/api-docs.json` documenta los dos campos nuevos en los dos FormRequests y los dos Resources.
- [ ] `php artisan test --compact` pasa completo y `vendor/bin/pint --dirty --test` no reporta cambios en los archivos tocados.

---

## Decisiones

- **Sí:** los dos valores los manda el frontend, resueltos con `GET /api/places/directions`. Mismo principio que `polyline` (SPEC 24) y `googlePlaceId` (SPEC 15): la API nunca llama a Google desde un dominio que no sea `Place`, y una llamada de rutas se factura aparte.
- **No:** calcular la distancia desde `polyline` o `points` en el servidor. Daría la longitud de la línea, no la de la ruta, y la duración no se puede derivar de una polilínea.
- **Sí:** kilómetros y horas decimales, las unidades que ya devuelve `DirectionsResource`. El front reenvía `distanceKilometers`/`durationHours` sin convertir nada.
- **No:** metros y segundos enteros (lo crudo de Google). Obligaría al front a deshacer la conversión que la API ya hizo en SPEC 16.
- **Sí:** nombres con la unidad, `estimated_kilometers`/`estimated_hours`. Un `estimated_time` a secas obliga a leer la documentación para saber si son minutos u horas.
- **Sí:** obligatorios en el `POST` (12 → 14 campos), cambio incompatible sin periodo de gracia. Precedente de SPEC 13 (vehículos), SPEC 21 (`type`) y SPEC 25 (documentos del piloto); vienen de la misma llamada que `polyline`, que ya era obligatoria.
- **Sí:** `required_with` cruzado en el `PATCH`: `polyline`, `estimatedKilometers` y `estimatedHours` viajan juntos o ninguno. Una polilínea nueva con la distancia vieja es incoherente, y el `PATCH` ya tiene un hueco declarado (cambiar el destino sin remandar la ruta) que esta spec no quiere duplicar.
- **No:** `sometimes|required` independiente por campo, como `polyline` hasta hoy. Más simple, pero deja guardar rutas incoherentes.
- **Sí:** `decimal(8,2)`/`(6,2)` con `max` en el FormRequest igual al tope de la columna. Un valor que desborda es 422 con mensaje, no un 500 de Postgres.
- **Sí:** `min:0`, no `min:0.01`. Una ruta muy corta redondeada a dos decimales puede dar `0.00` h y es un valor legítimo que devolvería `/directions`.
- **Sí:** string de dos decimales en la salida, como `gallons`, `price` y `salary`. `DirectionsResource` devuelve `number` porque no persiste nada; aquí el valor sale de una columna `decimal`.
- **Sí:** en `TripResource` **y** en `TripListResource`. Son dos escalares baratos, al revés que `polyline`/`points`, y en un listado sirven para mostrar «104 km · 1.75 h» sin pedir el detalle.
- **No:** en `TripInRouteResource` del dashboard. El tablero tiene su propia spec y su propio ámbito; si los necesita, va allí.
- **Sí:** columnas `nullable` sin backfill. Precedente de SPEC 21/25/27/28; por la API ningún viaje nuevo queda en `null`, así que `null` significa exactamente «anterior a SPEC 30».
- **No:** validación cruzada entre `polyline` y los dos números (que la distancia «cuadre» con la línea). La API no puede comprobarlo sin recalcular la ruta, y esa es la línea que no se cruza.
- **No:** filtros u orden por distancia o tiempo. Nadie los pidió; si llegan, son una línea en otra spec.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El frontend en producción sigue mandando el `POST` de 12 campos y el `PATCH` con solo `polyline` cuando se despliegue esta spec | Cambio incompatible declarado, sin periodo de gracia, como SPEC 13/21/25. El 422 llega con mensaje en español que nombra el campo; el despliegue del front debe ir junto al del back, y `references/trips-api.md` lo dice. |
| Un `PATCH` que cambia `locationId` o `departurePointId` sin remandar la ruta deja `polyline`, `estimatedKilometers` y `estimatedHours` obsoletos a la vez | Hueco declarado en SPEC 24 que esta spec no abre ni cierra: los tres campos quedan obsoletos **juntos**, que es mejor que obsoletos por separado. |
| Los valores no se validan contra la ruta: el front puede mandar `1000 km` con una polilínea de 5 km | Decisión explícita (la API no recalcula rutas). Es la misma confianza que ya se deposita en `polyline`; el dato es informativo, no cotiza ni bloquea nada. |
| Viajes anteriores a SPEC 30 con `null` en el listado | El front debe tratar `null` en las dos claves como «sin estimación», igual que hace con `traveledPolyline` y `pilotDpiImage`. Sin backfill por decisión. |

---

## Lo que **no** entra en esta spec

- Backfill de los viajes existentes.
- Calcular, recalcular o validar los dos números en el servidor, ni llamar a Google.
- Filtros u orden por distancia o tiempo en `GET /api/trips`.
- Comparación con el recorrido real: distancia recorrida, desvío, retraso, ETA.
- `TripInRouteResource` del dashboard y cualquier agregado en `/api/dashboard`.
- El payload de `TripPositionUpdated` y `TripPositionResource`.
- Rutas nuevas.

Cada una de ellas, si llega, va en su propia spec.
