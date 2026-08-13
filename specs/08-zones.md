# SPEC 08 — Zonas geográficas

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 03
> **Fecha:** 2026-08-07
> **Objetivo:** Registrar zonas geográficas nacionales como polígonos PostGIS `geography(Polygon,4326)`, con nombre único, color, estado y consulta de qué zona contiene un punto dado.

Depende de **SPEC 01** por el guard JWT y por el `User` al que apunta `registered_by`, y de **SPEC 03** por el middleware `role`, que restringe la escritura al `administrator`. No depende de SPEC 04 ni de SPEC 06: una zona no se relaciona todavía con vehículos ni con precios. No depende de SPEC 05: no lleva imagen.

Es la primera spec del proyecto que **exige PostgreSQL con PostGIS**. Hasta ahora las migraciones eran portables y la suite corría sobre SQLite en memoria; a partir de aquí no. Ese cambio de infraestructura es parte del alcance, no un efecto colateral.

---

## Alcance

**Dentro:**

- **Requisito de infraestructura:** PostgreSQL con la extensión PostGIS. La primera migración de esta spec ejecuta `CREATE EXTENSION IF NOT EXISTS postgis;` antes de crear la tabla.
- **Cambio en la suite de tests:** `phpunit.xml` deja de usar SQLite en memoria y apunta a una base Postgres dedicada (`legumexapps_transportes_testing`) en el mismo servidor local, con PostGIS habilitada. `RefreshDatabase` se conserva. A partir de esta spec, **ningún** test del proyecto corre sin Postgres levantado.
- Migración `zones`: `id`, `name` (`string`, único), `description` (`text`, nullable), `color` (`string(7)`), `area` (`geography(Polygon,4326)`), `status` (`boolean`, default `true`), `registered_by` (FK a `users`), `timestamps`. Sin `deleted_at`.
- **Índice GiST sobre `area`**, creado con SQL crudo en la misma migración. Sin él, `ST_Contains` hace escaneo secuencial sobre toda la tabla.
- Modelo `Zone` con factory, relación `registeredBy()` y estados de factory `active()` e `inactive()`.
- Cadena de capas completa en la subcarpeta `Zone/`: `ZoneServiceInterface`, `ZoneService`, `ZoneProvider`, `StoreZoneRequest`, `UpdateZoneRequest`, `ZoneResource` y `ZoneController`.
- `routes/zones.php` incluido desde `routes/api.php`, con la ruta fija `/{zone}/toggle-status` declarada **antes** del `apiResource`.
- **El polígono entra y sale como array de pares `[lat, lng]` con el anillo abierto**: `[[14.6349, -90.5069], [14.6402, -90.4998], [14.6281, -90.4931]]`. El service es el único punto del sistema que conoce el orden `lng lat` de PostGIS y el punto de cierre repetido; ni el cliente ni el resto de las capas los ven.
- **Validación del polígono:** mínimo 3 pares, cada par exactamente 2 números, `lat` en `[-90, 90]` y `lng` en `[-180, 180]`. Mensajes en español apuntando al índice del punto que falla.
- El `name` se normaliza a mayúsculas y es único a nivel global, con índice único en base, exactamente como en SPEC 07 (`Zone::normalizeName()`).
- El `color` es un hex `#RRGGBB` normalizado a mayúsculas, **opcional**: si no llega, la zona nace con `#3388FF` —el azul por defecto de Leaflet—, con el default fijado tanto en la columna como en el service. Es dato de presentación para pintar la zona en un mapa, sin ninguna semántica de negocio, y el `ZoneResource` nunca lo devuelve `null`.
- **Filtro `lat` + `lng` en el `index`**: cuando llegan los dos, el listado se acota con `ST_Contains` a las zonas que contienen ese punto. Convive con `status` y `search` como un filtro más.
- Filtros opcionales adicionales en `index`: `status` (booleano, valor no booleano se ignora) y `search` (`LIKE` sobre `name`, término normalizado a mayúsculas). Orden fijo `id ASC`.
- Paginación opcional por `limit` con `PaginatedResource`, acotada a `[10, 100]`, como en SPEC 03, 04, 06 y 07.
- **Escritura solo `administrator`**; lectura para cualquier usuario autenticado, sin `carrier.required`. `DELETE` es baja lógica idempotente (`status = false`) y existe `/{zone}/toggle-status`.
- `ZoneResource` en camelCase: `id`, `name`, `description`, `color`, `area`, `status`, `registeredByName`, `createdAt` y `updatedAt`, con el formato de fecha `d-m-Y h:i:s A` de SPEC 07.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **Validación de solape entre zonas.** Dos zonas pueden cruzarse; por eso el filtro `lat`+`lng` devuelve una lista y no una zona.
- **Validación de geometría auto-intersectada** (`ST_IsValid`). Un polígono en forma de "8" se acepta, y `ST_Contains` dará resultados discutibles sobre él.
- **Tope máximo de vértices.** Un trazado a mano alzada del front puede meter miles de puntos.
- **Agujeros (anillos interiores) y `MultiPolygon`.** Solo se admite un anillo exterior; una zona es una figura simple y contigua.
- **Área en km², perímetro, centroide** y cualquier otra métrica derivada (`ST_Area`, `ST_Centroid`).
- **Zona más cercana** a un punto que no cae dentro de ninguna (`ST_Distance`).
- **Asignación de zonas a empresas.** Son zonas de Legumex; no hay `carrier_id` ni pivote.
- **Relación con viajes, rutas o vehículos.** Las zonas se publican; nadie las consume todavía.
- **Borrado real.** Una zona usada en un histórico futuro no debe poder desaparecer.
- **Auditoría del polígono anterior.** El `PATCH` sobrescribe `area` sin dejar rastro; solo se conserva quién dio de alta la fila.
- **Edición parcial de vértices.** Para cambiar un punto se manda el array completo.
- **Importación desde GeoJSON, KML o shapefile**, y alta en lote.
- **Otros SRID.** Todo es 4326; no hay reproyección.
- **Orden configurable** (`sortBy`, `sortDir`) y filtros por rango de fechas.

---

## Modelo de datos

Esta spec no introduce ningún enum: `status` es un booleano, como en SPEC 07.

### 1. Extensión y tabla `zones`

```php
DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');

Schema::create('zones', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();              // siempre en mayúsculas
    $table->text('description')->nullable();
    $table->string('color', 7)->default('#3388FF');
    $table->geography('area', 'polygon', 4326);
    $table->boolean('status')->default(true);
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();
});

DB::statement('CREATE INDEX zones_area_gist_index ON zones USING GIST (area)');
```

`$table->geography('area', 'polygon', 4326)` es sintaxis nativa de Laravel 13; genera `geography(Polygon,4326)`, el equivalente exacto del decorador TypeORM de referencia. El `down()` hace `Schema::dropIfExists('zones')` pero **no** borra la extensión: otras tablas futuras la usarán.

El índice GiST va en SQL crudo porque el schema builder de Laravel no expone índices espaciales. Sin él, `ST_Contains` escanea la tabla entera.

`name` lleva índice único de verdad, como en SPEC 07 y por la misma razón: al guardarse siempre en mayúsculas, la unicidad es insensible a mayúsculas sin depender del collation.

`registered_by` usa `constrained('users')` sin `cascadeOnDelete`, igual que en SPEC 06 y 07.

### 2. Modelo

```php
#[Fillable(['name', 'description', 'color', 'status', 'registered_by'])]
class Zone extends Model
{
    public function registeredBy(): BelongsTo;

    /** Normaliza un nombre: recorta, colapsa espacios internos y pasa a mayúsculas. */
    public static function normalizeName(string $name): string;

    /**
     * Convierte pares [lat, lng] con anillo abierto en WKT de PostGIS.
     * Invierte cada par a `lng lat` y repite el primer punto al final.
     *
     * @param  array<int, array{0: float, 1: float}>  $pairs
     */
    public static function pairsToWkt(array $pairs): string;

    /**
     * Convierte el GeoJSON que devuelve ST_AsGeoJSON en pares [lat, lng]
     * con el anillo abierto: invierte cada par y descarta el punto repetido.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public static function geoJsonToPairs(string $geoJson): array;

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }
}
```

**`area` no es `fillable` a propósito.** No es un valor escalar que se asigne: entra por la expresión `ST_GeogFromText(...)` y sale por `ST_AsGeoJSON(...)`. Dejarla en `fillable` invitaría a un `Zone::create(['area' => $request->area])` que insertaría un array como texto y reventaría.

`pairsToWkt()` y `geoJsonToPairs()` son **el único lugar del proyecto que conoce el orden `lng lat` de PostGIS y el punto de cierre repetido**. Están en el modelo, estáticas y públicas, por la misma razón que `normalizeName()` en SPEC 07: tienen dos llamadores legítimos —el service al escribir y el Resource al leer— y la regla no puede duplicarse. Son inversas exactas: `geoJsonToPairs(pairsToWkt($p))` devuelve `$p`.

La factory nace `active`, con un nombre de zona, color aleatorio y un triángulo válido alrededor de Ciudad de Guatemala; añade los estados `active()` e `inactive()`.

**Ninguna relación inversa en `User`.**

### 3. Lectura del polígono

Toda consulta del service que vaya a producir un `ZoneResource` añade la columna calculada:

```php
->select('zones.*')
->selectRaw('ST_AsGeoJSON(area) as area_geojson')
```

PostgreSQL devuelve la geometría cruda como WKB en hexadecimal, ilegible desde PHP sin un decodificador binario. `ST_AsGeoJSON` la entrega ya parseada y es la vía más barata: un solo `json_decode` en el modelo y fuera.

### 4. Escritura del polígono

```php
DB::statement(
    'UPDATE zones SET area = ST_GeogFromText(?) WHERE id = ?',
    ["SRID=4326;{$wkt}", $zone->id]
);
```

El `create` inserta la fila **dentro de una transacción**: primero el resto de columnas por Eloquent, después el `UPDATE` del `area` con el WKT **como binding**, nunca interpolado. Es la única forma de que las coordenadas pasen por PDO en vez de concatenarse a la sentencia; con `DB::raw` interpolado, el `area` sería una vía de inyección aunque los valores estén validados como numéricos.

Como `area` es `NOT NULL`, la transacción es obligatoria: si el `UPDATE` falla, la fila a medias no puede quedar viva.

En `update`, el `area` solo se reescribe si el body la trae.

### 5. Resource

```php
// ZoneResource
[
    'id',
    'name',                // siempre en mayúsculas
    'description',         // string | null
    'color',               // '#3388FF'
    'area',                // [[14.6349, -90.5069], [14.6402, -90.4998], ...]  anillo abierto
    'status',              // true | false
    'registeredByName',
    'createdAt',           // '07-08-2026 06:03:22 PM'
    'updatedAt',
]
```

`area` sale de `Zone::geoJsonToPairs($this->area_geojson)`. Si `area_geojson` no viene cargada —porque alguien construyó el modelo sin el `selectRaw`— el Resource devuelve `[]` en vez de reventar; es un fallo de programación que no debe traducirse en un 500 en producción.

`registeredByName` sale de `$this->registeredBy->name`; el service carga la relación con `with('registeredBy')` para no provocar N+1.

`createdAt` y `updatedAt` usan el formato **`d-m-Y h:i:s A`** de SPEC 07, y por lo mismo se documentan en Swagger como `type: 'string'` **sin** `format: 'date-time'`.

### 6. Validación

```php
// StoreZoneRequest
'name'        => ['required', 'string', 'max:255', Rule::unique('zones', 'name')],
'description' => ['nullable', 'string', 'max:1000'],
'color'       => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
'area'        => ['required', 'array', 'min:3'],
'area.*'      => ['required', 'array', 'size:2'],
'area.*.0'    => ['required', 'numeric', 'between:-90,90'],    // lat
'area.*.1'    => ['required', 'numeric', 'between:-180,180'],  // lng
// status NO se acepta: nace siempre en true
// registeredBy NO se acepta: sale del usuario autenticado

// UpdateZoneRequest
'name'        => ['sometimes', 'string', 'max:255', Rule::unique('zones', 'name')->ignore($this->route('zone'))],
'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
'color'       => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
'status'      => ['sometimes', 'boolean'],
'area'        => ['sometimes', 'array', 'min:3'],
// ... mismas reglas anidadas
```

Los dos requests implementan `prepareForValidation()` con `Zone::normalizeName()` sobre `name` y `mb_strtoupper()` sobre `color`. Sin lo primero, mandar `zona norte` existiendo `ZONA NORTE` pasaría la validación `unique` y reventaría contra el índice con un 500 — el mismo razonamiento de SPEC 07.

Las reglas `area.*.0` y `area.*.1` son lo que fija el orden **`[lat, lng]`**: el primer elemento del par se valida contra el rango de latitud y el segundo contra el de longitud. Un par `[98, -14]` —longitud primero— falla con «La latitud del punto 1 debe estar entre -90 y 90», que es exactamente el error que debe ver quien invierta el orden.

`min:3` sobre `area` exige tres vértices distintos: el cierre lo pone el service, así que el cliente nunca manda el cuarto punto repetido.

En el `UpdateZoneRequest` **todos los campos son `sometimes`** y no hay `required_without` cruzado como en SPEC 07: aquí hay cinco campos editables en vez de dos, y encadenarlos todos produciría cinco mensajes idénticos en un 422. Un `PATCH` con body vacío se acepta y no cambia nada; es un no-op, no un error.

### 7. Reglas de negocio del service

- **Normalización.** `create` y `update` pasan `name` por `Zone::normalizeName()` y `color` por `mb_strtoupper()` antes de persistir, aunque el FormRequest ya lo haya hecho: el service es llamable directamente y no puede confiar en su llamador.
- **Nombre disponible.** `ensureNameIsAvailable(string $name, ?int $ignoreId = null)` lanza `BadRequestError` si otro registro ya tiene ese nombre. Duplica la regla `unique` del request a propósito, como en SPEC 07. Por HTTP nunca se ve ese 400: la regla `unique` del FormRequest corta antes con un 422; el `BadRequestError` cubre la llamada directa al service.
- **`registered_by`** sale del `User` autenticado que el service recibe por parámetro, nunca del body. El `update` no lo reescribe.
- **`create`** fuerza `status = true` y aplica `#3388FF` si no llega color.
- **`toggleStatus`** invierte el booleano. No recibe body.
- **`destroy`** pone `status = false` y es **idempotente**: sobre una zona ya inactiva responde 200 sin cambios. No borra la fila ni la geometría.
- **404 en todo lo que resuelve por id.** `show`, `update`, `toggleStatus` y `destroy` lanzan `NotFoundError`.
- **Filtros de `index`:**
  - `status` con `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)`; valor no booleano se ignora sin error.
  - `search` aplica `LIKE %TERM%` sobre `name` con el término en mayúsculas; en blanco se ignora.
  - `lat` + `lng`: **solo se aplica si llegan los dos y ambos son numéricos y están en rango**. Añade `whereRaw('ST_Contains(area::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))', [$lng, $lat])` — nótese el orden `lng, lat` de `ST_MakePoint`, invertido respecto a los query params. Si llega uno solo, o alguno está fuera de rango, el filtro **se ignora entero** en vez de fallar, coherente con cómo se tratan `status` y `search`.
  - No filtra por `status` implícitamente: `lat`+`lng` sin `status` devuelve también zonas inactivas que contienen el punto.
- **Orden fijo `id ASC`.**
- **`limit`:** ausente o no numérico devuelve la colección completa; numérico pagina, acotado a `[10, 100]`.

### 8. Contrato HTTP

| Método y ruta | Acción | Rol |
|---|---|---|
| `GET /api/zones` | Listado con filtros `status`, `search`, `lat`+`lng`, `limit` | cualquier autenticado |
| `POST /api/zones` | Alta | `administrator` |
| `GET /api/zones/{zone}` | Detalle | cualquier autenticado |
| `PATCH /api/zones/{zone}` | Edición parcial | `administrator` |
| `PATCH /api/zones/{zone}/toggle-status` | Invierte `status` | `administrator` |
| `DELETE /api/zones/{zone}` | Baja lógica idempotente | `administrator` |

La ruta fija `/{zone}/toggle-status` se declara **antes** del `apiResource`, como en SPEC 03, 04 y 07.

---

## Plan de implementación

Cada paso deja el sistema en verde. El primero es el único que puede romper código existente.

### Paso 1 — Mover la suite a PostgreSQL

Antes de escribir una línea de la feature. Es el paso con más riesgo y el que no admite quedarse a medias.

1. Crear la base `legumexapps_transportes_testing` en el Postgres local y habilitar PostGIS en ella (`CREATE EXTENSION postgis;`). La extensión se instala **por base de datos**, no por servidor: tenerla en la de desarrollo no la pone en la de tests.
2. En `phpunit.xml`, sustituir `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` por `DB_CONNECTION=pgsql` apuntando a esa base, con host, puerto, usuario y contraseña explícitos.
3. Correr `php artisan test --compact` **sin tocar nada más** y dejar la suite entera en verde sobre Postgres.

Lo que previsiblemente hay que ajustar aquí: comparaciones de texto sensibles a mayúsculas que SQLite tolera y Postgres no, orden de resultados no determinista en listados sin `ORDER BY`, y booleanos que en SQLite llegaban como `0`/`1`. Todo lo que rompa se arregla en este paso; ningún test se borra ni se marca como omitido.

**Punto de no retorno:** a partir de aquí, clonar el repo y correr los tests exige un Postgres con PostGIS levantado. Debe quedar escrito en el README y en `CLAUDE.md`.

### Paso 2 — Migración

`php artisan make:migration create_zones_table`. Extensión, tabla e índice GiST según el modelo de datos. `php artisan migrate` en desarrollo y `php artisan migrate:fresh` en la base de tests para verificar que la extensión se crea sola en una base virgen.

### Paso 3 — Modelo, factory y helpers de geometría

`Zone` con `fillable` (sin `area`), `casts`, `registeredBy()`, `normalizeName()`, `pairsToWkt()` y `geoJsonToPairs()`. Factory con estados `active()` e `inactive()` y un triángulo válido.

Aquí se escribe **el primer test, antes que el service**: un unit test de ida y vuelta que verifica que `geoJsonToPairs()` deshace exactamente lo que hace `pairsToWkt()`, incluidos el orden invertido y el punto de cierre. Es la única lógica de esta spec donde un error silencioso —coordenadas cambiadas— no produce ninguna excepción, solo zonas en el lugar equivocado del mapa.

### Paso 4 — Interface, Service y Provider

`ZoneServiceInterface` con PHPDoc de array shapes, `ZoneService` con `#[Override]` en cada método, `ZoneProvider` con el `bind`, registrado en `bootstrap/providers.php`. Incluye la transacción de `create`, el `UPDATE` parametrizado del `area`, el `selectRaw` de `ST_AsGeoJSON` y los filtros del `index`.

### Paso 5 — FormRequests

`StoreZoneRequest` y `UpdateZoneRequest` en `app/Http/Requests/Zone/`, con `messages()` en español —incluidos los mensajes por índice de punto— y `prepareForValidation()`.

### Paso 6 — Resource

`ZoneResource` en camelCase, con el `[]` defensivo cuando falta `area_geojson`.

### Paso 7 — Controller y rutas

`ZoneController` con `try/catch` → `ResponseHandler` y el service inyectado por parámetro de método. `routes/zones.php` con `jwt.auth` en todo y `role:administrator` en la escritura, `/{zone}/toggle-status` **antes** del `apiResource`, incluido desde `routes/api.php`. Verificar con `php artisan route:list --path=zones`.

### Paso 8 — Formato

`vendor/bin/pint --dirty --format agent`.

### Paso 9 — Tests

Disparar el agente `feature-tests` con el modelo `Zone`: Feature test HTTP (roles, validación, filtros, `lat`+`lng`, baja lógica idempotente) y Unit test del service.

### Paso 10 — Documentación

Disparar el agente `endpoint-docs` con el modelo `Zone` y regenerar `storage/api-docs/api-docs.json` con `php artisan l5-swagger:generate`. El schema del `area` es `type: 'array'` de arrays de dos números, con `minItems: 2` y `maxItems: 2` en el par y `example` de un triángulo real.

---

## Criterios de aceptación

**Infraestructura**

- [x] `php artisan test --compact` corre sobre PostgreSQL y la suite completa —incluidos todos los tests anteriores a esta spec— pasa en verde.
- [x] Ningún test previo fue borrado ni marcado como omitido para conseguirlo.
- [x] `php artisan migrate:fresh` sobre una base virgen con PostGIS instalada crea la tabla `zones` sin intervención manual.
- [x] El índice `zones_area_gist_index` existe tras la migración (`\d zones` lo lista como `gist`).

**Geometría**

- [x] Un `POST` con `area: [[14.6349,-90.5069],[14.6402,-90.4998],[14.6281,-90.4931]]` responde 201 y el `GET` posterior devuelve **esos mismos tres pares, en ese mismo orden, sin un cuarto punto**.
- [x] En base, `SELECT ST_AsText(area) FROM zones` muestra `POLYGON((-90.5069 14.6349, ...))` — longitud primero y con el punto de cierre repetido.
- [x] `SELECT ST_SRID(area) FROM zones` devuelve `4326`.
- [x] Un `POST` con `area` de 2 pares responde 422.
- [x] Un `POST` con el par `[98,-14]` responde 422 con el mensaje de latitud fuera de rango, no un 500.
- [x] Un `POST` con un par de 3 elementos responde 422.

**Punto en zona**

- [x] `GET /api/zones?lat=&lng=` con un punto **dentro** de una zona la devuelve en la lista.
- [x] Con un punto **fuera** de toda zona, devuelve una lista vacía y status 200 — no 404.
- [x] Con un punto dentro de dos zonas solapadas, devuelve las dos.
- [x] Enviando solo `lat` (o solo `lng`), el filtro se ignora y el listado sale completo, sin error.
- [x] `lat=200` se ignora igual que un `status` no booleano: listado completo, sin error.
- [x] `?lat=&lng=&status=true` combina ambos filtros.

**CRUD y permisos**

- [x] Un `administrator` crea, edita, alterna estado y da de baja; cualquier otro rol recibe 403 en esas cuatro acciones.
- [x] `carrier`, `pilot` y `manager` **sin empresa** pueden listar y ver el detalle: no hay `carrier.required`.
- [x] Sin token, cualquier ruta de zonas responde 401 en el sobre JSON del proyecto.
- [x] `POST` con `name: 'zona norte'` guarda y devuelve `ZONA NORTE`.
- [x] `POST` con `name: 'zona norte'` existiendo `ZONA NORTE` responde 422, nunca 500 del índice único. El corte lo da la regla `unique` del `StoreZoneRequest`; el `BadRequestError` (400) del service es la red de seguridad para quien lo llame directamente.
- [x] `POST` sin `color` devuelve `#3388FF`.
- [x] `POST` con `color: '#ff0000'` devuelve `#FF0000`.
- [x] `POST` con `color: 'rojo'` responde 422.
- [x] `DELETE` responde 200, deja `status: false` y **la fila sigue apareciendo** en `GET /api/zones` sin filtros.
- [x] Un segundo `DELETE` sobre la misma zona responde 200 otra vez, sin error.
- [x] `PATCH /{zone}/toggle-status` sobre una zona inactiva la deja activa, y viceversa.
- [x] `PATCH` que solo manda `name` no altera `area`, `color` ni `description`.
- [x] `PATCH` con body vacío responde 200 sin cambios.
- [x] `show`, `update`, `toggle-status` y `destroy` sobre un id inexistente responden 404.

**Listado y forma de la respuesta**

- [x] Sin `limit`, `GET /api/zones` devuelve la colección completa; con `limit=10`, el sobre trae `total`, `currentPage` y `lastPage` **en la raíz**, no bajo `meta`.
- [x] `limit=5` se acota a 10 y `limit=500` a 100.
- [x] `search=NOR` encuentra `ZONA NORTE`; `search=nor` también.
- [x] Todas las claves del `ZoneResource` están en camelCase y `createdAt` tiene la forma `07-08-2026 06:03:22 PM`.
- [x] Listar 20 zonas ejecuta un número de queries independiente del número de filas (sin N+1 sobre `registeredBy`).

**Cierre**

- [x] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [x] `php artisan route:list --path=zones` muestra `toggle-status` **antes** de la ruta `{zone}`.
- [x] `/api/documentation` muestra los seis endpoints con el schema de `area` y ejemplos reales.
- [x] Ningún archivo fuera de `ZoneService` y del modelo `Zone` menciona `ST_`, `WKT`, `GeogFromText` ni el orden `lng lat`.

---

## Decisiones tomadas y descartadas

### PostGIS nativo, y la suite entera a PostgreSQL

**Descartado:** guardar el polígono en una columna `jsonb` portable.

Era la opción sin coste de infraestructura: los tests seguían en SQLite y nada del proyecto cambiaba. Pero un array de coordenadas en JSON no es una geometría: no hay `ST_Contains`, así que el filtro punto-en-zona habría que calcularlo en PHP con ray-casting sobre **todas** las filas, sin índice posible. Se rechazó porque la consulta espacial es media feature, no un extra.

**Descartado:** el híbrido `jsonb` como fuente de verdad más una columna `geography` poblada solo en Postgres.

Habría conservado la suite en SQLite, pero deja dos representaciones del mismo dato que pueden desincronizarse, y las consultas espaciales solo funcionarían en producción — justo donde no hay tests que las cubran. Se rechazó por eso: un camino de código que la suite no puede ejecutar es un camino sin probar.

**Descartado:** columna `geography` real omitiendo (`skip`) los tests de zonas en SQLite.

Cero cambio de infraestructura a cambio de dejar la feature entera sin cobertura. Se rechazó por la regla del proyecto de que todo cambio va programáticamente probado.

**Consecuencia asumida:** clonar el repo ya no basta para correr los tests. Hace falta PostgreSQL con PostGIS. Es el precio de tener geometría de verdad, y se paga una sola vez.

### Pares `[lat, lng]` con el anillo abierto

**Descartado:** GeoJSON `Polygon` estándar.

Es lo que `ST_AsGeoJSON` devuelve tal cual y lo que consumen Leaflet, Mapbox y turf sin traducir. Se rechazó porque su orden es `[lng, lat]`, y en una API que se lee y se escribe a mano —Swagger, Postman, debugging— el orden invertido es la fuente de error más cara de esta spec: no falla, solo pone las zonas en otro sitio.

**Descartado:** anillo cerrado, con el primer punto repetido al final.

Es lo que PostGIS guarda literalmente, pero obliga a cada consumidor a conocer una regla de formato que no le aporta nada. El cierre es un detalle del motor; se queda en el service.

**Descartado:** WKT crudo (`'POLYGON((...))'`) como campo de la API.

Entra directo en PostGIS sin traducción, pero convierte un array estructurado en un string que el cliente tiene que componer y parsear a mano. Se rechazó sin discusión.

La asimetría queda contenida: **el cliente habla `[lat, lng]` abierto, PostGIS habla `lng lat` cerrado, y las dos funciones estáticas del modelo son la única frontera entre ambos.**

### Filtro `lat`+`lng` en el `index`, no un endpoint aparte

**Descartado:** `GET /api/zones/contains?lat=&lng=` como ruta fija.

Separaba las dos intenciones y era más explícito. Se rechazó porque devolvía exactamente la misma forma de respuesta que el listado y habría duplicado paginación, filtros y Resource. Como filtro compone con `status` y `search` gratis: «zonas activas que contienen este punto» es un solo query string, no un endpoint nuevo.

**Descartado:** devolver una sola zona, o 404 si ninguna la contiene.

Más cómodo de consumir, pero miente: como no se valida el solape, un punto puede caer en dos zonas, y la respuesta ocultaría la segunda. Devolver lista vacía en vez de 404 es coherente: preguntar qué zonas contienen un punto y que no haya ninguna no es un error.

### Validación mínima del polígono

**Descartado:** `ST_IsValid` para rechazar polígonos auto-intersectados, tope de vértices, y `ST_Intersects` para rechazar solapes.

Los tres se pidieron fuera. El de solape era además una decisión de negocio disfrazada de validación: rechazar zonas que se cruzan equivale a declarar que las zonas particionan el territorio, y no es el caso — de ahí que el filtro devuelva lista.

### `color` opcional con default `#3388FF`

**Descartado:** exigir el color en cada alta.

Habría obligado al front a elegir uno en el formulario de creación, cuando en la práctica el color es decoración y casi nunca se piensa. El default —el azul de Leaflet— hace que una zona recién creada ya se vea bien en el mapa sin decisión de nadie, y el `ZoneResource` puede prometer que nunca devuelve `null`.

### `area` fuera de `fillable`

No es un valor asignable: entra por `ST_GeogFromText` y sale por `ST_AsGeoJSON`. Dejarla en `fillable` habilitaría un `Zone::create(['area' => $request->area])` que inserta un array como texto. Se saca del `fillable` para que ese error no compile, no para que falle en runtime.

### El `area` viaja como binding, nunca interpolada

`DB::raw` con las coordenadas concatenadas habría sido más corto y habría evitado la transacción. Se rechazó: sería la única sentencia del proyecto construida por concatenación de entrada del usuario. Que los valores estén validados como numéricos no cambia la regla — la validación puede relajarse mañana, la inyección se queda.

### `UpdateZoneRequest` sin `required_without` cruzado

SPEC 07 lo usa para impedir el `PATCH` vacío, pero allí hay dos campos. Aquí hay cinco, y encadenarlos produciría cinco mensajes idénticos en un 422 confuso. Un `PATCH` vacío se acepta como no-op: no corrompe nada y el coste de prohibirlo es peor que el de permitirlo.

### `ST_Contains` sobre `area::geometry`

`ST_Contains` no acepta `geography`. El cast a `geometry` trata las coordenadas como plano cartesiano en vez de elipsoide; a la escala de estas zonas —decenas de kilómetros— la diferencia en un test de contención es nula, y el índice GiST sigue aplicando. La alternativa, `ST_Covers` sobre `geography`, es exacta pero tiene semántica distinta en los bordes. Se eligió el cast por ser el idioma más común y el más fácil de reconocer para quien lea el query.

---

## Riesgos identificados

### El Paso 1 rompe tests que hoy están en verde

`LIKE` en PostgreSQL distingue mayúsculas y en SQLite no; un `SELECT` sin `ORDER BY` no garantiza orden en Postgres; los booleanos dejan de llegar como `0`/`1`; y las secuencias de `id` no se comportan igual que el `AUTOINCREMENT` de SQLite tras un `RefreshDatabase`. Cualquiera de esas diferencias puede tumbar tests de SPEC 01 a 07 que nada tienen que ver con zonas.

**Mitigación:** el Paso 1 es un paso propio, completo y verificable antes de escribir una línea de la feature. Si algo revienta, se sabe con certeza que es el motor y no el código nuevo.

### `CREATE EXTENSION` exige superusuario

En el Postgres local funciona porque se conecta como `postgres`. En un servidor gestionado —RDS, Cloud SQL, Laravel Cloud— el usuario de la aplicación normalmente **no** puede crear extensiones, y `php artisan migrate` fallará en el primer despliegue.

**Mitigación:** la extensión debe habilitarse una vez, a mano y con un rol privilegiado, en cada entorno antes del primer deploy. El `IF NOT EXISTS` de la migración la hace inocua cuando ya está. Queda documentado como requisito de entorno, al lado del `upload_max_filesize` de SPEC 05.

### Invertir `lat` y `lng` no produce ningún error

Es el riesgo más caro de la spec. Un par al revés dentro del rango válido de ambos —`[14.6, -90.5]` frente a `[-90.5, 14.6]`— pasa la validación, se guarda, se lee y solo se nota cuando alguien mira un mapa y ve la zona en el océano Antártico. No hay excepción, no hay 500, no hay log.

**Mitigación:** las reglas `area.*.0` (rango de latitud) y `area.*.1` (rango de longitud) atrapan el caso frecuente, porque casi toda longitud real de Guatemala (`-90`) queda fuera del rango de latitud. El unit test de ida y vuelta de `pairsToWkt()` / `geoJsonToPairs()` del Paso 3 cubre el resto. **Ninguna de las dos protege del punto genuinamente ambiguo** (ambos valores en `[-90, 90]`); eso solo lo ve un humano frente a un mapa.

### Un polígono auto-intersectado se acepta

Sin `ST_IsValid`, una figura en "8" entra en base. PostGIS la guarda, pero `ST_Contains` sobre una geometría inválida devuelve resultados que no son ni verdaderos ni falsos de forma consistente, y el índice GiST puede saltárselos.

**Mitigación:** ninguna en esta spec — se pidió fuera. Queda anotado como la primera candidata a añadir si aparecen zonas con resultados de contención raros. La comprobación es una línea: `ST_IsValid` en el service, antes de persistir.

### Sin tope de vértices, el payload no tiene techo

Un trazado a mano alzada desde un mapa puede generar miles de puntos. Un `POST` con 50.000 vértices pasa la validación entera, construye un WKT de megabytes y engorda cada `GET` del listado, que devuelve el `area` completa de cada zona.

**Mitigación:** ninguna en esta spec. En la práctica, `post_max_size` de PHP corta antes de que sea un problema de base — pero el error que ve el usuario es confuso, el mismo síntoma descrito en SPEC 05.

### El listado devuelve la geometría completa de cada zona

`GET /api/zones` sin filtros emite todos los vértices de todas las zonas. Con pocas zonas simples es irrelevante; con muchas zonas detalladas, la respuesta crece rápido y el consumidor casi nunca necesita el polígono para pintar una tabla.

**Mitigación:** `limit` está disponible desde el día uno. Si llega a doler, la salida natural es `ST_Simplify` para el listado o un parámetro que omita el `area` — ninguna de las dos entra aquí.

### Un modelo cargado sin el `selectRaw` devuelve `area: []`

El `ZoneResource` no revienta si falta `area_geojson`, devuelve un array vacío. Eso evita un 500 en producción, pero convierte un error de programación en una respuesta silenciosamente incorrecta: una zona sin polígono, indistinguible de un bug de datos.

**Mitigación:** todos los caminos de lectura del service añaden el `selectRaw`, y el Feature test verifica que cada endpoint que devuelve una zona trae el `area` poblada. El `[]` defensivo es la última red, no la primera.
