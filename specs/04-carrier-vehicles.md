# SPEC 04 — Vehículos del transportista

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 03
> **Fecha:** 2026-08-05
> **Objetivo:** Permitir que un usuario con rol `carrier` gestione el inventario de vehículos de su propia empresa y que un `administrator` gestione el de todas, con estado operativo por enum y placa única entre los vehículos no desactivados.

---

## Alcance

**Dentro:**

- Enum `App\Enums\VehicleType` con `Truck`, `Van`, `Trailer` y `Pickup` (valores en base: `truck`, `van`, `trailer`, `pickup`).
- Enum `App\Enums\VehicleStatus` con `Active`, `Inactive` y `UnderRepair` (valores en base: `active`, `inactive`, `under_repair`).
- Migración `vehicles`: `id`, `carrier_id` (FK a `carriers`), `plate`, `brand`, `model`, `year`, `capacity` (decimal, **en libras**), `type`, `image` (nullable), `status` (default `active`), `timestamps`. **Sin índice único sobre `plate`** y **sin `deleted_at`**.
- Modelo `Vehicle` con factory, relación `carrier()`, y `Carrier::vehicles()` en el modelo existente.
- Cadena de capas completa: `VehicleServiceInterface`, `VehicleService`, `VehicleProvider`, `StoreVehicleRequest`, `UpdateVehicleRequest`, `VehicleResource` y `VehicleController`.
- `routes/vehicles.php` con un `apiResource` de cinco endpoints (`index`, `store`, `show`, `update`, `destroy`), incluido desde `routes/api.php`.
- **Ámbito por rol resuelto en el service**: un `carrier` solo alcanza los vehículos de su empresa; un `administrator` los de todas. La misma ruta sirve a los dos, sin endpoint `/me`.
- **Unicidad de la placa en el service, no en base**: una placa solo puede repetirse si todos los vehículos que ya la usan están en `inactive`. Se valida en `store` y también en `update` cuando la placa cambia.
- Normalización de `plate` a mayúsculas antes de validar y de persistir.
- **`DELETE` no borra**: cambia el `status` del vehículo a `inactive`. La fila permanece y sigue apareciendo en los listados.
- Filtros opcionales en `index`: `status` (valor del enum) y `carrierId` (**solo lo aplica el `administrator`**; en un `carrier` se ignora, su ámbito ya está fijado por su empresa). Un valor inválido en cualquiera de los dos se ignora, igual que un `limit` no numérico.
- Paginación opcional por `limit` con `PaginatedResource`, acotada a `[10, 100]`, exactamente como en SPEC 03.
- Imagen: se recibe y valida (`jpg`, `jpeg`, `png`), **no se almacena**, y en `image` se guarda un UUID con la extensión original.
- `VehicleResource` en camelCase: `id`, `plate`, `brand`, `model`, `year`, `capacity`, `type`, `image`, `status` y `carrierName`.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **Asignación de pilotos a vehículos.** Es el siguiente paso natural y abre preguntas propias (¿un piloto por vehículo?, ¿historial de asignaciones?).
- **Subida real de la imagen.** Misma deuda heredada de SPEC 03: aquí solo se genera y guarda el identificador.
- **Borrado real de vehículos**, papelera y restauración. `DELETE` solo desactiva.
- **Semántica operativa de `status`.** El campo dice si el vehículo puede usarse en viajes, pero todavía no existen viajes que lo consulten: hoy es un dato informativo.
- **Historial de propiedad de una placa.** Cuando un vehículo se desactiva y otra empresa registra la misma placa, quedan dos filas independientes sin vínculo entre ellas.
- **Reactivar un vehículo cuya placa ya fue tomada por otra empresa.** El `PATCH` que intente volverlo a `active` fallará por la validación de placa; resolver ese conflicto es otra spec.
- **Acceso del `pilot` y del `manager`** a cualquier endpoint de vehículos.
- **Búsqueda por texto y ordenación** en `index`. Solo los filtros y la paginación descritos.
- **Documentos del vehículo** (seguro, tarjeta de circulación, revisiones) y mantenimientos.
- **Notificaciones o correos** al registrar o desactivar un vehículo.

---

## Modelo de datos

### 1. Enums

```php
// app/Enums/VehicleType.php
enum VehicleType: string
{
    case Truck = 'truck';
    case Van = 'van';
    case Trailer = 'trailer';
    case Pickup = 'pickup';
}

// app/Enums/VehicleStatus.php
enum VehicleStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case UnderRepair = 'under_repair';
}
```

Los valores viajan en inglés en la API; la traducción al español es responsabilidad del front, como con `UserRole`.

### 2. Tabla `vehicles`

```php
Schema::create('vehicles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
    $table->string('plate');
    $table->string('brand');
    $table->string('model');
    $table->unsignedSmallInteger('year');
    $table->decimal('capacity', 10, 2);          // libras
    $table->string('type');
    $table->string('image')->nullable();
    $table->string('status')->default(VehicleStatus::Active->value);
    $table->timestamps();

    $table->index('plate');
});
```

`plate` lleva índice **no único**: la unicidad es condicional (solo entre no desactivados) y esa regla no se expresa en un índice simple. El índice existe porque la validación de placa consulta por esa columna en cada `store` y en cada `update` que la cambie.

`capacity` es `decimal(10, 2)` en **libras**. La unidad no se guarda en base: es una convención del dominio y va documentada en Swagger.

### 3. Modelos

```php
#[Fillable(['carrier_id', 'plate', 'brand', 'model', 'year', 'capacity', 'type', 'image', 'status'])]
class Vehicle extends Model
{
    public function carrier(): BelongsTo;   // empresa dueña

    protected function casts(): array
    {
        return [
            'type' => VehicleType::class,
            'status' => VehicleStatus::class,
            'capacity' => 'decimal:2',
            'year' => 'integer',
        ];
    }
}
```

En `Carrier` (sin columnas nuevas):

```php
public function vehicles(): HasMany;    // los vehículos de la empresa
```

### 4. Resource

```php
// VehicleResource
[
    'id', 'plate', 'brand', 'model', 'year',
    'capacity',                 // libras
    'type',                     // 'truck' | 'van' | 'trailer' | 'pickup'
    'image',
    'status',                   // 'active' | 'inactive' | 'under_repair'
    'carrierName',              // desde la relación carrier
]
```

`carrierName` sale de `$this->carrier->name`; el service carga la relación con `with('carrier')` para no provocar N+1 en los listados.

### 5. Validación

```php
// StoreVehicleRequest — todos los campos requeridos
'plate' => ['required', 'string', 'max:15'],
'brand' => ['required', 'string', 'max:100'],
'model' => ['required', 'string', 'max:100'],
'year' => ['required', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
'capacity' => ['required', 'numeric', 'min:0'],
'type' => ['required', Rule::enum(VehicleType::class)],
'image' => ['required', 'file', 'mimes:jpg,jpeg,png'],
// status NO se acepta: nace siempre en 'active'

// UpdateVehicleRequest — todos opcionales, incluido status
'plate' => ['sometimes', 'string', 'max:15'],
// ...
'status' => ['sometimes', Rule::enum(VehicleStatus::class)],
```

La unicidad de la placa **no** es una regla del FormRequest: vive en el service, porque depende del `status` de las filas existentes.

### 6. Reglas de negocio del service

- **Normalización:** `plate` pasa a mayúsculas (`Str::upper()`) antes de validar unicidad y antes de persistir.
- **Unicidad condicional:** existe conflicto si hay algún `Vehicle` con esa `plate` y `status !== inactive`, excluyendo al propio vehículo en un `update`. Con conflicto se lanza `BadRequestError`.
- **Ámbito por rol:** un `carrier` opera únicamente sobre `carrier_id = $user->currentCarrier()->id`. Alcanzar un vehículo fuera de su ámbito lanza `ForbiddenError` (403). Un `administrator` no tiene restricción de ámbito.
- **`image`:** `Str::uuid()` más la extensión real del archivo. El archivo se valida y se descarta.
- **`destroy`:** actualiza `status` a `inactive` y devuelve el vehículo. No borra la fila.
- **Filtros de `index`:** `status` solo se aplica si es un valor válido del enum; `carrierId` solo se aplica si el usuario es `administrator` y el valor es numérico. Cualquier otro caso se ignora sin error.
- **`limit`:** idéntico a SPEC 03 — ausente o no numérico devuelve la colección completa; numérico pagina, acotado a `[10, 100]`.

### 7. Contrato HTTP

| Método y ruta | Acción | Rol | `carrier.required` |
|---|---|---|---|
| `GET /api/vehicles` | `index` | carrier, administrator | sí |
| `POST /api/vehicles` | `store` | carrier | sí |
| `GET /api/vehicles/{vehicle}` | `show` | carrier, administrator | sí |
| `PUT\|PATCH /api/vehicles/{vehicle}` | `update` | carrier, administrator | sí |
| `DELETE /api/vehicles/{vehicle}` | `destroy` | carrier, administrator | sí |

Query params de `index`: `status`, `carrierId` (ignorado si no es `administrator`) y `limit`.

`administrator` atraviesa `carrier.required` por exención, igual que en SPEC 03.

---

## Plan de implementación

Cada paso deja el sistema arrancable y es commiteable por sí solo.

1. **Enums.** `app/Enums/VehicleType.php` y `app/Enums/VehicleStatus.php`, con los valores del modelo de datos. *Verificación:* `php artisan tinker --execute 'echo App\Enums\VehicleStatus::Active->value;'` imprime `active`.

2. **Tabla y modelo `Vehicle`.** `php artisan make:model Vehicle -mf`. Migración con las diez columnas, índice no único sobre `plate` y default `active` en `status`. Modelo con `#[Fillable]`, casts de `type`, `status`, `capacity` y `year`, y relación `carrier()`. Factory que genera placa, marca, modelo, año, capacidad, tipo aleatorio del enum y `status = active`. *Verificación:* `php artisan migrate` corre limpio y `Vehicle::factory()->create()->carrier` devuelve un `Carrier`.

3. **Relación inversa.** `Carrier::vehicles(): HasMany`. *Verificación:* `Carrier::factory()->has(Vehicle::factory()->count(3))->create()->vehicles` devuelve tres filas.

4. **Resource.** `app/Http/Resources/Vehicle/VehicleResource.php` con los diez campos en camelCase, `carrierName` desde la relación. *Verificación:* la suite existente sigue verde.

5. **Contrato del service.** `app/Interfaces/Vehicle/VehicleServiceInterface.php` con los cinco métodos y su PHPDoc de array shapes. Sin implementación.

6. **Service, lecturas.** `app/Services/Vehicle/VehicleService.php` con `getVehicles(User $user, array $filters)` y `getVehicleById(User $user, int $id)`. `getVehicles` arranca de `Vehicle::with('carrier')`, restringe por `carrier_id` cuando el usuario es `carrier`, aplica `status` y `carrierId` solo si son válidos, y pagina o no según `limit`. `getVehicleById` lanza `NotFoundError` si no existe y `ForbiddenError` si está fuera del ámbito del `carrier`.

7. **Service, unicidad de placa.** Método privado que recibe placa normalizada y un id a excluir, y lanza `BadRequestError` si existe otro vehículo con esa placa en un `status` distinto de `inactive`. Es la pieza que consumen `create` y `update`.

8. **Service, escrituras.** `createVehicle()` (normaliza placa, valida unicidad, resuelve `carrier_id` desde `currentCarrier()`, genera `image` y fuerza `status = active`), `updateVehicle()` (revalida la placa solo si cambia, respeta el ámbito por rol) y `deleteVehicle()` (pone `status = inactive` y devuelve el vehículo). *Verificación:* `php artisan test --compact` sigue verde.

9. **Provider.** `app/Providers/Vehicle/VehicleProvider.php` con `bind(VehicleServiceInterface::class, VehicleService::class)`, registrado en `bootstrap/providers.php`. *Verificación:* `php artisan tinker --execute 'app(App\Interfaces\Vehicle\VehicleServiceInterface::class);'` resuelve.

10. **FormRequests.** `StoreVehicleRequest` (siete campos requeridos, sin `status`) y `UpdateVehicleRequest` (todos opcionales, con `status`), ambos con `messages()` en español, en `app/Http/Requests/Vehicle/`.

11. **Controller.** `VehicleController` con los cinco métodos, `try/catch` hacia `ResponseHandler`, el service inyectado por parámetro y el usuario resuelto con `auth('api')->user()`. El `index` elige envoltorio con `$limit ? new PaginatedResource(...) : VehicleResource::collection(...)`.

12. **Rutas.** `routes/vehicles.php` con `jwt.auth` en el grupo, `apiResource` de cinco acciones y los middlewares `role` y `carrier.required` por acción según la tabla del contrato. Incluido desde `routes/api.php`. *Verificación:* `php artisan route:list --path=vehicles` muestra las cinco con sus middlewares.

13. **Formato.** `vendor/bin/pint --dirty --format agent`.

14. **Tests.** Delegar al agente `feature-tests`.

15. **Documentación.** Delegar al agente `endpoint-docs` y regenerar `storage/api-docs/api-docs.json`.

---

## Criterios de aceptación

**Base de datos y modelos**

- [x] `php artisan migrate:fresh` crea `vehicles` sin errores.
- [x] Insertar dos vehículos con la misma `plate` **no** falla en base: la unicidad no está en el índice.
- [x] `Vehicle::factory()->create()->carrier` devuelve un `Carrier`, y `$carrier->vehicles` una colección de `Vehicle`.
- [x] Un vehículo creado sin `status` explícito nace en `active`.
- [x] `$vehicle->type` y `$vehicle->status` devuelven instancias de `VehicleType` y `VehicleStatus`, no strings.
- [x] Borrar un `Carrier` arrastra sus vehículos por la cascada.

**Middlewares y roles**

- [x] Una petición sin token a cualquier endpoint de vehículos recibe 401.
- [x] Un `pilot` recibe 403 en los cinco endpoints.
- [x] Un `manager` recibe 403 en los cinco endpoints.
- [x] Un `carrier` sin empresa recibe 403 por `carrier.required`, incluido en `POST /api/vehicles`.
- [x] Un `administrator` sin empresa atraviesa `carrier.required` y recibe 200 en `GET /api/vehicles`.

**`POST /api/vehicles`**

- [x] Un `carrier` con empresa recibe 201 y el vehículo queda persistido con `carrier_id` igual al de su empresa y `status = active`.
- [x] La placa se persiste en mayúsculas aunque se envíe en minúsculas (`p123abc` → `P123ABC`).
- [x] Enviar `status` en el body no cambia nada: el vehículo nace en `active`.
- [x] Omitir cualquiera de los siete campos requeridos devuelve 422.
- [x] Un `type` fuera del enum devuelve 422.
- [x] Un `year` de 1800 devuelve 422.
- [x] Enviar un `.pdf` como `image` devuelve 422.
- [x] La columna `image` guarda un UUID con la extensión del archivo enviado, y **no** aparece ningún archivo nuevo en `storage/`.
- [x] Registrar una placa que ya usa un vehículo `active` de **la misma** empresa devuelve 400.
- [x] Registrar una placa que ya usa un vehículo `active` de **otra** empresa devuelve 400.
- [x] Registrar una placa que ya usa un vehículo `under_repair` devuelve 400.
- [x] Registrar una placa cuyos únicos portadores están en `inactive` devuelve 201, y quedan dos filas con la misma placa.
- [x] Un `administrator` recibe 403 en `store`: solo el `carrier` registra vehículos.

**`GET /api/vehicles`**

- [x] Un `carrier` recibe únicamente los vehículos de su empresa; los de otra empresa no aparecen.
- [x] Un `administrator` recibe los vehículos de todas las empresas.
- [x] El listado incluye los vehículos en `inactive` y en `under_repair` cuando no se manda `status`.
- [x] `?status=under_repair` devuelve solo los vehículos en ese estado.
- [x] `?status=cualquiercosa` se ignora y devuelve todos los registros del ámbito, sin error.
- [x] `?carrierId=N` como `administrator` devuelve solo los vehículos de esa empresa.
- [x] `?carrierId=N` como `carrier` se ignora: sigue recibiendo solo los suyos, aunque `N` sea otra empresa.
- [x] Cada elemento trae `carrierName` con el nombre de su empresa.
- [x] Listar 20 vehículos de 20 empresas distintas no dispara N+1 (una consulta para vehículos y una para carriers).

**Paginación**

- [x] `GET /api/vehicles` sin `limit` devuelve todos los registros y la respuesta **no** trae `total`, `currentPage` ni `lastPage`.
- [x] `GET /api/vehicles?limit=10` devuelve como máximo 10 y la respuesta trae `data`, `total`, `currentPage` y `lastPage` al nivel raíz del sobre.
- [x] `GET /api/vehicles?limit=abc` devuelve todos los registros sin error.
- [x] `GET /api/vehicles?limit=3` pagina de 10 en 10.
- [x] `GET /api/vehicles?limit=500` pagina de 100 en 100.
- [x] `limit` y `status` combinados se aplican los dos a la vez.

**`GET /api/vehicles/{id}`**

- [x] Un `carrier` recibe 200 con un vehículo de su empresa, `carrierName` incluido.
- [x] Un `carrier` que pide un vehículo de otra empresa recibe 403.
- [x] Un `administrator` recibe 200 con el vehículo de cualquier empresa.
- [x] Un id inexistente devuelve 404.

**`PATCH /api/vehicles/{id}`**

- [x] El dueño actualiza `brand`, `model`, `year`, `capacity`, `type` e `image`.
- [x] El dueño cambia `status` a `under_repair` y el cambio se persiste.
- [x] Cambiar la placa a una libre devuelve 200 y la persiste en mayúsculas.
- [x] Cambiar la placa a una que usa un vehículo `active` de otra empresa devuelve 400.
- [x] Reenviar la **misma** placa que ya tiene el vehículo devuelve 200: no colisiona consigo mismo.
- [x] Un `carrier` que actualiza un vehículo de otra empresa recibe 403.
- [x] Un `administrator` puede actualizar el vehículo de cualquier empresa.
- [x] Un `status` fuera del enum devuelve 422.

**`DELETE /api/vehicles/{id}`**

- [x] Devuelve 200 y la fila **sigue existiendo** en base con `status = inactive`.
- [x] El vehículo desactivado sigue apareciendo en `GET /api/vehicles`.
- [x] Tras desactivarlo, otra empresa puede registrar su placa y recibe 201.
- [x] Un `carrier` que desactiva un vehículo de otra empresa recibe 403.
- [x] Desactivar un vehículo ya `inactive` devuelve 200 sin efectos adicionales.

**Calidad**

- [x] `php artisan test --compact` pasa en verde, incluidos todos los tests de SPEC 01, 02 y 03 sin modificarlos.
- [x] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [x] `php artisan route:list --path=vehicles` muestra las cinco rutas con sus middlewares.
- [x] `php artisan l5-swagger:generate` regenera sin errores y los cinco endpoints aparecen en `/api/documentation`.

---

## Decisiones

**Modelo de datos**

- **Sí:** `status` como enum de tres valores (`active`, `inactive`, `under_repair`) en lugar de un booleano `active` como el de `carriers`. "En reparación" es un estado real del negocio que un booleano no puede representar sin inventar una segunda columna.
- **No:** `SoftDeletes` con `deleted_at` separado de `status`. Se evaluó tener dos ejes — operativo y de inventario — pero para el caso de uso actual "dar de baja" y "desactivar" son el mismo acto, y dos banderas obligan a decidir en cada consulta cuál manda.
- **Sí:** `capacity` en libras, como `decimal(10,2)`. La unidad es una convención del dominio y no se guarda en base; queda documentada en Swagger.
- **Sí:** `VehicleType` como enum en `App\Enums`. Es el patrón que ya usa `UserRole` y evita que la misma categoría entre como `camion`, `Camión` y `CAMION`.
- **Sí:** valores del enum en inglés. Coherente con `UserRole`; el español de cara al usuario lo pone el front.
- **Sí:** `carrierName` en el resource, y no `carrierId`. Al carrier le sobra el dato (siempre es el suyo) y al administrator le sirve para leer el listado sin resolver ids a mano.

**Unicidad de la placa**

- **Sí:** la unicidad se valida en el service contra los vehículos no desactivados, no con un índice único en base. Es la única forma de que desactivar un vehículo libere su placa, que es el comportamiento pedido.
- **No:** índice único sobre `plate`. Habría hecho la regla infalible a nivel de motor, pero impide para siempre reutilizar la placa de un vehículo dado de baja.
- **No:** índice único parcial (`WHERE status != 'inactive'`). SQLite lo soporta pero no todos los motores de destino lo hacen igual, y la suite corre sobre SQLite en memoria mientras producción no lo es. La regla se queda en un solo sitio: el service.
- **Sí:** un vehículo `under_repair` bloquea su placa. Sigue siendo de la empresa; solo el que se da de baja la suelta.
- **Sí:** normalizar la placa a mayúsculas antes de validar y persistir, como el `code` de SPEC 03. Sin eso, `p123abc` y `P123ABC` convivirían como dos vehículos distintos.
- **Sí:** el `update` revalida la placa solo cuando cambia, excluyendo al propio vehículo. Reenviar la placa que ya se tiene no puede ser un conflicto consigo mismo.

**Autorización y ámbito**

- **Sí:** el ámbito por rol se resuelve en el service, no en el middleware. `role:` solo dice quién puede llamar; "de quién es este vehículo" necesita base de datos y va donde va el resto de la lógica de negocio, igual que en `updateCarrier`.
- **Sí:** 403 y no 404 cuando un `carrier` alcanza un vehículo ajeno. Coherente con el `updateCarrier` de SPEC 03; se acepta que revela la existencia del id a cambio de un mensaje honesto.
- **Sí:** `carrier.required` también en `store`. Un `carrier` sin empresa no tiene dónde colgar un vehículo; el middleware corta antes de llegar al service.
- **Sí:** `store` es exclusivo del `carrier`. El `administrator` supervisa y corrige, pero el alta del inventario la hace quien es dueño de él.
- **Sí:** el `carrierId` de un `carrier` se ignora en vez de devolver 403. Es un parámetro de presentación; su ámbito ya está fijado y mandarlo no le da acceso a nada.

**Rutas y forma de la API**

- **Sí:** `/api/vehicles` plano, con el ámbito deducido del rol. Anidar bajo `/api/carriers/{carrier}/vehicles` obligaría al carrier a mandar su propio id y a validarlo en cada endpoint, sin ganar nada.
- **No:** un endpoint `/api/vehicles/me` separado. El `index` ya devuelve lo correcto para cada rol; duplicarlo sería mantener dos caminos a la misma consulta.
- **Sí:** `DELETE` se queda en el `apiResource` aunque no borre. Completa el CRUD que el front espera y "desactivar" es la operación natural detrás de ese verbo en este dominio.

**Comportamiento**

- **Sí:** la imagen se recibe, se valida y se descarta, guardando solo el identificador. Misma decisión —y misma deuda— que en SPEC 03, para que la spec de subida real resuelva los dos dominios de una vez.
- **Sí:** `status` no se acepta en `store` y sí en `update`. Nadie registra un vehículo que ya nace roto.
- **Sí:** filtros inválidos se ignoran en vez de devolver 422, igual que `limit`. Un parámetro de presentación mal escrito no debe tumbar una lectura.
- **Sí:** `with('carrier')` en las lecturas. `carrierName` en el resource dispara una consulta por fila si no se precarga.

**Proceso**

- **Sí:** misma cadena de capas y mismos agentes (`feature-tests`, `endpoint-docs`) que el resto del proyecto.
- **Sí:** reutilizar `PaginatedResource` tal cual, sin tocarlo. Se escribió genérico en SPEC 03 precisamente para esto.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| La unicidad de la placa vive solo en el service. Cualquier escritura futura que no pase por `VehicleService` — un seeder, un import masivo, un comando de Artisan — puede duplicar placas activas sin que nada lo impida. | Queda documentado que toda alta de vehículos debe pasar por el service. Si algún día aparece una vía de escritura alternativa, la validación tiene que replicarse ahí o subirse a un índice único parcial, asumiendo entonces que se pierde la reutilización de placas. |
| Dos peticiones simultáneas con la misma placa libre pueden crear dos vehículos activos duplicados: entre la consulta de unicidad y el `insert` no hay bloqueo. | Riesgo aceptado. El volumen del dominio es bajo y el alta es manual desde el front, no automatizada. Si llega a ocurrir, se resuelve con una transacción y un `lockForUpdate` sin cambiar el contrato HTTP. |
| Un vehículo desactivado cuya placa tomó otra empresa ya no se puede reactivar: el `PATCH` a `active` fallará con 400 y el mensaje hablará de la placa, no del motivo real. | Está declarado fuera de alcance. Debe quedar anotado en la documentación Swagger del `update` para que el front sepa por qué falla. |
| El listado devuelve por defecto **todos** los estados, incluidos los desactivados. Un front que pinte "mis vehículos" sin filtrar mostrará camiones dados de baja como si estuvieran operativos. | Decisión explícita: el resource siempre expone `status`, y el filtro está disponible. La responsabilidad de filtrar es del consumidor. |
| Al no borrar filas, las placas reutilizadas acumulan varias filas históricas con el mismo valor y sin vínculo entre ellas. Un `GET` por placa devolvería varios vehículos de empresas distintas. | Hoy no existe ninguna búsqueda por placa. Cuando exista, tendrá que decidir qué hace con las duplicadas, y esa decisión va en su spec. |
| `carrierName` en el resource abre un N+1 en los listados si alguien escribe una consulta nueva sin `with('carrier')`. | El `with('carrier')` está en el plan y hay un criterio de aceptación que cuenta las consultas del listado. |
| El borrado en cascada desde `carriers` arrastra los vehículos. Si algún día se borra una empresa de verdad, su inventario desaparece sin rastro. | SPEC 03 dejó `destroy` de carriers como no-op, así que hoy no hay forma de disparar la cascada. Cuando el borrado real exista, tendrá que decidir qué pasa con los vehículos. |
| La imagen se recibe, se valida y se descarta. Un front que suba una foto creerá que quedó guardada y verá una `image` que no resuelve a nada. | Deuda heredada y consciente de SPEC 03. Debe quedar anotado en la documentación Swagger del endpoint. |
| `capacity` en libras no se valida contra ninguna unidad: nada impide que un front mande kilos. | La unidad va documentada en Swagger y en el mensaje de validación. No hay forma técnica de distinguir 1.000 libras de 1.000 kilos. |

---

## Lo que **no** entra en esta spec

- Asignación de pilotos a vehículos.
- Subida real de la imagen a la nube o a disco.
- Borrado real de vehículos, papelera y restauración.
- Que `status` bloquee la asignación del vehículo a un viaje.
- Historial de propiedad de una placa entre empresas.
- Reactivar un vehículo cuya placa ya fue tomada por otra empresa.
- Acceso del `pilot` y del `manager` a los endpoints de vehículos.
- Búsqueda por texto y ordenación en el listado.
- Documentos del vehículo (seguro, tarjeta de circulación) y mantenimientos.
- Notificaciones o correos al registrar o desactivar un vehículo.

Cada uno de esos, si entra, va en su propia spec.
