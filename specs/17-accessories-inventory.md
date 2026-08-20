# SPEC 17 — Inventario de accesorios

> **Estado:** Implementado
> **Depende de:** SPEC 01
> **Fecha:** 2026-08-20
> **Objetivo:** Llevar un inventario nacional de accesorios —una fila por unidad física, identificada por un código único— con su precio, fecha de compra, estado operativo y porcentaje de depreciación anual, del que la API deriva el valor actual en cada lectura.

Depende de **SPEC 01** por el guard JWT, el enum `UserRole` y el middleware `role:`. No depende de SPEC 04: comparte la forma de `Vehicle` (estado de tres valores, baja lógica) pero no su tabla, su ámbito ni su código.

Es el primer catálogo nacional con **estado de tres valores** en vez del `status` booleano de `Product`, `Zone` y `Location`, y el primer recurso del proyecto que devuelve un **campo calculado en tiempo de lectura** (`currentValue`), sin columna que lo respalde.

---

## Alcance

**Dentro:**

- **Tabla nueva `accessories`** con `id`, `name`, `code`, `description`, `price`, `purchase_date`, `annual_depreciation`, `status`, `registered_by` y `timestamps`. No se toca ninguna tabla existente.
- **Enum nuevo `App\Enums\AccessoryStatus`** — `active`, `inactive`, `under_repair`. Mismos tres valores que `VehicleStatus`, pero enum propio: copiar tres casos es más barato que acoplar dos dominios sin relación.
- **Es un catálogo nacional.** No pertenece a ningún transportista ni a ningún vehículo: la tabla **no lleva `carrier_id` ni `vehicle_id`**. La ruta **no lleva `carrier.required`**.
- **Lectura abierta a cualquier autenticado** (`jwt.auth` a secas); **toda escritura es `role:administrator`**. Es la regla de SPEC 06–09 y 15.
- **Dominio `Accessory` completo** en su subcarpeta, con la cadena de capas del proyecto: `AccessoryServiceInterface`, `AccessoryService`, `AccessoryProvider`, `StoreAccessoryRequest`, `UpdateAccessoryRequest`, `AccessoryResource` y `AccessoryController`.
- **`routes/accessories.php`** incluido desde `routes/api.php`, declarado como `apiResource` sobre `'/'` con `->parameters(['' => 'accessory'])`, **CRUD completo**: `index`, `store`, `show`, `update`, `destroy`.
- **Una fila por unidad física.** Dos llantas iguales son dos registros con dos códigos distintos. **No hay columna `quantity`**.
- **`name` único y normalizado** con `Accessory::normalizeName()` (trim + colapsar espacios + mayúsculas), compartido por FormRequest y service, como `Product`, `Zone` y `Location`.
- **`code` único, obligatorio, normalizado y editable.** Es el identificador que teclea el usuario —código interno o número de serie—, distinto del `id` de la base. **Único global, sin importar el `status`**: a diferencia de la placa de un vehículo, un `inactive` **no** lo libera.
- **`currentValue`: campo derivado, calculado en cada lectura**, sin columna en la base y sin job que lo recalcule. Depreciación **lineal**, antigüedad en **fracción de años por días** (`días / 365`), con **piso en `0.00`**.
- **`annual_depreciation` es editable** por `PATCH`, como cualquier otro campo, y admite **`0`** (un accesorio que no se deprecia y siempre vale su precio).
- **`status` no se acepta en el alta**: el accesorio nace `active`. El `PATCH` lo mueve **libremente entre los tres valores**, sin reglas de transición.
- **`DELETE` es baja lógica**: pasa el `status` a `inactive`, la fila sigue viva y sigue apareciendo en los listados. Es la regla de SPEC 04, no la de los catálogos booleanos.
- **No hay ruta `/{accessory}/toggle-status`.** Con tres estados, un toggle no significa nada: el cambio de estado se hace por `PATCH`.
- **Filtros tolerantes del listado:** `status` (uno de los tres; un valor inválido **se ignora**) y `search` (`LIKE %term%` sobre `name` **y** `code`, ambos ya en mayúsculas). Orden fijo `id ASC`.
- **Paginación opt-in con `limit`**, acotada a `[10, 100]` y envuelta en `PaginatedResource`, como el resto del proyecto.
- **`registered_by` sale del usuario autenticado**, nunca del body, y **no se reescribe** en el `update`.
- **`price` y `purchase_date` son obligatorios** en el alta: `price` con `min:0.01` y `purchase_date` con `before_or_equal:today`.
- Tests Pest y documentación Swagger delegados a los agentes `feature-tests` y `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **Imagen del accesorio.** Se evaluó —el proyecto ya tiene `FileStorageServiceInterface`— y se dejó fuera a propósito. Va en otra spec.
- **Asignar el accesorio a un vehículo o a un transportista.** No hay `vehicle_id` ni `carrier_id`, ni endpoint de asignación, ni historial de a qué unidad estuvo montado.
- **Cantidad y control de existencias.** Una fila es una unidad; no hay `quantity`, ni entradas, ni salidas, ni mínimos de stock.
- **Filtrar u ordenar por `currentValue`.** No es columna, así que la base no puede hacerlo.
- **Valor residual configurable, depreciación por saldo decreciente y método seleccionable por accesorio.** El método es lineal para todos y está fijado en el código.
- **Reportes:** valor total del inventario, depreciación acumulada del período, proyección a N años, desglose por estado.
- **Bitácora de cambios.** El `PATCH` no deja rastro; no hay historial de precios, de estado ni de depreciación.
- **Gastos de mantenimiento del accesorio.** SPEC 14 es de vehículos y **no se toca**: `vehicle_expenses` no gana un `accessory_id`.
- **Categorías o tipos de accesorio.** Hoy la distinción cabe en `name` y `description`.
- **Borrado real.** El `DELETE` es baja lógica; no hay forma de eliminar una fila por la API.
- **Exportación a CSV o Excel.**
- **Moneda configurable.** GTQ es convención del dominio, documentada en Swagger.

---

## Modelo de datos

Esta spec **no toca ninguna tabla existente**. Crea una tabla, un enum y un modelo.

### 1. Tabla `accessories`

```php
Schema::create('accessories', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();
    $table->string('code')->unique();
    $table->text('description')->nullable();
    $table->decimal('price', 10, 2);
    $table->date('purchase_date');
    $table->decimal('annual_depreciation', 5, 2)->default(0);
    $table->string('status')->default('active');
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();

    $table->index('status');
});
```

- **`description` es la única columna nullable** de negocio. Se borra mandando `null` explícito (por eso el service usa `array_key_exists`, no `isset`, como `Zone::description`).
- `name` y `code` llevan **índice único de verdad**, y aun así el service revalida con `ensureNameIsAvailable()` y `ensureCodeIsAvailable()` para que una colisión dé **400 con mensaje en español** y no un 500 de Postgres. Es la regla de SPEC 07–09 y 15.
- `price` es `decimal(10,2)` — hasta 99 999 999.99 GTQ. Nada de flotantes con dinero.
- `annual_depreciation` es `decimal(5,2)` y se valida en `[0, 100]`. El `default(0)` es relleno de migración, no negocio: por la API el campo es obligatorio en el alta.
- `purchase_date` es `date`, no `datetime`: interesa el día. Los `timestamps` guardan cuándo se capturó, que es otra cosa.
- `status` es `string` con `default('active')`, no `enum` de Postgres, igual que `type` y `status` en `vehicles`: añadir un caso es tocar PHP, no migrar la base.
- La FK a `users` **no** cascadea: borrar al usuario que registró un accesorio no puede borrar el accesorio.
- **No hay columna para `currentValue`.** Ver el punto 5.

### 2. `App\Enums\AccessoryStatus`

```php
enum AccessoryStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case UnderRepair = 'under_repair';
}
```

Enum **plano**, sin método `label()`, como `VehicleStatus`. La etiqueta en español la pone el front; la API habla en snake_case. Es un enum **propio**, no un alias de `VehicleStatus`: los valores coinciden hoy por casualidad y los dos dominios deben poder divergir.

### 3. Modelo `Accessory`

```php
#[Fillable([
    'name', 'code', 'description', 'price',
    'purchase_date', 'annual_depreciation', 'status', 'registered_by',
])]
class Accessory extends Model
{
    public static function normalizeName(?string $name): ?string;   // trim + colapsar espacios + mayúsculas
    public static function normalizeCode(?string $code): ?string;   // trim + mayúsculas

    public function registeredBy(): BelongsTo;                      // User

    protected function casts(): array
    {
        return [
            'status' => AccessoryStatus::class,
            'price' => 'decimal:2',
            'annual_depreciation' => 'decimal:2',
            'purchase_date' => 'date',
        ];
    }
}
```

`normalizeName()` es la misma función que ya tienen `Product`, `Zone` y `Location`. `normalizeCode()` es hermana pero **no colapsa espacios interiores**: un código es un identificador, no una frase, y `A 100` y `A100` son códigos distintos que no deben fundirse.

### 4. Cálculo de `currentValue`

Depreciación **lineal**, antigüedad en **fracción de años**, piso en `0.00`:

```
años         = purchase_date->diffInDays(today) / 365
depreciado   = price × (annual_depreciation / 100) × años
currentValue = round(max(0, price − depreciado), 2)
```

- **Lineal, no saldo decreciente.** Es el método estándar de activos fijos y el usuario puede verificarlo mentalmente.
- **Fracción de días, no años cumplidos.** Si contara años enteros, el valor daría un salto de escalón cada aniversario y no se movería en los once meses intermedios.
- **Piso en `0.00`.** Un 20% anual a los seis años daría −2 000, que no significa nada. Nunca sale un negativo.
- **El día de la compra `currentValue == price`**, porque la antigüedad es 0.
- **`annual_depreciation = 0` ⇒ `currentValue == price` para siempre.**
- **365 días fijos**, sin corrección por año bisiesto: la diferencia es de horas sobre una cifra que ya es una convención contable.

### 5. Dónde vive el cálculo

**En `AccessoryResource`, y en ningún otro lado.** No hay columna, no hay job programado, no hay caché.

- Se computa en **cada lectura**, así que nunca queda desfasado: el mismo accesorio consultado hoy y dentro de un mes devuelve dos valores distintos sin que nadie escriba en la base.
- Sale **tanto en el listado como en el detalle**: es aritmética sobre campos ya cargados, no una consulta extra.
- **No se puede filtrar ni ordenar por `currentValue`.** La base no conoce el campo. Es el precio de no tener columna, y está asumido.

### 6. Forma de la respuesta

`AccessoryResource`, en camelCase como todo el proyecto:

```json
{
  "id": 12,
  "name": "GATO HIDRÁULICO 20 TON",
  "code": "ACC-0012",
  "description": "Gato de botella, comprado en Ferretería El Tornillo",
  "price": "10000.00",
  "purchaseDate": "20-08-2024",
  "annualDepreciation": "20.00",
  "currentValue": "6000.00",
  "status": "active",
  "registeredBy": "Roberto Santizo",
  "createdAt": "20-08-2024 09:14:33 AM"
}
```

`currentValue` sale como **string de dos decimales**, igual que `price`: es dinero, y el proyecto no manda dinero como float. `registeredBy` es el **nombre** del usuario, no su id, como en SPEC 14. Las fechas van en `d-m-Y` y `d-m-Y h:i:s A`.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo.

1. **Enum.** Crear `app/Enums/AccessoryStatus.php` con los tres casos. Nada más lo usa todavía.

2. **Migración y modelo.** `php artisan make:model Accessory -mf`. Rellenar la migración con las diez columnas, los dos índices únicos y el índice de `status`; el modelo con su `#[Fillable]`, `normalizeName()`, `normalizeCode()`, la relación `registeredBy()` y sus cuatro casts; la factory con datos coherentes (`price` entre 500 y 50 000, `purchase_date` en los últimos tres años, `annual_depreciation` entre 0 y 30, `code` único). Correr `php artisan migrate`.

3. **Contrato.** `app/Interfaces/Accessory/AccessoryServiceInterface.php` con los cinco métodos y su PHPDoc de array shapes: `getAccessories`, `createAccessory`, `getAccessoryById`, `updateAccessory`, `deleteAccessory`.

4. **Service, parte de lectura.** `app/Services/Accessory/AccessoryService.php` con `resolvePerPage()` (constantes `MIN_PER_PAGE` / `MAX_PER_PAGE`), `getAccessories()` —orden `id ASC`, filtros tolerantes `status` y `search` sobre `name` y `code`, paginación opt-in— y `getAccessoryById()`, que devuelve 404 si el id no existe.

5. **Service, parte de escritura.** `createAccessory()`, `updateAccessory()` y `deleteAccessory()`, más las dos guardas `ensureNameIsAvailable($name, $ignoreId)` y `ensureCodeIsAvailable($code, $ignoreId)`, que lanzan `BadRequestError`. El alta fuerza `status = active` e ignora cualquier `status` del body; `registered_by` sale de `auth('api')->user()` y el `update` no lo toca; `deleteAccessory()` solo pone `status = inactive`. `#[Override]` en cada método.

6. **Provider.** `app/Providers/Accessory/AccessoryProvider.php` con el `bind(AccessoryServiceInterface::class, AccessoryService::class)`, registrado en `bootstrap/providers.php`.

7. **FormRequests.** `StoreAccessoryRequest` (`name` y `code` `required|string|max:255` normalizados en `prepareForValidation()` con `unique:accessories`, `description` `nullable|string|max:1000`, `price` `required|numeric|min:0.01|max:99999999.99`, `purchase_date` `required|date|before_or_equal:today`, `annual_depreciation` `required|numeric|min:0|max:100`, **sin `status`**) y `UpdateAccessoryRequest` (los seis como `sometimes`, más `status` con `Rule::enum(AccessoryStatus::class)`, y los `unique` con `ignore` del id de la ruta). `messages()` en español en ambos.

8. **Resource, controller y rutas.** `AccessoryResource` con `currentValue` calculado según la fórmula del modelo de datos; `AccessoryController` con los cinco métodos, `try/catch` → `ResponseHandler` y el service inyectado por parámetro; `routes/accessories.php` con el `apiResource` sobre `'/'`, `->parameters(['' => 'accessory'])`, `jwt.auth` en el grupo, `role:administrator` en `store`, `update` y `destroy`, y **ninguna ruta fija** que declarar antes; `require` en `routes/api.php`. Verificación: `php artisan route:list --path=accessories` muestra las cinco rutas.

9. **Tests.** Disparar el agente `feature-tests` sobre `Accessory`: Feature test de los cinco endpoints, los cuatro roles, la unicidad de `name` y `code`, los filtros, la baja lógica y el `currentValue` en sus casos frontera; Unit test del service. Añadir un Unit test propio del cálculo de `currentValue` sobre el Resource (día de la compra, mitad de año, depreciación 0, valor pasado de cero). Correr `php artisan test --compact --filter=Accessory`.

10. **Documentación.** Disparar el agente `endpoint-docs` sobre `Accessory` y regenerar `storage/api-docs/api-docs.json`.

11. **Cierre.** `vendor/bin/pint --dirty --format agent` y escribir `references/accessories-api.md` para el frontend, con `references/zones-api.md` como plantilla.

---

## Criterios de aceptación

**Migración y modelo**

- [ ] `php artisan migrate` crea `accessories` con las diez columnas, los índices únicos de `name` y `code` y el índice de `status`.
- [ ] Ninguna tabla existente cambia.
- [ ] `Accessory::factory()->create()` produce una fila válida sin argumentos, y `->count(20)` no colisiona en `name` ni en `code`.
- [ ] `Accessory::normalizeName('  gato   hidráulico ')` devuelve `'GATO HIDRÁULICO'`.
- [ ] `Accessory::normalizeCode('  acc-12 ')` devuelve `'ACC-12'`, y `normalizeCode('a 100')` devuelve `'A 100'` (no colapsa el espacio interior).

**Alta**

- [ ] `POST /api/accessories` con `name`, `code`, `price`, `purchaseDate` y `annualDepreciation` devuelve 201 y el accesorio creado.
- [ ] Omitir cualquiera de esos cinco campos devuelve 422 con el mensaje en español.
- [ ] `description` es opcional: omitirla devuelve 201 y el campo sale `null`.
- [ ] El accesorio nace con `status = "active"` aunque el body mande `"under_repair"`.
- [ ] `registered_by` queda con el id del usuario autenticado aunque el body mande otro.
- [ ] `name` duplicado devuelve 400 (o 422 desde el FormRequest), nunca 500.
- [ ] `code` duplicado devuelve 400 (o 422 desde el FormRequest), nunca 500.
- [ ] Un `code` duplicado contra un accesorio `inactive` **también** se rechaza: la baja lógica no libera el código.
- [ ] `name` y `code` quedan guardados en mayúsculas aunque se manden en minúsculas.
- [ ] `price = 0` devuelve 422; `price = 0.01` se acepta.
- [ ] `purchaseDate` de mañana devuelve 422; la de hoy se acepta.
- [ ] `annualDepreciation = 0` se acepta; `= 100` se acepta; `= 100.01` y `= -1` devuelven 422.

**`currentValue`**

- [ ] Con `price = 10000`, `annualDepreciation = 20` y `purchaseDate` de hace exactamente 2 años, `currentValue` es `"6000.00"`.
- [ ] Con `purchaseDate` de hoy, `currentValue` es igual a `price`.
- [ ] Con `annualDepreciation = 0`, `currentValue` es igual a `price` sin importar la antigüedad.
- [ ] Con `price = 10000`, `annualDepreciation = 20` y 6 años de antigüedad, `currentValue` es `"0.00"`, nunca negativo.
- [ ] Con 6 meses de antigüedad, `currentValue` refleja media anualidad: la fracción se cuenta por días, no por años cumplidos.
- [ ] `currentValue` aparece en el listado **y** en el detalle, siempre como string de dos decimales.
- [ ] Ninguna columna de la base guarda `currentValue`: cambiar la fecha del sistema cambia el valor devuelto sin escribir nada.

**Listado**

- [ ] `GET /api/accessories` devuelve todos los accesorios ordenados por `id` ascendente.
- [ ] Los `inactive` **siguen apareciendo** en el listado sin filtro.
- [ ] `status=under_repair` devuelve solo los que están en reparación; `status=inexistente` devuelve el listado completo sin error.
- [ ] `search` encuentra por `name` y también por `code`, en minúsculas o mayúsculas indistintamente.
- [ ] Sin `limit` la respuesta es la colección completa, sin `total`, `currentPage` ni `lastPage`.
- [ ] `limit=5` pagina de 10 en 10 y `limit=500` de 100 en 100.

**Detalle, edición y baja**

- [ ] `GET /api/accessories/{id}` devuelve el accesorio; un id inexistente devuelve 404.
- [ ] `PATCH` con un solo campo cambia solo ese campo y deja los demás intactos.
- [ ] `PATCH` con `status` mueve el accesorio entre los tres valores en cualquier dirección, incluido `inactive` → `active`.
- [ ] `PATCH` con un `status` fuera del enum devuelve 422.
- [ ] `PATCH` de `annualDepreciation` cambia el `currentValue` devuelto en la misma respuesta.
- [ ] `PATCH` con `name` o `code` ya usados por **otro** accesorio devuelve 400; mandar el **propio** valor sin cambios se acepta.
- [ ] `PATCH` con `description: null` borra la descripción; omitir el campo la deja intacta.
- [ ] `PATCH` hecho por otro administrador deja `registered_by` con el usuario original.
- [ ] `PATCH` con body vacío devuelve 200 y no cambia nada.
- [ ] `DELETE` devuelve 200, deja la fila en la base con `status = "inactive"` y no borra nada.
- [ ] Un segundo `DELETE` devuelve 200 y el accesorio sigue `inactive`.

**Roles**

- [ ] Sin token, los cinco endpoints devuelven 401.
- [ ] Un `administrator` hace las cinco operaciones.
- [ ] Un `carrier`, un `pilot` y un `manager` leen (`index`, `show`) con 200 y reciben 403 en `store`, `update` y `destroy`.
- [ ] Un usuario autenticado **sin empresa** lee sin problema: la ruta no lleva `carrier.required`.

**Integración y calidad**

- [ ] `php artisan route:list --path=accessories` muestra exactamente cinco rutas y ninguna `toggle-status`.
- [ ] Ningún test de specs anteriores cambia ni falla.
- [ ] `php artisan test --compact` pasa completo.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `/api/documentation` muestra los cinco endpoints con sus schemas de request y response.
- [ ] Existe `references/accessories-api.md` con el contrato para el frontend.

---

## Decisiones

**Forma del dominio**

- **Sí:** catálogo nacional, sin `carrier_id`. El inventario de accesorios es de la operación, no de cada transportista; meter un dueño obligaría a decidir el ámbito por rol en cinco endpoints para una pregunta que nadie hizo.
- **Sí:** lectura para cualquier autenticado y escritura solo `administrator`. Es la regla que ya comparten `FuelPrice`, `Product`, `Zone`, `Location` y `FreightRate`.
- **Sí:** CRUD completo con `show` incluido. Cuesta lo mismo y el front lo quiere para el modal de edición.
- **No:** anidar bajo un vehículo (`/api/vehicles/{vehicle}/accessories`). El accesorio no pertenece a una unidad, y la asignación quedó fuera de alcance.
- **Sí:** una fila por unidad física. El `code` único lo exige: con una columna `quantity`, dos unidades compartirían serial y el identificador dejaría de identificar.

**Identificador**

- **Sí:** `code` obligatorio, único global, normalizado a mayúsculas y **editable**. Es el número que la gente lee en la etiqueta; poder corregir un typo sin borrar y recrear el registro es lo que se espera.
- **No:** llamarlo `serialNumber`. No siempre será un serial de fábrica; un código interno inventado por la empresa es igual de válido.
- **No:** dejarlo nullable. Un accesorio sin código no se puede buscar en bodega, y un `unique` con muchos `NULL` invita a una tabla llena de huecos.
- **Sí:** único **siempre**, sin importar el `status`. Es la diferencia deliberada con la placa de `Vehicle`, donde un `inactive` la libera: una placa se reasigna, un número de serie no.
- **Sí:** `normalizeCode()` no colapsa espacios interiores, a diferencia de `normalizeName()`. `A 100` y `A100` son códigos distintos y fundirlos perdería información.
- **Sí:** índice único en base **y** revalidación en el service. Una llamada directa al service debe dar 400 con mensaje, no un 500 de Postgres. Es la convención de SPEC 07–09.

**Estado**

- **Sí:** enum propio `AccessoryStatus` con los mismos tres valores que `VehicleStatus`. Copiar tres casos cuesta menos que acoplar dos dominios que hoy coinciden por casualidad y mañana pueden divergir.
- **No:** reutilizar `VehicleStatus` directamente. Un accesorio no es un vehículo, y el primer valor que le sobre o le falte a uno rompería al otro.
- **No:** `status` booleano como el resto de los catálogos. Son tres estados reales, y `en reparación` no es ni activo ni dado de baja.
- **No:** ruta `/{accessory}/toggle-status`. Con tres valores no hay nada que alternar; el `PATCH` ya cambia el estado.
- **Sí:** `status` **no** se acepta en el alta; el accesorio nace `active`. Es la regla de `Vehicle` en SPEC 04 y 13.
- **Sí:** el `PATCH` mueve el estado libremente entre los tres, sin reglas de transición. Una máquina de estados aquí solo bloquearía correcciones legítimas de captura.
- **Sí:** `DELETE` como baja lógica a `inactive`, no borrado real y no idempotente sobre una columna booleana. Un accesorio dado de baja sigue teniendo historia y sigue apareciendo en el listado, como el vehículo.

**Depreciación**

- **Sí:** un solo número, el **porcentaje anual**, ingresado por el usuario. Es lo que el contador ya tiene en su hoja.
- **No:** guardar vida útil en años y derivar el porcentaje. Es la misma información con un paso de traducción de más, y `0%` no tendría equivalente.
- **Sí:** editable por `PATCH`. Un porcentaje mal tecleado en el alta debe poder corregirse; recrear el registro perdería el `id` y la fecha de captura.
- **Sí:** se permite `0`. Hay activos que no se deprecian, y forzar un mínimo obligaría a inventar un `0.01` falso.
- **Sí:** método **lineal**. Es el estándar de activos fijos y el usuario puede verificar el resultado mentalmente.
- **No:** saldo decreciente. Es más fiel para algunos activos, pero nunca llega a cero y nadie lo pidió; si aterriza, será junto al método seleccionable por accesorio, en otra spec.
- **No:** valor residual configurable. Sería una columna más y un campo más en el alta para un caso que hoy no existe; el piso en `0.00` cubre la necesidad.
- **Sí:** antigüedad en **fracción de años por días**. Contar años cumplidos haría que el valor no se moviera durante once meses y saltara de golpe en el aniversario.
- **Sí:** 365 días fijos, sin corregir por bisiesto. La diferencia son horas sobre una cifra que ya es una convención.

**`currentValue`**

- **Sí:** campo **derivado en el Resource**, sin columna. Un valor calculado que se guarda queda obsoleto al día siguiente y obliga a un job nocturno que puede fallar en silencio.
- **No:** columna `current_value` recalculada por comando programado. Introduce estado que puede desincronizarse, y el cálculo es una multiplicación: no hay nada que optimizar.
- **No:** columna generada por Postgres. Dependería de `CURRENT_DATE` dentro de la base, que no es inmutable y por tanto no es indexable ni portable.
- **Sí:** sale en el listado y en el detalle. Es aritmética sobre campos ya cargados, no una consulta extra por fila.
- **Sí:** como string de dos decimales, igual que `price`. Es dinero, y el proyecto no manda dinero como float.
- **Sí:** no se puede filtrar ni ordenar por él, y se asume. Es el precio de no tener columna; cuando exista un reporte que lo necesite, tendrá su spec y decidirá si materializar.
- **Sí:** el piso en `0.00` vive en el mismo sitio que el resto de la fórmula. Un `currentValue` negativo no significa nada para quien lo lee.

**Campos**

- **Sí:** `name` único y normalizado, además del `code`. Son dos ejes distintos: el `code` identifica la unidad, el `name` dice qué es. Sin unicidad en el nombre, el mismo accesorio acabaría escrito de cinco formas.
- **Sí:** `description` opcional y de tipo `text`, borrable mandando `null`. Es donde caben el proveedor, la factura y el detalle, que no tienen columna propia.
- **Sí:** `price` con `min:0.01`. Un accesorio de cero es un error de captura, o un regalo que no se deprecia.
- **Sí:** `purchase_date` de tipo `date` y `before_or_equal:today`. Interesa el día, y un accesorio se registra cuando ya se compró; una compra futura es una orden de compra, que no existe en este proyecto.
- **Sí:** `registered_by` del usuario autenticado y sin reescribir en el `update`. Es la convención de todos los catálogos.
- **Sí:** `registeredBy` sale como el nombre en un string, no como objeto con `id`, igual que en SPEC 14.
- **No:** imagen del accesorio. El proyecto ya tiene `FileStorageServiceInterface` y meterlo era barato, pero añade ciclo de vida de archivos a un recurso que aún no lo necesita.
- **No:** categoría o tipo de accesorio. Hoy la distinción cabe en `name`; un enum cerrado o un catálogo administrable son un dominio entero para una agrupación que nadie consulta todavía.

**Listado**

- **Sí:** filtros tolerantes — un `status` inválido se ignora. Es la convención de SPEC 06–09; un 422 aquí rompería la pantalla por un parámetro que el front manda vacío.
- **Sí:** `search` sobre `name` **y** `code`. Quien busca en bodega tiene delante la etiqueta, no el nombre del catálogo.
- **Sí:** los `inactive` siguen listándose sin filtro, como los vehículos dados de baja. Ocultarlos haría que un accesorio desapareciera sin que nadie lo borrara.
- **Sí:** orden `id ASC` y paginación opt-in con `limit`, como `Product` y `Location`.
- **No:** un acumulado del valor del inventario en la raíz del sobre, al estilo del `totalAmount` de SPEC 14. Sería la suma de un campo que no existe en la base: habría que traer todas las filas y sumar en PHP. Es un reporte, y va en su spec.

**Rastro**

- **No:** bitácora de cambios, como la de salarios de SPEC 11. El salario es un compromiso con una persona; el precio de un gato hidráulico es un dato de catálogo, y auditarlo costaría más que corregirlo.
- **No:** `SoftDeletes`. La baja lógica ya la hace el `status`, y añadir un segundo mecanismo de "no está" obligaría a decidir cuál manda en cada consulta.

---

## Riesgos

| Riesgo | Mitigación |
| --- | --- |
| `currentValue` se calcula en cada lectura y el front lo guarda en caché o lo manda de vuelta en un `PATCH` | El campo es de **solo salida**: los FormRequests no lo aceptan y mandarlo se ignora en silencio. Queda documentado en Swagger y en `references/accessories-api.md` como derivado, junto con la fórmula exacta. |
| El mismo accesorio devuelve un `currentValue` distinto cada día y alguien lo reporta como bug | Es el comportamiento buscado: el valor depende de la fecha de consulta. Un criterio de aceptación lo fija explícitamente y la referencia para el front lo dice en la primera línea del campo. |
| La fórmula queda escrita en el Resource y alguien la reimplementa distinta en el front | La referencia para el frontend lleva la fórmula y los cuatro casos frontera con números concretos. El Unit test del cálculo es el contrato ejecutable. |
| Un accesorio dado de baja mantiene ocupado su `code` para siempre y nadie puede reutilizarlo | Es la decisión tomada: un número de serie no se reasigna. Si algún día hace falta liberar códigos, será una regla explícita como `ensurePlateIsAvailable()` en vehículos, no un descuido. |
| `AccessoryStatus` y `VehicleStatus` tienen los mismos tres valores y alguien los unifica "de paso" | Los dos enums viven en archivos separados y ningún código importa el del otro dominio. La decisión y su motivo están escritos arriba. |
| Con muchos accesorios, un reporte del valor total del inventario obligará a traer todas las filas y sumar en PHP | Asumido: ese reporte está fuera de alcance. Cuando aterrice, su spec decidirá si materializa `current_value` en una columna con un job, que es justo la complejidad que esta spec evita. |
| El índice único de `name` choca con la normalización y dos nombres que difieren solo en espacios acaban rechazados | Es el comportamiento buscado y el mismo de `Product`, `Zone` y `Location`: `normalizeName()` corre en el FormRequest **antes** de validar, así que el usuario ve un 422/400 con mensaje en español, no un 500. |

---

## Lo que **no** entra en esta spec

- **Imagen del accesorio.** Va en otra spec; este dominio no usa almacenamiento de archivos.
- **Asignar el accesorio a un vehículo o a un transportista**, ni historial de asignaciones.
- **Cantidad y control de existencias.** Una fila es una unidad; no hay `quantity`, entradas, salidas ni mínimos de stock.
- **Filtrar u ordenar por `currentValue`**, ni sumarlo en la raíz del sobre.
- **Valor residual, saldo decreciente y método de depreciación seleccionable.**
- **Reportes:** valor total del inventario, depreciación acumulada, proyecciones, desglose por estado.
- **Bitácora de cambios.** El `PATCH` no deja rastro.
- **Gastos de mantenimiento del accesorio.** SPEC 14 no se toca.
- **Categorías o tipos de accesorio.**
- **Borrado real.** El `DELETE` es baja lógica, siempre.
- **Exportación a CSV o Excel.**
- **Moneda configurable.** GTQ es convención del dominio.

Cada uno de estos, si aterriza, va en su propia spec.
