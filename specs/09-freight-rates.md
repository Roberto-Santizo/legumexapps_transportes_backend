# SPEC 09 — Tarifas de flete por zona y producto

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 03, SPEC 06, SPEC 07, SPEC 08
> **Fecha:** 2026-08-13
> **Objetivo:** Registrar tarifas de flete en quetzales por libra para cada combinación de zona, producto y tipo de combustible, vigentes desde un precio mínimo de combustible, y resolver automáticamente la tarifa aplicable a partir de un punto geográfico y del precio de combustible vigente.

Depende de **SPEC 01** por el guard JWT y por el `User` al que apunta `registered_by`; de **SPEC 03** por el middleware `role`, que restringe la escritura al `administrator`; de **SPEC 06** porque la cotización resuelve el `FuelPrice` `active` del tipo pedido y porque de ahí sale el enum `FuelType`; de **SPEC 07** por el `Product`; y de **SPEC 08** por la `Zone`, por PostGIS y por `ST_Contains`, que es lo que traduce el punto del destino en una zona.

Es la primera spec del proyecto que **cruza tres dominios existentes** en vez de publicar uno nuevo, y la primera que **consume** las zonas, los productos y los precios de combustible que las tres specs anteriores dejaron publicados sin consumidor. Es la base de la futura programación de viajes, pero **no** la contiene: aquí solo se responde "cuánto cuesta la libra hasta este punto", nunca "cuánto cuesta este viaje concreto de 45 000 libras registrado como tal".

**Modelo de banda abierta.** Una tarifa no se ata a una fila de `fuel_prices`: guarda un `fuel_min` y rige **desde** ese precio de combustible hacia arriba, hasta que exista otra banda más alta del mismo par. Con bandas cotizadas *desde 28* y *desde 35*, un diésel a 40 aplica la de 35, y un diésel a 25 aplica la de 28 — la más barata que exista. Ninguna consulta falla por el precio del combustible.

---

## Alcance

**Dentro:**

- Migración `freight_rates`: `id`, `zone_id` (FK a `zones`), `product_id` (FK a `products`), `fuel_type` (`string`), `fuel_min` (`decimal(8,2)`, GTQ por galón), `price_per_pound` (`decimal(12,6)`, GTQ por libra), `registered_by` (FK a `users`), `timestamps` y **`deleted_at`**.
- **Índice compuesto no único** `(zone_id, product_id, fuel_type, fuel_min)`: es exactamente la consulta caliente de la cotización. No es único porque la unicidad vive en el service, para que una tarifa borrada libere su `fuel_min`.
- Modelo `FreightRate` con `SoftDeletes`, factory y relaciones `zone()`, `product()` y `registeredBy()`.
- Cadena de capas completa en la subcarpeta `FreightRate/`: `FreightRateServiceInterface`, `FreightRateService`, `FreightRateProvider`, `StoreFreightRateRequest`, `UpdateFreightRateRequest`, `QuoteFreightRateRequest`, `FreightRateResource`, `FreightQuoteResource` y `FreightRateController`.
- **Ampliación del contrato de SPEC 08:** `ZoneServiceInterface` y `ZoneService` suman `getZoneContainingPoint(float $latitude, float $longitude): ?Zone`.
- `routes/freight_rates.php` incluido desde `routes/api.php`, con la ruta fija `/quote` declarada **antes** del `apiResource`.
- **Banda abierta por `fuel_min`.** Una tarifa rige desde ese precio de combustible hacia arriba. La cotización elige la banda con el `fuel_min` **más alto que sea ≤ el precio vigente**; si el vigente está por debajo de todas, elige la de `fuel_min` **más bajo**. Nunca falla por el precio del combustible.
- **`GET /api/freight-rates/quote`** con `lat`, `lng`, `productId` y `fuelType` **obligatorios** y `pounds` opcional. Resuelve la zona con `ST_Contains` (SPEC 08), toma el `FuelPrice` `active` de ese tipo (SPEC 06), elige la banda y devuelve la tarifa por libra. Si llega `pounds`, devuelve además el total.
- **El precio de combustible nunca viaja en la petición.** Sale siempre del `FuelPrice` `active`; si el cliente pudiera mandarlo, cotizaría al precio que le conviniera.
- **La cotización no persiste nada.** Es una consulta pura: ninguna fila se crea, se edita ni se marca.
- **Unicidad en el service:** no pueden existir dos tarifas **vivas** con el mismo `(zone_id, product_id, fuel_type, fuel_min)`. Intentarlo es `400 BadRequestError`. Un soft delete libera ese `fuel_min` para volver a cotizarlo.
- **Zona y producto deben estar activos** (`status: true`) para poder crear o editar una tarifa; intentarlo con cualquiera de los dos inactivo es `400`. La cotización trata la zona o el producto inactivos como inexistentes.
- **CRUD completo sin paginación:** `index` devuelve la colección completa, sin `limit` y sin `PaginatedResource`, con **un único filtro opcional: `zoneId`**, ordenada por `fuel_type ASC, fuel_min ASC`.
- **`DELETE` es soft delete real** (`deleted_at`). La fila desaparece del listado y de la cotización. No hay `status`, no hay `/toggle-status` y no hay `restore`.
- **Escritura solo `administrator`**, y también el `show`; el `index` y la cotización quedan abiertos a cualquier usuario autenticado, sin `carrier.required`.
- `registered_by` sale del usuario autenticado, nunca del body, y el `update` no lo reescribe.
- `FreightRateResource` en camelCase, con la zona y el producto resueltos por nombre para no obligar a un segundo `GET`.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **La programación de viajes.** Esta spec responde "cuánto vale la libra hasta aquí"; registrar el viaje, su carga, su vehículo, su piloto y su estado es la spec siguiente.
- **Validación de solape entre zonas.** Se asume que ninguna zona se cruza, y se garantizará en su propia spec. Aquí la cotización toma la **primera** zona que contiene el punto.
- **Bandas cerradas (`fuel_max`) y huecos.** Descartado en favor de la banda abierta.
- **Distancia real, kilometraje, geocodificación de direcciones y cálculo de ruta.** El destino es un punto, y lo único que se deriva de él es la zona que lo contiene.
- **Tarifa por vehículo, por capacidad o por tipo de camión.** La tarifa depende solo de zona, producto y combustible.
- **Escalas por volumen** (precio distinto a partir de X libras) y descuentos.
- **Impuestos, IVA, redondeo comercial y moneda configurable.** GTQ y libra son convención del dominio, documentadas en Swagger.
- **Recalcular o crear tarifas automáticamente** al registrarse un `FuelPrice` nuevo, y notificar de bandas no cotizadas.
- **Simulación de cotizaciones** mandando un precio de combustible hipotético.
- **Auditoría del `price_per_pound` anterior.** El `PATCH` sobrescribe sin dejar rastro; solo se conserva quién dio de alta la fila.
- **`restore` de tarifas borradas.** Si hace falta la banda otra vez, se cotiza de nuevo.
- **Filtros por producto o por tipo de combustible**, búsqueda, orden configurable y paginación en `index`.
- **Alta en lote e importación desde CSV o Excel.**
- **Cotizar mandando `zoneId` en vez del punto**, y cotizar varios productos en una sola llamada.

---

## Modelo de datos

Esta spec **no introduce ningún enum**: reutiliza `App\Enums\FuelType` de SPEC 06.

### 1. Tabla `freight_rates`

```php
Schema::create('freight_rates', function (Blueprint $table) {
    $table->id();
    $table->foreignId('zone_id')->constrained('zones');
    $table->foreignId('product_id')->constrained('products');
    $table->string('fuel_type');
    $table->decimal('fuel_min', 8, 2);            // GTQ por galón, desde el cual rige
    $table->decimal('price_per_pound', 12, 6);    // GTQ por libra
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();
    $table->softDeletes();

    $table->index(['zone_id', 'product_id', 'fuel_type', 'fuel_min']);
});
```

`price_per_pound` es `decimal(12, 6)`: seis decimales para que `0.454120` no pierda precisión, y seis enteros de margen. `fuel_min` es `decimal(8, 2)`, **la misma forma que `fuel_prices.price`** — comparar dos decimales con dos decimales evita sorpresas de redondeo en el `<=` de la banda.

El índice compuesto cubre la consulta de la cotización de punta a punta: filtra por los tres primeros y ordena por el cuarto. **No es único** por la misma razón que la placa de SPEC 04: con `deleted_at`, un índice único impediría recotizar un `fuel_min` que se borró.

Ninguna FK lleva `cascadeOnDelete`, como en SPEC 06, 07 y 08: borrar una zona o un producto que tiene tarifas debe frenarse, no propagarse.

### 2. Modelo

```php
#[Fillable(['zone_id', 'product_id', 'fuel_type', 'fuel_min', 'price_per_pound', 'registered_by'])]
class FreightRate extends Model
{
    use HasFactory, SoftDeletes;

    public function zone(): BelongsTo;
    public function product(): BelongsTo;
    public function registeredBy(): BelongsTo;

    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'fuel_min' => 'decimal:2',
            'price_per_pound' => 'decimal:6',
        ];
    }
}
```

La factory crea zona, producto y usuario administrador propios, con un `fuel_min` y un `price_per_pound` realistas. **Ninguna relación inversa** en `Zone`, `Product` ni `User`.

### 3. La resolución del punto vive en `ZoneService`, no aquí

SPEC 08 cerró con un criterio explícito: *ningún archivo fuera de `ZoneService` y del modelo `Zone` menciona `ST_`*. Esta spec lo respeta. Se **añade un método al contrato de SPEC 08**:

```php
// ZoneServiceInterface
/** Devuelve la primera zona activa que contiene el punto, o null si ninguna lo contiene. */
public function getZoneContainingPoint(float $latitude, float $longitude): ?Zone;
```

`FreightRateService` lo recibe **por constructor** —igual que los contratos de almacenamiento de SPEC 05, y a diferencia de la regla de inyección por parámetro que es solo del controller— y nunca escribe una consulta espacial. Filtra por `status = true` y ordena por `id ASC` para que, si algún día hay solape pese al supuesto, la elección sea determinista y no aleatoria.

### 4. Resources

```php
// FreightRateResource — el CRUD
[
    'id',
    'zoneId', 'zoneName',
    'productId', 'productName',
    'fuelType',        // 'diesel'
    'fuelMin',         // '30.00'  — rige desde este precio de combustible
    'pricePerPound',   // '0.454120'
    'registeredByName',
    'createdAt',       // '13-08-2026 08:45:12 PM'
    'updatedAt',
]

// FreightQuoteResource — la cotización
[
    'freightRateId',      // qué tarifa se aplicó
    'zoneId', 'zoneName',
    'productId', 'productName',
    'fuelType',           // 'diesel'
    'currentFuelPrice',   // '40.00'  — el FuelPrice active al momento de consultar
    'appliedFuelMin',     // '35.00'  — la banda elegida
    'pricePerPound',      // '0.454120'
    'pounds',             // '45000.00' | null
    'total',              // '20435.40' | null
]
```

`FreightQuoteResource` **no envuelve un modelo**: envuelve el array que devuelve el service. Devolver `FreightRateResource` habría sido más barato, pero omite las tres cosas que hacen útil la respuesta — el precio de combustible vigente, la banda que se eligió y el total.

`currentFuelPrice` y `appliedFuelMin` son la trazabilidad del cálculo: sin ellos, quien recibe `0.454120` no puede saber por qué le tocó esa tarifa y no otra.

Las fechas usan el formato `d-m-Y h:i:s A` de SPEC 07 y 08, documentado en Swagger como `type: 'string'` **sin** `format: 'date-time'`.

### 5. Cálculo del total

```php
$total = round($pounds * (float) $rate->price_per_pound, 2);
```

Se calcula en PHP, se redondea **solo al final** y sale con dos decimales, porque es dinero. El punto flotante es seguro a esta escala: el caso peor de este dominio ronda los once dígitos significativos y un `double` maneja quince. Redondear la tarifa antes de multiplicar sí perdería quetzales — por eso el redondeo va después del producto y no antes.

### 6. Validación

```php
// StoreFreightRateRequest
'zoneId'        => ['required', 'integer', 'exists:zones,id'],
'productId'     => ['required', 'integer', 'exists:products,id'],
'fuelType'      => ['required', Rule::enum(FuelType::class)],
'fuelMin'       => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
'pricePerPound' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
// registeredBy NO se acepta: sale del usuario autenticado

// UpdateFreightRateRequest — todos sometimes, PATCH vacío es no-op
'zoneId'        => ['sometimes', 'integer', 'exists:zones,id'],
'productId'     => ['sometimes', 'integer', 'exists:products,id'],
'fuelType'      => ['sometimes', Rule::enum(FuelType::class)],
'fuelMin'       => ['sometimes', 'numeric', 'min:0.01', 'max:999999.99'],
'pricePerPound' => ['sometimes', 'numeric', 'min:0.000001', 'max:999999.999999'],

// QuoteFreightRateRequest — valida la query, no el body
'lat'       => ['required', 'numeric', 'between:-90,90'],
'lng'       => ['required', 'numeric', 'between:-180,180'],
'productId' => ['required', 'integer', 'exists:products,id'],
'fuelType'  => ['required', Rule::enum(FuelType::class)],
'pounds'    => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
```

El body y la query hablan **camelCase** (`zoneId`, `fuelMin`, `pricePerPound`); el service traduce a snake_case al persistir, como el `carrierId` de SPEC 04 y el `fuelType` de SPEC 06.

`exists:` solo comprueba que la fila existe, **no que esté activa**: el `status` lo verifica el service, porque es una regla de negocio con su propio mensaje y su propio 400.

El `PATCH` vacío se acepta como no-op, siguiendo SPEC 08 y no SPEC 06: hay cinco campos editables, y encadenar `required_without` entre todos produciría cinco mensajes idénticos.

`QuoteFreightRateRequest` existe por la misma razón que `CurrentFuelPriceRequest` en SPEC 06: sin él, un `fuelType` ausente acabaría en un error del service en vez de un 422 con mensaje en español.

### 7. Reglas de negocio del service

- **Zona y producto activos.** `ensureZoneAndProductAreActive(int $zoneId, int $productId)` lanza `BadRequestError` si cualquiera tiene `status: false`. Corre en `create` y en `update`, incluso cuando el `PATCH` solo cambia el precio: una tarifa no se edita si su par ya no es cotizable.
- **`fuel_min` disponible.** `ensureFuelMinIsAvailable(int $zoneId, int $productId, string $fuelType, string $fuelMin, ?int $ignoreId = null)` lanza `BadRequestError` si ya existe una tarifa **viva** con esa combinación. Es la única regla de unicidad; no hay índice que la respalde a propósito.
- **Guarda compartida por id.** `show`, `update` y `destroy` resuelven la fila con **`withTrashed()`**: si el id no existe es `NotFoundError` (404); si existe pero está borrada es `BadRequestError` (400) con «La tarifa ya fue eliminada». Sin `withTrashed()`, el segundo `DELETE` sería un 404 inevitable.
- **`registered_by`** sale del `User` autenticado que el service recibe por parámetro, nunca del body. El `update` no lo reescribe.
- **`getFreightRates(array $filters)`** arranca de `FreightRate::with('zone', 'product', 'registeredBy')`, aplica `zoneId` si llega, ordena por `fuel_type ASC, fuel_min ASC` y devuelve siempre la colección completa, sin paginar. Un `zoneId` no numérico se ignora sin error; un `zoneId` numérico que no corresponde a ninguna zona devuelve lista vacía.
- **`destroy(int $id)`** hace `delete()` soft. La baja **no es idempotente**: el segundo `DELETE` responde 400.
- **`quote(array $filters)`**, en este orden exacto, porque cada fallo tiene su propio mensaje:
  1. **Zona.** `getZoneContainingPoint($lat, $lng)`. Si devuelve `null` → `404 NotFoundError`: «El punto indicado no pertenece a ninguna zona registrada».
  2. **Producto activo.** Si el producto tiene `status: false` → `400 BadRequestError`: «El producto seleccionado no está activo».
  3. **Combustible vigente.** El `FuelPrice` `active` de ese `fuel_type`. Si no hay → `400 BadRequestError`: «No existe un precio vigente para el combustible indicado».
  4. **Tarifas del par.** Si `(zona, producto, fuelType)` no tiene ninguna tarifa viva → `400 BadRequestError`: «No existe tarifa cotizada para ese producto en esa zona».
  5. **Banda aplicable.** La de mayor `fuel_min` que sea `<= currentFuelPrice`. Si ninguna lo cumple —el combustible está por debajo de todas—, la de **menor** `fuel_min`. Este paso **nunca falla**: si se llegó aquí, hay al menos una tarifa y siempre sale una.
  6. **Total.** Si llegó `pounds`, `round($pounds * $pricePerPound, 2)`; si no, `pounds` y `total` viajan `null`.

### 8. Contrato HTTP

| Método y ruta | Acción | Rol |
|---|---|---|
| `GET /api/freight-rates?zoneId=` | Listado completo, filtro opcional por zona | cualquier autenticado |
| `GET /api/freight-rates/quote?lat=&lng=&productId=&fuelType=&pounds=` | Cotización | cualquier autenticado |
| `POST /api/freight-rates` | Alta | `administrator` |
| `GET /api/freight-rates/{freightRate}` | Detalle | `administrator` |
| `PATCH /api/freight-rates/{freightRate}` | Edición parcial | `administrator` |
| `DELETE /api/freight-rates/{freightRate}` | Soft delete | `administrator` |

`/quote` se declara **antes** del `apiResource`, como en SPEC 03, 04, 06, 07 y 08. Ninguna ruta lleva `carrier.required`.

El `show` es solo para `administrator`, a diferencia de SPEC 06 y 08: quien no administra tarifas usa `/quote`, no el detalle.

---

## Plan de implementación

Cada paso deja el sistema arrancable y la suite en verde.

### Paso 1 — Ampliar el contrato de zonas

`getZoneContainingPoint(float $latitude, float $longitude): ?Zone` en `ZoneServiceInterface` y su implementación en `ZoneService`, filtrando `status = true` y ordenando por `id ASC`. Reutiliza el `whereRaw` de `ST_Contains` que ya existe en el filtro del `index`; se extrae a un método privado para no duplicar la consulta espacial.

Va primero y solo: es lo único de esta spec que toca código de una spec anterior. *Verificación:* la suite de SPEC 08 sigue verde y un unit test nuevo comprueba que un punto dentro devuelve la zona, uno fuera devuelve `null` y una zona inactiva que contiene el punto devuelve `null`.

### Paso 2 — Tabla y modelo

`php artisan make:model FreightRate -mf --no-interaction`. Migración con las ocho columnas, `softDeletes()`, las tres FK sin cascade y el índice compuesto no único. Modelo con `#[Fillable]`, `SoftDeletes`, los tres casts y las tres relaciones. Factory que crea zona, producto y administrador propios. *Verificación:* `php artisan migrate` corre limpio y `FreightRate::factory()->create()->zone` devuelve una `Zone`.

### Paso 3 — Resources

`FreightRateResource` y `FreightQuoteResource` en `app/Http/Resources/FreightRate/`, ambos en camelCase. El segundo envuelve un array, no un modelo. *Verificación:* la suite existente sigue verde.

### Paso 4 — Contrato del service

`app/Interfaces/FreightRate/FreightRateServiceInterface.php` con los seis métodos y su PHPDoc de array shapes. Sin implementación.

### Paso 5 — Service, CRUD

`FreightRateService` con `ZoneServiceInterface` inyectado **por constructor** y `#[Override]` en cada método. Aquí van `getFreightRates()`, la guarda compartida con `withTrashed()`, `ensureZoneAndProductAreActive()`, `ensureFuelMinIsAvailable()`, `create()`, `update()` y `destroy()`. *Verificación:* cotizar dos veces el mismo `fuel_min` para el mismo par lanza `BadRequestError`; borrar y volver a cotizar ese mismo `fuel_min` funciona.

### Paso 6 — Service, cotización

`quote(array $filters)` con los seis pasos de la sección 7 en ese orden exacto. **Aquí va el test antes que el resto de capas**, porque la selección de banda es la única lógica de esta spec donde un error no lanza ninguna excepción: solo devuelve un número equivocado. El unit test cubre banda exacta, banda intermedia, combustible por encima de todas, combustible por debajo de todas y una sola banda existente. *Verificación:* con bandas desde 28 y desde 35, un diésel a 40 aplica la de 35, uno a 30 la de 28 y uno a 25 también la de 28.

### Paso 7 — Provider

`app/Providers/FreightRate/FreightRateProvider.php` con el `bind`, registrado en `bootstrap/providers.php`. *Verificación:* `app(FreightRateServiceInterface::class)` resuelve.

### Paso 8 — FormRequests

`StoreFreightRateRequest`, `UpdateFreightRateRequest` y `QuoteFreightRateRequest` en `app/Http/Requests/FreightRate/`, con `messages()` en español.

### Paso 9 — Controller y rutas

`FreightRateController` con `try/catch` → `ResponseHandler` y el service inyectado por parámetro de método. `routes/freight_rates.php` con `jwt.auth` en el grupo, `role:administrator` en `store`, `show`, `update` y `destroy`, y `/quote` **antes** del `apiResource`. `require` en `routes/api.php`. *Verificación:* `php artisan route:list --path=freight-rates` lista seis rutas con `quote` antes de `{freightRate}`.

El prefijo de la URL es `freight-rates` (guion) y el archivo es `routes/freight_rates.php` (guion bajo), como en SPEC 06.

### Paso 10 — Formato

`vendor/bin/pint --dirty --format agent`.

### Paso 11 — Tests

Disparar el agente `feature-tests` con el modelo `FreightRate`: Feature test HTTP (roles, validación, unicidad de banda, soft delete, los cuatro fallos de la cotización y el cálculo del total) y Unit test del service.

### Paso 12 — Documentación

Disparar el agente `endpoint-docs` con el modelo `FreightRate` y regenerar `storage/api-docs/api-docs.json`. El schema de `/quote` documenta GTQ, libra y galón como unidades, y lleva un ejemplo real completo: 45 000 libras de brócoli a zona norte con diésel a 40.

---

## Criterios de aceptación

**Contrato de zonas (Paso 1)**

- [x] `getZoneContainingPoint()` devuelve la zona cuando el punto cae dentro de su polígono.
- [x] Devuelve `null` cuando el punto no cae en ninguna zona.
- [x] Devuelve `null` cuando la única zona que contiene el punto tiene `status: false`.
- [x] La suite completa de SPEC 08 sigue en verde tras el cambio.
- [x] Ningún archivo de `FreightRate/` menciona `ST_`, `WKT` ni el orden `lng lat`.

**Alta y unicidad de banda**

- [x] `POST` con `zoneId`, `productId`, `fuelType: diesel`, `fuelMin: 30.00` y `pricePerPound: 0.454120` responde 201 y devuelve `pricePerPound: '0.454120'` con los seis decimales intactos.
- [x] Un segundo `POST` con el **mismo** `(zoneId, productId, fuelType, fuelMin)` responde 400.
- [x] El mismo `fuelMin` para **otro** `fuelType`, otra zona u otro producto se acepta.
- [x] Tras un `DELETE`, volver a cotizar ese mismo `fuelMin` para ese mismo par responde 201.
- [x] `POST` sobre una zona con `status: false` responde 400.
- [x] `POST` sobre un producto con `status: false` responde 400.
- [x] `POST` con `zoneId` o `productId` inexistente responde 422, no 400.
- [x] `registeredBy` apunta al usuario autenticado aunque el body traiga otro.

**Selección de banda**

Con tarifas cotizadas *desde 28* (`0.400000`) y *desde 35* (`0.454120`) para el mismo par:

- [x] Diésel vigente a **40** → aplica `0.454120` y `appliedFuelMin: '35.00'`.
- [x] Diésel vigente a **35** exacto → aplica `0.454120`; el límite es inclusivo.
- [x] Diésel vigente a **30** → aplica `0.400000` y `appliedFuelMin: '28.00'`.
- [x] Diésel vigente a **25**, por debajo de todas → aplica `0.400000`, **sin error**.
- [x] Con una sola banda cotizada, cualquier precio de combustible la aplica.

**Cotización**

- [x] `GET /api/freight-rates/quote` con un punto dentro de una zona devuelve `zoneName`, `pricePerPound`, `currentFuelPrice` y `appliedFuelMin`.
- [x] Con `pounds: 45000` devuelve `total` con **dos** decimales, y el producto se calcula sobre la tarifa completa de seis decimales, no sobre una redondeada.
- [x] Sin `pounds`, `pounds` y `total` viajan `null` y el resto de la respuesta es idéntica.
- [x] `currentFuelPrice` coincide con el `FuelPrice` `active` de ese tipo aunque el cliente mande un precio en la query: el parámetro se ignora por completo.
- [x] Un punto **fuera** de toda zona responde **404**.
- [x] Un producto con `status: false` responde **400**.
- [x] Un `fuelType` sin ningún `FuelPrice` `active` responde **400**.
- [x] Un par zona+producto sin ninguna tarifa responde **400**.
- [x] Los cuatro fallos anteriores traen **mensajes distintos entre sí**, en español.
- [x] Una tarifa borrada no se aplica nunca: si era la única del par, la cotización responde 400.
- [x] `quote` sin `fuelType`, sin `productId`, sin `lat` o sin `lng` responde 422.
- [x] `lat=200`, `lng=500` o `fuelType=gasolina` responden 422 — a diferencia del `index` de SPEC 08, aquí un valor fuera de rango **no se ignora**.
- [x] La cotización **no persiste nada**: el número de filas de `freight_rates` no cambia tras llamarla.

**Edición y baja**

- [x] `PATCH` con solo `pricePerPound` cambia el precio y no toca `fuelMin`, `zoneId`, `productId` ni `fuelType`.
- [x] `PATCH` que mueve el `fuelMin` a un valor ya ocupado por otra tarifa viva del mismo par responde 400.
- [x] `PATCH` que mueve el `fuelMin` a un valor libre responde 200.
- [x] `PATCH` sobre una tarifa cuya zona o producto se desactivó después responde 400, aunque solo cambie el precio.
- [x] `PATCH` con body vacío responde 200 sin cambios.
- [x] `DELETE` responde 200 y la fila **desaparece** de `GET /api/freight-rates`.
- [x] La fila sigue existiendo en base con `deleted_at` poblado.
- [x] Un segundo `DELETE` sobre la misma tarifa responde **400**, no 404.
- [x] `show` y `update` sobre una tarifa borrada responden **400**.
- [x] `show`, `update` y `destroy` sobre un id que nunca existió responden **404**.

**Autorización**

- [x] Las seis rutas sin token responden 401 con el sobre estándar.
- [x] `GET /api/freight-rates` y `/quote` responden 200 con token de `carrier`, `pilot` y `manager`.
- [x] Ninguna ruta exige tener empresa: un `carrier` sin empresa las alcanza.
- [x] `POST`, `GET /{id}`, `PATCH` y `DELETE` responden 403 con token de `carrier`, `pilot` y `manager`.

**Listado y forma de la respuesta**

- [x] `GET /api/freight-rates?zoneId=` devuelve solo las tarifas de esa zona.
- [x] Sin `zoneId`, devuelve todas las tarifas de todas las zonas.
- [x] Un `zoneId` de una zona que no existe devuelve una lista vacía con 200.
- [x] Un `zoneId` no numérico se ignora y devuelve el listado completo, sin error. Cualquier otro query param se ignora.
- [x] `GET /api/freight-rates` devuelve la colección completa: `limit=10` **no** pagina y el sobre no trae `total`, `currentPage` ni `lastPage`.
- [x] El listado sale ordenado por `fuelType` y, dentro de cada tipo, por `fuelMin` ascendente.
- [x] Todas las claves de ambos Resources están en camelCase y `createdAt` tiene la forma `13-08-2026 08:45:12 PM`.
- [x] Listar 20 tarifas ejecuta un número de queries independiente del número de filas (sin N+1 sobre `zone`, `product` ni `registeredBy`).
- [x] Toda respuesta viaja en el sobre `{ statusCode, message, data }`.

**Cierre**

- [x] `php artisan test --compact` pasa la suite entera, incluidas las specs anteriores.
- [x] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [x] `php artisan route:list --path=freight-rates` muestra `quote` **antes** de la ruta `{freightRate}`.
- [x] `/api/documentation` muestra los seis endpoints con el ejemplo de 45 000 libras de brócoli.

---

## Decisiones tomadas y descartadas

### Banda abierta por `fuel_min`, sin `fuel_max`

**Descartado:** rango cerrado `fuel_min` + `fuel_max`.

Era la primera opción sobre la mesa y es la más explícita: cada tarifa dice exactamente entre qué precios rige. Se rechazó porque **deja huecos**, y el hueco es justo el caso que originó esta spec: el diésel a 40 con una única banda cotizada en `[28, 32]` no tiene tarifa, y la consulta falla en el momento en que alguien necesita programar un viaje. Con banda abierta, cotizar una vez basta para que el sistema siempre responda.

**Descartado:** valor exacto cotizado, una fila por cada precio de combustible.

Habría exigido cotizar cada centavo del diésel. Inviable a mano.

**Consecuencia asumida:** una tarifa cotizada *desde 28* sigue aplicándose con el diésel a 60 hasta que alguien cotice una banda superior. El sistema **nunca avisa** de que la tarifa se quedó vieja; devuelve un número desactualizado con toda la confianza. Es el precio de que la consulta no falle nunca, y está anotado como riesgo.

### El combustible es una banda, no una FK a `FuelPrice`

**Descartado:** `fuel_price_id` apuntando a la fila vigente al cotizar.

Es lo que pedía la intuición inicial, y se descartó en la primera ronda de preguntas: ataría la tarifa a **una** fila del histórico de SPEC 06, cuando lo que rige es cualquier precio dentro de un tramo. Con FK, cada `POST` de un `FuelPrice` nuevo dejaría todas las tarifas apuntando al pasado y obligaría a recapturarlas una por una.

La relación con SPEC 06 es de **lectura en el momento de consultar**, no de dependencia estructural. Por eso `freight_rates` no tiene ninguna columna que apunte a `fuel_prices`, y borrar el histórico de precios no rompería una sola tarifa.

### `fuel_type` como columna propia

**Descartado:** asumir diésel en todo el sistema.

Es lo que hoy usan los camiones, y habría ahorrado una columna y un parámetro obligatorio en `/quote`. Se rechazó por una razón barata: la columna cuesta hoy y evita rehacer la tabla —y todas las tarifas ya cotizadas— el día que un pickup cotice con regular. El enum ya existe desde SPEC 06.

### La unicidad vive en el service, no en un índice

Con `deleted_at`, un índice único sobre `(zone_id, product_id, fuel_type, fuel_min)` **bloquearía para siempre** un `fuel_min` que se borró: recotizar 30 tras haber borrado el 30 anterior fallaría contra el índice con un 500. Es exactamente el problema de la placa de SPEC 04 y se resuelve igual — el índice se queda como índice de consulta, no de restricción, y `ensureFuelMinIsAvailable()` mira solo las filas vivas.

### SoftDeletes en lugar de `status` booleano

**Descartado:** `status` booleano con baja lógica y `/toggle-status`, como SPEC 07 y 08.

Allí `status` es un estado del negocio: una zona desactivada sigue existiendo como zona. Aquí no hay tal estado — una tarifa está cotizada o no lo está. `deleted_at` dice justo eso y saca la fila de los listados y de la cotización sin ninguna cláusula extra.

**Descartado también:** un endpoint `restore`. Recotizar la banda es un `POST` de cinco campos; resucitar una tarifa vieja es más código y más superficie para equivocarse.

**Consecuencia:** la baja **no es idempotente**, a diferencia de SPEC 08. El segundo `DELETE` responde 400 con «La tarifa ya fue eliminada», que es información, no un error del cliente. Es también la razón de que las tres acciones por id resuelvan con `withTrashed()`.

### La consulta espacial se queda en `ZoneService`

SPEC 08 cerró con el criterio de que nadie fuera de `ZoneService` y del modelo `Zone` conoce PostGIS. Duplicar el `whereRaw` de `ST_Contains` dentro de `FreightRateService` habría sido cinco líneas y habría roto esa frontera el mismo día que se estrenó. Se añade un método al contrato de SPEC 08 y se inyecta **por constructor**, como los contratos de almacenamiento de SPEC 05.

Es la decisión con más valor a futuro de esta spec: el dominio de viajes va a necesitar la misma resolución punto→zona, y ya la tendrá lista.

### El precio de combustible nunca viaja en la petición

Aceptarlo por query habría permitido simular cotizaciones —"¿cuánto costaría si el diésel subiera a 45?"—, que es una función legítima y probablemente deseable. Se rechaza aquí porque el mismo parámetro convierte la cotización real en un número que el cliente elige: cualquiera cotizaría al precio que le conviene. Si la simulación hace falta, va como endpoint aparte y explícitamente marcado como tal.

### `index` con un solo filtro y sin paginación

Rompe la convención de SPEC 03, 04, 06, 07 y 08, que traen `limit` y `PaginatedResource`. Es deliberado: el listado de tarifas se lee como una **tabla de precios** completa —todas las bandas de un par, ordenadas—, no como un histórico que se navega. Paginarlo obligaría a recorrer páginas para ver una tabla que cabe en pantalla.

El único filtro es `zoneId`, y existe porque las tarifas se pintan **dentro de la vista de la zona**: sin él, el front tendría que traerse la tabla entera para mostrar un fragmento. `productId` y `fuelType` no entran — dentro de una zona el volumen ya es manejable y filtrarlo es trabajo del cliente sobre datos que ya tiene.

### `FreightQuoteResource` aparte de `FreightRateResource`

Devolver la tarifa cruda habría sido gratis. Se descartó porque omite las tres cosas que hacen auditable la respuesta: qué precio de combustible se usó, qué banda se eligió y cuál fue el total. Sin ellas, quien recibe `0.454120` no tiene forma de saber por qué le tocó ese número.

### Seis decimales en `price_per_pound`, dos en el total

El ejemplo real del dominio —`0.454120`— ya usa seis. Redondear la tarifa a dos decimales antes de multiplicar por 45 000 libras cambiaría el flete en cientos de quetzales, así que el redondeo va **después** del producto y solo sobre el total, que es lo único que se cobra.

### El destino es un punto, no una `zoneId`

Mandar la zona elegida a mano habría eliminado la dependencia de PostGIS y el 404 de "fuera de zona". Se rechazó porque obliga al usuario a saber en qué zona cae su destino, que es precisamente lo que el sistema debe resolver solo. La `zoneId` explícita queda como salida natural si algún día hay zonas solapadas y hace falta desambiguar.

---

## Riesgos identificados

### Una banda vieja se aplica en silencio para siempre

Es el riesgo más caro de la spec y es consecuencia directa de la banda abierta. Una tarifa cotizada *desde 28* sigue rigiendo con el diésel a 60: la consulta responde 200, con un número que se ve perfectamente válido y que ya no cubre el costo real del flete. No hay excepción, no hay 400, no hay log — solo un flete cotizado por debajo.

**Mitigación:** parcial. `currentFuelPrice` y `appliedFuelMin` viajan en cada respuesta precisamente para que la distancia entre ambos sea visible: `currentFuelPrice: '60.00'` junto a `appliedFuelMin: '28.00'` es la señal de que esa tarifa lleva mucho sin recotizarse. Lo que **no** hay en esta spec es nada que avise por sí solo. La salida natural es un umbral de desviación o un listado de "tarifas desactualizadas", y ninguna de las dos entra aquí.

### El `index` sin filtro de zona crece con el producto cartesiano

El listado sin `zoneId` devuelve todas las tarifas de todos los pares. El volumen no es lineal: es `zonas × productos × tipos de combustible × bandas`. Con 15 zonas, 40 productos y tres bandas de diésel ya son 1 800 filas en una sola respuesta, sin paginar.

**Mitigación:** el filtro `zoneId` es la salida práctica y cubre el consumidor principal, que es la vista de zona. El `with()` de las tres relaciones evita el N+1, que es lo que convertiría el problema de "respuesta grande" en "respuesta lenta". Si llega a doler, `limit` y el resto de filtros son la misma media hora que costaron en las otras cinco specs.

### Cotizar el `fuel_min` equivocado no falla, solo cambia el precio

Teclear `3` en vez de `30` crea una banda válida, distinta de las existentes y que pasa la unicidad sin problema. A partir de ahí es la banda más barata del par y se aplica a cualquier diésel por debajo de la siguiente — o a todos, si es la única. El error no se detecta al crear: se detecta cuando alguien cotiza un flete y el número sale raro.

**Mitigación:** `fuelMin` es editable precisamente por esto, y `appliedFuelMin` viaja en cada cotización, que es donde el error se hace visible. No hay validación posible: `3.00` es un precio de combustible sintácticamente correcto.

### El cambio en `ZoneService` toca una spec cerrada

El Paso 1 modifica el contrato y la implementación de SPEC 08, que está implementada y con su suite en verde. Extraer el `whereRaw` de `ST_Contains` a un método privado compartido con el filtro del `index` puede alterar el comportamiento de ese filtro sin que nadie lo note.

**Mitigación:** el Paso 1 va aislado y antes que nada, con la suite de SPEC 08 como criterio de aceptación explícito. Si algo se rompe, se sabe con certeza que fue ese cambio y no la feature nueva.

### El supuesto de "no hay zonas solapadas" no está garantizado por nada

SPEC 08 permite el solape de forma deliberada y documentada, y esta spec asume lo contrario. Hoy nada lo impide: dos zonas cruzadas con tarifas distintas para el mismo producto hacen que la cotización devuelva la de menor `id`, sin decir que había otra.

**Mitigación:** el orden `id ASC` hace la elección **determinista** —la misma consulta devuelve siempre la misma zona—, que es lo mínimo exigible en un cálculo de dinero. La garantía real depende de la spec de validación de solape que quedó pendiente. Hasta entonces, el supuesto es una convención operativa, no una restricción del sistema.

### El total lo puede recalcular el front y no coincidir

`pounds` es opcional; cuando no llega, el consumidor multiplica por su cuenta. Si redondea la tarifa a dos decimales antes de multiplicar —lo natural si la muestra en pantalla como `0.45`—, 45 000 libras dan 20 250.00 en vez de 20 435.40: **185 quetzales de diferencia** en un solo flete.

**Mitigación:** `pricePerPound` viaja con los seis decimales completos y el endpoint calcula el total cuando se le pasan las libras. Queda documentado en Swagger que el total autoritativo es el que devuelve la API, no el que se recalcula en pantalla.

### Desactivar una zona o un producto rompe el `PATCH` de sus tarifas

`update` verifica que la zona y el producto sigan activos aunque el `PATCH` solo cambie el precio. Si alguien desactiva un producto temporalmente, las tarifas de ese producto quedan **congeladas**: no se pueden editar ni recotizar hasta reactivarlo.

**Mitigación:** es el comportamiento decidido, no un accidente — editar la tarifa de algo que no se puede cotizar es trabajo perdido. El mensaje del 400 dice cuál de los dos está inactivo, para que la salida sea obvia. El `DELETE`, en cambio, **sí** funciona sobre una tarifa con zona o producto inactivo: borrar nunca se bloquea.
