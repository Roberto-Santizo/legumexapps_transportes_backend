# SPEC 23 — Catálogo de navieras

> **Estado:** Aprobado
> **Depende de:** SPEC 01
> **Fecha:** 2026-08-26
> **Objetivo:** Publicar un catálogo nacional de navieras con un único campo de negocio —`name` único y normalizado en mayúsculas—, lectura abierta a cualquier autenticado, escritura exclusiva del administrador y borrado con `SoftDeletes` sin vuelta atrás.

Depende de **SPEC 01** y de nada más: el guard JWT, el enum `UserRole` y el middleware `role:`. No toca ninguna tabla existente, no añade FK a ninguna parte y ninguna tabla gana `shipping_line_id`.

Es el **gemelo reducido de SPEC 22**: mismo patrón de baja (`SoftDeletes` real, no `status` booleano), misma repartición de roles, mismo listado y misma paginación, con un solo campo de negocio en vez de dos. Donde `Client` tiene `code` **y** `name`, aquí solo hay `name`, así que desaparecen la validación de espacios, el segundo índice único y el segundo `ensure…IsAvailable()`. Todo lo demás se copia deliberadamente, incluida la regla que lo hace usable: el service resuelve con `withTrashed()` para distinguir «ya eliminada» de «no existe», y el segundo `DELETE` responde **400**, no 404 ni 200.

Es además el **primer dominio del proyecto con un solo campo de negocio**, por debajo de `AccessoryCharacteristic` y de `Client`, que tienen dos.

**Lo que esta spec no es:** una ficha de naviera. No hay código, ni contacto, ni puertos asociados, ni contenedores, ni tarifas por naviera. Una naviera es un nombre, y hoy nada cuelga de ella.

---

## Alcance

**Dentro:**

- **Tabla nueva `shipping_lines`** con `id`, `name`, `registered_by`, `timestamps` y `deleted_at`. **Ninguna tabla existente se toca** y ninguna gana una FK a `shipping_lines`.
- **Ningún enum nuevo.** El dominio no tiene estados: una naviera existe o está borrada.
- **Dominio nacional:** no lleva `carrier_id`, no lleva `vehicle_id` y **ninguna ruta lleva `carrier.required`**.
- **Dominio `ShippingLine` completo** en su subcarpeta, con la cadena de capas del proyecto: `ShippingLineServiceInterface`, `ShippingLineService`, `ShippingLineProvider`, `StoreShippingLineRequest`, `UpdateShippingLineRequest`, `ShippingLineResource` y `ShippingLineController`.
- **Cinco rutas bajo `/api/shipping-lines`**: el `apiResource('/')->parameters(['' => 'shippingLine'])` con `index`, `store`, `show`, `update` y `destroy`. **No hay `/toggle-status` ni `/restore`**, así que —como en SPEC 22— no hay ninguna ruta fija antes del resource.
- **Lectura (`index`, `show`) abierta a cualquier autenticado** (`jwt.auth` a secas); **`store`, `update` y `destroy` solo `role:administrator`**.
- **`SoftDeletes`**, tercer dominio del proyecto que lo usa tras `FreightRate` y `Client`.
- **`name` único y global**, máximo 255, normalizado con `ShippingLine::normalizeName()` (trim + colapsar espacios interiores + MAYÚSCULAS), como en `Product`, `Location`, `Accessory` y `Client`.
- **La unicidad es global y un borrado no la libera:** la fila con `deleted_at` sigue ocupando su `name`. Precedente directo: `Client`.
- **El duplicado responde 400 desde el service**, con mensaje en español y **sin regla `unique` en el FormRequest** — la regla de Laravel no ve las filas borradas. El índice único existe, pero como último cortafuegos, no como vía de respuesta.
- **`registered_by`** sale siempre del usuario autenticado, nunca del body, y **no se reescribe** en el `update`.
- **`PATCH` edita el único campo** (`name`, `sometimes`). Cuerpo vacío responde 200 sin cambiar nada, como el resto de los `PATCH` del proyecto — y a diferencia del `PATCH` de salario de SPEC 11.
- **Las borradas no se ven nunca por API**: el listado las excluye, `GET /{id}` de una borrada es **404**, y `PATCH` o `DELETE` sobre una es **400 «La naviera ya fue eliminada»** —el service las resuelve con `withTrashed()` justo para poder distinguirlas de un id inexistente—.
- **Listado** con filtro tolerante `search` (`LIKE %term%` sobre `name`, ya en mayúsculas), orden fijo **`id ASC`** y paginación **opt-in por `limit`** acotada a `[10, 100]`.
- **`ShippingLineResource` con seis claves**, incluida `deletedAt` (`null` en `index`, `show`, `store` y `update`; con la fecha del borrado en la respuesta del `DELETE`).
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.
- Resumen de integración para el frontend en `references/shipping-lines-api.md`.

**Fuera de alcance (para specs futuras):**

- **Restaurar una naviera borrada.** No hay `PATCH /{shippingLine}/restore`, no hay `?trashed=true` y el contrato no tiene ningún método que lea `withTrashed()` hacia fuera. Un borrado erróneo se arregla **fuera de la aplicación**, tocando la base: es la consecuencia aceptada de combinar `SoftDeletes` con unicidad global, y está decidido a propósito.
- **Cualquier campo más allá de `name`.** Nada de código, sigla, contacto, teléfono, correo, país, web ni notas. Si hacen falta, es otra spec y es una migración aditiva sobre esta tabla. **En particular no hay `code`**: es la única diferencia estructural con `clients` y no se anticipa.
- **Relacionar la naviera con cualquier cosa.** Ninguna tabla gana `shipping_line_id`: ni `locations`, ni `clients`, ni `freight_rates`, ni ninguna otra. No hay navieras por destino ni tarifas por naviera, y `GET /api/freight-rates/quote` **no cambia de forma ni de parámetros**.
- **Vincularla con los puertos de SPEC 21.** `LocationType::Port` existe y es tentador cruzarlo con este catálogo; no se hace. Un puerto sigue siendo una etiqueta de `locations` y no sabe qué navieras operan en él.
- **`status` booleano y `/toggle-status`.** Una naviera no se «desactiva»: se borra o no.
- **Ámbito por empresa.** El catálogo es nacional: un `carrier` ve exactamente las mismas navieras que el `administrator`.
- **Bitácora de cambios.** Editar el `name` pisa el valor anterior sin dejar rastro; no hay tabla de historial ni `changed_by`.
- **Alta en lote**, importación desde archivo o sincronización con un ERP.
- **Archivos adjuntos.** Este dominio no sube nada al bucket: no hay logo de la naviera.
- **Búsqueda por otros criterios**: no hay filtro por fecha de alta, por autor ni por rango de `id`.

---

## Modelo de datos

Esta spec **no toca ninguna tabla existente**. Crea una tabla, un modelo y una factory, y **ningún enum**.

### 1. Tabla `shipping_lines`

```php
Schema::create('shipping_lines', function (Blueprint $table) {
    $table->id();
    /** Siempre en mayúsculas y con los espacios interiores ya colapsados. */
    $table->string('name')->unique();
    /** Sin cascade: borrar al usuario que dio de alta la naviera debe frenarse. */
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();
    $table->softDeletes();
});
```

- **El índice único convive con `SoftDeletes` a propósito.** Una fila con `deleted_at` sigue ocupando su entrada en el índice, que es exactamente la unicidad global que se quiere: una naviera borrada **no libera** su nombre. Es la diferencia con `FreightRate`, que deliberadamente **no** lleva índice único porque allí sí hace falta reutilizar un `fuel_min` ya borrado.
- **Ninguna columna es nullable** salvo `deleted_at`. No hay `code`, no hay `description`, no hay `status`.
- **Ningún índice extra.** El listado ordena por `id` y filtra con `LIKE %term%`, que no aprovecharía un B-tree.
- Es la **tabla más pequeña del proyecto**: una sola columna de negocio.

### 2. Modelo `ShippingLine`

```php
#[Fillable(['name', 'registered_by'])]
class ShippingLine extends Model
{
    /** @use HasFactory<ShippingLineFactory> */
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
}
```

- **Tercer modelo del proyecto sin `casts()`**, tras `AccessoryCharacteristic` y `Client`: aquí todo es texto y no hay ningún booleano, enum ni decimal que declarar. `deleted_at` lo castea el propio trait `SoftDeletes`.
- `normalizeName()` es **propia del modelo**, no importada de `Client` ni de `Product`: el proyecto ya repite esta regla en cinco modelos a propósito.
- **No hay `normalizeCode()`.** Es la única pieza de `Client` que no se copia.
- **`ShippingLine` no tiene ninguna relación más.** Nada cuelga de ella.

### 3. Salida — `ShippingLineResource`

Seis claves, en camelCase:

```json
{
  "id": 1,
  "name": "MAERSK LINE",
  "registeredByName": "Roberto Santizo",
  "createdAt": "26-08-2026 09:14:03 AM",
  "updatedAt": "26-08-2026 09:14:03 AM",
  "deletedAt": null
}
```

- `deletedAt` es `null` en **cuatro de los cinco endpoints** —`index`, `show`, `store` y `update`—, porque ninguno de ellos alcanza a una naviera borrada. La excepción es **la respuesta del propio `DELETE`**, que pinta la fila recién borrada y por tanto trae la marca de tiempo del borrado, en el mismo formato `d-m-Y h:i:s A` que las otras dos fechas.
- Sale `registeredByName`, no el `registered_by` crudo ni el objeto usuario. El service carga la relación con `with('registeredBy')` para que el listado no haga N+1.

### 4. Factory

`ShippingLineFactory` apoyada en `User::factory()->state(['role' => UserRole::Administrator])` para `registered_by`, con `name` único ya normalizado (nombre de naviera + sufijo único, para que `->count(20)` no choque contra el índice único). Añade el estado `trashed()` —copiado de `ClientFactory`— que planta `deleted_at`, porque los tests necesitan una fila borrada que siga ocupando su nombre. La necesitan los tests del agente `feature-tests`.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo.

1. **Migración, modelo y factory.** `php artisan make:model ShippingLine -mf`. Rellenar la migración con las cinco columnas, el índice único en `name` y `softDeletes()`; el modelo con su `#[Fillable(['name', 'registered_by'])]`, el trait `SoftDeletes`, `normalizeName()` y la relación `registeredBy()`, **sin `casts()`**; la factory apoyada en `User::factory()` con `name` ya normalizado y único, más el estado `trashed()`. Correr `php artisan migrate`. Verificación: `php artisan tinker --execute 'ShippingLine::factory()->create();'` no revienta y ninguna tabla existente cambió de columnas.

2. **Contrato.** `app/Interfaces/ShippingLine/ShippingLineServiceInterface.php` con **cinco** métodos, PHPDoc de array shapes y `@throws`:

   ```php
   public function getShippingLines(array $filters): LengthAwarePaginator|Collection;
   public function getShippingLineById(int $id): ShippingLine;
   public function create(User $user, array $data): ShippingLine;
   public function update(int $id, array $data): ShippingLine;
   public function destroy(int $id): ShippingLine;
   ```

   **No hay `toggleStatus()` ni `restore()`**, y ningún método del contrato expone filas borradas: `withTrashed()` es un detalle interno del service.

3. **Service, parte de lectura.** `app/Services/ShippingLine/ShippingLineService.php` con `resolvePerPage()` (constantes `MIN_PER_PAGE` / `MAX_PER_PAGE`), `getShippingLines()` —`with('registeredBy')`, filtro `search` tolerante sobre `name` con `LIKE %term%` tras normalizar el término, ignorado si queda vacío, orden `id ASC`, paginación opt-in— y `getShippingLineById()`, que consulta **sin** `withTrashed()` y lanza `NotFoundError('La naviera no existe')`, de modo que una borrada es 404 para el lector. `#[Override]` en cada método.

4. **Service, parte de escritura.** `create()`, `update()` y `destroy()`, más dos privadas:
   - `resolveWritableShippingLine(int $id): ShippingLine` — consulta **con `withTrashed()`**; lanza `NotFoundError('La naviera no existe')` si no hay fila y `BadRequestError('La naviera ya fue eliminada')` si la fila tiene `deleted_at`. Es la guarda común de `update()` y `destroy()`, y el único sitio del dominio que mira las filas borradas.
   - `ensureNameIsAvailable(string $name, ?int $ignoreId = null): void` — consulta **con `withTrashed()`** y lanza `BadRequestError` en español si otra naviera, borrada o no, ya ocupa ese nombre. El mensaje debe advertir explícitamente de que **el ocupante puede estar eliminado** (ver riesgo 1).

   El alta normaliza el nombre y toma `registered_by` de `$user->id`; el `update` usa `isset`, normaliza lo que llegue y **no reescribe** `registered_by`; `destroy()` llama a `delete()` sobre el modelo ya resuelto. Todo devuelve el modelo con `load('registeredBy')`.

5. **Provider.** `app/Providers/ShippingLine/ShippingLineProvider.php` con el `bind(ShippingLineServiceInterface::class, ShippingLineService::class)`, registrado en `bootstrap/providers.php`.

6. **FormRequests**, los dos con `messages()` en español y `prepareForValidation()` que normaliza `name` con `ShippingLine::normalizeName()` **antes** de validar:
   - `StoreShippingLineRequest` — `name` `required|string|max:255`. **Sin regla `unique`**: el duplicado lo levanta el service con 400. **`registeredBy` no aparece**: mandarlo se descarta sin error.
   - `UpdateShippingLineRequest` — `name` `sometimes|required|string|max:255`, con las mismas reglas de formato.

7. **Resource, controller y rutas.** `ShippingLineResource` con las seis claves de la sección anterior, `deletedAt` formateada como las otras fechas cuando exista; `ShippingLineController` con los cinco métodos, `try/catch` → `ResponseHandler` y el service inyectado **por parámetro de método**; `routes/shipping_lines.php` con prefijo `shipping-lines`, `name('shipping-lines.')`, `jwt.auth` en el grupo y el `apiResource('/')->parameters(['' => 'shippingLine'])->only(['index', 'store', 'show', 'update', 'destroy'])` con `role:administrator` en `store`, `update` y `destroy` vía `middlewareFor` —**sin ninguna ruta fija antes del resource**—; `require` en `routes/api.php`. Verificación: `php artisan route:list --path=shipping-lines` muestra las cinco rutas.

8. **Tests.** Disparar el agente `feature-tests` sobre `ShippingLine`: Feature test de los cinco endpoints y los cuatro roles (lectura para los cuatro, escritura solo `administrator`), la normalización del nombre, el 422 de `name` ausente y de más de 255 caracteres, el duplicado a 400, que una naviera **borrada** sigue ocupando su nombre, el 404 del `show` de una borrada, el 400 del segundo `DELETE` y del `PATCH` sobre una borrada, que el listado excluye las borradas, el filtro `search` y la paginación acotada a `[10, 100]`; Unit test del service. Correr `php artisan test --compact --filter=ShippingLine`.

9. **Regresión.** `php artisan test --compact` entera en verde: ningún dominio existente se tocó, así que cualquier rojo es una colisión de nombres o de rutas y hay que verlo antes de seguir.

10. **Documentación.** Disparar el agente `endpoint-docs` sobre `ShippingLine` y regenerar `storage/api-docs/api-docs.json`.

11. **Cierre.** `vendor/bin/pint --dirty --format agent` y escribir `references/shipping-lines-api.md` para el frontend, con `references/zones-api.md` como plantilla, dejando escrito que **una naviera borrada no se puede recuperar por API** y que su nombre queda ocupado para siempre.

---

## Criterios de aceptación

**Estructura**

- [ ] Existe la tabla `shipping_lines` con `id`, `name`, `registered_by`, `created_at`, `updated_at` y `deleted_at`; índice único en `name`; `deleted_at` es la única columna nullable.
- [ ] Ninguna tabla existente cambió de columnas y ninguna tiene una FK a `shipping_lines`.
- [ ] `php artisan route:list --path=shipping-lines` muestra exactamente **cinco** rutas, todas bajo `/api/shipping-lines`, y ninguna es `toggle-status` ni `restore`.
- [ ] Ninguna ruta del dominio lleva `carrier.required`.
- [ ] `ShippingLineServiceInterface` tiene **cinco** métodos.
- [ ] El modelo `ShippingLine` usa `SoftDeletes` y **no declara `casts()`**.

**Roles**

- [ ] `administrator`, `carrier`, `manager` y `pilot` autenticados obtienen **200** en `GET /api/shipping-lines` y en `GET /api/shipping-lines/{id}`.
- [ ] `carrier`, `manager` y `pilot` obtienen **403** en `POST`, `PATCH` y `DELETE`.
- [ ] Sin token, las cinco rutas responden **401** con el sobre habitual.

**Listado**

- [ ] Devuelve las navieras ordenadas por `id` ascendente.
- [ ] **No incluye las navieras borradas**, ni con filtro ni sin él; no existe ningún parámetro que las muestre.
- [ ] `?search=maer` encuentra `MAERSK LINE` (insensible a mayúsculas por normalización del término).
- [ ] `?search=` o solo espacios no filtra nada y devuelve el catálogo completo.
- [ ] Sin `limit` devuelve la colección completa; con `limit=5` pagina y el sobre trae `total`, `currentPage` y `lastPage` **en la raíz**, no bajo `meta`; `limit=1` se acota a 10 y `limit=500` a 100; `limit=abc` no pagina.
- [ ] Un catálogo vacío responde **200** con `data` vacío.

**Alta**

- [ ] `POST` con `name` válido responde **201**, con `registered_by` igual al usuario autenticado.
- [ ] `name` se guarda y devuelve en MAYÚSCULAS con espacios internos colapsados: `"  maersk   line "` → `"MAERSK LINE"`.
- [ ] `name` ausente responde **422**; `name` de más de 255 caracteres, también.
- [ ] `name` de solo espacios responde **422** (queda vacío tras normalizar).
- [ ] Enviar `registeredBy` en el body no cambia el autor.
- [ ] `name` duplicado (comparado ya normalizado) responde **400** desde el service, no 422.
- [ ] Un `name` que pertenece a una naviera **borrada** responde **400**: el borrado no libera el nombre.

**Detalle y edición**

- [ ] `GET /api/shipping-lines/{id}` inexistente responde **404** con «La naviera no existe».
- [ ] `GET /api/shipping-lines/{id}` de una naviera **borrada** responde **404**, no 400: para el lector no existe.
- [ ] `PATCH` con cuerpo vacío responde **200** sin cambiar nada.
- [ ] `PATCH` con `name` lo actualiza; reenviar el propio nombre **no** choca consigo mismo.
- [ ] `PATCH` sobre una naviera borrada responde **400** con «La naviera ya fue eliminada».
- [ ] `PATCH` **no** reescribe `registered_by`, aunque lo mande en el body.
- [ ] `PATCH` aplica la misma normalización y las mismas reglas de formato que el alta.

**Baja**

- [ ] `DELETE` responde **200**, deja `deleted_at` con fecha y la naviera **desaparece** del listado y del `show`.
- [ ] Un segundo `DELETE` sobre la misma naviera responde **400** con «La naviera ya fue eliminada», no 404 ni 200.
- [ ] `DELETE` sobre un id inexistente responde **404**, distinguible del caso anterior.
- [ ] La fila **sigue en la base** tras el `DELETE`: `ShippingLine::withTrashed()->find($id)` la encuentra.
- [ ] **No existe ninguna forma de restaurarla por API.**

**Salida**

- [ ] `ShippingLineResource` devuelve exactamente las seis claves acordadas, en camelCase.
- [ ] `deletedAt` sale como `null` en `index`, `show`, `store` y `update`, y con la fecha del borrado en la respuesta del `DELETE`.
- [ ] `registeredByName` trae el nombre del usuario y el listado **no hace N+1** (`with('registeredBy')`).
- [ ] `createdAt` y `updatedAt` salen en `d-m-Y h:i:s A`.

**Cierre**

- [ ] `php artisan test --compact` pasa entera.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `storage/api-docs/api-docs.json` incluye las cinco rutas y existe `references/shipping-lines-api.md`.

---

## Decisiones tomadas y descartadas

**1. `SoftDeletes` en vez del `status` booleano del resto de catálogos.**
Los seis catálogos nacionales anteriores a SPEC 22 se dan de baja con `status = false` y siguen apareciendo en el listado. Aquí se sigue el bando de `Client`: una naviera que ya no opera no tiene por qué seguir listándose. La contrapartida es que este dominio no tiene forma de «pausar» una naviera sin borrarla, y eso se acepta.

**2. Un solo campo de negocio, sin `code`.**
Es la única diferencia estructural con `clients` y se tomó por descarte explícito, no por olvido: la naviera se identifica por su nombre comercial, que ya es único y reconocible, y añadir un código obligaría al administrador a inventarlo o a copiarlo de un sistema que hoy no existe. Si mañana hace falta, es una migración aditiva sobre esta tabla.

**3. Modelo copiado de `Client`, no extraído a una base común.**
Las dos tablas son casi idénticas y aun así no comparten trait, clase base ni service abstracto. El proyecto ya repite `normalizeName()` en cinco modelos a propósito: un ancestro común acoplaría catálogos independientes para ahorrar unas líneas, y el día que uno de los dos cambie de regla —`Client` ya tiene un `code` que este no tiene— el ancestro se rompe por los dos lados.

**4. Sin columna `status` y sin `/toggle-status`.**
Con **un** campo de negocio, un eje de estado sería más contrato del que el dominio necesita, y `DELETE` y `toggle-status` pasarían a significar cosas parecidas pero distintas. Una naviera existe o está borrada.

**5. Sin `restore` y sin `?trashed=true`, asumiendo que un borrado es irreversible por API.**
Es la decisión con más consecuencias de la spec y se tomó con el callejón sin salida delante: combinada con la unicidad global (decisión 6), una naviera borrada por error **no se puede recrear con su mismo nombre**, y arreglarlo exige tocar la base a mano. Se acepta porque borrar es una acción de administrador y añadir un `restore` traería su propia pregunta —qué pasa si el nombre ya se reasignó— que este dominio no necesita responder hoy.

**6. Unicidad global de `name`: el borrado no lo libera.**
Se descartó el precedente de la placa de `Vehicle`, donde una baja libera el valor. El nombre identifica a una empresa real fuera del sistema; reutilizarlo apuntaría dos historiales distintos al mismo identificador. Se sigue el precedente de `Client` y del `code` de `Accessory`.

**7. Índice único en base, conviviendo con `SoftDeletes`.**
`FreightRate` deliberadamente **no** lleva índice único porque necesita reutilizar un `fuel_min` borrado. Aquí es al revés: como el borrado no libera nada, el índice único hace exactamente lo que se quiere y se queda como último cortafuegos. Los dos casos son coherentes con su propia regla de unicidad, no contradictorios.

**8. El duplicado a 400 desde el service, sin regla `unique` en el FormRequest.**
Se descartó la asimetría 422/400 de `Location` y `DeparturePoint`. Aquí es la única opción correcta: la regla `unique` de Laravel **no ve las filas borradas**, así que dejaría pasar un nombre ocupado por una naviera borrada y el 400 del service llegaría igual, un paso más tarde. Un solo camino para el mismo error.

**9. Nombre del dominio en inglés (`ShippingLine`), mensajes en español.**
Se descartó `Naviera` como nombre de clase y de tabla. El proyecto nombra en inglés todo el código y en español todo lo que ve el usuario; `navieras` en la ruta y `Naviera` en la clase habría sido el primer dominio mezclado. `Carrier` estaba ocupado por la empresa de transporte, así que no era candidato.

**10. Sin relación con `LocationType::Port` de SPEC 21.**
Se valoró cruzar el catálogo con los puertos, que es el vínculo natural del negocio. Se descartó por lo mismo que en SPEC 22: el catálogo se publica sin consumidores a propósito, y la spec que le dé uno decidirá si la relación es 1-N o N-N, con el caso real delante.

**11. `registered_by` presente aunque nadie lo consulte hoy.**
Se valoró la tabla mínima —`id`, `name`, `deleted_at`— que habría sido el primer catálogo sin autor. Se mantiene el autor por simetría con los siete catálogos anteriores y porque, en un dominio donde el borrado es irreversible, saber quién dio de alta la fila es lo mínimo que se puede pedir a la auditoría.

**12. `deletedAt` en el Resource aunque casi siempre salga `null`.**
Se valoró dejar el Resource en cinco claves, ya que solo la respuesta del `DELETE` la trae con valor. Se mantiene porque documenta hacia el front que este catálogo borra de verdad, a diferencia de los seis con `status`, y porque el día que exista un endpoint que muestre borradas la forma no cambia.

**13. Modelo sin `casts()`.**
No hay booleano, ni enum, ni decimal que declarar: el único campo es texto y `deleted_at` lo castea el trait. Se descartó añadir un `casts()` vacío por simetría con los demás modelos. Precedentes: `AccessoryCharacteristic` y `Client`.

---

## Riesgos identificados

**1. Borrado irreversible con el nombre quemado.**
Es el riesgo central de la spec y su forma más probable: un administrador borra la naviera equivocada, intenta darla de alta otra vez y recibe **400 «ya existe una naviera con ese nombre»** sin ninguna pista de que la ocupante está borrada y es invisible. El mensaje de error es engañoso justo en el caso en que más ayuda haría. **Aquí duele más que en SPEC 22**: allí quedaba el `code` como segundo identificador, y aquí el nombre es lo único que hay. *Mitigación:* el mensaje de `ensureNameIsAvailable()` debe decir explícitamente que el nombre puede pertenecer a una naviera **eliminada**, y `references/shipping-lines-api.md` debe abrir con esa advertencia.

**2. Una errata de captura crea una naviera nueva, no un error.**
Con un solo campo libre y sin código, `MAERSK LNE` es tan válida como `MAERSK LINE` y el catálogo acaba con dos filas para la misma empresa. Nada lo detecta: el índice único solo ve cadenas distintas. *Mitigación:* la corrección es un `PATCH` mientras la naviera no esté borrada; el filtro `search` ayuda al administrador a ver el duplicado antes de crearlo, y el front debería buscar antes de ofrecer el alta.

**3. Nada consume el catálogo todavía.**
Hasta que exista un dominio que apunte a `shipping_lines`, nadie valida que los datos sean correctos. *Mitigación:* asumido, igual que en SPEC 20 y SPEC 22.

**4. `SoftDeletes` sin consumidores hoy, con FK mañana.**
La primera tabla que gane `shipping_line_id` heredará el problema clásico: una fila apuntando a una naviera borrada, que las consultas normales no ven. Hoy no puede pasar porque nada la referencia. *Mitigación:* está declarado fuera de alcance; la spec que añada la FK tendrá que decidir si el borrado se bloquea, si arrastra, o si el consumidor lee `withTrashed()`.

**5. Confusión con el patrón de baja de los catálogos con `status`.**
`Product`, `Location`, `Zone` y `DeparturePoint` responden **200 idempotente** al segundo `DELETE` y siguen listando la fila; aquí el segundo `DELETE` es **400** y la fila desaparece. Un front que reutilice el componente de catálogo dará por hecho el comportamiento antiguo. *Mitigación:* `references/shipping-lines-api.md` debe abrir contrastando los dos comportamientos y señalando que este dominio se comporta como `clients`, no como el resto.

**6. Dos dominios casi idénticos que se copian a mano.**
`Client` y `ShippingLine` comparten estructura, service, rutas y tests salvo por el `code`. Copiar de `clients` es la vía rápida, y también la vía por la que se cuela un mensaje que dice «cliente» dentro del service de navieras, o un `ensureCodeIsAvailable()` que aquí no pinta nada. *Mitigación:* el paso 9 del plan corre la suite entera, y el Feature test debe afirmar sobre los mensajes en español literales, que es donde aparecería la copia mal hecha.
