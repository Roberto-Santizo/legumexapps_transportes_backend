# SPEC 14 — Gastos de mantenimiento de vehículos

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 04
> **Fecha:** 2026-08-18
> **Objetivo:** Registrar los gastos de mantenimiento de un vehículo —categoría, naturaleza preventiva o correctiva, monto, fecha y descripción— en un dominio propio cuyo listado siempre va acotado a un único vehículo.

Depende de **SPEC 01** por el guard JWT, el enum `UserRole` y el middleware `role:`; y de **SPEC 04** por la tabla `vehicles`, el modelo `Vehicle` y el ámbito por rol que acota a un `carrier` a los vehículos de su propia empresa.

Es el primer dominio del proyecto cuyo **listado no existe sin un filtro**: `GET /api/vehicle-expenses` sin `vehicleId` es 422, no un listado de toda la flota. La razón es que el consumidor es una sola pantalla —el detalle del vehículo— y un listado global no tiene lector. También es el primer recurso que **cuelga de un vehículo sin anidar la ruta**: la relación vive en el query param, no en la URL.

---

## Alcance

**Dentro:**

- **Tabla nueva `vehicle_expenses`** con `id`, `vehicle_id`, `category`, `nature`, `amount`, `expense_date`, `description`, `registered_by` y `timestamps`. No se toca `vehicles` ni ninguna otra tabla existente.
- **Dos enums nuevos**, ambos `string` y ambos obligatorios en el alta:
  - `App\Enums\VehicleExpenseCategory` — 22 casos, de `tires` a `other`.
  - `App\Enums\VehicleExpenseNature` — `preventive`, `corrective`.
- **Los dos ejes son independientes.** No hay validación cruzada entre `category` y `nature`: `brakes` + `preventive` es tan válido como `brakes` + `corrective`. Es la misma regla que `condition` vs `status` en SPEC 13.
- **Dominio `VehicleExpense` completo** en su propia subcarpeta, siguiendo la cadena de capas del proyecto: `VehicleExpenseServiceInterface`, `VehicleExpenseService`, `VehicleExpenseProvider`, `StoreVehicleExpenseRequest`, `UpdateVehicleExpenseRequest`, `VehicleExpenseResource` y `VehicleExpenseController`.
- **`routes/vehicle_expenses.php`** incluido desde `routes/api.php`, declarado como `apiResource` sobre `'/'` con `->parameters(['' => 'vehicleExpense'])`, **CRUD completo**: `index`, `store`, `show`, `update`, `destroy`.
- **`vehicleId` es obligatorio en `GET /api/vehicle-expenses`.** Sin él la petición es **422**, no un listado vacío ni un listado global. Es el único filtro obligatorio de todo el proyecto.
- **Filtros opcionales del listado:** `category`, `nature`, `dateFrom` y `dateTo`. Un valor inválido en cualquiera de ellos **se ignora**, no invalida la petición — la misma tolerancia que los catálogos de SPEC 06–09.
- **`totalAmount` en la raíz de la respuesta del listado:** la suma de `amount` de **todos** los gastos que cumplen los filtros, no solo los de la página actual.
- **Paginación opt-in con `limit`**, acotada a `[10, 100]` y envuelta en `PaginatedResource`, como el resto del proyecto.
- **Orden fijo:** `expense_date desc, id desc`. El `id` desempata dos gastos del mismo día.
- **Ámbito por rol, distinto en lectura y en escritura:**
  - `carrier` — solo vehículos de su propia empresa; tocar un gasto de un vehículo ajeno es **403**.
  - `administrator` y `manager` — **cualquier** vehículo de cualquier empresa.
  - **Escribir es solo `carrier` y `administrator`.** El `manager` recibe 403 en `store`, `update` y `destroy`.
  - El `pilot` recibe **403 en los cinco endpoints**.
- **El estado del vehículo no importa.** Un vehículo `inactive` acepta gastos igual que uno `active`: el mantenimiento pudo ocurrir antes de la baja.
- **`expense_date` no admite fechas futuras** (`before_or_equal:today`): un gasto se registra cuando ya ocurrió.
- **`vehicle_id` es inmutable.** Se fija en el alta y el `PATCH` no lo acepta; mover un gasto de vehículo es borrarlo y volverlo a crear.
- **`registered_by` sale del usuario autenticado**, nunca del body, y **no se reescribe** en el `update`: queda para siempre quien lo creó, aunque lo edite un `administrator`.
- **`DELETE` es borrado real.** Un gasto mal tecleado es basura, no historial.
- Tests Pest y documentación Swagger delegados a los agentes `feature-tests` y `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **Kilometraje del gasto** (`mileage_at_expense`). Se decidió dejarlo fuera a propósito; entra en otra spec y no toca `vehicles.mileage` de SPEC 13.
- **Adjuntar la factura** (imagen o PDF). El dominio no usa `FileStorageServiceInterface`.
- **`supplier` / taller y `invoice_number`.** Hoy caben en `description`.
- **Listado global de gastos de toda la flota** y comparativas entre vehículos.
- **Reportes y agregados por categoría, por mes o por empresa.** El listado devuelve un único `totalAmount`, no un desglose.
- **Costo por kilómetro, costo total de propiedad y proyección de mantenimiento.**
- **Mantenimiento programado**: próximo servicio, avisos por kilometraje o por fecha, órdenes de trabajo.
- **Catálogo administrable de categorías.** Se evaluó y se descartó: son dos enums, y añadir una categoría es un cambio de código, no un alta por API.
- **Bitácora de cambios del gasto.** No hay historial de ediciones: el `DELETE` borra de verdad y el `PATCH` no deja rastro.
- **Exportación a CSV o Excel.**
- **Moneda configurable.** GTQ es convención del dominio, documentada en Swagger.
- **Tocar `VehicleResource`, `VehicleService` o cualquier ruta de SPEC 04 y SPEC 13.** El detalle del vehículo no cambia de forma: los gastos se piden aparte.

---

## Modelo de datos

Esta spec **no toca ninguna tabla existente**. Crea una tabla, dos enums y un modelo.

### 1. Tabla `vehicle_expenses`

```php
Schema::create('vehicle_expenses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
    $table->string('category');
    $table->string('nature');
    $table->decimal('amount', 10, 2);
    $table->date('expense_date');
    $table->text('description');
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();

    $table->index(['vehicle_id', 'expense_date']);
});
```

- **Ninguna columna es `nullable`.** Los seis campos de negocio son obligatorios en el alta y siguen siéndolo después: el `PATCH` puede omitirlos, pero no mandarlos vacíos.
- `cascadeOnDelete` en `vehicle_id` sigue lo que ya hace `vehicles` con `carrier_id`. Hoy no existe borrado real de vehículos —`DELETE /api/vehicles/{id}` es baja lógica—, así que la cascada es una red por si algún día lo hay. La FK a `users` **no** cascadea: borrar al usuario que registró un gasto no puede borrar el gasto.
- `category` y `nature` son `string`, no `enum` de Postgres, igual que `type` y `status` en `vehicles`: añadir un caso es tocar PHP, no migrar la base.
- `amount` es `decimal(10,2)` — hasta 99 999 999.99 GTQ. Nada de flotantes con dinero.
- `expense_date` es `date`, no `datetime`: interesa el día, no la hora. Los `timestamps` guardan cuándo se capturó, que es otra cosa.
- `description` es `text` y **obligatoria**: es donde hoy caben el taller, el número de factura y el detalle de la pieza, que no tienen columna propia.
- El índice `(vehicle_id, expense_date)` cubre la consulta única de esta tabla: los gastos de un vehículo ordenados por fecha descendente.

### 2. `App\Enums\VehicleExpenseCategory`

```php
enum VehicleExpenseCategory: string
{
    case Tires = 'tires';
    case OilChange = 'oil_change';
    case Brakes = 'brakes';
    case SparePart = 'spare_part';
    case Battery = 'battery';
    case Suspension = 'suspension';
    case Engine = 'engine';
    case Transmission = 'transmission';
    case ElectricalSystem = 'electrical_system';
    case CoolingSystem = 'cooling_system';
    case Filters = 'filters';
    case AlignmentBalancing = 'alignment_balancing';
    case Clutch = 'clutch';
    case Exhaust = 'exhaust';
    case AirConditioning = 'air_conditioning';
    case BodyworkPaint = 'bodywork_paint';
    case GlassMirrors = 'glass_mirrors';
    case Inspection = 'inspection';
    case Washing = 'washing';
    case Towing = 'towing';
    case Labor = 'labor';
    case Other = 'other';
}
```

Enum **plano**, sin método `label()`, como `VehicleType`, `VehicleStatus` y `VehicleCondition`. La etiqueta en español la pone el front; la API habla en snake_case. `other` es un caso más del enum, sin trato especial en el código.

### 3. `App\Enums\VehicleExpenseNature`

```php
enum VehicleExpenseNature: string
{
    case Preventive = 'preventive';
    case Corrective = 'corrective';
}
```

Eje **independiente** de la categoría. `preventive` es lo que se hace antes de que falle; `corrective`, lo que se hace porque ya falló. La misma categoría admite las dos: cambiar llantas por desgaste programado es `tires` + `preventive`; cambiarlas por un reventón es `tires` + `corrective`. **No existe validación cruzada.**

### 4. Modelo `VehicleExpense`

```php
#[Fillable([
    'vehicle_id', 'category', 'nature', 'amount',
    'expense_date', 'description', 'registered_by',
])]
class VehicleExpense extends Model
{
    public function vehicle(): BelongsTo;                    // Vehicle
    public function registeredBy(): BelongsTo;               // User

    protected function casts(): array
    {
        return [
            'category' => VehicleExpenseCategory::class,
            'nature' => VehicleExpenseNature::class,
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }
}
```

`Vehicle` **no gana una relación `expenses()`** en esta spec. El acceso siempre nace del gasto, y añadirla invitaría a cargarla desde `VehicleResource`, que es justo lo que el alcance prohíbe.

### 5. Forma de la respuesta

`VehicleExpenseResource`, en camelCase como todo el proyecto:

```json
{
  "id": 41,
  "vehicleId": 7,
  "category": "tires",
  "nature": "preventive",
  "amount": "1250.00",
  "expenseDate": "12-08-2026",
  "description": "Cuatro llantas nuevas, taller El Rodaje, factura A-9912",
  "registeredBy": "Roberto Santizo",
  "createdAt": "12-08-2026 04:31:07 PM"
}
```

`registeredBy` es el **nombre** del usuario que lo registró, no su id: la pantalla lo muestra y nadie navega a ese usuario. El `id` sigue en la columna `registered_by`, pero no sale por la API. Las fechas van en `d-m-Y` y `d-m-Y h:i:s A`, el formato que ya usa el dominio `Pilot`.

Y el sobre del listado, con el acumulado en la raíz junto a la metadata de paginación:

```json
{
  "statusCode": 200,
  "message": "Gastos obtenidos correctamente",
  "totalAmount": "18430.50",
  "total": 23,
  "currentPage": 1,
  "lastPage": 3,
  "data": []
}
```

`totalAmount` suma **todos** los gastos que cumplen los filtros, no los de la página. Sin `limit` no hay paginación y `total`, `currentPage` y `lastPage` no aparecen, pero `totalAmount` **sí**: es dato de negocio, no metadata de paginación.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo.

1. **Enums.** Crear `app/Enums/VehicleExpenseCategory.php` con los 22 casos y `app/Enums/VehicleExpenseNature.php` con los dos. Nada más los usa todavía.

2. **Migración y modelo.** `php artisan make:model VehicleExpense -mf`. Rellenar la migración con las nueve columnas y el índice `(vehicle_id, expense_date)`, el modelo con su `#[Fillable]`, sus dos relaciones y sus cuatro casts, y la factory con datos coherentes (`amount` entre 100 y 5000, `expense_date` en los últimos seis meses, categoría y naturaleza aleatorias). Correr `php artisan migrate`.

3. **Contrato.** `app/Interfaces/VehicleExpense/VehicleExpenseServiceInterface.php` con los cinco métodos y su PHPDoc de array shapes: `getVehicleExpenses`, `createVehicleExpense`, `getVehicleExpenseById`, `updateVehicleExpense`, `deleteVehicleExpense`.

4. **Service, parte de lectura.** `app/Services/VehicleExpense/VehicleExpenseService.php` con `resolveVehicle()` —la guarda común: 404 si el vehículo no existe, 403 si es de otra empresa y el rol es `carrier`—, `resolvePerPage()` y `getVehicleExpenses()`. El listado ordena por `expense_date desc, id desc`, aplica los filtros tolerantes y calcula `totalAmount` con un `sum('amount')` sobre la consulta **antes** de paginar.

5. **Service, parte de escritura.** `createVehicleExpense()`, `getVehicleExpenseById()`, `updateVehicleExpense()` y `deleteVehicleExpense()`. Las cuatro pasan por `resolveVehicleExpense()`, que carga el gasto y reusa la comprobación de ámbito sobre su vehículo. `registered_by` sale de `auth('api')->user()` y el `update` no lo toca. `#[Override]` en cada método.

6. **Provider.** `app/Providers/VehicleExpense/VehicleExpenseProvider.php` con el `bind`, registrado en `bootstrap/providers.php`.

7. **FormRequests.** `StoreVehicleExpenseRequest` (los seis campos `required`, `category` y `nature` con `Rule::enum`, `amount` `numeric|min:0.01|max:99999999.99`, `expense_date` `date|before_or_equal:today`, `description` `string|max:1000`) y `UpdateVehicleExpenseRequest` (los cinco de negocio como `sometimes`, **sin `vehicle_id`**). `messages()` en español en ambos.

8. **Resource, controller y rutas.** `VehicleExpenseResource`; `VehicleExpenseController` con los cinco métodos, `try/catch` → `ResponseHandler` y el service inyectado por parámetro; `routes/vehicle_expenses.php` con el `apiResource` sobre `'/'`, `->parameters(['' => 'vehicleExpense'])`, `jwt.auth` en el grupo y `role:` por acción; `require` en `routes/api.php`. Verificación: `php artisan route:list --path=vehicle-expenses` muestra las cinco rutas.

9. **Tests.** Disparar el agente `feature-tests` sobre `VehicleExpense`: Feature test de los cinco endpoints, los cuatro roles, el 422 por `vehicleId` ausente, los filtros y el `totalAmount`; Unit test del service. Correr `php artisan test --compact --filter=VehicleExpense`.

10. **Documentación.** Disparar el agente `endpoint-docs` sobre `VehicleExpense` y regenerar `storage/api-docs/api-docs.json`.

11. **Cierre.** `vendor/bin/pint --dirty --format agent` y escribir `references/vehicle-expenses-api.md` para el frontend, con `references/zones-api.md` como plantilla.

---

## Criterios de aceptación

**Migración y modelo**

- [x] `php artisan migrate` crea `vehicle_expenses` con las nueve columnas y el índice `(vehicle_id, expense_date)`.
- [x] Ninguna columna de `vehicles` cambia y ninguna otra tabla se toca.
- [x] `VehicleExpense::factory()->create()` produce una fila válida sin argumentos.

**Alta**

- [x] `POST /api/vehicle-expenses` con los seis campos devuelve 201 y el gasto creado.
- [x] Omitir cualquiera de los seis campos devuelve 422 con el mensaje en español.
- [x] `category` o `nature` fuera del enum devuelve 422.
- [x] `amount = 0` devuelve 422; `amount = 0.01` se acepta.
- [x] `expense_date` de mañana devuelve 422; la de hoy se acepta.
- [x] `registered_by` queda con el id del usuario autenticado aunque el body mande otro.
- [x] Registrar un gasto sobre un vehículo con `status = inactive` devuelve 201.
- [x] `brakes` + `preventive` se acepta, igual que `brakes` + `corrective`.

**Listado**

- [x] `GET /api/vehicle-expenses` sin `vehicleId` devuelve 422.
- [x] `GET /api/vehicle-expenses?vehicleId=7` devuelve solo los gastos del vehículo 7.
- [x] Los resultados vienen ordenados por `expense_date` descendente, y dos gastos del mismo día por `id` descendente.
- [x] `totalAmount` es la suma de `amount` de todos los gastos filtrados, no solo los de la página.
- [x] Sin `limit` la respuesta trae `totalAmount` pero no `total`, `currentPage` ni `lastPage`.
- [x] Con `limit=10` la respuesta trae `totalAmount`, `total`, `currentPage` y `lastPage` en la raíz del sobre.
- [x] `limit=5` pagina de 10 en 10 y `limit=500` de 100 en 100.
- [x] `category=tires` devuelve solo esa categoría; `category=inexistente` devuelve el listado completo sin error.
- [x] `nature=preventive` devuelve solo los preventivos; un valor inválido se ignora.
- [x] `dateFrom` y `dateTo` acotan por `expense_date`, ambos inclusive; una fecha malformada se ignora.
- [x] Un `vehicleId` inexistente devuelve 404, no un listado vacío.

**Detalle, edición y borrado**

- [x] `GET /api/vehicle-expenses/{id}` devuelve el gasto; un id inexistente devuelve 404.
- [x] `PATCH` con un solo campo cambia solo ese campo y deja los demás intactos.
- [x] `PATCH` con `vehicleId` en el body **no** mueve el gasto de vehículo.
- [x] `PATCH` hecho por un `administrator` deja `registered_by` con el usuario original.
- [x] `PATCH` con body vacío devuelve 200 y no cambia nada.
- [x] `DELETE` devuelve 200 y la fila desaparece de la base; un segundo `DELETE` devuelve 404.

**Roles y ámbito**

- [x] Sin token, los cinco endpoints devuelven 401.
- [x] Un `pilot` recibe 403 en los cinco endpoints.
- [x] Un `manager` lee (`index`, `show`) gastos de cualquier empresa y recibe 403 en `store`, `update` y `destroy`.
- [x] Un `administrator` hace las cinco operaciones sobre vehículos de cualquier empresa.
- [x] Un `carrier` opera solo sobre vehículos de su empresa; con un vehículo ajeno recibe 403 en las cinco.
- [x] Un `carrier` que intenta leer o editar un gasto de un vehículo ajeno recibe 403, no 404.

**Integración y calidad**

- [x] `GET /api/vehicles/{vehicle}` responde exactamente igual que antes de esta spec: sin `expenses` y sin campos nuevos.
- [x] Ningún test de SPEC 04 ni de SPEC 13 cambia ni falla.
- [x] `php artisan test --compact` pasa completo.
- [x] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [x] `/api/documentation` muestra los cinco endpoints con sus schemas de request y response.
- [x] Existe `references/vehicle-expenses-api.md` con el contrato para el frontend.

---

## Decisiones

**Forma del dominio**

- **Sí:** dominio propio en `/api/vehicle-expenses`, plano, sin anidar bajo `/api/vehicles/{vehicle}`. Todos los `apiResource` del proyecto cuelgan de la raíz; anidar habría sido el primer recurso anidado y una excepción sin ganancia.
- **No:** embeber los gastos en `GET /api/vehicles/{vehicle}`. Habría cambiado la forma de `VehicleResource` —rompiendo al front de SPEC 04 y 13—, crecería sin límite y no podría paginar ni filtrar.
- **Sí:** `vehicleId` obligatorio en el listado, 422 si falta. El único consumidor es la pantalla de detalle del vehículo; un listado global de toda la flota no tiene lector hoy, y devolverlo por omisión sería una consulta cara que nadie pidió.
- **Sí:** CRUD completo, con `show` incluido. Cuesta lo mismo y el front lo quiere para el modal de edición.

**Categorías**

- **Sí:** enum `VehicleExpenseCategory` con 22 casos cerrados. Añadir una categoría es un cambio de código, revisado y desplegado, no un alta por API.
- **No:** catálogo en tabla con CRUD administrable (se evaluó como opción principal). Habría sido un dominio entero más —tabla, service, provider, rutas, tests— para un conjunto de valores que cambia una vez al año.
- **No:** texto libre en la categoría. Sin valores cerrados no hay agrupación posible, y el mismo concepto acabaría escrito de cinco formas.
- **Sí:** enum plano, sin `label()`. Es la convención de `VehicleType`, `VehicleStatus` y `VehicleCondition`: la API habla en snake_case y la etiqueta en español la pone el front.
- **Sí:** `other` como un caso más, sin trato especial en el código.
- **No:** una categoría `preventive_maintenance`. Con el eje `nature` aparte sería la misma información dos veces, y un gasto podría contradecirse a sí mismo.

**Preventivo / correctivo**

- **Sí:** `nature` como enum propio y **obligatorio**, en el gasto y no en la categoría. Es una decisión de quien registra, no una propiedad del tipo de trabajo.
- **No:** validación cruzada entre `category` y `nature`. Los dos ejes son independientes, igual que `condition` y `status` en SPEC 13: cambiar llantas puede ser programado o por reventón, y el sistema no tiene por qué opinar.
- **No:** `nature` nullable con un tercer estado "no clasificado". Son dos opciones; obligar a elegir cuesta un clic y evita un cajón de sastre que nadie limpia después.

**Campos**

- **Sí:** los seis campos de negocio son obligatorios y ninguna columna es `nullable`. A diferencia de SPEC 13, aquí la tabla es nueva y no hay filas viejas que sobrevivir, así que la base puede exigir lo mismo que la API.
- **Sí:** `description` obligatoria y de tipo `text`. Es donde caben hoy el taller, el número de factura y el detalle de la pieza, que no tienen columna propia.
- **No:** `mileage_at_expense`. Se sacó del alcance a propósito; entra en otra spec y no tocará `vehicles.mileage`, cuya regla de autorización de SPEC 13 queda intacta.
- **No:** `supplier`, `workshop` e `invoice_number` como columnas. Serían tres campos más sin consulta que los use; cuando exista un reporte por proveedor, tendrán su spec.
- **No:** adjuntar la factura. El proyecto ya tiene `FileStorageServiceInterface` y meterlo era barato, pero nadie lo pidió y añade ciclo de vida de archivos a un recurso que se borra de verdad.
- **Sí:** `expense_date` de tipo `date`, sin hora. Interesa el día del gasto; cuándo se capturó ya lo dicen los `timestamps`.
- **Sí:** `before_or_equal:today`. Un gasto se registra cuando ya ocurrió; una fecha futura es mantenimiento programado, que está fuera de alcance.
- **Sí:** `amount` con `min:0.01`. Un gasto de cero es un error de captura, no un gasto.
- **Sí:** `registeredBy` sale como el nombre en un string, no como objeto con `id`. La pantalla lo muestra y nadie navega a ese usuario.

**Ámbito y permisos**

- **Sí:** el ámbito se resuelve por el **vehículo**, no por un `carrierId` en la query. Con `vehicleId` obligatorio, el vehículo ya determina la empresa y un segundo filtro sería redundante y contradictorio.
- **Sí:** `manager` lee cualquier empresa y no escribe nada, igual que en SPEC 11. No tiene empresa propia, así que "solo la suya" no significaría nada.
- **Sí:** un `carrier` sobre un vehículo ajeno recibe **403, no 404**. Es la regla que ya aplica SPEC 04 en el mismo recurso; cambiarla aquí sería incoherente dentro del mismo vehículo.
- **Sí:** `registered_by` del usuario autenticado y **sin reescribir** en el `update`. Es la convención de `FuelPrice`, `Product` y `Zone`.
- **Sí:** `vehicle_id` inmutable. Un gasto nace atado a su vehículo; permitir moverlo abriría la puerta a mover gastos entre empresas y a falsear totales ya consultados.

**Borrado y rastro**

- **Sí:** borrado real. Un gasto mal tecleado es basura, no historial, y dejarlo con baja lógica ensuciaría el `totalAmount` o exigiría filtrarlo en cada consulta.
- **No:** `SoftDeletes` como en `FreightRate`. Ahí el soft delete existe porque una tarifa borrada debe distinguirse de un id inexistente; aquí ese caso no importa.
- **No:** bitácora de ediciones del gasto, como la de salarios de SPEC 11. El salario es un compromiso con una persona; un gasto es un apunte contable menor, y auditarlo costaría más que rehacerlo.

**Listado**

- **Sí:** `totalAmount` como nombre del acumulado. `total` ya lo ocupa el conteo de registros que `ResponseHandler` aplana desde el paginador; dos `total` distintos en la misma raíz eran una colisión garantizada.
- **Sí:** `totalAmount` se calcula sobre **todos** los resultados filtrados, no sobre la página. Un acumulado por página no responde a ninguna pregunta.
- **Sí:** `totalAmount` aparece también sin paginación. Es dato de negocio, no metadata del paginador.
- **Sí:** filtros tolerantes — un `category` o un `dateFrom` inválido se ignora. Es la convención de SPEC 06–09; un 422 aquí rompería la pantalla por un parámetro que el front manda vacío.
- **Sí:** orden `expense_date desc, id desc`. La fecha es lo que el usuario lee y el `id` desempata dos gastos del mismo día, cosa que `created_at` no garantiza.
- **No:** desglose por categoría o por mes en la respuesta. Es un reporte, y los reportes van en su propia spec.

---

## Riesgos

| Riesgo | Mitigación |
| --- | --- |
| `totalAmount` y `total` conviven en la misma raíz del sobre y el front confunde uno con otro | Nombres deliberadamente distintos y documentados en Swagger y en `references/vehicle-expenses-api.md`: `total` es el conteo de registros, `totalAmount` la suma en GTQ. |
| El `sum('amount')` del acumulado duplica la consulta del listado en cada petición | La consulta va acotada por `vehicle_id` obligatorio y cubierta por el índice `(vehicle_id, expense_date)`. Un vehículo no acumula miles de gastos; si algún día lo hace, se cachea o se mueve a un endpoint de resumen. |
| `cascadeOnDelete` en `vehicle_id` borraría el historial de gastos si alguien añade borrado real de vehículos | Hoy `DELETE /api/vehicles/{id}` es baja lógica y no borra filas, así que la cascada nunca dispara. Queda anotado aquí para que quien implemente el borrado real decida antes. |
| El borrado real hace irrecuperable un gasto borrado por error | Es la decisión tomada, con los ojos abiertos: el `DELETE` es solo `carrier` y `administrator`, y volver a capturar un gasto son seis campos. |
| Con 22 categorías, el front las escribe a mano y se desincroniza del enum al añadir una | `references/vehicle-expenses-api.md` lleva la lista completa de valores, y el Swagger regenerado la expone como `enum` del schema. |
| Alguien añade `Vehicle::expenses()` "de paso" y acaba cargándola en `VehicleResource` | El alcance lo prohíbe explícitamente y hay un criterio de aceptación que verifica que `GET /api/vehicles/{vehicle}` responde igual que antes. |

---

## Lo que **no** entra en esta spec

- **Kilometraje del gasto** (`mileage_at_expense`). Va en otra spec y no tocará `vehicles.mileage`.
- **Adjuntar la factura** (imagen o PDF). Este dominio no usa almacenamiento de archivos.
- **`supplier` / taller e `invoice_number`** como columnas propias. Hoy caben en `description`.
- **Listado global de gastos de toda la flota.** `vehicleId` es obligatorio, siempre.
- **Reportes y agregados** por categoría, por mes, por empresa o por naturaleza. Solo hay un `totalAmount`.
- **Costo por kilómetro, costo total de propiedad y proyección de mantenimiento.**
- **Mantenimiento programado:** próximo servicio, avisos por kilometraje o fecha, órdenes de trabajo.
- **Catálogo administrable de categorías.** Son dos enums; añadir un caso es un cambio de código.
- **Bitácora de ediciones del gasto.** El `PATCH` no deja rastro y el `DELETE` borra de verdad.
- **Exportación a CSV o Excel.**
- **Moneda configurable.** GTQ es convención del dominio.
- **Cualquier cambio en `VehicleResource`, `VehicleService` o las rutas de SPEC 04 y SPEC 13.**

Cada uno de estos, si aterriza, va en su propia spec.
