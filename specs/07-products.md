# SPEC 07 — Catálogo de productos

> **Estado:** Aprobado
> **Depende de:** SPEC 01, SPEC 03
> **Fecha:** 2026-08-07
> **Objetivo:** Mantener un catálogo nacional de productos —la mercancía que se transporta— con el nombre único y siempre en mayúsculas, y un estado booleano que el `administrator` gestiona y cualquier usuario autenticado consulta.

Depende de **SPEC 01** por el guard JWT y por el `User` al que apunta `registered_by`, y de **SPEC 03** porque de ahí sale el middleware `role`, que es lo que restringe la escritura al `administrator`. No depende de SPEC 04 ni de SPEC 06: un producto no se relaciona con vehículos ni con precios. No depende de SPEC 05: no lleva imagen.

---

## Alcance

**Dentro:**

- Migración `products`: `id`, `name` (`string`, **único**), `status` (`boolean`, default `true`), `registered_by` (FK a `users`), `timestamps`. Sin `deleted_at`.
- Modelo `Product` con factory, relación `registeredBy()` y estados de factory `active()` e `inactive()`.
- Cadena de capas completa en la subcarpeta `Product/`: `ProductServiceInterface`, `ProductService`, `ProductProvider`, `StoreProductRequest`, `UpdateProductRequest`, `ProductResource` y `ProductController`.
- `routes/products.php` incluido desde `routes/api.php`, con la ruta fija `/{product}/toggle-status` declarada **antes** del `apiResource`.
- **El `name` se normaliza a mayúsculas** al crear y al actualizar: `trim`, colapso de espacios internos y `mb_strtoupper`. Lo que se guarda es lo que se devuelve; el cliente puede mandar `brocoli` y la fila queda como `BROCOLI`.
- **`name` único a nivel global**, con índice único en base. Al estar siempre en mayúsculas, la unicidad es insensible a mayúsculas sin depender del collation del motor.
- **`status` es booleano**, nace en `true` y viaja como `true`/`false` en el JSON.
- **Lectura abierta a cualquier usuario autenticado** (`administrator`, `carrier`, `pilot`, `manager`), sin `carrier.required`: es un catálogo nacional, no de una empresa.
- **Escritura restringida a `administrator`** con `role:administrator` en `store`, `update`, `toggleStatus` y `destroy`.
- `PATCH /api/products/{product}` acepta `name` y `status`, ambos opcionales pero con al menos uno presente.
- `PATCH /api/products/{product}/toggle-status` invierte el `status` actual sin recibir body.
- **`DELETE` es baja lógica e idempotente**: pasa `status` a `false`. Sobre un producto ya inactivo responde 200 y lo deja igual. La fila nunca desaparece y sigue apareciendo en los listados.
- Reactivar es `toggle-status` sobre un inactivo, o un `PATCH` con `status: true`.
- `registered_by` se toma del usuario autenticado (`auth('api')->user()`), nunca del body. No cambia en el `update`: sigue apuntando a quien lo dio de alta.
- Filtros opcionales en `index`: `status` (booleano, valor no booleano se ignora) y `search` (`LIKE` sobre `name`, con el término normalizado a mayúsculas). Orden fijo `id ASC`.
- Paginación opcional por `limit` con `PaginatedResource`, acotada a `[10, 100]`, exactamente como en SPEC 03, 04 y 06.
- `ProductResource` en camelCase: `id`, `name`, `status`, `registeredByName`, `createdAt` y `updatedAt`.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **Cualquier columna más allá de `name` y `status`**: categoría, unidad de medida, precio, peso por caja, requiere refrigeración, código SKU. Se pidió el catálogo mínimo a propósito.
- **Imagen del producto.** No entra, así que esta spec no toca la infraestructura de SPEC 05.
- **Relación con viajes, guías o vehículos.** El catálogo se publica; nadie lo consume todavía.
- **Catálogo por empresa.** Los productos son de Legumex, no del transportista; no hay `carrier_id` ni `resolveScopedCarrierId()`.
- **Borrado real.** Un producto usado en un histórico futuro no debe poder desaparecer.
- **Auditoría de cambios de `name`.** El `PATCH` sobrescribe el nombre sin dejar rastro del anterior; solo se conserva quién dio de alta la fila.
- **Importación masiva por CSV** y alta/baja en lote.
- **Orden configurable** (`sortBy`, `sortDir`) y filtros por rango de fechas.
- **Nombres en varios idiomas** o alias de búsqueda.

---

## Modelo de datos

Esta spec no introduce ningún enum: el `status` es un booleano, no un estado con nombres.

### 1. Tabla `products`

```php
Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();          // siempre en mayúsculas
    $table->boolean('status')->default(true);
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();
});
```

`name` lleva **índice único de verdad**, a diferencia de la placa de SPEC 04: allí la unicidad es condicional (solo entre no desactivados) y por eso vive en el service; aquí es absoluta y la base la puede garantizar. Al guardarse siempre en mayúsculas, la unicidad resulta insensible a mayúsculas sin depender del collation del motor — que en SQLite (tests) y en MySQL (producción) no coincide.

`registered_by` usa `constrained('users')` sin `cascadeOnDelete`, igual que en SPEC 06: el proyecto no borra usuarios, y si algún día lo hiciera, la FK debe frenar el borrado antes que perder la trazabilidad.

No hay índice sobre `status`: con un catálogo de decenas de filas, un índice booleano de dos valores no aporta nada.

### 2. Modelo

```php
#[Fillable(['name', 'status', 'registered_by'])]
class Product extends Model
{
    public function registeredBy(): BelongsTo;   // usuario administrador que lo capturó

    /** Normaliza un nombre: recorta, colapsa espacios internos y pasa a mayúsculas. */
    public static function normalizeName(string $name): string;

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }
}
```

`registeredBy()` es `belongsTo(User::class, 'registered_by')` — la FK no sigue la convención `user_id` porque el nombre describe el rol de la relación, no la tabla.

`normalizeName()` es **estática y pública en el modelo** porque tiene dos llamadores legítimos: el `prepareForValidation()` de los FormRequests —que la necesita para que la regla `unique` compare el valor real— y el service, que la aplica antes de persistir. Es la única regla de esta spec que vive en el modelo, y está ahí justamente para no duplicarla.

La factory nace `active` con un nombre de producto agrícola y un `User` administrador; añade los estados `active()` e `inactive()`.

**Ninguna relación inversa en `User`.**

### 3. Resource

```php
// ProductResource
[
    'id',
    'name',               // siempre en mayúsculas
    'status',             // true | false
    'registeredByName',   // desde la relación registeredBy
    'createdAt',          // '07-08-2026 06:03:22 PM'
    'updatedAt',          // '07-08-2026 06:11:40 PM'
]
```

`registeredByName` sale de `$this->registeredBy->name`; el service carga la relación con `with('registeredBy')` para no provocar N+1 en los listados.

`createdAt` y `updatedAt` se emiten con el formato **`d-m-Y h:i:s A`** (`'07-08-2026 06:03:22 PM'`): día-mes-año y hora de 12 horas con AM/PM. Es una decisión propia de este dominio y **rompe con el resto del proyecto**, que devuelve las fechas en ISO 8601 crudo. Se aplica con `$this->created_at?->format('d-m-Y h:i:s A')`, con el operador nullsafe porque un modelo recién construido en memoria puede no tener timestamps.

Al no ser ISO, ambos campos se documentan en Swagger como `type: 'string'` **sin** `format: 'date-time'`, y su `example` muestra el formato real. Marcarlos como `date-time` haría que un cliente generado desde el schema intentara parsearlos como ISO y fallara.

`updatedAt` es, en la práctica, la fecha del último cambio de `name` o de `status`: es lo más cercano a una auditoría que ofrece esta spec, ya que no se guarda el valor anterior de ninguno de los dos.

### 4. Validación

```php
// StoreProductRequest
'name' => ['required', 'string', 'max:255', Rule::unique('products', 'name')],
// status NO se acepta: nace siempre en true
// registeredBy NO se acepta: sale del usuario autenticado

// UpdateProductRequest
'name' => ['required_without:status', 'string', 'max:255', Rule::unique('products', 'name')->ignore($this->route('product'))],
'status' => ['required_without:name', 'boolean'],
// el required_without cruzado es lo que impide el cuerpo vacío
```

Los dos requests implementan `prepareForValidation()` con `Product::normalizeName()` sobre el `name` recibido. Sin ese paso, mandar `brocoli` existiendo `BROCOLI` pasaría la validación `unique` y reventaría contra el índice de la base con un 500.

En el `UpdateProductRequest` los dos campos son opcionales por separado, así que con reglas `sometimes` un body vacío pasaría la validación y produciría un `PATCH` sin efecto. Para evitarlo, cada campo lleva `required_without` apuntando al otro: si no viene ninguno, los dos fallan y la respuesta es un 422 con el mensaje «Debe enviar al menos el nombre o el estado» en ambos.

Se propuso primero `Rule::atLeastOneOf(['name', 'status'])`, que habría dado un único mensaje, pero **ese método no existe en Laravel 13.23**, la versión del proyecto: `Rule` solo expone `anyOf()`, que valida que *un valor* cumpla uno de varios conjuntos de reglas, no que *uno de varios campos* esté presente. El `required_without` cruzado es nativo, no necesita validador propio y el efecto observable es el mismo, a cambio de repetir el mensaje en los dos campos.

`Rule::unique(...)->ignore($this->route('product'))` es lo que permite reenviar el mismo nombre en un `PATCH` sin chocar consigo mismo.

`max:255` es el límite de la columna `string`, no una regla de negocio.

### 5. Reglas de negocio del service

- **Normalización del nombre.** `create` y `update` pasan el `name` por `Product::normalizeName()` antes de persistir. Se aplica aunque el FormRequest ya lo haya hecho: el service es llamable directamente y no puede confiar en su llamador.
- **Nombre disponible.** Un método privado `ensureNameIsAvailable(string $name, ?int $ignoreId = null)` consulta si otro producto ya tiene ese nombre y lanza `BadRequestError` si lo hay. Duplica lo que ya cubre la regla `unique` del request, y es deliberado: sin él, una llamada directa al service produciría un 500 del índice en vez de un error de negocio.
- **`registered_by`** se toma del `User` autenticado que el service recibe por parámetro, nunca del body. El `update` no lo reescribe.
- **`create`** fuerza `status = true`. El body no puede fijarlo.
- **`update`** acepta `name`, `status` o ambos, y solo toca lo que venga.
- **`toggleStatus`** invierte el booleano actual: `true` pasa a `false` y `false` a `true`. No recibe body.
- **`destroy`** pone `status = false` y es **idempotente**: sobre un producto ya inactivo no falla, responde 200 y devuelve el recurso sin cambios.
- **404 en todo lo que resuelve por id.** `show`, `update`, `toggleStatus` y `destroy` lanzan `NotFoundError` si el id no existe. Ninguno distingue entre activo e inactivo: aquí no hay histórico intocable como en SPEC 06.
- **Filtros de `index`:** `status` se interpreta con `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` y solo se aplica si resuelve a booleano — acepta `true`, `false`, `1` y `0`, e ignora cualquier otra cosa sin error, como en SPEC 04 y 06. `search` aplica `LIKE %TERM%` sobre `name` con el término normalizado a mayúsculas; en blanco se ignora.
- **Orden fijo `id ASC`.** El catálogo se lee siempre en el orden en que se dio de alta.
- **`limit`:** idéntico a SPEC 03, 04 y 06 — ausente o no numérico devuelve la colección completa; numérico pagina, acotado a `[10, 100]`.

### 6. Contrato HTTP

| Método y ruta | Acción | Rol |
|---|---|---|
| `GET /api/products` | `index` | cualquier autenticado |
| `POST /api/products` | `store` | administrator |
| `GET /api/products/{product}` | `show` | cualquier autenticado |
| `PATCH /api/products/{product}` | `update` | administrator |
| `PATCH /api/products/{product}/toggle-status` | `toggleStatus` | administrator |
| `DELETE /api/products/{product}` | `destroy` | administrator |

Query params de `index`: `status`, `search` y `limit`. Ninguna ruta lleva `carrier.required`.

`/{product}/toggle-status` se declara **antes** del `apiResource`, siguiendo la convención del proyecto. El archivo replica la forma de `routes/vehicles.php` y `routes/fuel_prices.php`: `apiResource('/', ...)->parameters(['' => 'product'])` con `middlewareFor` por acción.

---

## Plan de implementación

Cada paso deja el sistema arrancable y es commiteable por sí solo.

1. **Tabla y modelo `Product`.** `php artisan make:model Product -mf --no-interaction`. Migración con las cinco columnas, índice único en `name`, default `true` en `status` y FK `registered_by` a `users`. Modelo con `#[Fillable]`, cast `'status' => 'boolean'` y relación `registeredBy()`. *Verificación:* `php artisan migrate` corre limpio y `Product::factory()->create()->registeredBy` devuelve un `User`.

2. **Normalización y estados de la factory.** `Product::normalizeName()` estática y pública, que recorta, colapsa espacios internos y pasa a mayúsculas. Estados `active()` e `inactive()` en `ProductFactory`. La factory genera nombres ya normalizados, para que ningún test parta de datos que el resto del sistema no podría haber producido. *Verificación:* `php artisan tinker --execute 'echo App\Models\Product::normalizeName("  mini   zanahoria ");'` imprime `MINI ZANAHORIA`.

3. **Resource.** `app/Http/Resources/Product/ProductResource.php` con los seis campos en camelCase, `registeredByName` desde la relación y `createdAt`/`updatedAt` con `->format('d-m-Y h:i:s A')`. *Verificación:* la suite existente sigue verde.

4. **Contrato del service.** `app/Interfaces/Product/ProductServiceInterface.php` con los seis métodos —`getProducts`, `getProductById`, `create`, `update`, `toggleStatus`, `destroy`— y su PHPDoc de array shapes. Sin implementación.

5. **Service, lecturas.** `app/Services/Product/ProductService.php` con `getProducts(array $filters)` y `getProductById(int $id)`. `getProducts` arranca de `Product::with('registeredBy')`, aplica `status` solo si `filter_var` lo resuelve a booleano, aplica `search` como `LIKE %TERM%` con el término normalizado, ordena por `id ASC` y pagina o no según `limit`. `getProductById` lanza `NotFoundError` si no existe. *Verificación:* con datos sembrados, `getProducts(['search' => 'broc'])` devuelve `BROCOLI`.

6. **Service, alta.** `create(User $user, array $data)`: normaliza el `name`, pasa por `ensureNameIsAvailable()`, fuerza `status = true` y fija `registered_by` con el usuario recibido. *Verificación:* crear `brocoli` guarda `BROCOLI`; repetir la llamada lanza `BadRequestError`.

7. **Service, escrituras restantes.** `update(int $id, array $data)` que normaliza el `name` si viene y revalida disponibilidad ignorando el propio id, y toca `status` solo si viene; `toggleStatus(int $id)` que invierte el booleano; `destroy(int $id)` que fija `status = false` sin fallar si ya lo estaba. Los tres resuelven la fila por id y lanzan `NotFoundError` si no existe. *Verificación:* dos `destroy` seguidos sobre el mismo producto no lanzan y lo dejan en `false`.

8. **Provider.** `app/Providers/Product/ProductProvider.php` con el `bind(ProductServiceInterface::class, ProductService::class)`, registrado en `bootstrap/providers.php`. *Verificación:* `app(ProductServiceInterface::class)` resuelve a `ProductService`.

9. **FormRequests.** `StoreProductRequest` y `UpdateProductRequest` en `app/Http/Requests/Product/`, ambos con `prepareForValidation()` llamando a `Product::normalizeName()`, las reglas `unique` —con `ignore()` en el update—, `required_without` cruzado entre `name` y `status` en el update y `messages()` en español.

10. **Controller.** `app/Http/Controllers/ProductController.php` con las seis acciones, el service inyectado **por parámetro de cada método**, `try/catch` a `ResponseHandler` y ninguna regla de negocio. Solo `store` resuelve el usuario con `auth('api')->user()`, porque es la única acción cuya firma en el service recibe un `User`.

11. **Rutas.** `routes/products.php` con el prefijo `products`, `jwt.auth` en el grupo, `/{product}/toggle-status` **antes** del `apiResource`, y `role:administrator` en las cuatro acciones de escritura. `require` en `routes/api.php`. *Verificación:* `php artisan route:list --path=products` lista las seis rutas con `toggle-status` antes del comodín.

12. **Formato.** `vendor/bin/pint --dirty --format agent`.

13. **Tests y documentación.** Disparar el agente `feature-tests` para el Feature test HTTP y el Unit test del service, y el agente `endpoint-docs` para los atributos `OpenApi\Attributes` y `php artisan l5-swagger:generate`.

---

## Criterios de aceptación

**Alta y normalización**

- [ ] `POST /api/products` con `name: "brocoli"` responde 201 y la fila se guarda como `BROCOLI`.
- [ ] `POST` con `name: "  mini   zanahoria  "` guarda `MINI ZANAHORIA`: sin espacios en los extremos y con los internos colapsados a uno.
- [ ] El producto nace con `status: true`.
- [ ] El body no puede fijar el estado: enviar `status: false` en el alta produce igualmente un producto con `status: true`.
- [ ] `registeredBy` apunta al usuario autenticado aunque el body traiga un `registeredBy` distinto.
- [ ] `POST` con un `name` que ya existe responde 422.
- [ ] `POST` con `name: "brocoli"` existiendo `BROCOLI` responde 422, no 500.

**Consulta y filtros**

- [ ] `GET /api/products` devuelve el catálogo ordenado por `id` ascendente.
- [ ] El listado sin filtros incluye tanto los activos como los inactivos.
- [ ] `GET /api/products?status=false` devuelve solo los inactivos.
- [ ] `GET /api/products?status=1` devuelve solo los activos.
- [ ] `GET /api/products?status=quizas` ignora el filtro y responde 200 con todo el catálogo.
- [ ] `GET /api/products?search=broc` devuelve `BROCOLI` aunque el término vaya en minúsculas.
- [ ] `GET /api/products?search=` ignora el filtro y responde 200.
- [ ] `GET /api/products?search=zzz` responde 200 con una lista vacía, no 404.
- [ ] `GET /api/products?limit=10` devuelve `total`, `currentPage` y `lastPage` en la raíz del sobre, no bajo `meta`.
- [ ] `GET /api/products?limit=abc` devuelve la colección completa sin paginar.
- [ ] `GET /api/products/{id}` devuelve el producto aunque esté inactivo.

**Modificación, toggle y baja**

- [ ] `PATCH /api/products/{id}` con `name: "fresa"` responde 200 y el nombre queda como `FRESA`.
- [ ] `PATCH` con solo `status: false` cambia el estado y no toca el nombre.
- [ ] `PATCH` con el body vacío responde 422.
- [ ] `PATCH` reenviando el mismo `name` del propio producto responde 200, no 422.
- [ ] `PATCH` con un `name` que ya usa otro producto responde 422.
- [ ] `PATCH` no reescribe `registeredBy`: sigue apuntando a quien dio de alta el producto.
- [ ] `PATCH /api/products/{id}/toggle-status` sobre un activo lo deja en `false`.
- [ ] `PATCH /api/products/{id}/toggle-status` sobre un inactivo lo deja en `true`.
- [ ] Dos `toggle-status` seguidos devuelven el producto al estado inicial.
- [ ] `toggle-status` responde 200 sin necesidad de enviar body.
- [ ] `DELETE /api/products/{id}` responde 200, deja `status: false` y la fila sigue existiendo en la base.
- [ ] El producto dado de baja sigue apareciendo en `GET /api/products` sin filtros.
- [ ] `DELETE` sobre un producto ya inactivo responde 200 y lo deja igual.
- [ ] Un producto inactivo puede reactivarse con `toggle-status` o con `PATCH` y `status: true`.
- [ ] Cualquier operación sobre un id inexistente responde 404.

**Autorización**

- [ ] Las seis rutas sin token responden 401 con el sobre estándar.
- [ ] `GET /api/products` y `GET /api/products/{id}` responden 200 con un token de `pilot`, de `carrier` y de `manager`.
- [ ] Ninguna ruta de lectura exige tener empresa: un `carrier` sin empresa las alcanza.
- [ ] `POST`, `PATCH`, `PATCH /toggle-status` y `DELETE` responden 403 con un token de `carrier`, de `pilot` y de `manager`.

**Validación y forma de la respuesta**

- [ ] `POST` sin `name` o con `name` vacío responde 422.
- [ ] `POST` con un `name` de más de 255 caracteres responde 422.
- [ ] `PATCH` con `status: "quizas"` responde 422.
- [ ] Los mensajes de validación están en español.
- [ ] Toda respuesta viaja en el sobre `{ statusCode, message, data }`.
- [ ] `ProductResource` expone exactamente `id`, `name`, `status`, `registeredByName`, `createdAt` y `updatedAt`, en camelCase.
- [ ] `status` sale como booleano JSON (`true`/`false`), no como `1`/`0` ni como cadena.
- [ ] `createdAt` y `updatedAt` salen con el formato `d-m-Y h:i:s A` (ej. `07-08-2026 06:03:22 PM`).
- [ ] Tras un `PATCH`, el `updatedAt` devuelto es posterior al `createdAt`.

**Integración**

- [ ] `php artisan route:list --path=products` lista seis rutas y `toggle-status` aparece antes que el comodín `{product}`.
- [ ] `php artisan test --compact` pasa toda la suite, incluidas las specs anteriores.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `php artisan l5-swagger:generate` regenera `storage/api-docs/api-docs.json` con los seis endpoints documentados.

---

## Decisiones

**Alcance**

- **Sí:** catálogo mínimo de dos columnas, `name` y `status`. Se pidió así a propósito: es lo que hace falta hoy para poblar un selector, y cada columna que no existe es una decisión que no hay que defender después.
- **No:** categoría, unidad de medida, precio, peso por caja o código SKU. Ninguna tiene consumidor todavía. Cuando el dominio de viajes exista y pida una de ellas, entra con su caso de uso encima.
- **No:** imagen del producto. Existe la infraestructura de SPEC 05 y no cuesta añadirla más adelante; un catálogo que solo alimenta un `<select>` no la necesita.
- **No:** relación con viajes, guías o vehículos. Esta spec publica el catálogo; nadie lo consume aún.
- **No:** catálogo por empresa. Los productos son de Legumex, no del transportista. Es la diferencia de fondo con SPEC 04: aquí no hay `carrier_id` ni `resolveScopedCarrierId()`.

**El nombre**

- **Sí:** `name` siempre en mayúsculas, normalizado en el alta y en la actualización. El efecto secundario es el que más valor tiene: convierte la unicidad insensible a mayúsculas en un problema resuelto, sin depender del collation, que no coincide entre el SQLite de los tests y el MySQL de producción.
- **Sí:** colapsar los espacios internos además de recortar los extremos. Sin eso, `MINI  ZANAHORIA` y `MINI ZANAHORIA` son dos filas distintas para el índice único y la misma cosa para un humano.
- **Sí:** unicidad global con **índice único de verdad**. Se separa a propósito de la placa de SPEC 04, donde la unicidad es condicional —solo entre vehículos no desactivados— y por eso vive en el service. Aquí es absoluta y la base la puede garantizar.
- **Sí:** `Product::normalizeName()` estática en el **modelo**. Tiene dos llamadores legítimos —el `prepareForValidation()` del FormRequest y el service— y ponerla en cualquiera de los dos obligaría al otro a depender de él. Es la única regla de esta spec que no vive en el service, y está ahí para no duplicarla.
- **Sí:** normalizar en el FormRequest **antes** de validar. Sin ese paso, mandar `brocoli` existiendo `BROCOLI` pasa la regla `unique` y revienta contra el índice con un 500 en vez de un 422.
- **Sí:** unicidad comprobada también dentro del service, aunque el request y el índice ya la cubran. Cada capa atrapa un caso distinto: el request da el 422 en español, el service protege la llamada directa, el índice protege la carrera entre dos altas simultáneas.

**Estado y baja**

- **Sí:** `status` como **booleano**, no como enum de dos valores tipo `VehicleStatus` o `FuelPriceStatus`. Un producto solo está o no está disponible; no hay un tercer estado plausible como `under_repair`. Si algún día lo hubiera, migrar a enum es una spec propia.
- **Sí:** `DELETE` como baja lógica. Un producto que aparezca en un histórico de viajes futuro no debe poder desaparecer.
- **Sí:** `DELETE` **idempotente**. Dar de baja algo que ya está de baja no es un error del cliente: el resultado que pidió ya se cumple. Es lo contrario a SPEC 06, donde operar sobre una fila `inactive` es 400 — allí lo inactivo es histórico congelado, aquí es solo un producto que no se está moviendo.
- **Sí:** endpoint `toggle-status` dedicado. Un catálogo se administra desde una tabla con un interruptor por fila, y el cliente no debería tener que saber el estado actual para invertirlo.
- **Sí:** que el `PATCH` **también** acepte `status`, solapándose con `toggle-status`. Decisión consciente, no descuido: el `toggle` sirve al interruptor de la tabla y el `PATCH` con `status` explícito sirve a un formulario de edición que manda el estado junto al nombre. Los dos acaban en el mismo campo y ninguno puede dejarlo inconsistente, porque es un booleano.
- **No:** filtrar los inactivos del listado por defecto. El listado devuelve todo y `?status=` filtra, igual que en SPEC 04 y SPEC 06. Un catálogo de administración necesita ver lo que dio de baja para poder reactivarlo.

**Autorización**

- **Sí:** lectura abierta a cualquier usuario autenticado, sin `carrier.required`. Es un dato de Legumex, común a todas las empresas transportistas; filtrar por empresa no significaría nada.
- **Sí:** escritura solo para `administrator` con `role:administrator`. Mismo criterio que SPEC 06.
- **Sí:** `registered_by` desde `auth('api')->user()`, nunca del body. Un campo de auditoría que el cliente puede fijar no audita nada.
- **Sí:** guardar `registered_by` aunque hoy solo escriba el `administrator`. Cuesta una columna y una FK, y es lo único que responde «¿quién metió esto?» cuando haya más de un administrador.

**Modelo y respuesta**

- **Sí:** orden fijo `id ASC`. Es un catálogo, se lee en el orden en que se dio de alta. Se descartó `name ASC` —que se propuso primero— y `created_at DESC` de las specs anteriores, que aquí solo mezclaría el orden sin aportar nada.
- **Sí:** paginación **opt-in** por `limit`, idéntica a SPEC 03, 04 y 06. Un `<select>` de productos quiere la lista entera; una tabla de administración manda `limit`. Las dos necesidades se cubren sin un parámetro obligatorio.
- **Sí:** búsqueda con el parámetro `search`, no `name`. Deja `name` libre por si algún día hace falta coincidencia exacta. Al comparar contra un `name` ya en mayúsculas, normalizar el término da búsqueda insensible a mayúsculas sin `LOWER()` ni collation especial.
- **Sí:** `createdAt` y `updatedAt` con el formato **`d-m-Y h:i:s A`**. **Rompe con el resto del proyecto**, que emite ISO 8601 crudo, y es deliberado: el cliente consume estas fechas para mostrarlas tal cual. La consecuencia asumida es que los dos campos se documentan en Swagger como `type: 'string'` sin `format: 'date-time'`, porque marcarlos como fecha haría que un cliente generado desde el schema intentara parsearlos como ISO y fallara.
- **Sí:** exponer `updatedAt`. Es lo más cercano a una auditoría que ofrece esta spec: dice cuándo cambió el producto, aunque no qué cambió.
- **No:** auditoría del valor anterior de `name` o de `status`. El `PATCH` sobrescribe sin dejar rastro. Si hace falta, es una tabla de histórico y una spec propia.
- **No:** relación inversa `User::products()`. Nadie necesita listar los productos que capturó un usuario.
- **No:** índice sobre `status`. Un booleano de dos valores sobre decenas de filas no se beneficia de un índice.
- **Sí:** FK `registered_by` sin `cascadeOnDelete`, igual que en SPEC 06.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| `toggle-status` capturado por el comodín `{product}` del `apiResource`, devolviendo un 404 confuso | Se declara antes del `apiResource`, como manda la convención del proyecto, y hay un criterio de aceptación que verifica el orden en `route:list` |
| Un `name` que esquiva la regla `unique` por diferencia de mayúsculas y revienta contra el índice con un 500 | Los dos FormRequests normalizan en `prepareForValidation()` **antes** de que corra la regla `unique`, y hay un criterio de aceptación que exige 422 —no 500— al mandar `brocoli` existiendo `BROCOLI` |
| Dos `POST` simultáneos con el mismo nombre pasan ambos la validación y uno choca contra el índice | Es el caso que el índice único existe para atrapar. Con un solo administrador dando de alta productos el escenario es improbable, y el resultado —un 500 en la segunda petición— es preferible a dos filas duplicadas |
| `status` llega al front como `1`/`0` en vez de `true`/`false`, porque SQLite y MySQL devuelven enteros | El cast `'status' => 'boolean'` en el modelo, con un criterio de aceptación que verifica el tipo JSON de la respuesta |
| Las fechas en `d-m-Y h:i:s A` no son parseables como ISO y un cliente generado desde el schema falla | Se documentan como `type: 'string'` sin `format: 'date-time'`, con el `example` mostrando el formato real. Es la contrapartida asumida de la decisión |
| El formato de fecha propio de este dominio se copia por inercia a specs futuras y el proyecto acaba con dos convenciones a medias | Queda registrado en decisiones como excepción explícita de `products`, no como el nuevo estándar |
| `search` sobre `name` sin índice hace un escaneo completo | Un catálogo de decenas de filas no lo nota. Si algún día crece a miles, se añade un índice en su propia spec |
| El `PATCH` con `status` y `toggle-status` divergen en comportamiento al implementarse por separado | Ambos escriben la misma columna booleana a través del mismo service; no hay estado intermedio posible en el que puedan discrepar |

---

## Lo que **no** entra en esta spec

- Cualquier columna más allá de `name` y `status`: categoría, unidad de medida, precio, peso por caja, código SKU.
- Imagen del producto.
- Relación con viajes, guías o vehículos.
- Catálogo por empresa transportista.
- Borrado real de un producto.
- Auditoría del valor anterior de `name` o de `status`.
- Importación masiva por CSV y alta o baja en lote.
- Orden configurable (`sortBy`, `sortDir`) y filtros por rango de fechas.
- Nombres en varios idiomas o alias de búsqueda.

Cada una de esas, si aterriza, va en su propia spec.
