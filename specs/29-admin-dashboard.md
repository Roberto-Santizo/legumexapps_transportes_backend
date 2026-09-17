# SPEC 29 — Tablero de administración (dashboard)

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 04, SPEC 13, SPEC 14, SPEC 19, SPEC 24, SPEC 26, SPEC 27
> **Fecha:** 2026-09-14
> **Objetivo:** Publicar el dominio `Dashboard` —cuatro endpoints de solo lectura bajo `/api/dashboard`, exclusivos de `administrator` y `manager`— que resumen viajes, gastos de vehículos, flota y viajes en curso a partir de los dominios ya existentes, sin crear tabla ni columna alguna.

Es el **primer dominio del proyecto sin escritura ni tabla propia que lee de otros dominios**: `Place` tampoco tiene tabla, pero sale a un proveedor externo; `Dashboard` no sale a ningún sitio, solo agrega lo que `trips`, `vehicles`, `vehicle_expenses`, `trip_positions`, `trip_fuels` y `trip_timeouts` ya guardan. Existe para que `administrator` y `manager` —los dos roles que ya ven todo, pero solo dominio a dominio— tengan una vista transversal centralizada en un único prefijo, en vez de reconstruirla en el frontend con una docena de listados.

Depende de SPEC 01 por los roles, de SPEC 04/13 por la flota y su ficha, de SPEC 14/19 por los gastos y su factura, de SPEC 24 por los viajes y su ámbito, de SPEC 26 por la última posición y de las dos SPEC 27 por combustible y paradas.

---

## Alcance

**Dentro:**

- **Dominio `Dashboard` completo** en su subcarpeta, con las capas de siempre menos las que no aplican: `DashboardServiceInterface` (cuatro métodos), `DashboardService`, `DashboardProvider` (registrado en `bootstrap/providers.php`), `DashboardController`, cuatro Resources en `app/Http/Resources/Dashboard/` y `routes/dashboard.php` incluido desde `routes/api.php`. **Sin FormRequest**: no hay cuerpo que validar y los filtros son tolerantes.
- **Cuatro rutas `GET`, todas con `jwt.auth` + `role:administrator,manager`** y sin `carrier.required` (los dos roles están exentos). Los otros dos roles reciben 403 en las cuatro.

  | Ruta | Devuelve | Rango de fechas sobre |
  |---|---|---|
  | `GET /api/dashboard/trips` | Un objeto de agregados de viajes | `recolection_date` |
  | `GET /api/dashboard/trips/in-route` | Lista de viajes `in_route` con última posición, combustible y parada abierta | — (se ignora) |
  | `GET /api/dashboard/vehicle-expenses` | Un objeto de agregados de gastos | `expense_date` |
  | `GET /api/dashboard/vehicles` | Listado de toda la flota con su viaje en curso | — (se ignora) |

  `/trips/in-route` se declara **antes** que `/trips` por orden de lectura, aunque no haya comodín que la capture.
- **Filtros comunes, todos tolerantes y sin FormRequest**: `carrierId` (inexistente se ignora), `dateFrom`/`dateTo` (`Y-m-d` estricto, por día completo; formato inválido se ignora, como en `GET /api/trips`). Sin fechas = **todo el histórico**. Los endpoints sin fecha de negocio (`/trips/in-route`, `/vehicles`) las ignoran en silencio.
- **Ámbito único**: `administrator` y `manager` ven **exactamente lo mismo**, todas las empresas. `carrierId` es un filtro, no un ámbito. En viajes, «empresa» es la de `assigned_by` (`User::currentCarrier()`), como el ámbito de SPEC 24; en gastos y flota, `vehicles.carrier_id`.
- **`/trips`**: `total`, `unassigned`, `byStatus`, `byCarrier`, `byClient`, `byShippingLine`, `byLocation`, `byMonth`. Viajes borrados **siempre fuera** (`SoftDeletes` por defecto).
- **`/vehicle-expenses`**: `totalAmount`, `count`, `byCategory`, `byNature`, `invoiced`/`notInvoiced` (conteo y monto), `byCarrier`, `byMonth`.
- **`/vehicles`**: **todos** los vehículos, incluidos `inactive`; paginación opt-in `[10, 100]`, orden `id ASC`; filtros tolerantes adicionales `status`, `condition` e `inRoute` (`true|false`). `currentTrip` = el `in_route` con `start_date` más reciente, o `null`.
- **`/trips/in-route`**: lista **sin paginar** de todos los viajes `in_route`, orden `start_date desc, id desc`; por viaje: identificación, empresa, piloto, vehículo, cliente, puerto, `startDate`, `lastPosition` (o `null`), `totalFuelGallons` (confirmadas), `unconfirmedFuelGallons`, y `openTimeout` con `stoppedMinutes` **medido contra `now()`** (o `null`).
- Anotaciones OpenAPI, `storage/api-docs/api-docs.json` regenerado, tests Pest (Feature de las cuatro rutas y roles; Unit del service) y `references/dashboard-api.md`.

**Fuera de alcance (para specs futuras):**

- **Escritura de cualquier tipo**: ni tabla, ni columna, ni migración, ni factory nueva. `Trip`, `Vehicle`, `VehicleExpense` no ganan relaciones nuevas salvo que el plan las exija (ver modelo de datos).
- **Datos financieros del vehículo en el listado**: `purchasePrice` y `monthlyInsuranceCost` no salen en `/vehicles`.
- **Bloques descartados en la definición**: combustible agregado por empresa/periodo, empresas y pilotos (conteos, salarios, documentos), paradas históricas agregadas, valor del inventario de accesorios.
- **Top N** de vehículos con más gasto o de empresas con más viajes: los desgloses salen completos, sin ranking ni corte.
- **Meses sin datos en `byMonth`**: solo aparecen los meses con al menos una fila; el frontend rellena los huecos.
- **Cargas de combustible una a una** en `/trips/in-route`: solo las dos sumas. Para el detalle está `GET /api/trips/{trip}/fuels`.
- **Caché, jobs o materialización**: cada llamada consulta la base en vivo.
- **Websocket del tablero**: `/trips/in-route` se refresca por polling del frontend; el canal `trips.{tripId}` sigue siendo por viaje.
- **Export** (CSV, PDF) y **comparativas** entre periodos.
- **Acceso del `carrier` a un tablero de su empresa**: si llega, es otra spec con su propio ámbito.

---

## Modelo de datos

Esta spec **no crea tablas, columnas, migraciones, enums, factories ni relaciones**. Solo define la forma de salida de cuatro Resources y el contrato del service. Cada agregado sale en el formato ya establecido: dinero y galones como **string de dos decimales**, ids como enteros, enums con el valor crudo en inglés, fechas `d-m-Y h:i:s A`.

### Contrato

```php
// app/Interfaces/Dashboard/DashboardServiceInterface.php
interface DashboardServiceInterface
{
    /** @param array{carrierId?: mixed, dateFrom?: mixed, dateTo?: mixed} $filters */
    public function getTripsSummary(array $filters): array;

    /** @param array{carrierId?: mixed} $filters */
    public function getTripsInRoute(array $filters): Collection;

    /** @param array{carrierId?: mixed, dateFrom?: mixed, dateTo?: mixed} $filters */
    public function getVehicleExpensesSummary(array $filters): array;

    /** @param array{carrierId?: mixed, status?: mixed, condition?: mixed, inRoute?: mixed, limit?: mixed} $filters */
    public function getVehicles(array $filters): Collection|LengthAwarePaginator;
}
```

Los filtros se leen tal cual llegan de la query string y el service los normaliza con `filter_var(..., FILTER_NULL_ON_FAILURE)` / `tryFrom()`, como en el resto del proyecto.

### 1. `TripsSummaryResource` — `GET /api/dashboard/trips`

```json
{
  "total": 120,
  "unassigned": 7,
  "byStatus": { "pending": 30, "inRoute": 12, "finished": 78 },
  "byCarrier": [ { "carrierId": 3, "carrierName": "TRANSPORTES X", "total": 40 } ],
  "byClient": [ { "clientId": 1, "clientName": "CLIENTE A", "total": 55 } ],
  "byShippingLine": [ { "shippingLineId": 2, "shippingLineName": "MAERSK", "total": 60 } ],
  "byLocation": [ { "locationId": 5, "locationName": "PUERTO QUETZAL", "total": 90 } ],
  "byMonth": [ { "month": "2026-08", "total": 41 } ]
}
```

- `byStatus` lleva **siempre las tres claves**, con `0` si no hay filas; los demás desgloses solo incluyen filas con al menos un viaje y van ordenados por `total desc, id asc` (`byMonth` por `month asc`).
- `byCarrier` resuelve la empresa con `JOIN carriers ON carriers.user_id = trips.assigned_by`: `/assignment` exige `role:carrier`, así que `assigned_by` es siempre el dueño de una empresa. Los viajes sin asignar **no aparecen** en `byCarrier`; por eso su suma puede ser menor que `total`.
- `unassigned` = `pending` con `pilot_id` y `vehicle_id` nulos (la bolsa de SPEC 24).
- `byMonth` agrupa `recolection_date` con `to_char(recolection_date, 'YYYY-MM')` (Postgres).
- Filtro `carrierId` acota **todos** los bloques al `assigned_by` de esa empresa; `total` y `unassigned` también (con `carrierId`, `unassigned` es siempre `0`).

### 2. `TripInRouteResource` — `GET /api/dashboard/trips/in-route`

```json
{
  "tripId": 18,
  "order": "ORD-001",
  "container": "MSKU1234567",
  "carrierId": 3,
  "carrierName": "TRANSPORTES X",
  "pilotId": 9,
  "pilotName": "Juan Pérez",
  "vehicleId": 4,
  "vehiclePlate": "P123ABC",
  "clientName": "CLIENTE A",
  "locationName": "PUERTO QUETZAL",
  "startDate": "14-09-2026 08:15:00 AM",
  "lastPosition": { "latitude": "14.60000000", "longitude": "-90.50000000", "recordedAt": "14-09-2026 09:00:15 AM" },
  "totalFuelGallons": "45.00",
  "unconfirmedFuelGallons": "10.00",
  "openTimeout": { "startedAt": "14-09-2026 08:50:00 AM", "latitude": "14.60000000", "longitude": "-90.50000000", "stoppedMinutes": 10.25 }
}
```

- 16 claves. `lastPosition` y `openTimeout` son `null` cuando no hay punto o no hay parada abierta.
- `lastPosition` = el `trip_positions` con mayor `recorded_at, id` de cada viaje, resuelto en **una sola consulta** (`DISTINCT ON (trip_id)` de Postgres), no una por viaje.
- `stoppedMinutes = round((now() − started_at) / 60, 2)`: **medido contra `now()`** a propósito, al revés que `durationMinutes` de `TripTimeoutResource`, porque este endpoint es una foto del momento y no historial.
- `totalFuelGallons` = suma de `gallons` con `loaded_at IS NOT NULL` (mismo número que `TripResource`); `unconfirmedFuelGallons` = suma con `loaded_at IS NULL`. Ambas por `withSum`.

### 3. `VehicleExpensesSummaryResource` — `GET /api/dashboard/vehicle-expenses`

```json
{
  "totalAmount": "15300.50",
  "count": 42,
  "byCategory": [ { "category": "tires", "count": 10, "totalAmount": "8000.00" } ],
  "byNature": { "preventive": { "count": 30, "totalAmount": "9000.00" }, "corrective": { "count": 12, "totalAmount": "6300.50" } },
  "invoiced": { "count": 25, "totalAmount": "12000.00" },
  "notInvoiced": { "count": 17, "totalAmount": "3300.50" },
  "byCarrier": [ { "carrierId": 3, "carrierName": "TRANSPORTES X", "count": 20, "totalAmount": "7000.00" } ],
  "byMonth": [ { "month": "2026-08", "count": 15, "totalAmount": "5000.00" } ]
}
```

- `byNature`, `invoiced` y `notInvoiced` llevan siempre sus claves, con `0`/`"0.00"`; `byCategory`, `byCarrier` y `byMonth` solo filas con datos, ordenadas por `totalAmount desc` (`byMonth` por `month asc`).
- `byCarrier` sale por `JOIN vehicles ON vehicles.id = vehicle_expenses.vehicle_id`; el `status` del vehículo no importa (SPEC 14). Filtro `carrierId` sobre `vehicles.carrier_id`.

### 4. `DashboardVehicleResource` — `GET /api/dashboard/vehicles`

```json
{
  "id": 4,
  "plate": "P123ABC",
  "type": "truck",
  "status": "active",
  "condition": "used",
  "mileage": 90000,
  "kilometersPerGallon": "12.50",
  "carrierId": 3,
  "carrierName": "TRANSPORTES X",
  "inRoute": true,
  "currentTrip": { "tripId": 18, "order": "ORD-001", "container": "MSKU1234567", "pilotName": "Juan Pérez", "startDate": "14-09-2026 08:15:00 AM" }
}
```

- 11 claves. `currentTrip` es `null` (e `inRoute` `false`) si el vehículo no tiene ningún viaje `in_route`; con más de uno, el de `start_date desc, id desc`.
- Se resuelve sin relación nueva: el service lee `Trip::query()->where('status', in_route)->whereIn('vehicle_id', …)->with('pilot')` **una vez por página** y lo indexa por `vehicle_id` en PHP. Precedente: `finish()` lee `TripPosition::query()` sin `Trip::positions()`.
- Filtro `inRoute=true|false` (`FILTER_VALIDATE_BOOLEAN`): con `true`, `whereIn('id', vehicle_id de los in_route)`; con `false`, `whereNotIn`. Se aplica **antes** de paginar, para que `total` sea correcto.
- El listado paginado se envuelve en `PaginatedResource($paginator, DashboardVehicleResource::class)`.

---

## Plan de implementación

Cada paso deja la suite verde y la API funcionando. Como no hay migración, el primer paso ya publica una ruta.

1. **Esqueleto del dominio y primera ruta.** Crear `app/Interfaces/Dashboard/DashboardServiceInterface.php` (los cuatro métodos, con PHPDoc de array shapes), `app/Services/Dashboard/DashboardService.php` (cuatro métodos con `#[Override]`, tres de ellos lanzando `\LogicException('Not implemented')` de momento), `app/Providers/Dashboard/DashboardProvider.php` registrado en `bootstrap/providers.php`, `app/Http/Controllers/DashboardController.php` con `try/catch` → `ResponseHandler`, y `routes/dashboard.php` incluido desde `routes/api.php` (entre `clients.php` y `departure_points.php`, orden alfabético) con las cuatro rutas bajo `prefix('dashboard')->name('dashboard.')` y middleware `['jwt.auth', 'role:administrator,manager']`. Test Feature `tests/Feature/DashboardTest.php` con el dataset de middleware (`dashboardEndpoints()`): 401 sin token, 403 para `carrier` y `pilot` en las cuatro.
2. **`getTripsSummary()` + `TripsSummaryResource`.** Método privado `applyTripFilters(Builder, array): Builder` que resuelve `carrierId` (subconsulta `assigned_by IN (SELECT user_id FROM carriers WHERE id = ?)`) y el rango sobre `recolection_date` con `resolveDate()` (`Carbon::createFromFormat('Y-m-d')` estricto, inválido → `null`). Los ocho bloques salen de consultas clonadas (`clone $query`) sobre la misma base. `TripsSummaryResource` en `app/Http/Resources/Dashboard/`. Tests: totales, `byStatus` con las tres claves a `0` en base vacía, `unassigned`, `carrierId` acota todos los bloques, rango por `recolection_date`, borrados fuera, fecha inválida ignorada.
3. **`getVehicleExpensesSummary()` + `VehicleExpensesSummaryResource`.** Misma estructura que el paso 2 sobre `vehicle_expenses` con `JOIN vehicles`; rango sobre `expense_date`. Tests: `totalAmount` como string de dos decimales, `invoiced`/`notInvoiced` siempre presentes, `byCarrier` con vehículos `inactive` incluidos, `carrierId`, rango.
4. **`getVehicles()` + `DashboardVehicleResource`.** `Vehicle::query()->with('carrier')->orderBy('id')`, filtros tolerantes `carrierId`, `status`, `condition`, `inRoute`; `resolvePerPage()` con `[10, 100]`; tras obtener la página, una consulta a `Trip` `in_route` por los `vehicle_id` de la página, indexada por `vehicle_id` conservando el primero en orden `start_date desc, id desc`, y asignada a cada modelo como atributo transitorio `currentTrip` para que el Resource no consulte nada. Tests: `inactive` listado, `inRoute` y `currentTrip` con dos `in_route`, filtro `inRoute=true|false` antes de paginar, paginación opt-in.
5. **`getTripsInRoute()` + `TripInRouteResource`.** `Trip::query()->where('status', in_route)` con `with(['assignedBy', 'pilot', 'vehicle', 'client', 'location'])`, `withSum` doble (`loaded_at IS NOT NULL` / `IS NULL`), filtro `carrierId`, orden `start_date desc, id desc`; luego dos consultas por el conjunto de ids: última posición (`DISTINCT ON (trip_id)`) y parada abierta (`whereNull('ended_at')`), ambas indexadas por `trip_id` y asignadas como atributos transitorios. Tests con `travelTo()` para fijar `stoppedMinutes`: sin puntos → `lastPosition: null`, con parada abierta → minutos contra `now()`, sumas de combustible, `carrierId`, `dateFrom` ignorado.
6. **Test Unit del service.** `tests/Unit/DashboardServiceTest.php`: los cuatro métodos contra la base, incluido que `getTripsSummary()` con `carrierId` inexistente devuelve lo mismo que sin filtro (tolerancia) y que los desgloses salen ordenados como dice el modelo de datos.
7. **OpenAPI.** Atributos `OA` en `DashboardController` y en los cuatro Resources (los objetos anidados como schemas propios: `TripsSummaryByCarrier`, `TripInRouteLastPosition`, etc.). `php artisan l5-swagger:generate`, `vendor/bin/pint --dirty --format agent`, suite completa `php artisan test --compact`.
8. **Referencia para el frontend.** `references/dashboard-api.md` con las cuatro rutas, sus filtros, la forma exacta de cada respuesta y las tres notas que confunden: `byCarrier` no suma `total`, `stoppedMinutes` cambia en cada lectura y los endpoints sin fecha ignoran el rango.

---

## Criterios de aceptación

**Rutas y roles**

- [x] `php artisan route:list --path=api/dashboard` muestra exactamente cuatro rutas `GET`: `/api/dashboard/trips`, `/api/dashboard/trips/in-route`, `/api/dashboard/vehicle-expenses`, `/api/dashboard/vehicles`.
- [x] Las cuatro responden 401 sin token y 403 con el sobre habitual para `carrier` y `pilot`.
- [x] `administrator` y `manager` reciben la **misma respuesta** ante la misma base y los mismos filtros.
- [x] Ninguna migración nueva: `database/migrations/` no cambia y no aparece ninguna tabla ni columna.

**`GET /api/dashboard/trips`**

- [x] Con la base vacía responde `total: 0`, `unassigned: 0`, `byStatus: {pending: 0, inRoute: 0, finished: 0}` y los cinco desgloses como `[]`.
- [x] Un viaje con `deleted_at` no cuenta en ningún bloque.
- [x] `byCarrier` agrupa por la empresa de `assigned_by`; un viaje sin asignar suma en `total` y `unassigned` pero no aparece en `byCarrier`.
- [x] `?carrierId=N` acota los ocho bloques a esa empresa; un `carrierId` inexistente o no numérico se ignora y devuelve el histórico completo.
- [x] `?dateFrom=2026-09-01&dateTo=2026-09-30` incluye un viaje con `recolection_date` el 30-09 a las 23:59 y excluye uno del 01-10 a las 00:00.
- [x] `?dateFrom=01/09/2026` (formato inválido) se ignora y devuelve el histórico completo.
- [x] `byMonth` devuelve `month` como `YYYY-MM` y solo los meses con al menos un viaje, en orden ascendente.

**`GET /api/dashboard/trips/in-route`**

- [x] Devuelve solo viajes con `status = in_route`; un `pending` con `start_date` puesto no sale.
- [x] Cada elemento tiene las 16 claves; `lastPosition` es `null` si el viaje no tiene puntos y `openTimeout` es `null` si no hay parada con `ended_at IS NULL`.
- [x] `lastPosition` es el punto de mayor `recorded_at, id` del viaje.
- [x] Con `travelTo()` fijado 10 minutos y 15 segundos después de `started_at`, `stoppedMinutes` es `10.25`.
- [x] `totalFuelGallons` suma solo cargas con `loaded_at` y `unconfirmedFuelGallons` solo las que no lo tienen, ambas como string de dos decimales.
- [x] `?carrierId=N` acota a los viajes asignados por esa empresa; `dateFrom`/`dateTo` no cambian la respuesta.
- [x] Sin viajes en curso responde 200 con `data: []`.

**`GET /api/dashboard/vehicle-expenses`**

- [x] Con la base vacía responde `totalAmount: "0.00"`, `count: 0`, `byNature` con `preventive` y `corrective` a cero, `invoiced` y `notInvoiced` a cero, y los tres desgloses como `[]`.
- [x] Un gasto de un vehículo `inactive` cuenta igual.
- [x] `invoiced.count + notInvoiced.count === count` y sus montos suman `totalAmount`.
- [x] `?carrierId=N` acota por `vehicles.carrier_id`; el rango corta por `expense_date` por día completo.

**`GET /api/dashboard/vehicles`**

- [x] Sin `limit` devuelve todos los vehículos, incluidos `inactive`, en orden `id ASC`; con `?limit=10` pagina con `total/currentPage/lastPage` en la raíz.
- [x] Un vehículo con dos viajes `in_route` devuelve `inRoute: true` y en `currentTrip` el de `start_date` más reciente.
- [x] Un vehículo cuyo único viaje es `finished` devuelve `inRoute: false` y `currentTrip: null`.
- [x] `?inRoute=true` devuelve solo vehículos con viaje en curso y `total` refleja ese recorte; `?inRoute=false` los demás; `?inRoute=basura` se ignora.
- [x] `status`, `condition` y `carrierId` inválidos se ignoran; válidos filtran.
- [x] La respuesta no incluye `purchasePrice` ni `monthlyInsuranceCost`.

**Calidad**

- [x] `GET /api/dashboard/trips/in-route` con N viajes ejecuta un número de consultas **independiente de N** (verificado con `expect(DB::getQueryLog())` o `assertQueryCount` equivalente en el test).
- [x] `php artisan test --compact` en verde y `vendor/bin/pint --dirty --format agent` sin cambios pendientes.
- [x] `storage/api-docs/api-docs.json` documenta las cuatro rutas y los cuatro schemas.
- [x] Existe `references/dashboard-api.md`.

---

## Decisiones

- **Sí:** dominio propio `Dashboard` con las capas de siempre (interface, service, provider, controller, resources, rutas) aunque no tenga tabla. Centraliza los endpoints transversales bajo un prefijo y mantiene el patrón del proyecto; `Place` es el precedente de dominio sin modelo.
- **No:** meter cada endpoint en el dominio que lee (`GET /api/trips/summary`, `GET /api/vehicles/fleet`). Dispersaría la autorización `role:administrator,manager` en cuatro archivos de rutas y el frontend tendría que saber en qué dominio vive cada vista.
- **Sí:** `role:administrator,manager` en middleware y **ningún ámbito en el service**. Los dos roles ya ven todo en cada dominio; `carrierId` es un filtro voluntario, no una restricción.
- **No:** un tablero para `carrier` acotado a su empresa. Exigiría reintroducir `resolveScopedCarrierId()` y la matriz de SPEC 24 en un dominio pensado para no tener ámbito; si llega, es otra spec.
- **Sí:** cuatro endpoints en vez de un `GET /api/dashboard` único. El frontend carga cada bloque por separado y una consulta lenta no bloquea el resto.
- **Sí:** filtros tolerantes sin FormRequest, como todos los listados del proyecto. Un tablero no debe fallar con 422 por un filtro mal escrito: muestra el histórico.
- **No:** `IndexDashboardRequest` con validación estricta. Solo `VehicleExpense` y `AccessoryCharacteristic` validan el índice, y lo hacen porque su filtro es obligatorio; aquí ninguno lo es.
- **Sí:** sin fechas = todo el histórico. Un periodo por defecto (mes en curso, 30 días) es una decisión de UI que el frontend puede mandar explícitamente.
- **Sí:** `byCarrier` de viajes por `JOIN carriers.user_id = trips.assigned_by`, sin pasar por `User::currentCarrier()`. `/assignment` exige `role:carrier`, así que `assigned_by` es siempre un dueño; resolver la empresa en SQL evita una consulta por usuario.
- **Sí:** los viajes sin asignar cuentan en `total` y `unassigned` pero no en `byCarrier`. No tienen empresa; inventar una fila `carrierId: null` mezclaría dos conceptos en un mismo desglose.
- **Sí:** `byStatus`, `byNature`, `invoiced` y `notInvoiced` llevan siempre sus claves a cero; los desgloses por entidad solo filas con datos. Las claves fijas las conoce el frontend de antemano; las entidades no.
- **No:** rellenar `byMonth` con meses a cero. Requiere decidir desde cuándo hasta cuándo, y sin filtro de fechas no hay respuesta; el frontend conoce el rango que pidió y rellena.
- **Sí:** `stoppedMinutes` medido contra `now()`, al revés que `durationMinutes` de SPEC 27. Aquella es historial y debe ser estable entre lecturas; esta es una foto en vivo cuyo único valor es cambiar.
- **Sí:** `/trips/in-route` sin paginar. Son los viajes en curso *ahora*, decenas como mucho; paginar una vista de mapa fragmentaría lo que se quiere ver junto.
- **Sí:** flota como listado paginado y no como agregado. Es lo que se pidió en la definición: ver cada vehículo, su estado y si está en ruta.
- **Sí:** resolver `currentTrip`, `lastPosition` y `openTimeout` con **una consulta por conjunto** indexada por id en PHP, sin añadir `Vehicle::trips()`, `Trip::positions()` ni `Trip::timeouts()`. Mantiene la línea de SPEC 26–28 de no ampliar modelos publicados por una sola lectura, y evita el N+1 sin depender de relaciones.
- **Sí:** `DISTINCT ON (trip_id)` para la última posición. Es Postgres puro, pero la suite y el proyecto ya exigen Postgres desde SPEC 08; un `MAX()` + `JOIN` sería portable y peor.
- **No:** `purchasePrice` y `monthlyInsuranceCost` en el listado de flota. Quedaron fuera al confirmar las columnas; añadirlas después es un cambio aditivo al Resource.
- **No:** caché de los agregados. Con el volumen actual las consultas son baratas y una caché obligaría a decidir invalidación en seis dominios de escritura.
- **No:** websocket del tablero. `/trips/in-route` es una foto; el canal por viaje de SPEC 26 ya cubre el seguimiento fino.
- **No:** combustible por empresa/periodo, empresas y pilotos, paradas históricas, inventario de accesorios. Se descartaron en la definición; cada uno cabe como endpoint nuevo bajo el mismo prefijo en otra spec.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Los agregados de viajes y gastos recorren las tablas enteras sin fechas | Los índices existentes (`recolection_date`, `expense_date`, FKs) cubren los filtros; si el histórico crece, la primera medida es que el frontend mande siempre un rango, y la segunda, una spec de caché |
| `stoppedMinutes` depende del reloj del servidor y de `now()` en el test | Los tests fijan el tiempo con `travelTo()`; en producción el reloj del servidor ya es la fuente de `started_at`, así que ambos lados miden lo mismo |
| Un `manager` ve montos de gasto y flota de todas las empresas | Es el comportamiento pedido y el que ya tiene en `GET /api/vehicle-expenses`; el filtro `carrierId` acota por elección, no por permiso |
| `DISTINCT ON` ata el service a Postgres | Ya lo está por PostGIS desde SPEC 08; la consulta vive en un único método privado (`latestPositionsByTrip()`) para poder reescribirla |

---

## Lo que **no** entra en esta spec

- Ninguna escritura, tabla, columna o migración.
- Tablero para `carrier` acotado a su empresa.
- Combustible agregado, empresas y pilotos, paradas históricas, valor de accesorios.
- `purchasePrice` y `monthlyInsuranceCost` en la flota.
- Top N, meses a cero, comparativas entre periodos, export.
- Caché, jobs, materialización o websocket del tablero.
- Relaciones nuevas en `Vehicle`, `Trip` ni ningún modelo publicado.

Cada uno de estos, si llega, va en su propia spec.
