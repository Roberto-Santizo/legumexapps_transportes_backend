# SPEC 22 — Catálogo de clientes

> **Estado:** Implementado
> **Depende de:** SPEC 01
> **Fecha:** 2026-08-26
> **Objetivo:** Publicar un catálogo nacional de clientes con solo dos campos —`code` único y `name` único—, escritura exclusiva del administrador y borrado con `SoftDeletes` sin vuelta atrás.

Depende de **SPEC 01** y de nada más: el guard JWT, el enum `UserRole` y el middleware `role:`. No toca ninguna tabla existente, no añade FK a ninguna parte y ninguna tabla gana `client_id`.

Es el catálogo **más pequeño del proyecto** —dos columnas de negocio— y a la vez el primero que se aparta del patrón de baja de los demás. `Product`, `Location`, `Zone` y `DeparturePoint` se dan de baja con un `status` booleano y siguen apareciendo en el listado; aquí el `DELETE` es un `SoftDeletes` real: la fila desaparece de la API y no vuelve. El único precedente de `SoftDeletes` en el proyecto es `FreightRate`, y de ahí se copian las dos reglas que lo hacen usable: el service resuelve con `withTrashed()` para distinguir «ya eliminado» de «no existe», y el segundo `DELETE` responde **400**, no 404 ni 200.

**Lo que esta spec no es:** una ficha de cliente. No hay NIT, ni dirección, ni contacto, ni teléfono, ni crédito, ni tarifas por cliente. Un cliente es un código y un nombre, y hoy nada cuelga de él.

---

## Alcance

**Dentro:**

- **Tabla nueva `clients`** con `id`, `code`, `name`, `registered_by`, `timestamps` y `deleted_at`. **Ninguna tabla existente se toca** y ninguna gana una FK a `clients`.
- **Ningún enum nuevo.** El dominio no tiene estados: un cliente existe o está borrado.
- **Dominio nacional:** no lleva `carrier_id`, no lleva `vehicle_id` y **ninguna ruta lleva `carrier.required`**.
- **Dominio `Client` completo** en su subcarpeta, con la cadena de capas del proyecto: `ClientServiceInterface`, `ClientService`, `ClientProvider`, `StoreClientRequest`, `UpdateClientRequest`, `ClientResource` y `ClientController`.
- **Cinco rutas bajo `/api/clients`**: el `apiResource('/')->parameters(['' => 'client'])` con `index`, `store`, `show`, `update` y `destroy`. **No hay `/toggle-status` ni `/restore`**, así que esta es la primera ruta de catálogo del proyecto sin ninguna ruta fija antes del resource.
- **Lectura (`index`, `show`) abierta a cualquier autenticado** (`jwt.auth` a secas); **`store`, `update` y `destroy` solo `role:administrator`**.
- **`SoftDeletes`**, segundo dominio del proyecto que lo usa tras `FreightRate` y el primero de un catálogo.
- **`code` único y global**, tecleado por el administrador, máximo **15** caracteres, normalizado con `Client::normalizeCode()` (trim + MAYÚSCULAS) y **sin ningún espacio**: un `code` con espacio interior es **422**, no otro código.
- **`name` único y global**, máximo 255, normalizado con `Client::normalizeName()` (trim + colapsar espacios interiores + MAYÚSCULAS), como en `Product` y `Location`.
- **La unicidad de ambos es global y un borrado no la libera:** la fila con `deleted_at` sigue ocupando su `code` y su `name`. Precedente: el `code` de `Accessory`.
- **Los dos duplicados responden 400 desde el service**, con mensaje en español, siguiendo el bando de `Accessory` y no la asimetría 422/400 de `Location`. Los índices únicos existen, pero como último cortafuegos, no como vía de respuesta.
- **`registered_by`** sale siempre del usuario autenticado, nunca del body, y **no se reescribe** en el `update`.
- **`PATCH` edita los dos campos** (`code`, `name`), ambos `sometimes`. Cuerpo vacío responde 200 sin cambiar nada, como el resto de los `PATCH` del proyecto.
- **Los borrados no se ven nunca por API**: el listado los excluye, `GET /{id}` de un borrado es **404**, y `PATCH` o `DELETE` sobre uno es **400 «El cliente ya fue eliminado»** —el service los resuelve con `withTrashed()` justo para poder distinguirlos de un id inexistente—.
- **Listado** con filtro tolerante `search` (`LIKE %term%` sobre `code` **y** `name`, ya en mayúsculas), orden fijo **`id ASC`** y paginación **opt-in por `limit`** acotada a `[10, 100]`.
- **`ClientResource` con siete claves**, incluida `deletedAt` (`null` en `index`, `show`, `store` y `update`; con la fecha del borrado en la respuesta del `DELETE`).
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.
- Resumen de integración para el frontend en `references/clients-api.md`.

**Fuera de alcance (para specs futuras):**

- **Restaurar un cliente borrado.** No hay `PATCH /{client}/restore`, no hay `?trashed=true` y el contrato no tiene ningún método que lea `withTrashed()` hacia fuera. Un borrado erróneo se arregla **fuera de la aplicación**, tocando la base: es la consecuencia aceptada de combinar `SoftDeletes` con unicidad global, y está decidido a propósito.
- **Cualquier campo más allá de `code` y `name`.** Nada de NIT, dirección, teléfono, correo, contacto, límite de crédito, condiciones de pago ni notas. Si hacen falta, es otra spec y es una migración aditiva sobre esta tabla.
- **Relacionar el cliente con cualquier cosa.** Ninguna tabla gana `client_id`: ni `freight_rates`, ni `locations`, ni `vehicle_expenses`, ni ninguna otra. No hay tarifas por cliente, no hay destinos por cliente y `GET /api/freight-rates/quote` **no cambia de forma ni de parámetros**.
- **`status` booleano y `/toggle-status`.** Un cliente no se «desactiva»: se borra o no. Si algún día hace falta pausar un cliente sin borrarlo, esa spec añade la columna.
- **Ámbito por empresa.** El catálogo es nacional: un `carrier` ve exactamente los mismos clientes que el `administrator`. Ninguna ruta lleva `carrier.required`.
- **Generar el `code` automáticamente**, como hace `Carrier` con su código de 6 caracteres. Aquí lo teclea el administrador porque el cliente ya trae su código de fuera.
- **Bitácora de cambios.** Editar el `code` o el `name` pisa el valor anterior sin dejar rastro; no hay tabla de historial ni `changed_by`.
- **Alta en lote**, importación desde archivo o sincronización con un ERP.
- **Archivos adjuntos.** Este dominio no sube nada al bucket.
- **Búsqueda por otros criterios**: no hay filtro por fecha de alta, por autor ni por rango de `id`.

---

## Modelo de datos

Esta spec **no toca ninguna tabla existente**. Crea una tabla, un modelo y una factory, y **ningún enum**.

### 1. Tabla `clients`

```php
Schema::create('clients', function (Blueprint $table) {
    $table->id();
    /**
     * Tecleado por el administrador y siempre en mayúsculas, así que el índice único
     * no depende del collation. Nunca contiene espacios: el FormRequest los rechaza.
     */
    $table->string('code', 15)->unique();
    /** Siempre en mayúsculas y con los espacios interiores ya colapsados. */
    $table->string('name')->unique();
    /** Sin cascade: borrar al usuario que dio de alta el cliente debe frenarse. */
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();
    $table->softDeletes();
});
```

- **Los dos índices únicos conviven con `SoftDeletes` a propósito.** Una fila con `deleted_at` sigue ocupando su entrada en el índice, que es exactamente la unicidad global que se quiere: un cliente borrado **no libera** su `code` ni su `name`. Es la diferencia con `FreightRate`, que deliberadamente **no** lleva índice único porque allí sí hace falta poder reutilizar un `fuel_min` ya borrado.
- `code` es `string(15)`, no `string(255)`: el límite vive en la base **y** en el FormRequest.
- **Ninguna columna es nullable** salvo `deleted_at`. No hay `description`, no hay `status`.
- **Ningún índice extra.** El listado ordena por `id` y filtra con `LIKE %term%`, que no aprovecharía un B-tree.

### 2. Modelo `Client`

```php
#[Fillable(['code', 'name', 'registered_by'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, SoftDeletes;

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /** Trim + colapsar espacios interiores + MAYÚSCULAS. */
    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }

    /** Trim + MAYÚSCULAS. No colapsa nada: el código válido no tiene espacios. */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }
}
```

- **Segundo modelo del proyecto sin `casts()`**, tras `AccessoryCharacteristic`: aquí todo es texto y no hay ningún booleano, enum ni decimal que declarar. `deleted_at` lo castea el propio trait `SoftDeletes`.
- Las dos normalizaciones son **propias del modelo**, no importadas de `Product` ni de `Accessory`: el proyecto ya las repite a propósito en cinco modelos.
- `normalizeCode()` **no colapsa** espacios interiores porque no tiene que hacerlo: un `code` con espacios ni siquiera llega al service, lo corta el FormRequest con 422.
- **`Client` no tiene ninguna relación más.** Nada cuelga de él.

### 3. Salida — `ClientResource`

Siete claves, en camelCase:

```json
{
  "id": 1,
  "code": "CLI-001",
  "name": "AGROEXPORTADORA DEL SUR S.A.",
  "registeredByName": "Roberto Santizo",
  "createdAt": "26-08-2026 09:14:03 AM",
  "updatedAt": "26-08-2026 09:14:03 AM",
  "deletedAt": null
}
```

- `deletedAt` es `null` en **cuatro de los cinco endpoints** —`index`, `show`, `store` y `update`—, porque ninguno de ellos alcanza a un cliente borrado. La excepción es **la respuesta del propio `DELETE`**, que pinta la fila recién borrada y por tanto trae la marca de tiempo del borrado, en el mismo formato `d-m-Y h:i:s A` que las otras dos fechas. Es el único camino por el que un cliente ve esta clave con valor, y documenta hacia el front que este catálogo borra de verdad.
- Sale `registeredByName`, no el `registered_by` crudo ni el objeto usuario. El service carga la relación con `with('registeredBy')` para que el listado no haga N+1.

### 4. Factory

`ClientFactory` apoyada en `User::factory()` para `registered_by`, con `code` único de faker ya en mayúsculas y **sin espacios**, y `name` único ya normalizado. La necesitan los tests del agente `feature-tests`.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo.

1. **Migración, modelo y factory.** `php artisan make:model Client -mf`. Rellenar la migración con las seis columnas, los dos índices únicos y `softDeletes()`; el modelo con su `#[Fillable]`, el trait `SoftDeletes`, `normalizeName()`, `normalizeCode()` y la relación `registeredBy()`, **sin `casts()`**; la factory apoyada en `User::factory()`, con `code` único en mayúsculas y sin espacios y `name` ya normalizado. Correr `php artisan migrate`. Verificación: `php artisan tinker --execute 'Client::factory()->create();'` no revienta y ninguna tabla existente cambió de columnas.

2. **Contrato.** `app/Interfaces/Client/ClientServiceInterface.php` con **cinco** métodos, PHPDoc de array shapes y `@throws`:

   ```php
   public function getClients(array $filters): LengthAwarePaginator|Collection;
   public function getClientById(int $id): Client;
   public function create(User $user, array $data): Client;
   public function update(int $id, array $data): Client;
   public function destroy(int $id): Client;
   ```

   **No hay `toggleStatus()` ni `restore()`**, y ningún método del contrato expone filas borradas: `withTrashed()` es un detalle interno del service.

3. **Service, parte de lectura.** `app/Services/Client/ClientService.php` con `resolvePerPage()` (constantes `MIN_PER_PAGE` / `MAX_PER_PAGE`), `getClients()` —`with('registeredBy')`, filtro `search` tolerante aplicado sobre `code` **y** `name` con `LIKE %term%` tras normalizar el término, ignorado si queda vacío, orden `id ASC`, paginación opt-in— y `getClientById()`, que consulta **sin** `withTrashed()` y lanza `NotFoundError('El cliente no existe')`, de modo que un borrado es 404 para el lector. `#[Override]` en cada método.

4. **Service, parte de escritura.** `create()`, `update()` y `destroy()`, más tres privadas:
   - `resolveWritableClient(int $id): Client` — consulta **con `withTrashed()`**; lanza `NotFoundError('El cliente no existe')` si no hay fila y `BadRequestError('El cliente ya fue eliminado')` si la fila tiene `deleted_at`. Es la guarda común de `update()` y `destroy()`, y el único sitio del dominio que mira las filas borradas.
   - `ensureCodeIsAvailable(string $code, ?int $ignoreId = null): void` — consulta **con `withTrashed()`** y lanza `BadRequestError` en español si otro cliente, borrado o no, ya ocupa ese código.
   - `ensureNameIsAvailable(string $name, ?int $ignoreId = null): void` — igual, para el nombre.

   El alta normaliza los dos campos y toma `registered_by` de `$user->id`; el `update` usa `isset` para los dos, normaliza lo que llegue y **no reescribe** `registered_by`; `destroy()` llama a `delete()` sobre el modelo ya resuelto. Todo devuelve el modelo con `load('registeredBy')`.

5. **Provider.** `app/Providers/Client/ClientProvider.php` con el `bind(ClientServiceInterface::class, ClientService::class)`, registrado en `bootstrap/providers.php`.

6. **FormRequests**, los dos con `messages()` en español y `prepareForValidation()` que normaliza `code` con `normalizeCode()` y `name` con `normalizeName()` **antes** de validar:
   - `StoreClientRequest` — `code` `required|string|max:15|regex:/^\S+$/u`, `name` `required|string|max:255`. **Ninguno lleva regla `unique`**: los dos duplicados los levanta el service con 400. **`registeredBy` no aparece**: mandarlo se descarta sin error.
   - `UpdateClientRequest` — los dos mismos como `sometimes|required`, con las mismas reglas de formato.

   El `regex:/^\S+$/u` es el que hace que un `code` con cualquier espacio sea **422** con el mensaje «El código no puede contener espacios».

7. **Resource, controller y rutas.** `ClientResource` con las siete claves de la sección anterior, `deletedAt` formateada como las otras fechas cuando exista; `ClientController` con los cinco métodos, `try/catch` → `ResponseHandler` y el service inyectado **por parámetro de método**; `routes/clients.php` con prefijo `clients`, `jwt.auth` en el grupo y el `apiResource('/')->parameters(['' => 'client'])->only(['index', 'store', 'show', 'update', 'destroy'])` con `role:administrator` en `store`, `update` y `destroy` vía `middlewareFor` —**sin ninguna ruta fija antes del resource, porque este dominio no tiene ninguna**—; `require` en `routes/api.php`. Verificación: `php artisan route:list --path=clients` muestra las cinco rutas.

8. **Tests.** Disparar el agente `feature-tests` sobre `Client`: Feature test de los cinco endpoints y los cuatro roles (lectura para los cuatro, escritura solo `administrator`), la normalización de `code` y `name`, el 422 del `code` con espacios, el 422 del `code` de más de 15 caracteres, los dos duplicados a 400, que un cliente **borrado** sigue ocupando su `code` y su `name`, el 404 del `show` de un borrado, el 400 del segundo `DELETE` y del `PATCH` sobre un borrado, que el listado excluye los borrados, el filtro `search` sobre los dos campos y la paginación acotada a `[10, 100]`; Unit test del service. Correr `php artisan test --compact --filter=Client`.

9. **Regresión.** `php artisan test --compact` entera en verde: ningún dominio existente se tocó, así que cualquier rojo es una colisión de nombres o de rutas y hay que verlo antes de seguir.

10. **Documentación.** Disparar el agente `endpoint-docs` sobre `Client` y regenerar `storage/api-docs/api-docs.json`.

11. **Cierre.** `vendor/bin/pint --dirty --format agent` y escribir `references/clients-api.md` para el frontend, con `references/zones-api.md` como plantilla, dejando escrito que **un cliente borrado no se puede recuperar por API** y que su `code` y su `name` quedan ocupados para siempre.

---

## Criterios de aceptación

**Estructura**

- [x] Existe la tabla `clients` con `id`, `code`, `name`, `registered_by`, `created_at`, `updated_at` y `deleted_at`; índices únicos en `code` y `name`; `code` es `varchar(15)`; `deleted_at` es la única columna nullable.
- [x] Ninguna tabla existente cambió de columnas y ninguna tiene una FK a `clients`.
- [x] `php artisan route:list --path=clients` muestra exactamente **cinco** rutas, todas bajo `/api/clients`, y ninguna es `toggle-status` ni `restore`.
- [x] Ninguna ruta del dominio lleva `carrier.required`.
- [x] `ClientServiceInterface` tiene **cinco** métodos.
- [x] El modelo `Client` usa `SoftDeletes` y **no declara `casts()`**.

**Roles**

- [x] `administrator`, `carrier`, `manager` y `pilot` autenticados obtienen **200** en `GET /api/clients` y en `GET /api/clients/{id}`.
- [x] `carrier`, `manager` y `pilot` obtienen **403** en `POST`, `PATCH` y `DELETE`.
- [x] Sin token, las cinco rutas responden **401** con el sobre habitual.

**Listado**

- [x] Devuelve los clientes ordenados por `id` ascendente.
- [x] **No incluye los clientes borrados**, ni con filtro ni sin él; no existe ningún parámetro que los muestre.
- [x] `?search=agro` encuentra `AGROEXPORTADORA DEL SUR S.A.` (insensible a mayúsculas por normalización del término).
- [x] `?search=CLI-001` encuentra el cliente por su **código**: el filtro busca en `code` y en `name`.
- [x] `?search=` o solo espacios no filtra nada y devuelve el catálogo completo.
- [x] Sin `limit` devuelve la colección completa; con `limit=5` pagina y el sobre trae `total`, `currentPage` y `lastPage` **en la raíz**, no bajo `meta`; `limit=1` se acota a 10 y `limit=500` a 100; `limit=abc` no pagina.
- [x] Un catálogo vacío responde **200** con `data` vacío.

**Alta**

- [x] `POST` con `code` y `name` válidos responde **201**, con `registered_by` igual al usuario autenticado.
- [x] `name` se guarda y devuelve en MAYÚSCULAS con espacios internos colapsados: `"  agro   del sur "` → `"AGRO DEL SUR"`.
- [x] `code` se guarda y devuelve en MAYÚSCULAS y con los extremos recortados: `" cli-001 "` → `"CLI-001"`.
- [x] `code` con cualquier espacio interior (`"CLI 001"`) responde **422** con «El código no puede contener espacios».
- [x] `code` de más de 15 caracteres responde **422**; `name` de más de 255, también.
- [x] `code` o `name` ausentes responden **422**.
- [x] Enviar `registeredBy` en el body no cambia el autor.
- [x] `code` duplicado (comparado ya normalizado) responde **400** desde el service, no 422.
- [x] `name` duplicado (comparado ya normalizado) responde **400** desde el service, no 422.
- [x] Un `code` que pertenece a un cliente **borrado** responde **400**: el borrado no libera el código. Lo mismo con el `name`.

**Detalle y edición**

- [x] `GET /api/clients/{id}` inexistente responde **404** con «El cliente no existe».
- [x] `GET /api/clients/{id}` de un cliente **borrado** responde **404**, no 400: para el lector no existe.
- [x] `PATCH` con cuerpo vacío responde **200** sin cambiar nada.
- [x] `PATCH` acepta `code` y `name` por separado o juntos; reenviar el propio `code` o el propio `name` **no** choca consigo mismo.
- [x] `PATCH` sobre un cliente borrado responde **400** con «El cliente ya fue eliminado».
- [x] `PATCH` **no** reescribe `registered_by`, aunque lo mande en el body.
- [x] `PATCH` aplica las mismas normalizaciones y las mismas reglas de formato que el alta.

**Baja**

- [x] `DELETE` responde **200**, deja `deleted_at` con fecha y el cliente **desaparece** del listado y del `show`.
- [x] Un segundo `DELETE` sobre el mismo cliente responde **400** con «El cliente ya fue eliminado», no 404 ni 200.
- [x] `DELETE` sobre un id inexistente responde **404**, distinguible del caso anterior.
- [x] La fila **sigue en la base** tras el `DELETE`: `Client::withTrashed()->find($id)` la encuentra.
- [x] **No existe ninguna forma de restaurarlo por API.**

**Salida**

- [x] `ClientResource` devuelve exactamente las siete claves acordadas, en camelCase.
- [x] `deletedAt` sale como `null` en `index`, `show`, `store` y `update`, y con la fecha del borrado en la respuesta del `DELETE`.
- [x] `registeredByName` trae el nombre del usuario y el listado **no hace N+1** (`with('registeredBy')`).
- [x] `createdAt` y `updatedAt` salen en `d-m-Y h:i:s A`.

**Cierre**

- [x] `php artisan test --compact` pasa entera.
- [x] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [x] `storage/api-docs/api-docs.json` incluye las cinco rutas y existe `references/clients-api.md`.

---

## Decisiones tomadas y descartadas

**1. `SoftDeletes` en vez del `status` booleano del resto de catálogos.**
Los seis catálogos nacionales anteriores se dan de baja con `status = false` y siguen apareciendo en el listado. Aquí se descartó ese patrón: un cliente que ya no opera no tiene por qué seguir listándose, y el proyecto ya tiene el precedente de `SoftDeletes` en `FreightRate`. La contrapartida es que este dominio no tiene forma de «pausar» un cliente sin borrarlo, y eso se acepta.

**2. Sin columna `status` y sin `/toggle-status`.**
Se valoró tener los dos ejes, como en `Vehicle` (`status` operativo + `condition`). Se descartó: con dos campos de negocio, un tercer eje de estado es más contrato del que el dominio necesita, y `DELETE` y `toggle-status` pasarían a significar cosas parecidas pero distintas. Un cliente existe o está borrado.

**3. Sin `restore` y sin `?trashed=true`, asumiendo que un borrado es irreversible por API.**
Es la decisión con más consecuencias de la spec y se tomó con el callejón sin salida delante: combinada con la unicidad global (decisión 4), un cliente borrado por error **no se puede recrear con su mismo código ni con su mismo nombre**, y arreglarlo exige tocar la base a mano. Se acepta porque borrar es una acción de administrador, el `DELETE` no es accidental y añadir un `restore` traería su propia pregunta —qué pasa si el código ya se reasignó— que este dominio no necesita responder hoy.

**4. Unicidad global de `code` y `name`: el borrado no los libera.**
Se descartó el precedente de la placa de `Vehicle`, donde una baja libera el valor. Un código de cliente identifica a una empresa real fuera del sistema; reutilizarlo apuntaría dos historiales distintos al mismo identificador. Se sigue el precedente del `code` de `Accessory`, que es global y tampoco se libera.

**5. Índices únicos en base, conviviendo con `SoftDeletes`.**
`FreightRate` deliberadamente **no** lleva índice único porque necesita reutilizar un `fuel_min` borrado. Aquí es al revés: como el borrado no libera nada, el índice único hace exactamente lo que se quiere y se queda como último cortafuegos. Los dos casos son coherentes con su propia regla de unicidad, no contradictorios.

**6. Los dos duplicados a 400 desde el service, sin regla `unique` en el FormRequest.**
Se descartó la asimetría 422/400 de `Location` y `DeparturePoint`. Además de ser el bando de `Accessory` —el otro catálogo con `name` **y** `code` únicos—, aquí es la única opción correcta: la regla `unique` de Laravel **no ve las filas borradas**, así que dejaría pasar un código ocupado por un cliente borrado y el 400 del service llegaría igual, un paso más tarde. Un solo camino para el mismo error.

**7. `code` sin espacios, rechazado con 422 en vez de normalizado.**
Se valoró colapsar o eliminar los espacios en `normalizeCode()`, como hace `normalizeName()` con el nombre. Se descartó: silenciar el espacio convertiría `CLI 001` en `CLI001` sin avisar, y el administrador no vería que tecleó mal. Un espacio en un código es un error de captura, no una variante.

**8. `code` tecleado por el administrador, no generado por el sistema.**
`Carrier` genera un código de 6 caracteres porque ese código nace dentro de la aplicación y sirve para que un piloto se una. El código de un cliente nace **fuera**, en el ERP o en la facturación, y generarlo aquí obligaría a mantener dos códigos para la misma empresa.

**9. `code` limitado a 15 caracteres en la base, no solo en la validación.**
Se descartó `string(255)` con el tope solo en el FormRequest. Si mañana hace falta un código más largo, que sea una migración explícita y no un cambio de una línea en una regla.

**10. Cinco rutas, sin ninguna ruta fija antes del `apiResource`.**
Es el único catálogo del proyecto cuyo `apiResource` va solo. Rompe la simetría visual con los otros seis archivos de rutas, y se prefirió eso a inventar una séptima ruta que el dominio no necesita.

**11. `deletedAt` en el Resource aunque hoy siempre salga `null`.**
Se valoró dejar el Resource en seis claves, ya que ningún endpoint puede devolver un cliente borrado. Se mantiene porque documenta hacia el front que este catálogo borra de verdad, a diferencia de los otros seis, y porque el día que exista un endpoint que muestre borrados la forma no cambia.

**12. Modelo sin `casts()`.**
No hay booleano, ni enum, ni decimal que declarar: los dos campos son texto y `deleted_at` lo castea el trait. Se descartó añadir un `casts()` vacío por simetría con los demás modelos. Precedente: `AccessoryCharacteristic`.

**13. Dos campos y nada más, sin FK a ninguna parte.**
Se descartó anticipar NIT, dirección o contacto «ya que estamos», y se descartó añadir `client_id` a `freight_rates`. El catálogo se publica vacío de relaciones a propósito: la spec que le dé un consumidor decidirá entonces cómo se vincula, con el caso de uso real delante.

**14. `normalizeName()` y `normalizeCode()` duplicados en el modelo, no extraídos a un trait.**
El proyecto ya repite estas reglas en cinco modelos a propósito. Un trait compartido acoplaría catálogos independientes para ahorrar cuatro líneas, y cambiar la regla en uno pasaría a cambiarla en todos sin querer.

---

## Riesgos identificados

**1. Borrado irreversible con el identificador quemado.**
Es el riesgo central de la spec y su forma más probable: un administrador borra el cliente equivocado, intenta darlo de alta otra vez y recibe **400 «ya existe un cliente con ese código»** sin ninguna pista de que el ocupante está borrado y es invisible. El mensaje de error es engañoso justo en el caso en que más ayuda haría. *Mitigación:* el mensaje de `ensureCodeIsAvailable()` y el de `ensureNameIsAvailable()` deben decir explícitamente que el valor puede pertenecer a un cliente **eliminado**, y `references/clients-api.md` debe abrir con esa advertencia.

**2. Nada consume el catálogo todavía.**
Hasta que exista un dominio que apunte a `clients`, nadie valida que los datos sean correctos: un código mal tecleado, un nombre duplicado con una letra distinta o un cliente que ya no existe no lo detecta ningún flujo. *Mitigación:* asumido, igual que en SPEC 20; la corrección es un `PATCH` mientras el cliente no esté borrado.

**3. `SoftDeletes` sin consumidores hoy, con FK mañana.**
La primera tabla que gane `client_id` heredará el problema clásico: una fila apuntando a un cliente borrado, que las consultas normales no ven. Hoy no puede pasar porque nada lo referencia. *Mitigación:* está declarado fuera de alcance; la spec que añada la FK tendrá que decidir si el borrado se bloquea, si arrastra, o si el consumidor lee `withTrashed()`.

**4. Confusión con el patrón de baja de los otros seis catálogos.**
`Product`, `Location`, `Zone` y `DeparturePoint` responden **200 idempotente** al segundo `DELETE` y siguen listando la fila; aquí el segundo `DELETE` es **400** y la fila desaparece. Un front que reutilice el componente de catálogo dará por hecho el comportamiento antiguo. *Mitigación:* `references/clients-api.md` debe abrir contrastando los dos comportamientos, no solo describiendo este.

**5. Colisión de nombres con el término «cliente» del dominio de transportes.**
`Client` es un nombre genérico y el proyecto ya usa la palabra en otro sentido (el cliente HTTP, el front). Un `ClientService` en `app/Services/Client/` no se confunde en el código, pero sí en la conversación. *Mitigación:* nombrar siempre el dominio como «catálogo de clientes» y no abreviar; el riesgo es de comunicación, no de código.

**6. El `code` de 15 caracteres puede quedarse corto.**
El límite sale de una estimación, no de un dato del ERP. Si un código real no cabe, el alta responde 422 sin alternativa. *Mitigación:* el límite está en la base **y** en el FormRequest a propósito (decisión 9), así que ampliarlo es una migración aditiva de una línea y no rompe nada existente.
