# SPEC 20 — Puntos de partida

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 12, SPEC 15
> **Fecha:** 2026-08-25
> **Objetivo:** Publicar un catálogo nacional de puntos de partida anclados a un lugar de Google Places, con la misma forma que los destinos de SPEC 15 pero sin ninguna tarifa que cotizar.

Depende de **SPEC 01** por el guard JWT, el enum `UserRole` y el middleware `role:`, y de **SPEC 12** porque el front resuelve el lugar en `GET /api/places` antes de dar de alta el punto —la API sigue **sin llamar nunca a Google**—. De **SPEC 15** no depende en código: es su **molde**. `Location`, `locations`, `LocationService` y `freight_rates` **no se tocan en ninguna línea**.

Es el **primer dominio del proyecto que nace como copia declarada de otro**. La diferencia no está en lo que añade sino en lo que le falta: un destino existe para ser cotizado —`FreightRate` cuelga de él, exige que esté activo y lo usa como eje de precios—, mientras que un punto de partida no tiene tarifas, no participa en `/quote` y ninguna otra tabla apunta a él. Esa ausencia es la que borra del contrato el `getActiveDeparturePointById()` que en `Location` solo existe para servir a las tarifas.

**Lo que esta spec no es:** un dominio de rutas ni de trayectos. No hay origen-destino emparejado, no hay distancia, no hay kilometraje y no hay tarifa por par. Un punto de partida es un pin con nombre, y nada más lo consume todavía.

---

## Alcance

**Dentro:**

- **Tabla nueva `departure_points`** con `id`, `name`, `description`, `google_place_id`, `latitude`, `longitude`, `status`, `registered_by` y `timestamps`. **Ninguna tabla existente se toca**: ni `locations`, ni `freight_rates`, ni ninguna otra.
- **Ningún enum nuevo.** El estado es un booleano, igual que en `Location`, `Product` y `Zone`.
- **Tabla plana, sin PostGIS.** Las coordenadas son `decimal(10,8)` y `decimal(11,8)`, como en SPEC 15, y salen del Resource como **cadena**, no como float.
- **Dominio nacional:** no lleva `carrier_id`, no lleva `vehicle_id` y **ninguna ruta lleva `carrier.required`**.
- **Dominio `DeparturePoint` completo** en su subcarpeta, con la cadena de capas del proyecto: `DeparturePointServiceInterface`, `DeparturePointService`, `DeparturePointProvider`, `StoreDeparturePointRequest`, `UpdateDeparturePointRequest`, `DeparturePointResource` y `DeparturePointController`.
- **Seis rutas bajo `/api/departure-points`**: el `apiResource('/')->parameters(['' => 'departurePoint'])` con los cinco métodos, más `PATCH /{departurePoint}/toggle-status` declarada **antes** del resource para que el comodín no la capture.
- **Lectura (`index`, `show`) abierta a cualquier autenticado** (`jwt.auth` a secas); **`store`, `update`, `destroy` y `toggle-status` solo `role:administrator`**. Misma regla que SPEC 15.
- **Anclado a Google Places sin llamar a Google:** el front busca en `GET /api/places`, elige, y manda `name`, `googlePlaceId`, `latitude` y `longitude` ya resueltos. `DeparturePointService` **no conoce** `PlaceServiceInterface`.
- **`name` único y normalizado** con `DeparturePoint::normalizeName()` (trim + colapsar espacios + MAYÚSCULAS), compartido por los FormRequests y el service, que revalida con `ensureNameIsAvailable($name, $ignoreId)` aunque exista índice único.
- **`google_place_id` único**, guardado **tal cual llega** (opaco y sensible a mayúsculas), con la guarda `ensureGooglePlaceIdIsAvailable($googlePlaceId, $ignoreId)`.
- **Asimetría deliberada de los duplicados, replicada de SPEC 15:** `name` repetido es **422** (regla `unique` del FormRequest); `googlePlaceId` repetido es **400** desde el service, con el mensaje que **nombra al ocupante** — «El lugar seleccionado ya está registrado en el punto de partida {NOMBRE}».
- **Unicidad solo dentro de la tabla.** Un mismo lugar de Google puede ser a la vez punto de partida y destino: **no hay ninguna comprobación cruzada contra `locations`**, ni por `name` ni por `google_place_id`.
- **`status` no se acepta en el alta:** el punto nace `true`. `registered_by` sale del usuario autenticado, nunca del body, y **no se reescribe** en el `update`.
- **`PATCH` edita los seis campos** (`name`, `description`, `googlePlaceId`, `latitude`, `longitude`, `status`); `description` se borra mandando `null` (por eso `array_key_exists`, no `isset`). **Sin validación cruzada** entre `googlePlaceId` y las coordenadas: reapuntar solo el lugar es válido y deja el pin anterior.
- **`DELETE` es baja lógica idempotente** (`status = false`): la fila sigue viva y sigue apareciendo en el listado. `toggle-status` invierte el estado.
- **Listado** con filtros tolerantes `status` y `search` (`LIKE %term%` sobre el nombre ya en mayúsculas), orden fijo **`id ASC`** y paginación **opt-in por `limit`** acotada a `[10, 100]`.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.
- Resumen de integración para el frontend en `references/departure-points-api.md`.

**Fuera de alcance (para specs futuras):**

- **Tarifas.** No existe `FreightRate` por punto de partida, no hay par origen-destino, no hay precio por trayecto y `GET /api/freight-rates/quote` **no cambia de forma ni de parámetros**. Por eso el contrato **no incluye `getActiveDeparturePointById()`**: sin tarifas nadie necesita exigir que el punto esté activo.
- **Cualquier cambio en el dominio de destinos.** `Location`, `LocationResource`, `LocationService` y las rutas de `/api/locations` quedan byte a byte como los dejó SPEC 15, y `locations` **no gana** ninguna columna `type` ni relación con esta tabla.
- **Fusionar los dos catálogos** en uno solo con discriminador, ahora o después. Si algún día hacen falta rutas origen-destino, esa spec decidirá; esta no lo prepara.
- **Distancias, kilometraje, tiempo estimado o cálculo de ruta** entre un punto de partida y un destino. La API no llama a Directions ni a ninguna otra API de Google.
- **Validación cruzada contra `locations`**: un mismo `googlePlaceId` en las dos tablas es válido y no se avisa.
- **Comprobar contra Google** que las coordenadas correspondan al `googlePlaceId`. Se puede dar de alta el pin de un sitio con el place id de otro y responde 201 sin aviso, exactamente como en SPEC 15.
- **Filtro por cercanía** (`?lat=&lng=`), radio, geometría o PostGIS de cualquier clase. Esta tabla no tiene columnas espaciales.
- **Vincular un punto de partida a una empresa, a un vehículo o a un gasto.** Ninguna tabla existente gana una FK a `departure_points`.
- **Horarios de carga, código de bodega, contacto o responsable** como columnas. Lo que haga falta se teclea en `description`.
- **Borrado físico, `SoftDeletes` y bitácora de cambios.** Editar pisa el valor anterior sin dejar rastro.
- **Alta en lote** e importación masiva desde un archivo.
- **Archivos adjuntos** (foto del portón, croquis). Este dominio no sube nada al bucket.

---

## Modelo de datos

Esta spec **no toca ninguna tabla existente**. Crea una tabla, un modelo y una factory, y **ningún enum**.

### 1. Tabla `departure_points`

```php
Schema::create('departure_points', function (Blueprint $table) {
    $table->id();
    /** Siempre en mayúsculas, así que el índice único no depende del collation. */
    $table->string('name')->unique();
    $table->text('description')->nullable();
    /** Identificador opaco de Google, guardado tal cual llega: es sensible a mayúsculas. */
    $table->string('google_place_id')->unique();
    /**
     * Ocho decimales dan precisión de poco más de un milímetro, y la parte entera
     * justa para ±90 y ±180. Nada de flotantes en una coordenada que apunta a un portón.
     */
    $table->decimal('latitude', 10, 8);
    $table->decimal('longitude', 11, 8);
    $table->boolean('status')->default(true);
    /** Sin cascade: borrar al usuario que dio de alta el punto debe frenarse. */
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();
});
```

- **Calcada de `locations`**, con dos precisiones sobre por qué es otra tabla y no la misma:
  - Los índices únicos de `name` y `google_place_id` son **por tabla**: el mismo lugar puede estar dado de alta como destino y como punto de partida sin que ninguna base lo impida. Es la decisión de la sección anterior, y aquí es donde se materializa.
  - `freight_rates.location_id` sigue apuntando **solo** a `locations`. Ninguna FK nueva sale de esta tabla ni entra en ella salvo `registered_by`.
- `description` es `text` y **el único campo nullable**; no se indexa y **no participa** en el filtro `search`.
- `status` tiene `default(true)` en base, pero eso es red de seguridad: por la API el alta **nunca** acepta `status` y siempre nace activo.
- **Ningún índice extra.** El listado ordena por `id` y filtra por `status` (booleano de dos valores, sin selectividad) y por `name` con `LIKE %term%`, que no usaría un índice B-tree de todos modos.

### 2. Modelo `DeparturePoint`

```php
#[Fillable(['name', 'description', 'google_place_id', 'latitude', 'longitude', 'status', 'registered_by'])]
class DeparturePoint extends Model
{
    /** @use HasFactory<DeparturePointFactory> */
    use HasFactory;

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }

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

- **Todas las columnas son fillable**: como en `Location` y a diferencia de `Zone`, aquí no hay geometría que tenga que entrar como expresión SQL.
- `normalizeName()` es **propio del modelo**, no importado de `Location`: los dos dominios comparten la regla pero no el acoplamiento, exactamente como ya la repiten `Product`, `Zone` y `Location` entre sí.
- Los casts `decimal:8` son los que hacen que las coordenadas salgan como **cadena** en el JSON.
- **`DeparturePoint` no tiene ninguna relación más.** Nada cuelga de él.

### 3. Salida — `DeparturePointResource`

Diez claves, en camelCase, idénticas en nombre y formato a `LocationResource`:

```json
{
  "id": 1,
  "name": "BODEGA CENTRAL ESCUINTLA",
  "description": "Entrada por el km 58, portón de carga 2",
  "googlePlaceId": "ChIJd8BlQ2BZwokRAFUEcm_qrcA",
  "latitude": "14.63490000",
  "longitude": "-90.50690000",
  "status": true,
  "registeredByName": "Roberto Santizo",
  "createdAt": "25-08-2026 09:14:03 AM",
  "updatedAt": "25-08-2026 09:14:03 AM"
}
```

- `latitude` y `longitude` viajan como **cadena** con ocho decimales, no como número: es el efecto del cast `decimal:8` y es contrato, no accidente.
- Sale `registeredByName`, no el `registered_by` crudo ni el objeto usuario. El service carga la relación con `with('registeredBy')` para que el listado no haga N+1.
- Fechas en `d-m-Y h:i:s A`, el formato del resto de catálogos.

### 4. Factory

`DeparturePointFactory` apoyada en `User::factory()` para `registered_by`, con `name` ya normalizado, `google_place_id` único generado por faker, coordenadas dentro de rango y `status` en `true`. La necesitan los tests del agente `feature-tests`.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo.

1. **Migración, modelo y factory.** `php artisan make:model DeparturePoint -mf`. Rellenar la migración con las nueve columnas y los dos únicos (`name`, `google_place_id`); el modelo con su `#[Fillable]`, `normalizeName()`, la relación `registeredBy()` y los tres casts; la factory apoyada en `User::factory()`, con nombres ya normalizados y coordenadas en rango. Correr `php artisan migrate`. Verificación: `php artisan tinker --execute 'DeparturePoint::factory()->create();'` no revienta y `locations` sigue con las mismas columnas.

2. **Contrato.** `app/Interfaces/DeparturePoint/DeparturePointServiceInterface.php` con **seis** métodos, PHPDoc de array shapes y `@throws`, calcado de `LocationServiceInterface` **menos** `getActiveDeparturePointById()`:

   ```php
   public function getDeparturePoints(array $filters): LengthAwarePaginator|Collection;
   public function getDeparturePointById(int $id): DeparturePoint;
   public function create(User $user, array $data): DeparturePoint;
   public function update(int $id, array $data): DeparturePoint;
   public function toggleStatus(int $id): DeparturePoint;
   public function destroy(int $id): DeparturePoint;
   ```

3. **Service, parte de lectura.** `app/Services/DeparturePoint/DeparturePointService.php` con `resolvePerPage()` (constantes `MIN_PER_PAGE` / `MAX_PER_PAGE`), `getDeparturePoints()` —`with('registeredBy')`, filtro `status` tolerante con `filter_var(..., FILTER_NULL_ON_FAILURE)`, filtro `search` normalizado con `normalizeName()` y aplicado solo si no queda vacío, orden `id ASC`, paginación opt-in— y `getDeparturePointById()`, que lanza `NotFoundError('El punto de partida no existe')`. `#[Override]` en cada método.

4. **Service, parte de escritura.** `create()`, `update()`, `toggleStatus()` y `destroy()`, más las dos guardas privadas `ensureNameIsAvailable($name, $ignoreId = null)` y `ensureGooglePlaceIdIsAvailable($googlePlaceId, $ignoreId = null)`, ambas lanzando `BadRequestError`; la segunda consulta la fila ocupante para **nombrarla** en el mensaje. El alta normaliza el `name`, deja el `googlePlaceId` intacto, fuerza `status = true` y toma `registered_by` de `$user->id`; el `update` usa `array_key_exists` para `description` y `isset` para el resto, y **no reescribe** `registered_by`; `destroy()` es baja lógica idempotente. Todo devuelve el modelo con `load('registeredBy')` donde la respuesta lo necesita.

5. **Provider.** `app/Providers/DeparturePoint/DeparturePointProvider.php` con el `bind(DeparturePointServiceInterface::class, DeparturePointService::class)`, registrado en `bootstrap/providers.php`.

6. **FormRequests**, los dos con `messages()` en español y `prepareForValidation()` que normaliza **solo** el `name`:
   - `StoreDeparturePointRequest` — `name` `required|string|max:255|unique:departure_points,name`, `description` `nullable|string`, `googlePlaceId` `required|string|max:255` **sin regla `unique`** (el 400 lo levanta el service), `latitude` `required|numeric|between:-90,90`, `longitude` `required|numeric|between:-180,180`. **`status` y `registeredBy` no aparecen**: mandarlos se descarta sin error.
   - `UpdateDeparturePointRequest` — los mismos cinco como `sometimes`, con el `unique` del `name` ignorando la propia fila, más `status` `sometimes|boolean`.

7. **Resource, controller y rutas.** `DeparturePointResource` con las diez claves de la sección anterior; `DeparturePointController` con los seis métodos, `try/catch` → `ResponseHandler` y el service inyectado **por parámetro de método**; `routes/departure_points.php` con prefijo `departure-points`, `jwt.auth` en el grupo, el `PATCH /{departurePoint}/toggle-status` **antes** del `apiResource('/')->parameters(['' => 'departurePoint'])` y `role:administrator` en `store`, `update`, `destroy` y `toggle-status` vía `middlewareFor`; `require` en `routes/api.php`. Verificación: `php artisan route:list --path=departure-points` muestra las seis rutas.

8. **Tests.** Disparar el agente `feature-tests` sobre `DeparturePoint`: Feature test de los seis endpoints y los cuatro roles (lectura para los cuatro, escritura solo `administrator`), la normalización del `name`, la asimetría 422/400 de los dos duplicados, que el mismo `googlePlaceId` de un `Location` existente **se acepta**, que el alta ignora `status` y nace activo, el borrado de `description` con `null`, la baja lógica idempotente, el `toggle-status`, los filtros tolerantes y la paginación acotada a `[10, 100]`; Unit test del service. Correr `php artisan test --compact --filter=DeparturePoint`.

9. **Regresión de SPEC 15.** `php artisan test --compact --filter='Location|FreightRate'` en verde sin haber tocado esos archivos — es la prueba de que el dominio nuevo no se coló en el viejo.

10. **Documentación.** Disparar el agente `endpoint-docs` sobre `DeparturePoint` y regenerar `storage/api-docs/api-docs.json`.

11. **Cierre.** `vendor/bin/pint --dirty --format agent` y escribir `references/departure-points-api.md` para el frontend, con `references/zones-api.md` como plantilla.

---

## Criterios de aceptación

**Estructura**

- [ ] Existe la tabla `departure_points` con `id`, `name`, `description`, `google_place_id`, `latitude`, `longitude`, `status`, `registered_by` y `timestamps`; únicos en `name` y `google_place_id`; `description` es la única columna nullable.
- [ ] `locations` y `freight_rates` tienen exactamente las mismas columnas e índices que dejó SPEC 15, y ninguna FK apunta a `departure_points`.
- [ ] `php artisan route:list --path=departure-points` muestra exactamente **seis** rutas, todas bajo `/api/departure-points`.
- [ ] Ninguna ruta del dominio lleva `carrier.required`.
- [ ] `DeparturePointServiceInterface` tiene **seis** métodos y **no** declara `getActiveDeparturePointById()`.
- [ ] Ningún archivo de `app/Services/DeparturePoint/` menciona `Location`, `PlaceServiceInterface` ni `Http::`.

**Roles**

- [ ] `administrator`, `carrier`, `manager` y `pilot` autenticados obtienen **200** en `GET /api/departure-points` y en `GET /api/departure-points/{id}`.
- [ ] `carrier`, `manager` y `pilot` obtienen **403** en `POST`, `PATCH`, `DELETE` y `PATCH /{id}/toggle-status`.
- [ ] Sin token, las seis rutas responden **401** con el sobre habitual.

**Listado**

- [ ] Devuelve los puntos ordenados por `id` ascendente.
- [ ] `?status=false` devuelve solo los inactivos; `?status=cualquiercosa` **se ignora** y devuelve todos, no lista vacía.
- [ ] `?search=bodega` encuentra `BODEGA CENTRAL` (insensible a mayúsculas por normalización del término); `?search=` o solo espacios no filtra nada.
- [ ] Sin `limit` devuelve la colección completa; con `limit=5` pagina y el sobre trae `total`, `currentPage` y `lastPage` **en la raíz**, no bajo `meta`; `limit=1` se acota a 10 y `limit=500` a 100; `limit=abc` no pagina.
- [ ] Un catálogo vacío responde **200** con `data` vacío.

**Alta**

- [ ] `POST` con `name`, `googlePlaceId`, `latitude` y `longitude` válidos responde **201**, con `registered_by` igual al usuario autenticado y `status` en `true`.
- [ ] `name` se guarda y devuelve en MAYÚSCULAS con espacios internos colapsados: `"  bodega   central "` → `"BODEGA CENTRAL"`.
- [ ] Enviar `status: false` en el alta **se ignora**: el punto nace activo y la respuesta es 201, no 422.
- [ ] Enviar `registeredBy` en el body no cambia el autor.
- [ ] `name` duplicado (comparado ya normalizado) responde **422** con «Ya existe un punto de partida con ese nombre».
- [ ] `googlePlaceId` duplicado responde **400** con «El lugar seleccionado ya está registrado en el punto de partida {NOMBRE}», nombrando al ocupante.
- [ ] Un `googlePlaceId` que ya existe en `locations` responde **201**: no hay validación cruzada entre las dos tablas. Lo mismo con un `name` que ya existe como destino.
- [ ] `latitude` fuera de `[-90, 90]` o `longitude` fuera de `[-180, 180]` responde **422**; `description` ausente guarda `null`.
- [ ] El `googlePlaceId` se guarda **tal cual**: dos cadenas que solo difieren en mayúsculas se aceptan como dos lugares distintos.

**Detalle y edición**

- [ ] `GET /api/departure-points/{id}` inexistente responde **404** con «El punto de partida no existe».
- [ ] Un punto `inactive` se consulta con **200**: la baja lógica no lo oculta.
- [ ] `PATCH` con cuerpo vacío responde **200** sin cambiar nada.
- [ ] `PATCH` acepta los seis campos; reenviar el propio `name` o el propio `googlePlaceId` **no** choca consigo mismo.
- [ ] `PATCH` con `description: null` **borra** la descripción; omitirla la deja intacta.
- [ ] `PATCH` que cambia solo el `googlePlaceId` responde **200** y **no** valida que las coordenadas correspondan.
- [ ] `PATCH` **no** reescribe `registered_by`, aunque lo mande en el body.

**Baja**

- [ ] `DELETE` responde **200**, deja `status` en `false` y la fila **sigue apareciendo** en el listado.
- [ ] Un segundo `DELETE` sobre el mismo punto responde **200** y lo deja igual (idempotente).
- [ ] `PATCH /{id}/toggle-status` invierte el estado en ambas direcciones y responde **200**.

**Salida**

- [ ] `DeparturePointResource` devuelve exactamente las diez claves acordadas, en camelCase.
- [ ] `latitude` y `longitude` salen como **cadena** con ocho decimales.
- [ ] `registeredByName` trae el nombre del usuario y el listado **no hace N+1** (`with('registeredBy')`).
- [ ] `createdAt` y `updatedAt` salen en `d-m-Y h:i:s A`.

**Regresión y cierre**

- [ ] `php artisan test --compact --filter='Location|FreightRate'` pasa sin que ningún archivo de esos dominios haya cambiado.
- [ ] `GET /api/freight-rates/quote` acepta los mismos parámetros y devuelve la misma forma que antes de esta spec.
- [ ] `php artisan test --compact` pasa entera.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `storage/api-docs/api-docs.json` incluye las seis rutas y existe `references/departure-points-api.md`.

---

## Decisiones tomadas y descartadas

**1. Tabla nueva `departure_points`, no una columna `type` en `locations`.**
Se descartó la tabla única con discriminador. Habría ahorrado un CRUD, pero obligaba a filtrar por tipo en el listado de destinos, en `/quote` y en la FK `freight_rates.location_id`: un cambio incompatible sobre un dominio publicado, con el riesgo permanente de que un punto de partida se cuele como destino cotizable por un filtro olvidado. Dos tablas hacen ese error imposible por construcción.

**2. Sin ninguna validación cruzada contra `locations`.**
Se valoró exigir que un lugar de Google fuera destino **o** punto de partida, nunca ambos. Se descartó: una bodega de la que se sale y a la que también se entrega es un caso real, y la comprobación acoplaría para siempre dos dominios que esta spec quiere separados. La unicidad vive **por tabla**.

**3. `getActiveDeparturePointById()` fuera del contrato.**
En `Location` ese método existe únicamente porque `FreightRate` exige destino activo. Aquí no hay tarifas, así que no tendría consumidor. Se aplica el precedente de SPEC 15, que borró `getZoneContainingPoint()` al quedarse sin quien lo llamara: un método sin llamador es deuda, no previsión. Si algún día un dominio necesita exigir el punto activo, esa spec lo añade.

**4. Asimetría 422 / 400 replicada tal cual.**
Podría haberse unificado todo a 422 poniendo `unique` también sobre `googlePlaceId`. Se descartó porque cortaría antes de que el service pudiera **nombrar** el punto que ya ocupa ese lugar, que es justo lo que hace útil el error. La columna sí lleva índice único, pero como último cortafuegos, no como vía de respuesta.

**5. `normalizeName()` duplicado en el modelo, no extraído a un trait compartido.**
Se descartó factorizarlo con `Location`, `Product` y `Zone`. El proyecto ya lo repite en cuatro modelos a propósito: el trait acoplaría dominios independientes para ahorrar tres líneas, y cambiar la regla en un catálogo pasaría a cambiarla en todos sin querer.

**6. `DELETE` como baja lógica idempotente, no borrado físico.**
Un punto de partida se deja de usar, no se teclea mal como un gasto de SPEC 14. Conservar la fila mantiene legibles los registros históricos que algún día lo referencien y permite reactivarlo con `toggle-status`.

**7. Sin `SoftDeletes` y sin bitácora.**
El único dominio con `SoftDeletes` es `FreightRate`, y lo tiene porque necesita distinguir «tarifa borrada» de «tarifa inexistente» al recotizar. Aquí el `status` booleano cubre el caso entero.

**8. Anclado a Google Places sin que la API llame a Google.**
Misma decisión de SPEC 15, repetida por escrito para que no se reabra: el front resuelve el lugar en `GET /api/places` y manda `googlePlaceId` y coordenadas ya resueltos. Cada llamada a Google se paga, y hacerla dos veces —al buscar y al guardar— no aporta nada.

**9. Sin validación cruzada entre `googlePlaceId` y las coordenadas.**
Riesgo asumido, idéntico al de SPEC 15: se puede guardar el pin de un sitio con el place id de otro. Comprobarlo exigiría una llamada de pago en cada alta y en cada edición para atrapar un error de captura poco frecuente.

**10. Sin índices más allá de los dos únicos.**
El filtro `status` es un booleano de dos valores sin selectividad y el `search` es un `LIKE %term%`, que no aprovecharía un B-tree. Un índice aquí solo encarecería las escrituras.

---

## Riesgos identificados

**1. Dos catálogos casi idénticos que se irán separando por deriva.**
`DeparturePoint` nace como copia literal de `Location`. En cuanto uno de los dos reciba una corrección —un mensaje, un filtro, un ajuste de `normalizeName()`— el otro no la recibirá salvo que alguien se acuerde. Es el precio aceptado en las decisiones 1 y 5. *Mitigación:* esta spec deja escrito que son copias declaradas; cualquier arreglo que aplique a los dos debe hacerse en los dos y decirlo en el commit.

**2. Confusión de dominio en el front: pedir puntos de partida a `/api/locations`.**
Los dos endpoints devuelven la misma forma con las mismas diez claves, así que una llamada al catálogo equivocado **no falla**: devuelve datos plausibles del otro dominio. Es el fallo más probable de esta spec y el más silencioso. *Mitigación:* `references/departure-points-api.md` debe abrir diciendo qué endpoint es cuál y que los `id` de las dos tablas **no son intercambiables**.

**3. Ids solapados entre las dos tablas.**
`departure_points.id = 3` y `locations.id = 3` son filas distintas sin ninguna relación. Si algún día se guarda un id de punto de partida donde se espera un destino, no habrá error de FK que lo detenga: apuntará a otra fila real. *Mitigación:* ninguna tabla gana FK a `departure_points` en esta spec, así que hoy el riesgo es solo del cliente; la spec que empareje origen y destino tendrá que nombrar las columnas sin ambigüedad (`departure_point_id`, no `origin_id`).

**4. Presión para fusionar los dos catálogos en la spec siguiente.**
La primera vez que se pidan rutas origen-destino, la tentación será unificar las tablas y migrar. Esta spec **no lo prepara** deliberadamente, y esa migración tendría que mover `freight_rates` con ella. *Mitigación:* está declarado fuera de alcance; la decisión se toma entonces, con el caso de uso real delante.

**5. Datos sin dueño mientras nadie los consuma.**
Hasta que exista un consumidor, el catálogo se llena sin que ningún flujo valide que los puntos son correctos: un pin mal puesto o un lugar duplicado en mayúsculas distintas no lo detecta nadie. *Mitigación:* asumido; el catálogo es de administradores y la corrección es un `PATCH`.
