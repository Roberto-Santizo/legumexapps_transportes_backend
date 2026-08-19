# SPEC 15 — Destinos puntuales (`Location`) como base de las tarifas de flete

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 03, SPEC 06, SPEC 07, SPEC 08, SPEC 09, SPEC 12
> **Fecha:** 2026-08-19
> **Objetivo:** Publicar un dominio `Location` de destinos puntuales identificados por `google_place_id` y coordenadas `lat`/`lng`, y sustituir la zona por el destino como eje de las tarifas de flete, que pasan a cotizarse mandando un `locationId` explícito en vez de un punto geográfico.

Depende de **SPEC 01** por el guard JWT y por el `User` al que apunta `registered_by`; de **SPEC 03** por el middleware `role:`, que restringe la escritura al `administrator`; de **SPEC 06** por el `FuelPrice` vigente y el enum `FuelType`; de **SPEC 07** por el `Product`; de **SPEC 09** porque **reescribe su eje**: `freight_rates.zone_id` pasa a `location_id`; de **SPEC 08** solo para **quitarle** `getZoneContainingPoint()`, que se queda sin consumidor; y de **SPEC 12** porque el `google_place_id` y las coordenadas salen de `GET /api/places`.

Es la primera spec del proyecto que **retira una capacidad ya publicada**: la cotización por punto geográfico desaparece. Hasta ahora el sistema resolvía solo en qué zona caía un destino; a partir de aquí el destino es una fila concreta que alguien dio de alta, y la ambigüedad del polígono —solape, punto fuera de toda zona, geometría inválida— deja de existir en el camino del dinero.

**El dominio de zonas queda intacto.** `zones`, `/api/zones`, el polígono PostGIS y su índice GiST siguen exactamente como los dejó SPEC 08. Lo único que pierden es su papel en las tarifas: a partir de esta spec las zonas se dibujan en el mapa y no cotizan nada.

---

## Alcance

**Dentro:**

- **Tabla nueva `locations`:** `id`, `name` (`string`, único), `description` (`text`, nullable), `google_place_id` (`string`, único), `latitude` (`decimal(10,8)`), `longitude` (`decimal(11,8)`), `status` (`boolean`, default `true`), `registered_by` (FK a `users`) y `timestamps`. Sin `deleted_at`, sin `color` y **sin ninguna columna PostGIS**.
- Modelo `Location` con factory, relación `registeredBy()`, `normalizeName()` propio y estados de factory `active()` e `inactive()`.
- Cadena de capas completa en la subcarpeta `Location/`: `LocationServiceInterface`, `LocationService`, `LocationProvider`, `StoreLocationRequest`, `UpdateLocationRequest`, `LocationResource` y `LocationController`.
- `routes/locations.php` incluido desde `routes/api.php`, con `/{location}/toggle-status` declarada **antes** del `apiResource` sobre `'/'` con `->parameters(['' => 'location'])`.
- **Forma idéntica a SPEC 08:** escritura solo `administrator`, lectura para cualquier autenticado, sin `carrier.required`. Filtros `status` y `search` (`LIKE` sobre el nombre ya en mayúsculas), orden fijo `id ASC`, paginación opt-in con `limit` acotada a `[10, 100]` y envuelta en `PaginatedResource`. `DELETE` es baja lógica **idempotente** (`status = false`) y existe `/toggle-status`.
- **`name` único global**, normalizado a mayúsculas con `Location::normalizeName()`, con índice único en base y revalidado en el service con `ensureNameIsAvailable($name, $ignoreId)`.
- **`google_place_id` obligatorio y único.** Identifica el lugar real; dos destinos no pueden apuntar al mismo sitio de Google. **Es editable:** un `PATCH` puede reapuntar el destino a otro lugar, revalidando la unicidad, y el destino conserva su `id` y sus tarifas.
- **La API nunca llama a Google.** El front busca en `GET /api/places` (SPEC 12), elige, y manda `name`, `googlePlaceId`, `latitude` y `longitude` ya resueltos. `LocationService` no conoce `PlaceServiceInterface`.
- **`latitude` y `longitude` son editables** por `PATCH`: afinar el pin de un destino ya registrado es corrección, no un destino nuevo.
- **Cambio de eje en `freight_rates` (SPEC 09):** migración que **vacía la tabla**, borra `zone_id` y su FK, añade `location_id` (`not null`, FK a `locations`, sin cascade) y rehace el índice compuesto como `(location_id, product_id, fuel_type, fuel_min)`. Las tarifas existentes son de prueba y se descartan; no hay backfill.
- **Renombrado completo del contrato de tarifas**, sin periodo de gracia:
  - `GET /api/freight-rates/quote?locationId=&productId=&fuelType=[&pounds=]` — **`lat` y `lng` desaparecen**.
  - `GET /api/freight-rates?locationId=` — el filtro `zoneId` deja de existir.
  - `POST` y `PATCH` reciben `locationId` en vez de `zoneId`.
  - `FreightRateResource` y `FreightQuoteResource` devuelven `locationId` y `locationName` en vez de `zoneId` y `zoneName`.
- **La unicidad de banda pasa a ser `(location_id, product_id, fuel_type, fuel_min)`**, con la misma regla de SPEC 09: vive en `ensureFuelMinIsAvailable()` y no en un índice único, para que un soft delete libere el `fuel_min`.
- **Nuevos pasos de fallo de `/quote`**, en este orden: destino activo → producto activo → combustible vigente → tarifas del trío → banda. Un `locationId` inexistente es **422** (`exists:locations,id`); uno inactivo es **400** con mensaje propio. El **404 de "el punto no pertenece a ninguna zona" desaparece**.
- **Crear o editar una tarifa exige destino y producto activos**, exactamente como hoy exige zona y producto (`ensureLocationAndProductAreActive`).
- **`getZoneContainingPoint()` se elimina** de `ZoneServiceInterface`, de `ZoneService` y de sus tests: pierde a su único consumidor. `whereContainsPoint()` **se queda**, porque lo sigue usando el filtro `?lat=&lng=` del listado de zonas.
- **`FreightRateService` deja de inyectar `ZoneServiceInterface` por constructor.** Tras esta spec, el dominio de tarifas no conoce ni zonas ni PostGIS.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`, incluida la **regeneración** de los schemas de `FreightRate` afectados por el renombrado.
- Resumen de integración para el frontend en `references/locations-api.md`, que documenta también el cambio incompatible de `/api/freight-rates`.

**Fuera de alcance (para specs futuras):**

- **Tocar el dominio de zonas más allá de quitar `getZoneContainingPoint()`.** `zones`, `/api/zones`, el polígono, el índice GiST y el filtro `?lat=&lng=` siguen exactamente igual. PostGIS **sigue siendo requisito** del proyecto y de la suite.
- **Deprecar, marcar o borrar `/api/zones`.** Las zonas siguen siendo un recurso de primera clase, solo que sin tarifas.
- **Relación entre `Location` y `Zone`.** Un destino no sabe en qué zona cae, y no hay `zone_id` en `locations` ni endpoint que lo resuelva.
- **Cotizar por punto geográfico o por destino más cercano.** Se descartó en favor del `locationId` explícito; recuperarlo es otra spec y otro endpoint.
- **Que el service llame a Google Places** para resolver coordenadas, validar el `googlePlaceId` o refrescar el nombre.
- **Columna `address` o dirección formateada.** Hoy cabe en `description`.
- **Destinos por empresa.** Son destinos nacionales de Legumex; no hay `carrier_id` ni pivote, igual que las zonas.
- **Borrado real de destinos.** Un destino con tarifas o con viajes futuros no debe poder desaparecer; la baja es lógica.
- **Migrar o recuperar las tarifas de flete existentes.** La tabla se vacía y se recaptura a mano.
- **Auditoría de coordenadas o de nombre anteriores.** El `PATCH` sobrescribe sin dejar rastro; solo se conserva quién dio de alta la fila.
- **Filtros por cercanía, bounding box, radio o distancia** en el listado de destinos, y orden configurable.
- **Alta en lote e importación desde CSV, Excel o Google Places.**
- **Agrupar destinos**, jerarquías, alias o destinos duplicados con el mismo `googlePlaceId`.
- **Programación de viajes.** Sigue fuera, igual que en SPEC 09.

---

## Modelo de datos

Esta spec **no introduce ningún enum**: `status` es un booleano, como en SPEC 07 y 08, y el `fuel_type` de las tarifas sigue siendo el `FuelType` de SPEC 06.

### 1. Tabla `locations`

```php
Schema::create('locations', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();                 // siempre en mayúsculas
    $table->text('description')->nullable();
    $table->string('google_place_id')->unique();
    $table->decimal('latitude', 10, 8);
    $table->decimal('longitude', 11, 8);
    $table->boolean('status')->default(true);
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();
});
```

`latitude` es `decimal(10,8)` y `longitude` es `decimal(11,8)`: ocho decimales dan precisión de **poco más de un milímetro**, muy por encima de lo que devuelve Google, y la parte entera justa para `±90` y `±180`. Nada de flotantes en una coordenada que decide un precio.

`name` y `google_place_id` llevan **índice único de verdad**, como el `name` de SPEC 07 y 08. El nombre se guarda siempre en mayúsculas, así que la unicidad es insensible a mayúsculas sin depender del collation; el `google_place_id` se guarda **tal cual lo devuelve Google**, sin normalizar, porque es un identificador opaco y sensible a mayúsculas.

`registered_by` usa `constrained('users')` sin `cascadeOnDelete`, igual que en SPEC 06, 07 y 08.

**Ninguna columna PostGIS y ningún índice espacial.** La tabla es plana y no exige la extensión.

### 2. Modelo `Location`

```php
#[Fillable(['name', 'description', 'google_place_id', 'latitude', 'longitude', 'status', 'registered_by'])]
class Location extends Model
{
    public function registeredBy(): BelongsTo;

    /** Normaliza un nombre: recorta, colapsa espacios internos y pasa a mayúsculas. */
    public static function normalizeName(string $name): string;

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'status' => 'boolean',
        ];
    }
}
```

A diferencia de `Zone`, **todas las columnas son `fillable`**: no hay ninguna que entre como expresión SQL. `normalizeName()` es una copia de la de `Zone` y `Product` y vive aquí por la misma razón que allí — tiene dos llamadores legítimos, el FormRequest antes de la regla `unique` y el service justo antes de persistir.

La factory nace `active`, con un nombre de lugar, un `google_place_id` único de la forma `ChIJ...` y coordenadas realistas dentro de Guatemala; añade los estados `active()` e `inactive()`.

**Ninguna relación inversa** en `User`, y **ninguna relación `freightRates()`** en `Location`: la tarifa apunta al destino, no al revés.

### 3. Migración sobre `freight_rates`

```php
// up()
DB::table('freight_rates')->delete();          // las tarifas por zona se descartan

Schema::table('freight_rates', function (Blueprint $table) {
    $table->dropIndex(['zone_id', 'product_id', 'fuel_type', 'fuel_min']);
    $table->dropConstrainedForeignId('zone_id');

    $table->foreignId('location_id')->after('id')->constrained('locations');
    $table->index(['location_id', 'product_id', 'fuel_type', 'fuel_min']);
});
```

El `delete()` va **antes** de tocar el esquema: `location_id` es `not null` y sin FK a nada, y una tabla con filas no admitiría la columna nueva. Es una migración **destructiva y consciente** — se decidió que las tarifas existentes son de prueba.

`dropConstrainedForeignId` borra la FK y la columna en un paso. El índice compuesto se borra antes porque incluye `zone_id`, y se rehace idéntico con `location_id` en su lugar: sigue cubriendo la consulta de la cotización de punta a punta, y **sigue sin ser único** por la razón de SPEC 09 — con `deleted_at`, un índice único bloquearía para siempre un `fuel_min` que se borró.

El `down()` hace lo inverso y también vacía la tabla. Una migración destructiva no se revierte con datos.

### 4. Cambios en el modelo `FreightRate`

```php
#[Fillable(['location_id', 'product_id', 'fuel_type', 'fuel_min', 'price_per_pound', 'registered_by'])]
class FreightRate extends Model
{
    public function location(): BelongsTo;   // antes zone()
    public function product(): BelongsTo;
    public function registeredBy(): BelongsTo;
}
```

Los tres `casts` no cambian. La factory pasa a crear un `Location` propio en vez de una `Zone`.

### 5. Resources

```php
// LocationResource — nuevo
[
    'id',
    'name',              // 'BODEGA CENTRAL ESCUINTLA'
    'description',       // string | null
    'googlePlaceId',     // 'ChIJk8Y...'
    'latitude',          // '14.63490000'
    'longitude',         // '-90.50690000'
    'status',            // true
    'registeredByName',
    'createdAt',         // '19-08-2026 08:45:12 PM'
    'updatedAt',
]

// FreightRateResource — cambian dos claves
[ 'id', 'locationId', 'locationName', 'productId', 'productName', /* …resto igual… */ ]

// FreightQuoteResource — cambian dos claves
[ 'freightRateId', 'locationId', 'locationName', 'productId', 'productName',
  'fuelType', 'currentFuelPrice', 'appliedFuelMin', 'pricePerPound', 'pounds', 'total' ]
```

`latitude` y `longitude` salen como **string con ocho decimales**, no como float, por el mismo criterio que `pricePerPound` en SPEC 09: son el `decimal:8` del cast y no pasan por una conversión que pueda perder dígitos.

Las fechas usan el formato `d-m-Y h:i:s A` de SPEC 07, 08 y 09, documentado en Swagger como `type: 'string'` **sin** `format: 'date-time'`.

En `FreightQuoteResource` **no viajan las coordenadas del destino**: quien cotiza mandó el `locationId` y ya las tiene. `locationName` está por la misma razón que estaba `zoneName` — para no obligar a un segundo `GET`.

### 6. Validación

```php
// StoreLocationRequest
'name'          => ['required', 'string', 'max:255', 'unique:locations,name'],   // sobre el nombre ya normalizado
'description'   => ['nullable', 'string'],
'googlePlaceId' => ['required', 'string', 'max:255', 'unique:locations,google_place_id'],
'latitude'      => ['required', 'numeric', 'between:-90,90'],
'longitude'     => ['required', 'numeric', 'between:-180,180'],
// status NO se acepta: el destino nace activo
// registeredBy NO se acepta: sale del usuario autenticado

// UpdateLocationRequest — todos sometimes, PATCH vacío es no-op
'name'          => ['sometimes', 'string', 'max:255', Rule::unique('locations', 'name')->ignore($this->route('location'))],
'description'   => ['sometimes', 'nullable', 'string'],
'googlePlaceId' => ['sometimes', 'string', 'max:255', Rule::unique('locations', 'google_place_id')->ignore($this->route('location'))],
'latitude'      => ['sometimes', 'numeric', 'between:-90,90'],
'longitude'     => ['sometimes', 'numeric', 'between:-180,180'],
'status'        => ['sometimes', 'boolean'],
// registeredBy NO figura: sale del usuario autenticado

// QuoteFreightRateRequest — lat y lng desaparecen
'locationId' => ['required', 'integer', 'exists:locations,id'],
'productId'  => ['required', 'integer', 'exists:products,id'],
'fuelType'   => ['required', Rule::enum(FuelType::class)],
'pounds'     => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],

// Store/UpdateFreightRateRequest — zoneId pasa a locationId, el resto no cambia
'locationId' => ['required'|'sometimes', 'integer', 'exists:locations,id'],
```

El `name` se normaliza en `prepareForValidation()` **antes** de que corra la regla `unique`, como en SPEC 07 y 08: si no, `Bodega Central` pasaría la validación contra un `BODEGA CENTRAL` ya guardado y reventaría en el índice único con un 500.

`description` se borra mandando `null`, por eso el service usa `array_key_exists` y no `isset`, igual que en SPEC 08.

`googlePlaceId` es el único identificador externo del proyecto que se puede reapuntar. **No hay validación cruzada con las coordenadas**: cambiar el `googlePlaceId` sin cambiar `latitude`/`longitude` es válido y deja el destino apuntando a un lugar de Google cuyo pin no coincide. Es la misma independencia de ejes que `condition` vs `status` en SPEC 13 — y queda anotado como riesgo.

`exists:` solo comprueba que la fila existe, **no que esté activa**: el `status` lo verifica el service, porque es regla de negocio con su propio mensaje y su propio 400.

### 7. Reglas de negocio de `LocationService`

- **`ensureNameIsAvailable(string $name, ?int $ignoreId = null)`** lanza `BadRequestError` si el nombre ya existe, aunque haya índice único, para que una llamada directa dé 400 y no 500. Mismo patrón que `Product` y `Zone`.
- **`ensureGooglePlaceIdIsAvailable(string $googlePlaceId, ?int $ignoreId = null)`** lanza `BadRequestError` si ese lugar ya está registrado en **otro** destino, con mensaje propio que dice cuál lo ocupa. Corre en `create` y en `update`, con la misma firma que `ensureNameIsAvailable()`.
- **`registered_by`** sale del `User` autenticado que el service recibe por parámetro, nunca del body, y el `update` **no lo reescribe**.
- **`getLocations(array $filters)`** aplica `status` y `search`, ordena por `id ASC` y pagina opt-in con `limit` acotado a `[10, 100]`. Un `status` no booleano **se ignora** (`filter_var(..., FILTER_NULL_ON_FAILURE)`), como en los catálogos de SPEC 06–08.
- **`destroy(int $id)`** pone `status = false` y es **idempotente**: borrar dos veces responde 200 las dos.
- **`toggleStatus(int $id)`** invierte el `status`. Ninguna de las dos operaciones mira si el destino tiene tarifas.

### 8. Cambios en `FreightRateService`

- **Deja de recibir `ZoneServiceInterface` por constructor.** Tras esta spec el constructor queda vacío y se elimina.
- **`ensureZoneAndProductAreActive()` pasa a `ensureLocationAndProductAreActive()`**, con la misma semántica: corre en `create` y en `update`, incluso cuando el `PATCH` solo mueve el precio, y el 400 dice cuál de los dos está inactivo.
- **`quote(array $filters)`** queda en **cinco** pasos, en este orden exacto:
  1. **Destino activo.** Se resuelve por `locationId`. Si `status` es `false` → `400`: «El destino seleccionado no está activo». *(El id inexistente ya lo atrapó el 422 del FormRequest.)*
  2. **Producto activo.** Si `status` es `false` → `400`: «El producto seleccionado no está activo».
  3. **Combustible vigente.** El `FuelPrice` `active` de ese `fuel_type`. Si no hay → `400`: «No existe un precio vigente para el combustible indicado».
  4. **Tarifas del trío.** Si `(destino, producto, fuelType)` no tiene ninguna tarifa viva → `400`: «No existe tarifa cotizada para ese producto en ese destino».
  5. **Banda aplicable.** Igual que en SPEC 09 y **nunca falla**: la de mayor `fuel_min` que sea `<= currentFuelPrice`; si el combustible está por debajo de todas, la de menor `fuel_min`. El total sigue siendo `round($pounds * $pricePerPound, 2)`, redondeado **después** del producto.

El paso 1 de SPEC 09 —resolver la zona por el punto, con su `404`— **desaparece**. La cotización ya no tiene ningún fallo `404`.

### 9. Contrato HTTP

| Método y ruta | Acción | Rol |
|---|---|---|
| `GET /api/locations?status=&search=&limit=` | Listado | cualquier autenticado |
| `POST /api/locations` | Alta | `administrator` |
| `GET /api/locations/{location}` | Detalle | cualquier autenticado |
| `PATCH /api/locations/{location}` | Edición parcial | `administrator` |
| `DELETE /api/locations/{location}` | Baja lógica idempotente | `administrator` |
| `PATCH /api/locations/{location}/toggle-status` | Alterna el estado | `administrator` |

Y en tarifas, **las seis rutas siguen existiendo con los mismos métodos y roles**; solo cambian los parámetros:

| Antes (SPEC 09) | Ahora |
|---|---|
| `GET /api/freight-rates?zoneId=` | `GET /api/freight-rates?locationId=` |
| `GET /api/freight-rates/quote?lat=&lng=&productId=&fuelType=&pounds=` | `GET /api/freight-rates/quote?locationId=&productId=&fuelType=&pounds=` |
| `POST` / `PATCH` con `zoneId` | `POST` / `PATCH` con `locationId` |

Ninguna ruta lleva `carrier.required`.

---

## Plan de implementación

Cada paso deja el sistema arrancable y la suite en verde, **con una excepción declarada**: el Paso 8 es el cambio incompatible y no se puede partir.

### Paso 1 — Tabla, modelo y factory de `Location`

`php artisan make:model Location -mf --no-interaction`. Migración con las ocho columnas, los dos índices únicos y la FK a `users` sin cascade. Modelo con `#[Fillable]`, los tres casts, `registeredBy()` y `normalizeName()`. Factory con `google_place_id` único, coordenadas dentro de Guatemala y los estados `active()` e `inactive()`.

*Verificación:* `php artisan migrate` corre limpio y `Location::factory()->create()->registeredBy` devuelve un `User`.

### Paso 2 — Resource

`LocationResource` en `app/Http/Resources/Location/`, en camelCase, con el formato de fecha `d-m-Y h:i:s A`.

*Verificación:* la suite existente sigue verde.

### Paso 3 — Contrato del service

`app/Interfaces/Location/LocationServiceInterface.php` con `getLocations`, `getLocationById`, `create`, `update`, `destroy` y `toggleStatus`, con su PHPDoc de array shapes. Sin implementación.

### Paso 4 — Service

`LocationService` con `#[Override]` en cada método: `resolvePerPage()` con sus constantes `MIN_PER_PAGE`/`MAX_PER_PAGE`, `ensureNameIsAvailable()`, `ensureGooglePlaceIdIsAvailable()`, los filtros tolerantes y la baja lógica idempotente.

*Verificación:* crear dos destinos con el mismo `googlePlaceId` lanza `BadRequestError`; el segundo `destroy` responde sin error.

### Paso 5 — Provider

`app/Providers/Location/LocationProvider.php` con el `bind`, registrado en `bootstrap/providers.php`.

*Verificación:* `app(LocationServiceInterface::class)` resuelve.

### Paso 6 — FormRequests

`StoreLocationRequest` y `UpdateLocationRequest` en `app/Http/Requests/Location/`, con `prepareForValidation()` normalizando el nombre y `messages()` en español.

### Paso 7 — Controller y rutas

`LocationController` con `try/catch` → `ResponseHandler` y el service inyectado por parámetro de método. `routes/locations.php` con `jwt.auth` en el grupo, `role:administrator` en `store`, `update`, `destroy` y `toggleStatus`, y `/{location}/toggle-status` **antes** del `apiResource`. `require` en `routes/api.php`.

*Verificación:* `php artisan route:list --path=locations` lista seis rutas con `toggle-status` antes de `{location}`.

Hasta aquí, **nada de lo existente se ha tocado**: el dominio `Location` está publicado y funcionando, y las tarifas siguen cotizando por zona.

### Paso 8 — Cambio de eje en las tarifas *(paso único, no divisible)*

Es el cambio incompatible, y sus piezas no se pueden separar sin dejar la suite roja:

1. Migración sobre `freight_rates`: `delete()`, `dropIndex`, `dropConstrainedForeignId('zone_id')`, `location_id` y el índice compuesto nuevo.
2. `FreightRate`: `#[Fillable]` y `location()` en vez de `zone()`. Factory creando un `Location`.
3. `FreightRateService`: se elimina el constructor con `ZoneServiceInterface`, `ensureZoneAndProductAreActive()` pasa a `ensureLocationAndProductAreActive()`, `ensureFuelMinIsAvailable()` cambia de clave, `getFreightRates()` filtra por `locationId` y carga `with('location', ...)`, y `quote()` pierde el paso 1 y queda en cinco.
4. `StoreFreightRateRequest`, `UpdateFreightRateRequest` y `QuoteFreightRateRequest`: `zoneId` → `locationId`, y en el de cotización desaparecen `lat` y `lng`.
5. `FreightRateResource` y `FreightQuoteResource`: `zoneId`/`zoneName` → `locationId`/`locationName`.
6. `tests/Feature/FreightRateTest.php`, `tests/Unit/FreightRateServiceTest.php` y `tests/Unit/FreightRateModelTest.php`: se actualizan aquí mismo, incluida la eliminación de los casos de "punto fuera de zona".

*Verificación:* `php artisan test --compact --filter=FreightRate` en verde, y `grep -ri "zone" app/Services/FreightRate app/Http/Requests/FreightRate app/Http/Resources/FreightRate app/Models/FreightRate.php` no devuelve nada.

### Paso 9 — Limpiar el contrato de zonas

Eliminar `getZoneContainingPoint()` de `ZoneServiceInterface`, de `ZoneService` y sus casos en `tests/Unit/ZoneServiceTest.php`. **`whereContainsPoint()` se queda**: lo sigue usando el filtro `?lat=&lng=` del listado.

Va después del Paso 8 y aislado, porque hasta entonces el método todavía tiene consumidor. Es lo único de esta spec que toca SPEC 08.

*Verificación:* `php artisan test --compact --filter=Zone` en verde y `GET /api/zones?lat=&lng=` sigue devolviendo las zonas que contienen el punto.

### Paso 10 — Formato

`vendor/bin/pint --dirty --format agent`.

### Paso 11 — Tests

Disparar el agente `feature-tests` con el modelo `Location`: Feature test HTTP (roles, validación, unicidad de nombre y de `googlePlaceId`, filtros, paginación, baja lógica idempotente y `toggle-status`) y Unit test del service.

### Paso 12 — Documentación

Disparar el agente `endpoint-docs` con el modelo `Location` y **regenerar los schemas de `FreightRate`** afectados por el renombrado. `php artisan l5-swagger:generate`.

### Paso 13 — Resumen de integración

`references/locations-api.md`, siguiendo `references/zones-api.md` como plantilla, con una sección propia para el **cambio incompatible** de `/api/freight-rates` y su tabla de equivalencias `zoneId` → `locationId`.

---

## Criterios de aceptación

**Alta de destinos**

- [x] `POST /api/locations` con `name`, `googlePlaceId`, `latitude` y `longitude` responde 201 y el destino nace con `status: true`.
- [x] `description` es opcional: sin ella el destino se crea y el Resource devuelve `null`.
- [x] El `name` se guarda en mayúsculas y con los espacios internos colapsados: `  bodega   central ` queda `BODEGA CENTRAL`.
- [~] Un segundo `POST` con el mismo nombre en distinta caja (`Bodega Central`) responde **422**, no 400 ni 500.
      *Resuelto en implementación:* §6 de esta misma spec exige la regla `unique:locations,name` en el FormRequest, y con ella Laravel corta en validación antes de que el service pueda lanzar su `BadRequestError`. Se mantuvo el 422 por decisión explícita, igual que en `Zone` y `Product`; el 400 sigue siendo alcanzable llamando al service directamente y está cubierto en el Unit test. El `googlePlaceId` sí se dejó sin regla `unique` para que su 400 llegue al cliente.
- [x] Un segundo `POST` con el mismo `googlePlaceId` responde **400**, y el mensaje nombra al destino que ya lo ocupa.
- [x] El `googlePlaceId` se guarda **tal cual llega**, sin pasar a mayúsculas.
- [x] `latitude: 91`, `longitude: 181` o coordenadas no numéricas responden **422**.
- [x] `latitude` y `longitude` salen del Resource como string con **ocho decimales**.
- [x] Mandar `status: false` en el alta no lo respeta: el destino nace activo.
- [x] `registeredBy` apunta al usuario autenticado aunque el body traiga otro.

**Edición y baja**

- [x] `PATCH` con solo `name` cambia el nombre y no toca las coordenadas ni el `googlePlaceId`.
- [x] `PATCH` que mueve `latitude`/`longitude` responde 200 y el destino conserva su `id`.
- [x] `PATCH` que cambia el `googlePlaceId` a uno libre responde 200; a uno ya usado por otro destino responde **400**.
- [x] `PATCH` que reenvía el **mismo** `googlePlaceId` del propio destino responde 200, no 400.
- [x] `PATCH { description: null }` borra la descripción.
- [x] `PATCH` con body vacío responde 200 sin cambios.
- [x] `DELETE` responde 200 y deja el destino con `status: false`, sin borrar la fila.
- [x] Un segundo `DELETE` responde **200** otra vez: la baja es idempotente.
- [x] Un destino desactivado **sigue apareciendo** en el listado sin filtro.
- [x] `PATCH /{location}/toggle-status` invierte el estado y responde 200.
- [x] `PATCH`, `DELETE`, `show` y `toggle-status` sobre un id inexistente responden **404**.

**Listado**

- [x] `GET /api/locations` devuelve la colección completa cuando no llega `limit`.
- [x] `limit=10` pagina y el sobre trae `total`, `currentPage` y `lastPage` **en la raíz**, no bajo `meta`.
- [x] `limit=5` se acota a 10 y `limit=500` se acota a 100.
- [x] `limit=abc` no pagina y devuelve la colección completa, sin error.
- [x] `status=true` y `status=false` filtran; `status=quizás` **se ignora** y devuelve el listado completo.
- [x] `search=bodega` encuentra `BODEGA CENTRAL`: el término se normaliza antes del `LIKE`.
- [x] El listado sale ordenado por `id ASC`.
- [x] Listar 20 destinos ejecuta un número de queries independiente del número de filas (sin N+1 sobre `registeredBy`).
- [x] Todas las claves del Resource están en camelCase y `createdAt` tiene la forma `19-08-2026 08:45:12 PM`.

**Autorización de destinos**

- [x] Las seis rutas sin token responden 401 con el sobre estándar.
- [x] `GET /api/locations` y `GET /api/locations/{id}` responden 200 con token de `carrier`, `pilot` y `manager`.
- [x] `POST`, `PATCH`, `DELETE` y `toggle-status` responden 403 con token de `carrier`, `pilot` y `manager`.
- [x] Ninguna ruta exige tener empresa: un `carrier` sin empresa alcanza las de lectura.

**Cambio de eje en las tarifas**

- [x] Tras `php artisan migrate:fresh`, `freight_rates` tiene `location_id` y **no** tiene `zone_id`.
- [x] El índice compuesto existe sobre `(location_id, product_id, fuel_type, fuel_min)` y **no es único**.
- [x] `POST /api/freight-rates` con `locationId` responde 201; con `zoneId` responde **422** por `locationId` faltante.
- [x] Dos tarifas vivas con el mismo `(locationId, productId, fuelType, fuelMin)` son 400; tras un `DELETE`, ese `fuelMin` vuelve a aceptarse.
- [x] `POST` sobre un destino con `status: false` responde 400.
- [x] `PATCH` de una tarifa cuyo destino se desactivó después responde 400, aunque solo cambie el precio.
- [x] `GET /api/freight-rates?locationId=` filtra por destino; sin él devuelve todas, sin paginar.
- [x] `FreightRateResource` devuelve `locationId` y `locationName`, y **ninguna** clave `zoneId` ni `zoneName`.
- [x] `grep -ri "zone" app/Services/FreightRate app/Http/Requests/FreightRate app/Http/Resources/FreightRate app/Models/FreightRate.php` no devuelve ninguna línea.

**Cotización**

- [x] `GET /api/freight-rates/quote?locationId=&productId=&fuelType=` devuelve `locationName`, `pricePerPound`, `currentFuelPrice` y `appliedFuelMin`.
- [x] `lat` y `lng` en la query **se ignoran por completo**: no filtran, no validan y no cambian la respuesta.
- [x] `quote` sin `locationId` responde **422**.
- [x] Un `locationId` que no existe responde **422**, no 404.
- [x] Un `locationId` de un destino inactivo responde **400** con mensaje propio.
- [x] Un producto inactivo responde 400; un `fuelType` sin `FuelPrice` vigente responde 400; un trío sin tarifas responde 400.
- [x] Los cuatro fallos de 400 traen **mensajes distintos entre sí**, en español.
- [x] **Ninguna respuesta de `/quote` es 404.**
- [x] La selección de banda sigue intacta: con bandas *desde 28* y *desde 35*, un diésel a 40 aplica la de 35, a 30 la de 28 y a 25 también la de 28, sin error.
- [x] Con `pounds: 45000`, `total` sale con dos decimales y se calcula sobre la tarifa completa de seis.
- [x] Sin `pounds`, `pounds` y `total` viajan `null`.
- [x] La cotización no persiste nada: el número de filas de `freight_rates` no cambia tras llamarla.

**El dominio de zonas queda intacto**

- [x] Las seis rutas de `/api/zones` siguen respondiendo igual que antes de esta spec.
- [x] `GET /api/zones?lat=&lng=` sigue devolviendo las zonas que contienen el punto.
- [x] `ZoneServiceInterface` **ya no declara** `getZoneContainingPoint()`, y `ZoneService` tampoco lo implementa.
- [x] `whereContainsPoint()` sigue existiendo en `ZoneService`.
- [x] `php artisan test --compact --filter=Zone` pasa entero.
- [x] `locations` no tiene ninguna columna PostGIS y ningún archivo de `Location/` menciona `ST_`.

**Cierre**

- [x] `php artisan test --compact` pasa la suite entera, incluidas las specs anteriores.
- [x] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [~] `php artisan route:list --path=locations` lista las seis rutas; `toggle-status` se registra **antes** del `apiResource` (verificable con `--sort=definition`), que es lo que impide que el comodín `{location}` la capture. La salida por defecto del comando ordena por URI, así que la muestra al final — igual que en `zones`.
- [x] `/api/documentation` muestra los seis endpoints de destinos y los seis de tarifas ya con `locationId`.
- [x] `references/locations-api.md` existe y documenta la tabla de equivalencias `zoneId` → `locationId`.

---

## Decisiones tomadas y descartadas

### El destino se manda por `id`, no por punto

**Descartado:** seguir mandando `lat`/`lng` y resolver el **destino más cercano** con un radio máximo.

Habría conservado el contrato actual de `/quote`, pero traslada al servidor una decisión que el usuario ya tomó: si el front acaba de elegir un lugar de una lista, mandar su `id` es exacto y mandar sus coordenadas es una aproximación que hay que deshacer. Además obliga a elegir un radio —25 km, 5 km— que es arbitrario y que convierte un destino mal tecleado en una cotización silenciosamente equivocada.

**Descartado también:** aceptar las dos formas. Dos caminos de resolución significan dos conjuntos de fallos y dos formas de cotizar el mismo viaje con números distintos.

**Consecuencia asumida:** cotizar un lugar exige darlo de alta antes. No hay cotización *ad hoc* de una dirección suelta, y eso es deliberado — el destino pasa a ser un dato administrado, no un parámetro libre.

### Los destinos sustituyen a las zonas en las tarifas, pero las zonas no se tocan

**Descartado:** eliminar el dominio `Zone` por completo.

Era la opción más limpia —dejaba de existir un dominio geográfico sin consumidor y PostGIS dejaba de ser requisito del proyecto y de la suite—, pero borra trabajo publicado y probado por una razón que hoy no aprieta. Las zonas siguen siendo útiles para pintar el mapa y para agrupar operación, aunque no coticen.

**Descartado también:** marcar `/api/zones` como `deprecated` en Swagger. Deprecar algo que no se piensa borrar es ruido: el front leería una advertencia que nunca se cumple.

**Consecuencia asumida:** el proyecto queda con **dos dominios geográficos vivos** —zonas poligonales y destinos puntuales— sin ninguna relación entre ellos, y **PostGIS sigue siendo requisito** de la suite aunque el camino del dinero ya no lo use.

### Cambiar el eje de `freight_rates` en vez de crear una tabla paralela

**Descartado:** tabla nueva `location_freight_rates` conviviendo con `freight_rates`.

Habría evitado el cambio incompatible, pero duplica el dominio entero —modelo, service, requests, resources, controller, rutas— para expresar exactamente la misma regla de banda abierta. Dos tablas de tarifas también significan dos respuestas posibles a "cuánto cuesta la libra", que es justo lo que no puede pasar en un cálculo de dinero.

**Descartado también:** `zone_id` y `location_id` ambos `nullable` con un `CHECK` de exactamente uno. Conserva el histórico a cambio de que toda consulta, todo filtro y todo Resource tengan que ramificar para siempre.

### Migración destructiva, sin backfill

Las tarifas actuales son de prueba y se descartan. Un backfill exigiría inventar un destino por cada zona —un punto que la represente— y ese punto no existe: una zona es un polígono, no un lugar. Cualquier centroide sería un dato fabricado con apariencia de dato real, y quedaría en la tabla que decide precios.

**Consecuencia:** esta spec **no se puede aplicar sobre una base con tarifas reales** sin recapturarlas a mano. Está anotado como riesgo.

### Dos columnas `decimal`, no un `geography(Point,4326)`

**Descartado:** columna PostGIS con índice GiST, como `Zone::area`.

Solo se paga si hay consultas espaciales, y tras la decisión del `locationId` explícito no queda ninguna: no hay distancia, ni vecino más cercano, ni contención. Con dos `decimal` el modelo queda plano, todas las columnas son `fillable`, no hace falta `readQuery()` con `ST_AsGeoJSON` y la tabla no depende de la extensión.

`decimal(10,8)` y `decimal(11,8)` dan precisión de milímetros, muy por encima de la de Google, y no arrastran los errores de redondeo de un flotante.

### `google_place_id` obligatorio, único y **editable**

Obligatorio porque es lo que ancla el destino a un lugar real y verificable; único porque dos filas apuntando al mismo sitio son un duplicado por definición, y el índice único lo impide de verdad.

**Descartado:** hacerlo inmutable. Era la primera opción —"cambiar de lugar es crear otro destino"—, y se rechazó porque destruye el historial: un destino con tarifas ya cotizadas que se re-registra pierde su `id` y obliga a recapturarlas todas. Reapuntarlo conserva la fila y sus tarifas.

**Consecuencia:** no hay validación cruzada entre `google_place_id` y las coordenadas. Se puede reapuntar el lugar y dejar el pin viejo. Está anotado como riesgo.

### La API no llama a Google

**Descartado:** mandar solo `googlePlaceId` y resolver `latitude`/`longitude` con `PlaceServiceInterface::getPlaceById()` (SPEC 12).

Es menos campos en el body y garantiza que las coordenadas coincidan con el lugar. Se rechazó por precio y por fragilidad: cada alta costaría una llamada facturada, y un `POST /api/locations` podría responder **503** por un problema de la cuenta de Google. El front ya tiene las coordenadas en la mano —acaba de recibirlas de `/api/places`— y mandarlas es gratis.

`LocationService` no conoce `PlaceServiceInterface`, y `google_place_id` es para él una cadena opaca que no valida contra nadie.

### Renombrado completo del contrato, sin periodo de gracia

**Descartado:** mantener `zoneId`/`zoneName` en la API apuntando a `locations`.

Habría evitado tocar el front, a cambio de una desalineación permanente entre lo que dice la API y lo que hay en la base. Es la clase de mentira que se descubre seis meses después depurando.

**Descartado también:** renombrar el recurso entero a `/api/location-freight-rates`. Rompe más URLs para expresar lo mismo; el recurso sigue siendo la tarifa de flete.

Es el mismo criterio del `POST` de SPEC 13: cambio incompatible, aplicado de golpe y documentado, en un proyecto con un único consumidor conocido.

### Se elimina `getZoneContainingPoint()`

**Descartado:** dejarlo en el contrato, sin llamador.

Un método público en una interfaz es una promesa, y esta spec le quita su única razón de existir. Mantenerlo obliga a implementarlo en cualquier doble de test y a seguir probándolo. `whereContainsPoint()` se queda porque el filtro `?lat=&lng=` del listado de zonas sí lo usa, así que **el conocimiento de PostGIS no se pierde**: si el día de mañana hace falta resolver punto→zona otra vez, es un método público de cinco líneas sobre algo que ya existe.

### `Location`, no `Destination`, y sin `color`

`Destination` describe el papel que juega hoy —el punto final de un flete—, y ese papel puede cambiar: mañana el mismo registro puede ser origen. `Location` describe qué es la fila, no para qué se usa, y no obliga a renombrar la tabla cuando aparezcan los orígenes.

**`color` se descarta** porque era un dato de presentación del polígono: un pin de mapa no necesita relleno. Si el front quiere diferenciarlos, lo hace por `status`.

### Sin columna `address` y sin relación `Location`–`Zone`

`address` cabe hoy en `description` y añadirla obligaría a decidir quién la mantiene al cambiar el `googlePlaceId`. La relación con la zona se descartó porque nadie la consume: nada pregunta "¿en qué zona cae este destino?", y calcularla exigiría volver a meter PostGIS en un dominio del que acaba de salir.

---

## Riesgos identificados

### La migración destructiva borra tarifas reales si se aplica tarde

`up()` empieza con `DB::table('freight_rates')->delete()`. Hoy eso descarta datos de prueba. Si esta spec se implementa dentro de un mes y para entonces alguien capturó la tabla de precios real, la migración se la lleva sin preguntar y sin dejar copia.

**Mitigación:** parcial. El riesgo se cierra implementando pronto. Si no, el paso previo obligatorio es un `pg_dump` de `freight_rates` antes de migrar, y la recaptura es manual: no hay backfill posible porque una zona no tiene un punto que la represente. Está en el alcance como decisión, no como accidente.

### El front deja de cotizar el día del deploy, y el error no explica por qué

`lat` y `lng` desaparecen de `/quote` sin periodo de gracia. Un cliente sin actualizar manda `?lat=&lng=&productId=&fuelType=` y recibe un **422 por `locationId` faltante** — un mensaje correcto que no dice nada sobre el cambio de contrato. Lo mismo con `?zoneId=` en el listado, que simplemente se ignora y devuelve todas las tarifas de todos los destinos, sin error visible.

**Mitigación:** `references/locations-api.md` lleva la tabla de equivalencias y Swagger se regenera en el mismo paso. Es la misma apuesta de SPEC 13 —un único consumidor conocido, cambio de golpe y documentado—, y depende de que el front se despliegue coordinado. **El caso silencioso es el peor:** el listado sin filtro no falla, solo devuelve de más.

### Las coordenadas dejan de influir en el precio, pero siguen pareciendo que lo hacen

Tras esta spec, `/quote` resuelve el destino por `id` y **nunca lee `latitude` ni `longitude`**. Un destino con el pin mal puesto cotiza exactamente igual que uno bien puesto. Quien corrija las coordenadas esperando que cambie la tarifa no verá ningún cambio, y quien las teclee mal no verá ningún fallo.

**Mitigación:** ninguna en esta spec, y es consecuencia directa de la decisión del `locationId` explícito. Las coordenadas quedan como dato de presentación —pintar el pin en el mapa— y como semilla de la spec de viajes, que sí las necesitará para distancia y ruta. Conviene que Swagger lo diga: la tarifa depende del destino, no de dónde esté.

### Reapuntar el `googlePlaceId` deja el pin desalineado, sin aviso

`googlePlaceId` es editable y no hay validación cruzada con las coordenadas. Un `PATCH { googlePlaceId }` a secas deja la fila apuntando a un lugar de Google y mostrando el pin del anterior, y el sistema lo acepta con 200.

**Mitigación:** parcial. Es la contrapartida de conservar el `id` y las tarifas al reapuntar, que era el objetivo. La salida barata es que el front mande siempre los tres campos juntos —`googlePlaceId`, `latitude`, `longitude`— porque los tres salen de la misma respuesta de `/api/places`. La API no lo exige.

### Dos destinos distintos para el mismo lugar físico

El índice único sobre `google_place_id` impide dos filas con **el mismo** id de Google, no dos filas del **mismo sitio**. Google devuelve identificadores distintos para la bodega y para su entrada, o para el mismo lugar re-registrado. Dos destinos así pasan la unicidad, aceptan tarifas distintas para el mismo producto y nadie los relaciona.

**Mitigación:** el `name` único obliga a inventar dos nombres distintos, lo que hace el duplicado visible al capturarlo. No hay detección automática, y una por proximidad —"ya existe un destino a 200 m"— es exactamente el cálculo de distancia que esta spec sacó del alcance.

### Desactivar un destino congela sus tarifas sin decirlo

Heredado de SPEC 09 y ahora con más superficie: `update` de una tarifa exige destino y producto activos, incluso si el `PATCH` solo mueve el precio. Un destino desactivado deja todas sus tarifas **incotizables y no editables** a la vez, y la baja lógica no avisa de cuántas arrastra.

**Mitigación:** el mensaje del 400 dice cuál de los dos está inactivo, y reactivar el destino restituye todo. El `DELETE` de la tarifa **sí** sigue funcionando: borrar nunca se bloquea.

### La banda vieja sigue aplicándose en silencio

El riesgo más caro de SPEC 09 **no se resuelve aquí**: una tarifa cotizada *desde 28* sigue rigiendo con el diésel a 60, ahora por destino en vez de por zona. Cambiar el eje no cambia nada de esto.

**Mitigación:** la misma de entonces — `currentFuelPrice` y `appliedFuelMin` viajan en cada cotización para que la distancia sea visible. Cambiar de zona a destino **multiplica el número de bandas a mantener**: donde una zona cubría veinte lugares, ahora hay veinte filas que recotizar. El riesgo no es nuevo, pero es más grande.

---

## Lo que **no** entra en esta spec

- Borrar, deprecar o modificar el dominio de zonas, más allá de quitar `getZoneContainingPoint()`.
- Cotizar por punto geográfico, por destino más cercano o por radio.
- Que la API llame a Google Places para resolver o validar nada.
- Relación entre destinos y zonas, distancia, ruta o kilometraje.
- Migrar o recuperar las tarifas de flete existentes.
- Destinos por empresa, agrupaciones, alias o jerarquías.
- Columna `address`, alta en lote e importación.
- Programación de viajes.

Cada uno, si entra, va en su propia spec.
