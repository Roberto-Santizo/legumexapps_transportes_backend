# SPEC 11 — Salario base de pilotos y bitácora de cambios

> **Estado:** Aprobado
> **Depende de:** SPEC 01, SPEC 03
> **Fecha:** 2026-08-15
> **Objetivo:** Guardar el salario base mensual de cada piloto vinculado a una empresa transportista y dejar registrado cada cambio de ese salario en una bitácora, expuestos por un dominio `Pilot` propio con listado, asignación de salario e historial.

Depende de **SPEC 01** por el guard JWT, el enum `UserRole` y el middleware `role:`; y de **SPEC 03** por la pivote `carrier_pilots`, el modelo `CarrierPilot` y `User::currentCarrier()`, que es lo que acota a un transportista a sus propios pilotos.

Es la primera spec que **publica un dominio sobre una tabla pivote existente**. `carrier_pilots` deja de ser solo un vínculo y pasa a llevar un dato de negocio propio — el salario —, con su propio ciclo de vida y su propio rastro de auditoría.

**El endpoint de SPEC 03 no se toca.** `GET /api/carriers/me/pilots` y `CarrierPilotResource` siguen exactamente como están, sin `salary`. El dominio nuevo vive en paralelo, en `/api/pilots`, y es el único sitio donde el salario existe de cara a la API.

---

## Alcance

**Dentro:**

- Migración que añade `salary` (`decimal(10,2)`, **nullable**, sin default) a `carrier_pilots`. Un piloto que se une con `POST /api/carriers/join` nace **sin salario asignado**, y `null` significa exactamente eso — no "gana cero".
- El salario es **mensual y en GTQ**. La columna no lo dice; lo dicen el PHPDoc del modelo y Swagger.
- Migración nueva `carrier_pilot_salary_histories`: `id`, `carrier_pilot_id` (FK a `carrier_pilots`), `previous_salary` (`decimal(10,2)`, **nullable** — es `null` en la primera asignación), `new_salary` (`decimal(10,2)`), `changed_by` (FK a `users`) y `timestamps`. **Sin `reason`, sin `notes` y sin `effective_from`**: la bitácora registra qué cambió y quién lo cambió, nada más.
- Modelo `CarrierPilotSalaryHistory` con sus relaciones, y `CarrierPilot` amplía su `#[Fillable]` con `salary`, suma el cast `decimal:2` y la relación `salaryHistories()`.
- **Dominio `Pilot` completo** en su propia subcarpeta: `PilotServiceInterface`, `PilotService`, `PilotProvider`, `UpdatePilotSalaryRequest`, `PilotResource`, `PilotSalaryHistoryResource` y `PilotController`.
- `routes/pilots.php` incluido desde `routes/api.php`, declarado como `apiResource` **con `->only(['index'])`**. El resto del CRUD queda deliberadamente sin generar.
- **Tres endpoints y ninguno más:**
  - `GET /api/pilots` — listado de pilotos con su salario.
  - `PATCH /api/pilots/{pilot}/salary` — asignar o actualizar el salario.
  - `GET /api/pilots/{pilot}/salary-history` — la bitácora de ese piloto.
- **`{pilot}` es el `user_id`**, no el `id` de la fila de `carrier_pilots`. Es el identificador que el front ya tiene en pantalla.
- Las dos rutas fijas se declaran **antes** del `apiResource`, como en SPEC 03, 04, 06, 07, 08 y 09.
- **Ámbito por rol, distinto en lectura y en escritura:**
  - `administrator` y `manager` **leen todos** los pilotos de todas las empresas y pueden filtrar por `carrierId`.
  - `carrier` queda acotado a su propia empresa; el parámetro `carrierId` se le **ignora**, y tocar un piloto de otra empresa es **403**.
  - **Escribir el salario es solo `administrator` y `carrier`.** El `manager` recibe 403 en el `PATCH`.
  - El `pilot` recibe **403 en los tres endpoints**, incluso sobre sí mismo.
- Paginación opt-in con `limit`, acotada a `[10, 100]` y envuelta en `PaginatedResource`, igual que el resto del proyecto. El historial **también** pagina.
- **Un `PATCH` con el mismo salario que ya tiene responde 400** y no escribe en la bitácora: toda fila del historial es un cambio real.
- Bajar el salario está permitido, sin restricción. Rango válido `[0.01, 99999999.99]`.
- La escritura del salario y la fila de bitácora corren en una **misma transacción**: no puede quedar un salario cambiado sin rastro, ni un rastro de un cambio que no ocurrió.
- `changed_by` sale del usuario autenticado, nunca del body.
- Tests Pest y documentación Swagger delegados a los agentes `feature-tests` y `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **El resto del CRUD del dominio `Pilot`:** `show`, `store`, `update` y `destroy`. Vincular un piloto sigue siendo `POST /api/carriers/join` de SPEC 03.
- **Desvincular un piloto de una empresa.** Hoy no existe en ninguna parte y sigue sin existir.
- **Tocar `GET /api/carriers/me/pilots`, `CarrierPilotResource` o `CarrierService::getMyPilots()`.** Se quedan como están, sin `salary`, y ningún test de SPEC 03 cambia.
- **Salario efectivo a futuro** (`effective_from`) y aumentos programados.
- **Motivo, nota o adjunto del cambio.** La bitácora guarda el qué y el quién, no el por qué.
- **Editar o borrar una fila de la bitácora.** Es de solo escritura y solo lectura; no hay endpoint que la modifique.
- **Historial global de la empresa** (`GET /api/pilots/salary-history` sin id) y exportación a CSV o Excel.
- **Cálculo de nómina, bonificaciones, horas extra, descuentos, IGSS, ISR y pagos por viaje.** Esto guarda un número base mensual; no lo interpreta ni lo liquida.
- **Cambio de salario en lote** a varios pilotos a la vez.
- **Moneda configurable.** GTQ es convención del dominio, documentada en Swagger.
- **Ocultar el salario a quien no debe verlo dentro de una empresa.** El control es por rol, no por campo: quien alcanza `GET /api/pilots` ve el salario de todos los pilotos que su ámbito le permite listar.
- **Notificar al piloto** de que su salario cambió.

---

## Modelo de datos

Esta spec **no introduce ningún enum** y no toca `users`, `carriers` ni ninguna tabla fuera de `carrier_pilots`.

### 1. Columna `salary` en `carrier_pilots`

```php
Schema::table('carrier_pilots', function (Blueprint $table) {
    $table->decimal('salary', 10, 2)->nullable()->after('user_id');
});
```

`nullable()` **sin default**: un piloto que se une nace con `salary = null`, que significa "todavía no se lo han asignado". `0.00` significaría "gana cero", que es otra cosa. `decimal(10,2)` da hasta 99 999 999.99 GTQ mensuales, sobrado para el dominio y honesto con el dinero — nada de flotantes.

`joinCarrier()` de SPEC 03 **no cambia**: sigue insertando solo `carrier_id` y `user_id`, y la columna nueva se queda `null` por omisión.

### 2. Tabla `carrier_pilot_salary_histories`

```php
Schema::create('carrier_pilot_salary_histories', function (Blueprint $table) {
    $table->id();
    $table->foreignId('carrier_pilot_id')->constrained('carrier_pilots')->cascadeOnDelete();
    $table->decimal('previous_salary', 10, 2)->nullable();
    $table->decimal('new_salary', 10, 2);
    $table->foreignId('changed_by')->constrained('users');
    $table->timestamps();

    $table->index(['carrier_pilot_id', 'id']);
});
```

- `previous_salary` es `nullable` porque la **primera** asignación no tiene anterior. Es la única fila del historial de un piloto que puede traer `null` ahí.
- `cascadeOnDelete` en `carrier_pilot_id` sigue lo que ya hace `carrier_pilots` con `carrier_id` y `user_id`: si algún día se borra el vínculo, su historial se va con él. La FK a `users` **no** cascadea: borrar al administrador que hizo un cambio no puede borrar el rastro de ese cambio.
- El índice `(carrier_pilot_id, id)` cubre la consulta única de esta tabla: el historial de un piloto ordenado del cambio más reciente al más viejo.
- **No hay `reason`, `notes` ni `effective_from`.** El cambio rige desde que se guarda; `created_at` es la fecha de vigencia.

### 3. Modelos

```php
// CarrierPilot — cambia el atributo Fillable y suma cast y relación
#[Fillable(['carrier_id', 'user_id', 'salary'])]
class CarrierPilot extends Model
{
    public function carrier(): BelongsTo;                    // sin cambios
    public function user(): BelongsTo;                       // sin cambios
    public function salaryHistories(): HasMany;              // CarrierPilotSalaryHistory

    protected function casts(): array
    {
        return ['salary' => 'decimal:2'];
    }
}
```

```php
#[Fillable(['carrier_pilot_id', 'previous_salary', 'new_salary', 'changed_by'])]
class CarrierPilotSalaryHistory extends Model
{
    public function carrierPilot(): BelongsTo;
    public function changedBy(): BelongsTo;                  // User

    protected function casts(): array
    {
        return ['previous_salary' => 'decimal:2', 'new_salary' => 'decimal:2'];
    }
}
```

El nombre de la tabla es plural irregular en inglés, así que el modelo declara `protected $table = 'carrier_pilot_salary_histories'` solo si la convención de Laravel no acierta.

`CarrierPilot` **no tiene factory** hoy y sigue sin tenerla; los tests lo crean con `CarrierPilot::create()`, como ya hace `CarrierServiceTest`. `CarrierPilotSalaryHistory` sí lleva factory, para poder sembrar un historial largo y probar la paginación.

### 4. Resources

```php
// PilotResource — envuelve un CarrierPilot con user y carrier cargados
[
    'id',            // user_id del piloto, NO el id de carrier_pilots
    'name',          // 'Roberto Santizo'
    'email',
    'carrierId',
    'carrierName',
    'salary',        // '4500.00' | null  — GTQ mensuales
    'joinedAt',      // '04-08-2026 10:15:00 AM'  — created_at de carrier_pilots
]

// PilotSalaryHistoryResource — envuelve un CarrierPilotSalaryHistory
[
    'id',
    'previousSalary',  // '4000.00' | null en la primera asignación
    'newSalary',       // '4500.00'
    'changedById',
    'changedByName',
    'changedAt',       // '15-08-2026 09:30:12 PM'  — created_at
]
```

`id` es el `user_id` por la misma razón que en `CarrierPilotResource` de SPEC 03: es el identificador con el que el front vuelve a llamar, y es el que viaja en `PATCH /api/pilots/{pilot}/salary`. El `id` de la fila pivote no sale nunca de la API.

Las fechas usan el formato `d-m-Y h:i:s A` de SPEC 07, 08 y 09, documentado en Swagger como `type: 'string'` **sin** `format: 'date-time'`. `CarrierPilotResource` conserva su `joinedAt` ISO original — son dos Resources distintos y solo el nuevo adopta la convención nueva.

`salary` sale como cadena con dos decimales por el cast `decimal:2`, o `null` si no se ha asignado.

### 5. Validación

```php
// UpdatePilotSalaryRequest — un solo campo, obligatorio
'salary' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
```

`required`, no `sometimes`: este `PATCH` existe para cambiar el salario, así que un body vacío es un 422, no un no-op. `min:0.01` cierra la puerta a `0` y a los negativos — poner a alguien en cero es desvincularlo, y eso no está en esta spec.

**No se acepta `changedBy` en el body**, ni ningún otro campo. Sale del usuario autenticado.

El `limit` y el `carrierId` del listado son query params y **no tienen FormRequest**: se validan por tolerancia dentro del service, como en SPEC 04, 06, 07, 08 y 09.

### 6. Reglas de negocio del service

- **Ámbito, `resolveScopedCarrierId(User $user): ?int`** — copiado del de `VehicleService` con una diferencia: aquí devuelven `null` (sin ámbito, ven todo) **tres** roles y no uno. `administrator` y `manager` no tienen ámbito; cualquier otro queda acotado a `currentCarrier()`, y si no tiene empresa es `ForbiddenError`.
- **`getPilots(User $user, array $filters)`** — arranca de `CarrierPilot::with('user', 'carrier')`, aplica el ámbito, y solo si el ámbito es `null` aplica el filtro `carrierId`. Un `carrierId` no numérico se **ignora**; uno que no corresponde a ninguna empresa devuelve lista vacía con 200. Ordena por `id ASC`. Pagina si llega `limit` numérico.
- **Guarda compartida, `resolvePilot(User $user, int $userId): CarrierPilot`** — busca la fila de `carrier_pilots` por `user_id`. Si no existe → `NotFoundError` (404) «El piloto no existe o no está vinculado a ninguna empresa transportista». Si existe pero cae fuera del ámbito → `ForbiddenError` (403). La usan `updateSalary()` y `getSalaryHistory()`.
- **El 404 cubre dos casos a propósito:** un `user_id` que no existe y un usuario que existe pero no es piloto de nadie. Para esta API los dos son "no hay tal piloto", y distinguirlos filtraría información sobre usuarios ajenos.
- **`updateSalary(int $userId, array $data, User $user): CarrierPilot`** — dentro de `DB::transaction`:
  1. `resolvePilot()` con `lockForUpdate()` sobre la fila.
  2. Si el salario nuevo es **igual** al actual → `BadRequestError` (400) «El salario indicado es el mismo que el piloto ya tiene registrado». La comparación es sobre el valor formateado a dos decimales, no sobre el crudo: `4500`, `4500.00` y `4500.004` son el mismo salario. Es el mismo truco que `fuelMinValue()` de SPEC 09.
  3. Guardar el `salary` anterior en memoria, escribir el nuevo.
  4. Insertar la fila de bitácora con `previous_salary` (el anterior, `null` si era la primera vez), `new_salary` y `changed_by = $user->id`.

  Los pasos 3 y 4 van en la **misma transacción** porque a medias quedaría un salario sin rastro o un rastro de un cambio que no ocurrió.
- **`getSalaryHistory(int $userId, User $user, ?string $limit)`** — `resolvePilot()` primero (mismo 404 y mismo 403 que el `PATCH`), luego `salaryHistories()->with('changedBy')` ordenado por **`id DESC`**: el cambio más reciente arriba. Un piloto sin cambios devuelve lista vacía con 200, no 404.
- El orden por `id DESC` y no por `created_at DESC` es deliberado: dos cambios en el mismo segundo empatarían la fecha y el orden sería indefinido.

### 7. Contrato HTTP

| Método y ruta | Acción | Roles |
|---|---|---|
| `GET /api/pilots?limit=&carrierId=` | Listado de pilotos con su salario | `administrator`, `manager`, `carrier` |
| `PATCH /api/pilots/{pilot}/salary` | Asignar o actualizar el salario | `administrator`, `carrier` |
| `GET /api/pilots/{pilot}/salary-history?limit=` | Bitácora de cambios de ese piloto | `administrator`, `manager`, `carrier` |

```php
// routes/pilots.php
Route::prefix('pilots')->name('pilots.')->middleware('jwt.auth')->group(function (): void {
    Route::patch('/{pilot}/salary', [PilotController::class, 'updateSalary'])
        ->middleware(["role:{$carrier},{$administrator}", 'carrier.required'])
        ->name('salary.update');

    Route::get('/{pilot}/salary-history', [PilotController::class, 'salaryHistory'])
        ->middleware(["role:{$carrier},{$administrator},{$manager}", 'carrier.required'])
        ->name('salary.history');

    Route::apiResource('/', PilotController::class)
        ->parameters(['' => 'pilot'])
        ->only(['index'])
        ->middlewareFor('index', ["role:{$carrier},{$administrator},{$manager}", 'carrier.required']);
});
```

Las dos rutas fijas van **antes** del `apiResource`. `carrier.required` acompaña a `role:` en las tres, como en SPEC 04: `administrator` y `manager` están exentos por definición del middleware, y a un `carrier` sin empresa lo frena antes de llegar al service.

El `pilot` no aparece en ningún `role:` de este archivo: recibe **403 en los tres endpoints**, también sobre sí mismo.

---

## Plan de implementación

Cada paso deja el sistema arrancable y la suite en verde.

### Paso 1 — Columna `salary`

`php artisan make:migration add_salary_to_carrier_pilots_table --no-interaction`, con `decimal(10,2)` `nullable()` `after('user_id')` y su `down()` que la elimina. `CarrierPilot` amplía el `#[Fillable]` con `'salary'` y suma `casts()` con `'salary' => 'decimal:2'`.

*Verificación:* `php artisan migrate` corre limpio y la suite de SPEC 03 sigue verde **sin haber tocado un solo test** — un piloto recién unido sale con `salary` `null`.

### Paso 2 — Tabla e historial

`php artisan make:model CarrierPilotSalaryHistory -mf --no-interaction`. Migración con las seis columnas, las dos FK (`carrier_pilot_id` con cascade, `changed_by` sin él) y el índice compuesto. Modelo con `#[Fillable]`, los dos casts `decimal:2` y las relaciones `carrierPilot()` y `changedBy()`. `CarrierPilot` suma `salaryHistories(): HasMany`.

*Verificación:* `CarrierPilotSalaryHistory::factory()->create()->carrierPilot` devuelve un `CarrierPilot`, y borrar la fila pivote arrastra su historial.

### Paso 3 — Resources

`PilotResource` y `PilotSalaryHistoryResource` en `app/Http/Resources/Pilot/`, ambos en camelCase y con el formato de fecha `d-m-Y h:i:s A`. `CarrierPilotResource` **no se abre**.

*Verificación:* la suite existente sigue verde.

### Paso 4 — Contrato del service

`app/Interfaces/Pilot/PilotServiceInterface.php` con `getPilots()`, `updateSalary()` y `getSalaryHistory()`, cada uno con su PHPDoc de array shapes. Sin implementación todavía.

### Paso 5 — Service, ámbito y listado

`PilotService` con `#[Override]` en cada método público. Aquí van `resolvePerPage()`, `resolveScopedCarrierId()` — con `administrator` **y** `manager` sin ámbito — y `getPilots()`.

*Verificación:* un `carrier` solo ve sus pilotos aunque mande `carrierId` de otra empresa; un `manager` los ve todos; un `carrierId` no numérico se ignora sin error.

### Paso 6 — Service, guarda por id y `updateSalary()`

`resolvePilot()` con su 404 y su 403, y `updateSalary()` dentro de `DB::transaction` con `lockForUpdate()`, la comparación a dos decimales y la inserción de la fila de bitácora.

**El unit test va con este paso**, antes que las capas HTTP: es la única lógica de la spec donde un error no lanza excepción — un `previous_salary` mal calculado escribe un historial equivocado y la respuesta sale 200 igual. El test cubre primera asignación (`previous_salary` `null`), segunda asignación, bajada de salario y salario idéntico.

*Verificación:* dos `PATCH` seguidos con valores distintos dejan **dos** filas de bitácora encadenadas; un tercero con el valor actual responde 400 y **no** añade una tercera.

### Paso 7 — Service, historial

`getSalaryHistory()` con `resolvePilot()` primero, `with('changedBy')`, orden `id DESC` y paginación opt-in.

*Verificación:* un piloto sin cambios devuelve colección vacía, no 404; un piloto de otra empresa devuelve 403 para un `carrier`.

### Paso 8 — Provider

`app/Providers/Pilot/PilotProvider.php` con el `bind(PilotServiceInterface::class, PilotService::class)`, registrado en `bootstrap/providers.php`.

*Verificación:* `app(PilotServiceInterface::class)` resuelve.

### Paso 9 — FormRequest

`UpdatePilotSalaryRequest` en `app/Http/Requests/Pilot/`, con el único campo `salary`, sus reglas y `messages()` en español.

### Paso 10 — Controller y rutas

`PilotController` con `try/catch` → `ResponseHandler` y el service inyectado **por parámetro de método**. `routes/pilots.php` con las dos rutas fijas antes del `apiResource` `->only(['index'])`, y su `require` en `routes/api.php`.

*Verificación:* `php artisan route:list --path=pilots` lista **tres** rutas, con `{pilot}/salary` y `{pilot}/salary-history` por delante del `index`.

### Paso 11 — Formato

`vendor/bin/pint --dirty --format agent`.

### Paso 12 — Tests

Disparar el agente `feature-tests` con el dominio `Pilot`: `tests/Feature/PilotTest.php` (los tres endpoints, los cuatro roles, ámbito por empresa, validación y bitácora) y `tests/Unit/PilotServiceTest.php`.

Se usa el agente y no la skill `test-endpoint` porque aquí sí hay un dominio nuevo con archivos de test nuevos, a diferencia de SPEC 10. La instrucción explícita al agente es que **el dominio solo tiene tres endpoints**: no debe generar casos de `store`, `show`, `update` ni `destroy`.

`tests/Feature/CarrierTest.php` y `tests/Unit/CarrierServiceTest.php` **no se tocan**. Que sigan verdes sin una sola línea modificada es el criterio de que SPEC 03 no se rompió.

### Paso 13 — Documentación

Disparar el agente `endpoint-docs` con el dominio `Pilot` y regenerar `storage/api-docs/api-docs.json`. La descripción de `salary` debe decir **GTQ y mensual**, y la del `PATCH`, que un salario idéntico al vigente responde 400.

---

## Criterios de aceptación

**Migraciones y modelos**

- [x] `php artisan migrate` corre limpio sobre una base ya migrada.
- [x] Un piloto recién unido con `POST /api/carriers/join` tiene `salary` **`null`**, no `0.00`.
- [x] `CarrierPilot::create(['carrier_id' => …, 'user_id' => …, 'salary' => 4500])` persiste `'4500.00'`.
- [x] Borrar una fila de `carrier_pilots` borra sus filas de `carrier_pilot_salary_histories`.
- [x] Borrar el usuario que aparece en `changed_by` **falla** con error de FK: el rastro no se borra en cascada.
- [x] El `down()` de las dos migraciones revierte sin dejar la tabla ni la columna.

**Listado `GET /api/pilots`**

- [x] Un `carrier` recibe **solo** los pilotos de su propia empresa.
- [x] Un `carrier` que manda `carrierId` de otra empresa recibe igualmente **sus** pilotos: el parámetro se ignora, no da error.
- [x] Un `administrator` sin `carrierId` recibe los pilotos de **todas** las empresas.
- [x] Un `manager` sin `carrierId` recibe también los de todas las empresas.
- [x] `carrierId` numérico como `administrator` o `manager` acota el listado a esa empresa.
- [x] Un `carrierId` de una empresa inexistente devuelve lista **vacía** con 200.
- [x] Un `carrierId` no numérico se ignora y devuelve el listado completo, sin error.
- [x] Sin `limit`, la respuesta trae la colección completa y el sobre **no** incluye `total`, `currentPage` ni `lastPage`.
- [x] Con `limit=10` el sobre trae los tres metadatos en la raíz, no bajo `meta`.
- [x] `limit=1` pagina de 10 en 10 y `limit=500` de 100 en 100.
- [x] Cada elemento trae `id`, `name`, `email`, `carrierId`, `carrierName`, `salary` y `joinedAt`, todo en camelCase.
- [x] `id` es el **`user_id`** del piloto; el `id` de la fila de `carrier_pilots` no aparece en ninguna respuesta.
- [x] Un piloto sin salario asignado sale con `salary: null`.
- [x] Listar 20 pilotos ejecuta un número de queries independiente del número de filas (sin N+1 sobre `user` ni `carrier`).

**Asignación `PATCH /api/pilots/{pilot}/salary`**

- [x] La **primera** asignación responde 200, deja `salary` en la fila pivote y crea **una** fila de bitácora con `previous_salary` `null`.
- [x] La segunda asignación crea una segunda fila con `previous_salary` igual al salario anterior.
- [x] Bajar el salario responde 200 y se registra igual que una subida.
- [x] Mandar el **mismo** salario responde **400** y el número de filas de la bitácora **no cambia**.
- [x] `4500`, `4500.00` y `4500.004` se consideran el mismo salario que un `salary` de `'4500.00'`: los tres responden 400.
- [x] `changed_by` apunta al usuario autenticado aunque el body traiga otro valor.
- [x] Body vacío responde **422**; `salary: 0`, negativo, `'abc'` o mayor que 99999999.99 responden 422.
- [x] Un `carrier` sobre un piloto de **otra** empresa responde **403**.
- [x] Un `administrator` sobre un piloto de cualquier empresa responde 200.
- [x] Un `user_id` que no existe responde **404**.
- [x] Un `user_id` que existe pero **no es piloto de ninguna empresa** responde **404**, con el mismo mensaje.
- [x] Si la inserción en la bitácora falla, el `salary` de `carrier_pilots` **no queda modificado** (la transacción revierte los dos).

**Historial `GET /api/pilots/{pilot}/salary-history`**

- [x] Tras tres cambios, devuelve **tres** filas ordenadas del más reciente al más antiguo.
- [x] La fila más antigua es la que tiene `previousSalary: null`.
- [x] Cada elemento trae `id`, `previousSalary`, `newSalary`, `changedById`, `changedByName` y `changedAt`.
- [x] Un piloto sin ningún cambio devuelve lista **vacía** con 200, no 404.
- [x] `limit` pagina el historial con la misma regla `[10, 100]`.
- [x] Un `carrier` sobre un piloto de otra empresa responde **403**; sobre uno suyo, 200.
- [x] Un `manager` puede leer el historial de cualquier piloto.
- [x] Un `user_id` inexistente responde 404.

**Autorización**

- [x] Los tres endpoints sin token responden **401** con el sobre estándar.
- [x] Un `pilot` recibe **403** en los tres, incluso usando **su propio** `user_id`.
- [x] Un `manager` recibe 200 en `index` y en `salary-history`, y **403** en el `PATCH`.
- [x] Un `carrier` **sin empresa registrada** recibe 403 en los tres (lo frena `carrier.required`).
- [x] Un `administrator` sin empresa alcanza los tres sin problema: está exento de `carrier.required`.

**No regresión de SPEC 03**

- [x] `GET /api/carriers/me/pilots` devuelve exactamente el mismo JSON que antes de esta spec, **sin** `salary`.
- [x] `CarrierPilotResource` no cambió.
- [x] `CarrierService::getMyPilots()` y `joinCarrier()` no cambiaron.
- [x] `tests/Feature/CarrierTest.php` y `tests/Unit/CarrierServiceTest.php` pasan **sin haber sido modificados**.
- [x] El índice único sobre `carrier_pilots.user_id` sigue en pie: un piloto no puede unirse a dos empresas.

**Cierre**

- [x] `php artisan test --compact` pasa la suite entera, incluidas las diez specs anteriores.
- [x] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [x] `php artisan route:list --path=pilots` muestra **tres** rutas, con las dos fijas antes del `index`.
- [x] `/api/documentation` muestra los tres endpoints, con `salary` documentado como GTQ mensuales y el 400 del salario idéntico.

---

## Decisiones tomadas y descartadas

### Columna en la pivote **más** tabla histórica, no una sola de las dos

**Descartado:** solo la tabla histórica, con una fila `active` por piloto al estilo de `fuel_prices` de SPEC 06.

Era la opción más consistente con el proyecto —el salario vigente sería la fila activa y no habría dato duplicado— y se rechazó por el coste de lectura: cada listado de pilotos tendría que resolver la fila vigente de cada uno, y `carrier_pilots` es una tabla que se lee constantemente y se escribe casi nunca. La columna deja el salario vigente a un `select` de distancia.

**Descartado:** solo la columna, sin bitácora. Es la mitad de lo pedido: `updated_at` dice cuándo cambió, nunca desde cuánto ni por obra de quién.

**Consecuencia asumida:** el salario vigente vive en dos sitios —la columna y la última fila del historial— y nada en la base garantiza que coincidan. La transacción del `updateSalary()` es la única salvaguarda, y por eso es innegociable.

### `salary` nace `null`, no `0.00`

`null` es "el transportista todavía no le asignó salario"; `0.00` sería "gana cero", que en este dominio no significa nada. La diferencia es visible en el front sin ninguna lógica extra, y hace que la primera fila de bitácora —la única con `previous_salary` `null`— sea identificable de un vistazo.

Por lo mismo el `PATCH` valida `min:0.01`: no hay forma de volver a un salario de cero, porque poner a alguien en cero es desvincularlo y eso es otra spec.

### `{pilot}` es el `user_id`

**Descartado:** el `id` de la fila de `carrier_pilots`.

Es el identificador natural de la tabla que se está modificando, pero no lo tiene nadie: `CarrierPilotResource` de SPEC 03 y `PilotResource` de esta exponen el `user_id` como `id`, y ese es el número que el front trae en pantalla. Exponer el id del pivote habría obligado a devolverlo en los dos Resources solo para poder volver a llamar.

**Consecuencia:** el service traduce `user_id` → fila pivote en `resolvePilot()`, que es el único sitio del dominio que conoce esa correspondencia.

### El 404 no distingue "no existe" de "no es piloto"

Un `user_id` inexistente y un usuario real que no está vinculado a ninguna empresa devuelven el **mismo** 404 con el mismo mensaje.

Distinguirlos sería más informativo y convertiría el endpoint en un oráculo de qué ids de usuario existen en el sistema, consultable por cualquier `carrier` autenticado. Para esta API los dos casos son "no hay tal piloto".

### Escritura sin `manager`, lectura con él

El `manager` ve **todos** los pilotos y **todos** los historiales, sin ámbito de empresa, exactamente igual que un `administrator`. Lo que no puede es tocar un salario.

Es la primera vez en el proyecto que un rol tiene alcance total de lectura y cero de escritura, y por eso el ámbito no se resuelve con `role === Administrator` como en SPEC 04, sino con una lista de dos roles exentos. Es el punto donde `resolveScopedCarrierId()` de `PilotService` deja de ser una copia del de `VehicleService`.

### El `pilot` no accede a su propio salario

**Descartado:** dejar que un `pilot` llame a `GET /api/pilots` y reciba únicamente su propia fila, o que lea su propio historial.

Es defendible —es su sueldo— y se descartó por alcance: haría falta una tercera rama de ámbito, un caso especial en `resolvePilot()` y una decisión sobre qué ve de sus compañeros. Hoy el dominio entero es de administración, y el `pilot` recibe 403 en las tres rutas. Cuando haga falta un "mi salario", será un endpoint propio bajo otro prefijo, no un caso especial de este.

### Salario idéntico es 400, no un 200 silencioso

**Descartado:** aceptar el `PATCH` y no escribir en la bitácora.

Habría sido más amable con un front que reenvía el formulario sin cambios, y deja al usuario creyendo que hizo algo cuando no hizo nada. Con el 400, **toda fila del historial es un cambio real** y la bitácora nunca tiene ruido. La comparación se hace sobre el valor formateado a dos decimales, igual que `fuelMinValue()` de SPEC 09, porque si no un `4500.004` se colaría como cambio.

### Sin `reason` y sin `effective_from`

**Descartado:** un campo de texto con el motivo del cambio.

Se pidió explícitamente dejarlo fuera. Un campo opcional de texto libre que nadie está obligado a llenar acaba vacío en el 90 % de las filas y no se puede consultar; si el motivo importa, importa como dato obligatorio y con estructura, y eso es una decisión propia.

**Descartado:** `effective_from` para programar un aumento a futuro. Convertiría cada lectura del salario en "la fila vigente a día de hoy" y arrastraría un job o una consulta por fecha a todo el dominio. El cambio rige desde que se guarda; `created_at` es la vigencia.

### El dominio nuevo no se lleva `GET /api/carriers/me/pilots`

**Descartado:** eliminar la ruta de SPEC 03 o hacerla delegar en `PilotService`.

Era la recomendación inicial —dos caminos al mismo dato envejecen mal— y se rechazó por una razón concreta: mover ese endpoint obliga a reescribir sus tests, y esos tests son precisamente lo que garantiza que SPEC 03 sigue en pie. Se queda intacta, sin `salary`, y el dominio nuevo vive en paralelo.

**Consecuencia asumida:** hay dos endpoints que listan pilotos, con Resources distintos y ámbitos distintos. El de SPEC 03 es "los pilotos de mi empresa" para un `carrier`; el nuevo es el de administración, con salario. Unificarlos, si algún día molesta, es borrar una ruta.

### `apiResource` con `->only(['index'])`

**Descartado:** declarar las dos rutas como `Route::get()` y `Route::patch()` sueltas, sin `apiResource`.

Habría sido más honesto con lo que hay —tres rutas, ningún CRUD— y se descartó porque se pidió el `apiResource` explícitamente y porque deja el andamiaje puesto: añadir `show` o `destroy` el día que hagan falta es una entrada en el `->only()` y un método en el controller, no reescribir el archivo de rutas.

### La bitácora sí tiene lector

En la primera ronda quedó como tabla de solo escritura, sin endpoint. Se corrigió: un historial que no se puede consultar no es una bitácora, es una tabla que crece. `GET /api/pilots/{pilot}/salary-history` es la tercera ruta del dominio y la única razón por la que la tabla existe.

### Tests con el agente, no con las skills

Al revés que SPEC 10. Allí había dos endpoints ya testeados a los que se les añadía una clave; aquí hay un dominio nuevo, con archivos de test nuevos y la cadena de capas completa, que es exactamente el caso de `feature-tests`. La instrucción explícita al agente es que el dominio tiene **tres** endpoints y no cinco.

---

## Riesgos identificados

### El salario vigente vive en dos sitios

`carrier_pilots.salary` y la última fila de `carrier_pilot_salary_histories` dicen lo mismo, y **nada en la base garantiza que coincidan**. Un `UPDATE` directo a la columna —una corrección manual en producción, un seeder, un script de migración de datos— deja la bitácora mintiendo, sin error y sin señal.

**Mitigación:** parcial. La transacción de `updateSalary()` garantiza la coherencia mientras el cambio pase por la API, y es la única puerta que existe hoy. Lo que no hay es una restricción de base que lo imponga; un trigger o una vista materializada serían la garantía real y ninguna de las dos entra aquí. Queda como norma operativa: **el salario no se toca por fuera de la API.**

### La bitácora crece sin techo y sin purga

Cada cambio de salario es una fila que no se borra nunca. Con 200 pilotos y un ajuste anual son 200 filas al año — irrelevante. Pero un script que reasigne salarios en lote, o un front que reintente el `PATCH`, escriben tantas filas como llamadas hagan.

**Mitigación:** el 400 del salario idéntico corta el caso más probable —el reenvío del mismo formulario— de raíz. La paginación del historial evita que una respuesta crezca sin límite. No hay archivado ni purga, y no debería hacer falta en este orden de magnitud.

### Un `manager` ve el salario de todos los pilotos del país

Es lo pedido, y conviene decirlo en voz alta: el `manager` no está acotado a ninguna empresa, así que `GET /api/pilots` le devuelve el sueldo de cada piloto de cada transportista registrado. El dato es sensible y el control es solo por rol.

**Mitigación:** ninguna dentro de esta spec. La salida natural, si algún día molesta, es acotar al `manager` por empresa o retirarle el campo `salary` del Resource — las dos son un cambio pequeño y localizado, precisamente porque el ámbito vive en un único método.

### El `403` de ámbito filtra que el piloto existe

`resolvePilot()` responde 404 si el piloto no existe y 403 si existe pero es de otra empresa. Un `carrier` que itere ids puede distinguir los dos casos y deducir cuántos pilotos hay en el sistema.

**Mitigación:** consciente y aceptada, porque es el comportamiento de SPEC 04 con los vehículos y cambiarlo aquí crearía dos convenciones distintas para lo mismo. Devolver 404 en ambos casos sería más hermético y menos diagnosticable; se elige la coherencia con el resto del proyecto.

### Dos endpoints listan pilotos con reglas distintas

`GET /api/carriers/me/pilots` y `GET /api/pilots` devuelven el mismo conjunto para un `carrier`, con Resources distintos, formatos de fecha distintos y uno con salario y otro sin él. Quien llegue nuevo al código va a preguntarse cuál usar.

**Mitigación:** está documentado en Swagger y en el alcance de esta spec: el de SPEC 03 es el listado del transportista, el nuevo es el de administración. La duplicación es el precio de no tocar los tests de SPEC 03, y se paga a sabiendas.

### La columna `salary` no dice que es mensual

`decimal(10,2)` no lleva unidad ni periodicidad. Nada impide que alguien, dentro de un año, guarde ahí un pago por viaje o un salario quincenal, y el sistema lo aceptará sin queja.

**Mitigación:** el PHPDoc del modelo, la descripción de Swagger y esta spec lo dicen. Es documentación, no una restricción — no hay forma de imponerlo en la base. Si algún día conviven varias periodicidades, hará falta una columna que las distinga y esta spec habrá que releerla entera.

---

## Lo que **no** entra en esta spec

Repetición deliberada de lo ya dicho en el alcance:

- `show`, `store`, `update` y `destroy` del dominio `Pilot`.
- Desvincular un piloto de una empresa transportista.
- Tocar `GET /api/carriers/me/pilots`, `CarrierPilotResource` o `CarrierService`.
- Salario efectivo a futuro (`effective_from`) y aumentos programados.
- Motivo, nota o adjunto del cambio de salario.
- Editar o borrar filas de la bitácora.
- Historial global de la empresa y exportación a CSV o Excel.
- Nómina, bonificaciones, horas extra, descuentos, IGSS, ISR y pagos por viaje.
- Cambio de salario en lote.
- Moneda configurable: GTQ es convención del dominio.
- Que un `pilot` consulte su propio salario o su propio historial.
- Notificar al piloto de que su salario cambió.

Cada uno de ellos, si entra, va en su propia spec.
