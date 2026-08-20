# SPEC 18 — Características de accesorios

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 17
> **Fecha:** 2026-08-20
> **Objetivo:** Permitir que cada accesorio del inventario nacional lleve un número libre de características propias en forma de pares nombre/valor —uno puede tener «PLACA» y otro «TIPO DE COMBUSTIBLE»—, expuestas en su propio dominio CRUD cuyo listado exige siempre un `accessoryId`.

Depende de **SPEC 01** por el guard JWT, el enum `UserRole` y el middleware `role:`, y de **SPEC 17** por la tabla `accessories`, de la que cuelga cada fila y que decide con su existencia si el listado responde o da 404.

Es la **segunda vez que un recurso cuelga de otro sin ruta anidada** —la primera fue SPEC 14 con los gastos de vehículo—, y reutiliza ese patrón entero: el vínculo viaja en el cuerpo (`accessoryId`) y en el query param obligatorio del índice, no en la URL. Es también el primer dominio del proyecto cuyo **conjunto de campos no está fijado por el esquema**: dos accesorios pueden describirse con listas de características completamente distintas sin migrar nada.

**Lo que esta spec no es:** un catálogo nacional de características. No existe una tabla de nombres permitidos ni un tipo declarado por característica; el nombre se teclea libre y el valor es siempre texto. `AccessoryResource` **no cambia de forma**: el inventario se lee como hasta ahora y las características se piden aparte.

---

## Alcance

**Dentro:**

- **Tabla nueva `accessory_characteristics`** con `id`, `accessory_id`, `name`, `value`, `registered_by` y `timestamps`. **No se toca ninguna tabla existente**, ni `accessories` ni ninguna otra.
- **Ningún enum nuevo.** El valor es texto libre: no hay tipo declarado, no hay lista de nombres permitidos y no hay `status` — la tabla no lleva estado de ninguna clase.
- **Es un dominio nacional**, como el inventario del que cuelga: **no lleva `carrier_id` ni `vehicle_id`** y **ninguna ruta lleva `carrier.required`**.
- **Dominio `AccessoryCharacteristic` completo** en su subcarpeta, con la cadena de capas del proyecto: `AccessoryCharacteristicServiceInterface`, `AccessoryCharacteristicService`, `AccessoryCharacteristicProvider`, `IndexAccessoryCharacteristicRequest`, `StoreAccessoryCharacteristicRequest`, `UpdateAccessoryCharacteristicRequest`, `AccessoryCharacteristicResource` y `AccessoryCharacteristicController`.
- **Cinco rutas planas bajo `/api/accessory-characteristics`**, declaradas con el `apiResource('/')->parameters(['' => 'accessoryCharacteristic'])` de los catálogos. **Ninguna ruta anidada**: no existe `/api/accessories/{accessory}/characteristics`.
- **`accessoryId` obligatorio en el índice**, como el `vehicleId` de SPEC 14: `GET /api/accessory-characteristics` sin él es **422** (formato de validación de Laravel, no el sobre habitual), y con un id inexistente es **404**, no lista vacía. Lleva FormRequest propio para el índice, el segundo del proyecto.
- **Lectura (`index`, `show`) abierta a cualquier autenticado** (`jwt.auth` a secas); **`store`, `update` y `destroy` solo `role:administrator`**. Misma regla que SPEC 17.
- **Unicidad de `name` por accesorio**: índice único `(accessory_id, name)` y guarda `ensureNameIsAvailable($accessoryId, $name, $ignoreId)` que devuelve **400 en español** en vez del 500 de Postgres. Dos accesorios distintos pueden tener ambos «PLACA».
- **Normalización asimétrica**: `name` pasa por `Accessory::normalizeName()` (trim + colapsar espacios + MAYÚSCULAS); `value` **solo `trim`**, tal como lo teclea el usuario.
- **`accessory_id` inmutable** en el `PATCH`; `name` y `value` sí se editan. `registered_by` sale del usuario autenticado y **no se reescribe** en el `update`.
- **`DELETE` es borrado físico**: la fila desaparece de la tabla. No hay baja lógica, no hay `SoftDeletes` y no hay bitácora de cambios.
- **El `status` del accesorio no importa**: uno `inactive` o `under_repair` lista, acepta y edita características igual.
- Paginación **opt-in por `limit`** acotada a `[10, 100]`, orden fijo **`id ASC`**, y **ningún filtro** más allá del `accessoryId` obligatorio.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.
- Resumen de integración para el frontend en `references/accessory-characteristics-api.md`.

**Fuera de alcance (para specs futuras):**

- **Catálogo nacional de características.** No hay tabla de nombres permitidos, ni administración de un diccionario, ni autocompletado servido por la API. Que «PLACA» y «Placa del remolque» convivan es responsabilidad de quien las teclea.
- **Tipos de valor.** Todo es texto: ni `number`, ni `date`, ni `boolean`, ni unidades, ni validación por tipo. Un valor «doce» y un valor «12» son igual de válidos.
- **Características obligatorias por tipo de accesorio.** Ningún accesorio exige tener «PLACA»; se puede dar de alta sin ninguna característica y quedarse así para siempre.
- **Alta en lote.** Un `POST` crea una fila. Tres características son tres llamadas, sin transacción que las agrupe ni endpoint de sincronización.
- **Cambios en `AccessoryResource`.** El inventario **no** gana una clave `characteristics` ni un contador, `Accessory` **no** gana relación `characteristics()` expuesta por la API, y `GET /api/accessories` no cambia de forma ni de coste.
- **Filtrar o buscar accesorios por característica.** No existe «dame los accesorios cuya PLACA sea P-123ABC»: el índice va siempre en la dirección accesorio → características.
- **Búsqueda dentro del listado** (`search` sobre `name` o `value`) y orden configurable.
- **Historial de cambios.** Editar el valor pisa el anterior sin dejar rastro, y borrar no deja tumba.
- **Copiar o heredar características** entre accesorios, plantillas por tipo de pieza e importación masiva.
- **Archivos adjuntos** a una característica (foto de la placa, factura). El valor es una cadena.

---

## Modelo de datos

Esta spec **no toca ninguna tabla existente**. Crea una tabla y un modelo, y ningún enum.

### 1. Tabla `accessory_characteristics`

```php
Schema::create('accessory_characteristics', function (Blueprint $table) {
    $table->id();
    $table->foreignId('accessory_id')->constrained('accessories');
    $table->string('name');
    $table->string('value', 500);
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();

    $table->unique(['accessory_id', 'name']);
});
```

- **Ninguna columna es nullable.** Las cuatro de negocio son obligatorias: una característica sin valor no informa de nada, y no hay `status` porque la baja es física.
- El **índice único compuesto `(accessory_id, name)`** es la unicidad de verdad, y aun así el service revalida con `ensureNameIsAvailable()` para que una colisión dé **400 en español** y no un 500 de Postgres. Es la regla de SPEC 07–09, 15 y 17.
- **No hace falta un índice suelto sobre `accessory_id`**: el único compuesto lo lleva de primera columna y sirve al filtro del listado, que es la única consulta del dominio.
- `value` es `string(500)`, no `text`: es un dato corto (una placa, un tipo de combustible), y el límite de la base coincide con el de la validación.
- **Ninguna FK cascadea.** La de `accessories` no, porque el `DELETE` de accesorios es baja lógica y la cascada nunca dispararía; si algún día alguien borra un accesorio de verdad, la restricción debe **fallar ruidosamente** en vez de llevarse filas por delante en silencio. La de `users` tampoco: borrar a quien capturó una característica no puede borrar la característica.

### 2. Modelo `AccessoryCharacteristic`

```php
#[Fillable(['accessory_id', 'name', 'value', 'registered_by'])]
class AccessoryCharacteristic extends Model
{
    public static function normalizeName(string $name): string;    // trim + colapsar espacios + MAYÚSCULAS
    public static function normalizeValue(string $value): string;  // trim, y nada más

    public function accessory(): BelongsTo;      // Accessory
    public function registeredBy(): BelongsTo;   // User
}
```

- **No tiene método `casts()`.** Es el primer modelo del proyecto sin ninguno: no hay enum, ni dinero, ni fecha propia. Los `timestamps` los castea Eloquent solo.
- `normalizeName()` es una **copia** de la de `Accessory`, no una llamada a ella. El proyecto ya repite esa función en `Product`, `Zone`, `Location` y `Accessory`: cada modelo es dueño de su normalización y puede divergir sin arrastrar a los demás.
- `normalizeValue()` es deliberadamente **asimétrica** con `normalizeName()`: solo recorta los extremos. El nombre es un identificador y por eso se colapsa y se sube a mayúsculas —lo que hace la unicidad insensible a mayúsculas sin depender del collation—; el valor es **contenido del usuario** y subirlo a mayúsculas lo estropearía («Diésel» no es «DIÉSEL»). El precio de esa decisión está asumido: sin búsqueda por valor, no hay nada que se vuelva sensible a mayúsculas.
- Las dos funciones las comparten el FormRequest —que las necesita antes de que corra la regla `unique`— y el service, que las aplica justo antes de persistir.

### 3. Forma de la respuesta

`AccessoryCharacteristicResource`, en camelCase como todo el proyecto:

```json
{
  "id": 4,
  "accessoryId": 12,
  "name": "PLACA",
  "value": "P-123ABC",
  "registeredBy": "Roberto Santizo",
  "createdAt": "20-08-2026 09:14:33 AM"
}
```

- `accessoryId` sale como **número**, no como objeto anidado: el cliente ya tiene el accesorio, porque tuvo que mandar su id para llegar hasta aquí. **No hay `accessoryName`**, ni `accessory` embebido, ni `updatedAt`.
- `registeredBy` es el **nombre** del usuario, no su id, como en SPEC 14 y 17. `createdAt` va en `d-m-Y h:i:s A`, el formato del proyecto, que **no es ISO 8601**.
- `name` sale **siempre en mayúsculas**; `value`, exactamente como se guardó.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo.

1. **Migración, modelo y factory.** `php artisan make:model AccessoryCharacteristic -mf`. Rellenar la migración con las seis columnas y el único compuesto `(accessory_id, name)`; el modelo con su `#[Fillable]`, `normalizeName()`, `normalizeValue()` y las dos relaciones (`accessory()`, `registeredBy()`), **sin `casts()`**; la factory apoyada en `Accessory::factory()` y `User::factory()`, con nombres de característica ya normalizados y valores cortos. Correr `php artisan migrate`.

2. **Contrato.** `app/Interfaces/AccessoryCharacteristic/AccessoryCharacteristicServiceInterface.php` con cinco métodos y su PHPDoc de array shapes y `@throws`, siguiendo la firma de `VehicleExpenseServiceInterface`:

   ```php
   public function getAccessoryCharacteristics(array $filters): array;
   public function createAccessoryCharacteristic(array $data, User $user): AccessoryCharacteristic;
   public function getAccessoryCharacteristicById(int $id): AccessoryCharacteristic;
   public function updateAccessoryCharacteristic(array $data, int $id): AccessoryCharacteristic;
   public function deleteAccessoryCharacteristic(int $id): AccessoryCharacteristic;
   ```

   A diferencia de SPEC 14, **ningún método recibe el `User` para resolver ámbito**: el inventario es nacional y el rol ya lo filtró el middleware. El `User` entra solo en el alta, y solo para el `registered_by`.

3. **Service, parte de lectura.** `app/Services/AccessoryCharacteristic/AccessoryCharacteristicService.php` con `resolvePerPage()` (constantes `MIN_PER_PAGE` / `MAX_PER_PAGE`), `resolveAccessory(int $id): Accessory` —**404 si el accesorio no existe**, sin mirar su `status`—, `getAccessoryCharacteristics()` —resuelve primero el accesorio, filtra por `accessory_id`, orden `id ASC`, paginación opt-in y **ningún otro filtro**— y `getAccessoryCharacteristicById()`, que devuelve 404 si el id no existe. `#[Override]` en cada método.

4. **Service, parte de escritura.** `createAccessoryCharacteristic()`, `updateAccessoryCharacteristic()` y `deleteAccessoryCharacteristic()`, más la guarda `ensureNameIsAvailable(int $accessoryId, string $name, ?int $ignoreId = null)` que lanza `BadRequestError`. El alta resuelve el accesorio con `resolveAccessory()` (404 si no existe), normaliza `name` y `value` y toma `registered_by` de `auth('api')->user()`; el `update` **no acepta `accessory_id`** (`UPDATABLE_FIELDS` no lo incluye), no reescribe `registered_by` y revalida el nombre contra el accesorio ya guardado ignorando la propia fila; `delete` es `delete()` de verdad y devuelve el modelo borrado para la respuesta.

5. **Provider.** `app/Providers/AccessoryCharacteristic/AccessoryCharacteristicProvider.php` con el `bind(AccessoryCharacteristicServiceInterface::class, AccessoryCharacteristicService::class)`, registrado en `bootstrap/providers.php`.

6. **FormRequests**, los tres con `messages()` en español:
   - `IndexAccessoryCharacteristicRequest` — `accessoryId` `required|integer` y nada más, calcado de `IndexVehicleExpenseRequest`: **sin regla `exists`**, porque el 404 lo levanta el service y el 422 se reserva a la ausencia del parámetro. `limit` queda sin validar, como en el resto del proyecto.
   - `StoreAccessoryCharacteristicRequest` — `accessory_id` `required|integer|exists:accessories,id`, `name` `required|string|max:255` normalizado en `prepareForValidation()`, `value` `required|string|max:500` recortado en el mismo sitio.
   - `UpdateAccessoryCharacteristicRequest` — `name` y `value` como `sometimes` con las mismas reglas y la misma normalización. **`accessory_id` no aparece**: mandarlo se ignora en silencio.

7. **Resource, controller y rutas.** `AccessoryCharacteristicResource` con las seis claves de la sección anterior; `AccessoryCharacteristicController` con los cinco métodos, `try/catch` → `ResponseHandler` y el service inyectado **por parámetro de método**; `routes/accessory_characteristics.php` con prefijo `accessory-characteristics`, `jwt.auth` en el grupo, el `apiResource` sobre `'/'` con `->parameters(['' => 'accessoryCharacteristic'])` y `role:administrator` en `store`, `update` y `destroy` vía `middlewareFor`; `require` en `routes/api.php`. Verificación: `php artisan route:list --path=accessory-characteristics` muestra las cinco rutas.

8. **Tests.** Disparar el agente `feature-tests` sobre `AccessoryCharacteristic`: Feature test de los cinco endpoints, los cuatro roles (lectura para los cuatro, escritura solo `administrator`), el 422 sin `accessoryId`, el 404 con `accessoryId` inexistente, la unicidad por accesorio (400 al repetir nombre en el mismo accesorio, 201 al repetirlo en otro), la normalización asimétrica de `name` y `value`, la inmutabilidad de `accessory_id` en el `PATCH`, el borrado físico y que un accesorio `inactive` funciona igual; Unit test del service. Correr `php artisan test --compact --filter=AccessoryCharacteristic`.

9. **Documentación.** Disparar el agente `endpoint-docs` sobre `AccessoryCharacteristic` y regenerar `storage/api-docs/api-docs.json`.

10. **Cierre.** `vendor/bin/pint --dirty --format agent` y escribir `references/accessory-characteristics-api.md` para el frontend, con `references/zones-api.md` como plantilla.

---

## Criterios de aceptación

**Estructura**

- [ ] Existe la tabla `accessory_characteristics` con `id`, `accessory_id`, `name`, `value`, `registered_by` y `timestamps`, ninguna columna nullable y el único compuesto `(accessory_id, name)`.
- [ ] Ninguna tabla existente cambió: `accessories` tiene las mismas columnas que dejó SPEC 17.
- [ ] `php artisan route:list --path=accessory-characteristics` muestra exactamente **cinco** rutas, todas bajo `/api/accessory-characteristics`, y **ninguna** ruta contiene `accessories/{accessory}/characteristics`.
- [ ] Ninguna ruta del dominio lleva `carrier.required`.

**Listado**

- [ ] `GET /api/accessory-characteristics` **sin `accessoryId`** responde **422** con el formato de validación de Laravel.
- [ ] Con un `accessoryId` que no existe responde **404**, no lista vacía.
- [ ] Con un `accessoryId` de un accesorio `inactive` o `under_repair` responde **200** con sus características.
- [ ] Devuelve solo las características de ese accesorio, ordenadas por `id` ascendente.
- [ ] Sin `limit` devuelve la colección completa; con `limit=5` pagina y el sobre trae `total`, `currentPage` y `lastPage` en la raíz; `limit=1` se acota a 10 y `limit=500` a 100.
- [ ] Un accesorio sin características devuelve **200** con `data` vacío.

**Alta**

- [ ] `POST` con `accessoryId`, `name` y `value` válidos responde **201** y la fila queda con `registered_by` igual al usuario autenticado.
- [ ] `name` se guarda y devuelve en MAYÚSCULAS y con los espacios internos colapsados: `"  placa   trasera "` → `"PLACA TRASERA"`.
- [ ] `value` se guarda recortado y **sin cambiar de caja**: `"  Diésel "` → `"Diésel"`.
- [ ] Repetir un `name` (ya normalizado) en el **mismo** accesorio responde **400** con mensaje en español, no 500.
- [ ] El **mismo** `name` en **otro** accesorio responde **201**.
- [ ] Falta `accessoryId`, `name` o `value` → **422**; `accessoryId` inexistente → **422** por la regla `exists`; `value` de más de 500 caracteres → **422**.
- [ ] Mandar `registeredBy` o `registered_by` en el cuerpo no cambia quién queda registrado.

**Detalle, edición y baja**

- [ ] `GET /api/accessory-characteristics/{id}` devuelve las seis claves del Resource y **404** si el id no existe.
- [ ] `PATCH` solo con `value` cambia el valor y deja `name` intacto, y al revés.
- [ ] `PATCH` con `accessoryId` **no** mueve la fila de accesorio: se ignora en silencio y responde 200.
- [ ] `PATCH` que deja el `name` chocando con otro del mismo accesorio responde **400**; reenviar el propio `name` sin cambios responde **200**.
- [ ] `PATCH` no reescribe `registered_by`.
- [ ] `DELETE` responde **200** y la fila **desaparece de la tabla**; un segundo `DELETE` del mismo id responde **404**.
- [ ] Borrar una característica no toca el accesorio ni las demás características.

**Autorización**

- [ ] Sin token, las cinco rutas responden **401**.
- [ ] `index` y `show` responden **200** con los cuatro roles: `administrator`, `carrier`, `pilot` y `manager` — incluido un `carrier` sin empresa registrada.
- [ ] `store`, `update` y `destroy` responden **200/201** solo con `administrator`, y **403** con `carrier`, `pilot` y `manager`.

**No regresión**

- [ ] `GET /api/accessories` y `GET /api/accessories/{accessory}` devuelven **exactamente la misma forma** que antes de esta spec: sin `characteristics` y sin contador.
- [ ] `php artisan test --compact` pasa entera.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.

---

## Decisiones tomadas y descartadas

**Pares nombre/valor libres, no catálogo nacional de características.** Se descartó la tabla `characteristics` administrada por el admin más una pivote con el valor. Habría garantizado que «PLACA» se escriba igual siempre, pero son **dos dominios y dos CRUD**, y obliga a dar de alta el nombre antes de poder usarlo — justo lo contrario de «se pueden agregar diferentes características». Si el diccionario hace falta algún día, se añade encima sin migrar los valores.

**Tabla propia, no columna JSON en `accessories`.** Una columna `characteristics jsonb` no habría creado tabla ni dominio, pero rompe la forma del proyecto: sin FK, sin unicidad declarada, sin `registered_by` por fila y con consultas que ningún otro dominio usa.

**El valor es siempre texto.** Se descartó declarar un tipo por característica (`number`, `date`, `boolean`) con validación condicional: multiplica el FormRequest, obliga a castear en el Resource y no aporta nada a los dos ejemplos reales (una placa y un tipo de combustible, ambos texto).

**Recurso plano con `accessoryId` obligatorio, no ruta anidada.** El proyecto **no tiene ni una ruta anidada** y SPEC 14 ya resolvió este mismo caso así. El `accessoryId` obligatorio en el índice es deliberado: un listado global de características de todo el inventario no lo pide nadie y sería una consulta cara sin sentido de negocio.

**Ausencia del `accessoryId` es 422 y `accessoryId` inexistente es 404.** Dos fallos distintos con dos códigos distintos: uno es una petición mal formada y el otro es un accesorio que no está. Se descartó devolver lista vacía en el segundo caso, que dejaría al front sin saber si el accesorio no existe o simplemente no tiene características.

**`AccessoryResource` no cambia.** Se descartó embeber `characteristics` en el detalle del accesorio, aunque es lo cómodo para el front. Habría convertido una spec aditiva en un cambio de forma de un recurso ya publicado, con su eager loading y su coste en el listado. El front hace dos llamadas.

**Normalización asimétrica: `name` a mayúsculas, `value` solo recortado.** El nombre es un identificador —y al estar siempre en mayúsculas la unicidad resulta insensible a mayúsculas sin depender del collation—; el valor es contenido del usuario y subirlo a mayúsculas lo estropea. Se acepta el precio: no hay búsqueda por valor, así que no hay nada que se vuelva sensible a mayúsculas.

**`normalizeName()` copiada, no reutilizada de `Accessory`.** `Product`, `Zone`, `Location` y `Accessory` ya repiten esa función. Cada modelo es dueño de su normalización y puede divergir sin arrastrar a los demás; llamar a la de otro dominio acoplaría dos tablas por una decisión de formato.

**Unicidad por accesorio, no global.** `(accessory_id, name)`. Que dos accesorios distintos tengan ambos «PLACA» es lo normal; que uno tenga dos «PLACA» es un error de captura.

**Índice único y guarda en el service.** Redundante a propósito, como en SPEC 07–09, 15 y 17: el índice protege la integridad, la guarda entrega un **400 en español** en vez del 500 de Postgres.

**Borrado físico, sin `status` ni `SoftDeletes`.** Una característica mal tecleada es basura, no historial — el mismo argumento de los gastos de vehículo en SPEC 14. Y sin baja lógica no hay estado que gobernar ni filtro que ofrecer.

**Las características sobreviven a la baja del accesorio.** El `DELETE` de accesorios es baja lógica y no borra nada, así que no hay cascada que disparar. La FK se deja **sin `onDelete('cascade')`** para que un borrado físico del accesorio, si algún día ocurre, falle ruidosamente en vez de llevarse filas en silencio.

**`accessory_id` inmutable.** Mover una característica de accesorio es borrarla y crearla; permitirlo por `PATCH` obligaría a revalidar la unicidad contra el destino y a resolver dos accesorios en la misma petición.

**Escritura solo `administrator`, lectura para cualquier autenticado.** Misma regla que el inventario del que cuelga (SPEC 17) y que los catálogos nacionales de SPEC 06–09 y 15.

**El `status` del accesorio no interviene.** Un accesorio dado de baja sigue listando, aceptando y editando características: la ficha de una pieza retirada sigue siendo información válida.

**Alta de una en una, sin lote.** Un `POST` crea una fila. Un endpoint de sincronización («estas son todas sus características») exige decidir qué pasa con las que no vienen en la lista, y esa es una decisión que no hace falta tomar hoy.

**Sin filtros ni búsqueda en el listado.** Las características de un accesorio se cuentan con los dedos: filtrar es complejidad sin beneficio. La paginación opt-in por `limit` se mantiene solo por coherencia con el resto del proyecto.

---

## Riesgos identificados

**Deriva de nombres.** Sin catálogo, nada impide que un accesorio tenga «PLACA», otro «PLACA TRASERA» y un tercero «NO. DE PLACA». La normalización solo unifica caja y espacios, no sinónimos. Mitigación de esta spec: **ninguna**, es una consecuencia asumida del diseño libre. Si el desorden se vuelve un problema real, la salida es el catálogo nacional que esta spec deja fuera de alcance.

**Dos llamadas por accesorio en el front.** Una pantalla que lista 40 accesorios con sus características hace 41 peticiones, porque `AccessoryResource` no las embebe y el índice exige un `accessoryId` a la vez. Es el precio de no tocar un recurso publicado. Mitigación: la pantalla de listado no necesita características —solo la ficha de detalle—, y si hiciera falta, un `accessoryIds[]` en el índice es aditivo y no rompe nada.

**Datos sin tipo.** Un valor «12/03/2024» y otro «marzo de 2024» son igual de válidos, y ningún informe futuro podrá operar con ellos sin limpiar a mano. Riesgo real, aceptado a cambio de la simplicidad; tipar después obliga a migrar los valores ya capturados.

**Longitud de `value` en 500.** Si alguien intenta usar una característica como campo de notas largas, chocará con el límite. Ampliar la columna es una migración aditiva sin pérdida.
