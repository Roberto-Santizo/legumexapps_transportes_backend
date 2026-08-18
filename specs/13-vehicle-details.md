# SPEC 13 — Ficha técnica y financiera del vehículo

> **Estado:** Aprobado
> **Depende de:** SPEC 01, SPEC 04
> **Fecha:** 2026-08-18
> **Objetivo:** Ampliar el vehículo con seis campos nuevos — condición, rendimiento, valor de compra, costo mensual de seguro, kilometraje y número de motor —, obligatorios en el alta, editables en la edición salvo el kilometraje, que solo un administrador puede cambiar.

Depende de **SPEC 01** por el guard JWT, el enum `UserRole` y el middleware `role:`; y de **SPEC 04** por la tabla `vehicles`, el modelo `Vehicle`, el dominio completo (`VehicleService`, sus FormRequests, su Resource y sus rutas) y el ámbito por rol que ya acota a un `carrier` a su propia empresa.

Es la primera spec que **amplía un dominio ya publicado sin crear uno nuevo**: no aparece ninguna tabla, ningún controller y ninguna ruta. Todo el trabajo es una migración aditiva sobre `vehicles`, un enum nuevo y la propagación de seis columnas por las capas que ya existen.

Es también la primera vez que un **campo suelto tiene su propia regla de autorización**. Hasta ahora el permiso se resolvía por ruta (`role:`, `carrier.required`) y todo el cuerpo corría la misma suerte. Aquí el `PATCH` es alcanzable por `carrier` y `administrator` igual que antes, pero `mileage` solo lo mueve el `administrator`: el permiso deja de ser propiedad del endpoint y pasa a ser propiedad del campo, comprobado en el service.

---

## Alcance

**Dentro:**

- **Una migración aditiva sobre `vehicles`** con seis columnas nuevas. No se crea ninguna tabla, no se borra ninguna columna y no se toca ninguna de las siete que ya existen.
- **Todas las columnas nacen `nullable` o con `default`**, para que las filas ya registradas sobrevivan a la migración sin backfill manual. Los defaults son de relleno (`1`, `used`), no valores de negocio: un vehículo creado por la API nunca los usa, porque el FormRequest exige los seis campos.
- **Enum nuevo `App\Enums\VehicleCondition`** con dos casos: `New = 'new'` y `Used = 'used'`. Es el tercer enum del dominio, junto a `VehicleType` y `VehicleStatus`.
- **`condition` NO es `status`.** Son dos campos distintos y ninguno reemplaza al otro: `status` es el estado operativo (`active`/`inactive`/`under_repair`) y sigue rigiendo la baja lógica y la unicidad de la placa; `condition` es cómo se adquirió el vehículo (`new`/`used`) y no gobierna nada.
- `Vehicle` amplía su `#[Fillable]` con las seis columnas y suma los casts correspondientes; `VehicleFactory` genera valores realistas para las seis.
- **Los seis campos son obligatorios en `POST /api/vehicles`.** El alta pasa de siete campos a trece, y omitir cualquiera de los nuevos es 422. Es un cambio incompatible para el cliente actual, asumido a propósito.
- **Cinco de los seis son editables en `PATCH /api/vehicles/{vehicle}`** con la semántica parcial de siempre (`sometimes|required`): enviarlos vacíos es 422, omitirlos no los toca.
- **`mileage` tiene autorización propia, comprobada en el service, no en un middleware:**
  - Enviarlo con **el mismo valor** que ya tiene el vehículo no es un cambio: el `PATCH` continúa con 200 sea cual sea el rol. Es lo que ocurre cuando el front reenvía el formulario completo con el campo oculto.
  - Enviarlo con un valor **distinto** siendo `administrator` lo actualiza, sin restricción: puede subir y puede bajar.
  - Enviarlo con un valor **distinto** siendo `carrier` es **403**, y no se aplica ningún otro cambio del cuerpo.
- **`engine_number` se normaliza a mayúsculas** antes de persistir y antes de filtrar, igual que la placa. `abc123` y `ABC123` son el mismo número de motor.
- **`engine_number` no es único.** A diferencia de la placa, dos vehículos pueden compartirlo sin conflicto: no hay índice, no hay comprobación en el service y la reactivación de un vehículo sigue revalidando **solo la placa**, exactamente como hoy.
- **Dos filtros nuevos en `GET /api/vehicles`**, sumados a los `status`, `carrierId` y `limit` que ya existen:
  - `condition` — valor del enum; **coincidencia exacta**.
  - `engineNumber` — **coincidencia parcial** `LIKE %term%` sobre el valor ya en mayúsculas, así que normalizar el término basta para ser case-insensitive (mismo truco que el `search` de Products).
  - Los dos son **tolerantes**: un valor inválido se ignora y devuelve el listado completo, nunca una lista vacía ni un 422.
- `VehicleResource` expone los seis campos en camelCase (`kilometersPerGallon`, `purchasePrice`, `monthlyInsuranceCost`, `mileage`, `engineNumber`, `condition`), sin ocultar ninguno por rol.
- El PHPDoc de `VehicleServiceInterface` actualiza sus tres array shapes (`$filters`, el del alta y el de la edición) y `updateVehicle` documenta el 403 del kilometraje.
- **El dinero es GTQ** por convención del dominio, igual que el salario de SPEC 11: la columna no lo dice, lo dicen el PHPDoc y Swagger.
- Tests Pest y documentación Swagger delegados a los agentes `feature-tests` y `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **El endpoint dedicado de kilometraje** (`PATCH /api/vehicles/{vehicle}/mileage`). El kilometraje se captura en el alta y, hasta que exista ese endpoint, solo un `administrator` lo corrige por el `PATCH` general.
- **La regla de que el kilometraje no puede bajar.** Se descarta deliberadamente: un odómetro se cambia y un dato se captura mal, y el `administrator` necesita poder corregirlo.
- **Bitácora de cambios** de kilometraje o de cualquiera de los otros cinco campos. No hay tabla de historial y ningún cambio deja rastro.
- **Unicidad del número de motor**, en cualquier forma. Ni índice, ni comprobación condicional como la de la placa.
- **Borrar el número de motor** mandando `null`. La columna admite `null` solo para las filas anteriores a esta spec; por la API nunca se llega a ese estado.
- **Backfill del número de motor** de los vehículos ya registrados. Se quedan en `null` y hay que capturarlos a mano.
- **Cualquier validación cruzada entre campos.** Un vehículo `new` con 90 000 km es válido, y un `purchase_price` de `1.00` también.
- **El resto de la póliza de seguro:** aseguradora, número de póliza, vigencia, deducible, cobertura y fecha de renovación. Esta spec guarda un único número: cuánto se paga al mes.
- **Usar el rendimiento para calcular nada.** `kilometers_per_gallon` es un dato de ficha; no alimenta la cotización de SPEC 09 ni ningún cálculo de costo de viaje.
- **Depreciación o valor actual del vehículo.** `purchase_price` es lo que costó, y no se recalcula nunca.
- **Alertas o mantenimientos disparados por kilometraje.**
- **Conversión de unidades.** Se guardan km/galón, kilómetros y quetzales; el backend no convierte a millas, litros ni dólares.
- **Ocultar los campos financieros según el rol.** Quien alcanza `GET /api/vehicles` ve `purchasePrice` y `monthlyInsuranceCost` de todos los vehículos de su ámbito.
- **Tocar el ámbito por rol de las rutas de vehículos, el filtro `carrierId`, la unicidad condicional de la placa o la baja lógica del `DELETE`.** Todo eso sigue exactamente como lo dejó SPEC 04, y el `manager` sigue sin alcanzar el dominio.
- **Exportar el inventario** a CSV o Excel.

---

## Modelo de datos

Esta spec **no crea ninguna tabla**. Todo el modelo de datos es una migración aditiva sobre `vehicles`, un enum nuevo y la propagación de las seis columnas por el modelo y la factory.

### 1. Migración `add_details_to_vehicles_table`

```php
Schema::table('vehicles', function (Blueprint $table) {
    $table->string('condition')->default(VehicleCondition::Used->value)->after('type');
    $table->decimal('kilometers_per_gallon', 6, 2)->default(1)->after('condition');
    $table->decimal('purchase_price', 12, 2)->default(1)->after('kilometers_per_gallon');
    $table->decimal('monthly_insurance_cost', 10, 2)->default(1)->after('purchase_price');
    $table->unsignedInteger('mileage')->default(1)->after('monthly_insurance_cost');
    $table->string('engine_number', 50)->nullable()->after('mileage');
});
```

| Columna | Tipo | Rango / forma | Nulo |
|---|---|---|---|
| `condition` | `string` + enum `VehicleCondition` | `new` \| `used` | no, default `used` |
| `kilometers_per_gallon` | `decimal(6,2)` | `0.01` – `9999.99` km/gal | no, default `1` |
| `purchase_price` | `decimal(12,2)` | `0.01` – `9999999999.99` GTQ | no, default `1` |
| `monthly_insurance_cost` | `decimal(10,2)` | `0.01` – `99999999.99` GTQ **al mes** | no, default `1` |
| `mileage` | `unsignedInteger` | `0` – `4294967295` km enteros | no, default `1` |
| `engine_number` | `string(50)` | texto en mayúsculas | **sí** |

Decisiones de la migración:

- **Cinco columnas llevan `default` y una es `nullable`.** Es la única forma de que la migración corra sobre una tabla con filas sin pedir un backfill a mano. Los defaults son relleno, no negocio: `1` no significa "cuesta un quetzal", significa "esta fila es anterior a la spec". Un vehículo creado por la API nunca los usa, porque los seis campos son `required` en el alta.
- **El default se queda permanente en la columna**, no se retira en una segunda migración. Como el FormRequest exige los seis campos, el default solo protege inserciones directas a base de datos.
- **`engine_number` es la excepción y va `nullable` sin default.** Rellenar todas las filas existentes con el mismo texto llenaría el inventario de números de motor falsos e indistinguibles; `null` dice la verdad — "no capturado" — y se ve de un vistazo cuáles faltan.
- **Dinero en `decimal`, nunca en flotante**, igual que `capacity` de SPEC 04 y `salary` de SPEC 11.
- **`mileage` es entero sin signo**: el odómetro no tiene decimales y no puede ser negativo. Los 4 294 967 295 km del `unsignedInteger` son cuatro órdenes de magnitud más de lo que recorre un camión en su vida.
- **`engine_number` no lleva índice.** Su filtro es `LIKE %term%`, que ningún índice btree puede aprovechar, y no hay unicidad que sostener.
- `condition` no es palabra reservada en PostgreSQL y Laravel entrecomilla los identificadores de todas formas, así que el nombre no necesita alias en ninguna consulta.
- El `down()` revierte con un único `dropColumn` de las seis.

### 2. Enum `App\Enums\VehicleCondition`

```php
namespace App\Enums;

enum VehicleCondition: string
{
    case New = 'new';
    case Used = 'used';
}
```

Mismo patrón que `VehicleType` y `VehicleStatus`: enum de respaldo `string`, claves en TitleCase y valores en inglés. Traducir `new`/`used` a pantalla es responsabilidad del cliente.

### 3. Modelo `Vehicle`

```php
#[Fillable([
    'carrier_id', 'plate', 'brand', 'model', 'year', 'capacity', 'type',
    'condition', 'kilometers_per_gallon', 'purchase_price',
    'monthly_insurance_cost', 'mileage', 'engine_number',
    'image', 'status',
])]
class Vehicle extends Model
{
    protected function casts(): array
    {
        return [
            'type' => VehicleType::class,
            'status' => VehicleStatus::class,
            'condition' => VehicleCondition::class,
            'capacity' => 'decimal:2',
            'kilometers_per_gallon' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'monthly_insurance_cost' => 'decimal:2',
            'mileage' => 'integer',
            'year' => 'integer',
        ];
    }
}
```

La relación `carrier()` no cambia. Los tres casts `decimal:2` hacen que esos valores viajen en el JSON como **cadena** (`"185000.00"`), no como número — el mismo comportamiento que ya tiene `capacity`, y el front ya lo maneja. `mileage`, en cambio, viaja como entero.

El PHPDoc del modelo es el único sitio que dice que `purchase_price` y `monthly_insurance_cost` están en **GTQ** y que el seguro es **mensual**; la columna no lo sabe.

### 4. `VehicleFactory`

```php
'condition' => fake()->randomElement(VehicleCondition::cases()),
'kilometers_per_gallon' => fake()->randomFloat(2, 3, 25),
'purchase_price' => fake()->randomFloat(2, 50000, 900000),
'monthly_insurance_cost' => fake()->randomFloat(2, 300, 4000),
'mileage' => fake()->numberBetween(0, 500000),
'engine_number' => strtoupper(fake()->bothify('??######')),
```

Rangos realistas para un camión de carga en Guatemala. La factory genera siempre un `engine_number`: el `null` de la columna es un estado heredado, no un caso que los tests tengan que fabricar por defecto.

---

## Plan de implementación

Nueve pasos. Del 1 al 3 el sistema sigue funcionando exactamente igual que antes (la API ni se entera de las columnas nuevas); el corte incompatible ocurre en el paso 4, cuando el alta empieza a exigir los seis campos.

### 1. Enum `VehicleCondition`

Crear `app/Enums/VehicleCondition.php` con los casos `New = 'new'` y `Used = 'used'`.

Nada lo usa todavía. El sistema sigue funcionando.

### 2. Migración, modelo y factory

- `php artisan make:migration add_details_to_vehicles_table --table=vehicles` y escribir las seis columnas con sus `default` y el `nullable` de `engine_number`, en el orden de la sección anterior. El `down()` las quita con un solo `dropColumn`.
- `Vehicle`: ampliar `#[Fillable]` con las seis columnas y sumar los cinco casts nuevos (`condition` al enum, tres `decimal:2` y `mileage` a `integer`). Documentar en el PHPDoc del modelo que el dinero es **GTQ** y que el seguro es **mensual**.
- `VehicleFactory`: generar las seis con los rangos realistas ya definidos.

Tras este paso la tabla tiene las columnas, las filas viejas tienen sus defaults y **ningún endpoint ha cambiado**: el alta sigue creando vehículos con siete campos y el Resource sigue devolviendo los mismos ocho.

### 3. `VehicleResource`

Añadir las seis propiedades en camelCase al `toArray()`, después de `type` y antes de `image`:

```php
'condition' => $this->condition->value,
'kilometersPerGallon' => $this->kilometers_per_gallon,
'purchasePrice' => $this->purchase_price,
'monthlyInsuranceCost' => $this->monthly_insurance_cost,
'mileage' => $this->mileage,
'engineNumber' => $this->engine_number,
```

`condition` sale como el valor del enum, igual que `type` y `status`. Los tres `decimal:2` salen como cadena; `mileage` como entero; `engineNumber` puede ser `null` en vehículos anteriores a esta spec.

Este paso es aditivo para el cliente: la respuesta gana campos y no pierde ninguno.

### 4. `StoreVehicleRequest` — el corte incompatible

Sumar seis reglas obligatorias con sus `messages()` en español:

```php
'condition' => ['required', Rule::enum(VehicleCondition::class)],
'kilometers_per_gallon' => ['required', 'numeric', 'min:0.01'],
'purchase_price' => ['required', 'numeric', 'min:0.01'],
'monthly_insurance_cost' => ['required', 'numeric', 'min:0.01'],
'mileage' => ['required', 'integer', 'min:0'],
'engine_number' => ['required', 'string', 'max:50'],
```

- Los tres números con decimales van `min:0.01`, no `min:0`: un rendimiento de cero, un vehículo que costó cero o un seguro de cero al mes son captura errónea, no datos.
- `mileage` sí admite `0`: un vehículo nuevo con cero kilómetros es legítimo. Es entero, así que `120000.5` es 422.
- **No hay validación cruzada** entre `condition` y `mileage`.

A partir de aquí, `POST /api/vehicles` exige trece campos.

### 5. `UpdateVehicleRequest`

Las mismas seis reglas con `sometimes` delante. `engine_number` queda `['sometimes', 'required', 'string', 'max:50']`: **no se puede vaciar**, un `null` es 422.

`mileage` se valida aquí como cualquier otro campo (`sometimes|required|integer|min:0`) — la regla de **quién** puede cambiarlo no es validación, es autorización, y vive en el service.

### 6. `VehicleServiceInterface`

Actualizar los tres array shapes del PHPDoc:

- `$filters` de `getVehicles`: sumar `condition?: string|null` y `engineNumber?: string|null`, documentando que el primero es exacto, el segundo parcial y que **los dos se ignoran si no son válidos**.
- El del alta: sumar los seis campos, con las unidades escritas (km/galón, GTQ, GTQ mensuales, kilómetros).
- El de la edición: sumar los seis como opcionales, y documentar que `updateVehicle` lanza `ForbiddenError` cuando un `carrier` intenta mover el kilometraje.

### 7. `VehicleService`

**7.1 — Filtros nuevos en `getVehicles()`**, junto al de `status` y con la misma tolerancia:

```php
$condition = isset($filters['condition']) ? VehicleCondition::tryFrom($filters['condition']) : null;

if ($condition !== null) {
    $query->where('condition', '=', $condition->value);
}

$engineNumber = $this->normalizeEngineNumber($filters['engineNumber'] ?? null);

if ($engineNumber !== null) {
    $query->where('engine_number', 'like', "%{$engineNumber}%");
}
```

`normalizeEngineNumber()` es el helper privado que hace `trim` + `Str::upper` y devuelve `null` si el valor no es una cadena o queda vacío. Lo comparten el filtro, el alta y la edición: es el único sitio que conoce la regla de normalización, igual que `Model::normalizeName()` en Products y Zones.

Como la columna se guarda ya en mayúsculas, el `LIKE` es case-insensitive sin recurrir a `ILIKE`. Las filas con `engine_number` a `null` nunca casan con el filtro, que es el comportamiento correcto: no tienen número que buscar.

**7.2 — `createVehicle()`**: pasar los seis campos al `Vehicle::create()`, con `engine_number` normalizado. `condition` llega del cuerpo; `status` sigue naciendo `active` y sin depender de él.

**7.3 — `updateVehicle()` y la autorización del kilometraje.** La comprobación va **al principio del método, justo después de `getVehicleById()`** y antes de tocar la placa, la imagen o cualquier otro campo:

```php
if (array_key_exists('mileage', $data) && (int) $data['mileage'] !== $vehicle->mileage) {
    if ($user->role !== UserRole::Administrator) {
        throw new ForbiddenError('Solo un administrador puede modificar el kilometraje del vehículo');
    }

    $vehicle->mileage = (int) $data['mileage'];
}
```

- La comparación es sobre **enteros**, así que `"120000"` y `120000` son el mismo kilometraje y no disparan nada.
- Mandar el valor que el vehículo ya tiene **no es un cambio**: no hay 403 y el `PATCH` continúa con normalidad, sea cual sea el rol. Es lo que ocurre cuando el front reenvía el formulario completo con el campo oculto.
- El 403 corta **antes de subir la imagen**, por la misma razón por la que la placa se valida antes: un `PATCH` condenado no puede dejar un archivo huérfano en el bucket.
- Un valor distinto siendo `administrator` se aplica sin más: **puede subir y puede bajar**.

El resto de campos nuevos entra en el `foreach` que ya existe, que pasa de cinco a nueve:

```php
foreach (['brand', 'model', 'year', 'capacity', 'type', 'condition',
          'kilometers_per_gallon', 'purchase_price', 'monthly_insurance_cost'] as $field) {
```

Y `engine_number` se asigna aparte, porque necesita normalizarse:

```php
if (array_key_exists('engine_number', $data)) {
    $vehicle->engine_number = $this->normalizeEngineNumber($data['engine_number']);
}
```

**7.4 — Lo que NO se toca**: `ensurePlateIsAvailable()`, `resolveScopedCarrierId()`, `storeImage()`, `resolvePerPage()`, `getVehicleById()` y `deleteVehicle()` quedan intactos. No aparece ningún `ensureEngineNumberIsAvailable()`, y la revalidación de placa al reactivar sigue mirando **solo la placa**.

### 8. Lo que no cambia en absoluto

`VehicleController` y `routes/vehicles.php` **no se tocan**: no hay endpoint nuevo, no hay ruta nueva y no cambia ningún middleware. El `manager` sigue sin alcanzar el dominio y el `pilot` tampoco.

### 9. Formato, tests y documentación

- `vendor/bin/pint --dirty --format agent`.
- Agente `feature-tests`: ampliar `VehicleTest` y el Unit del service con los casos nuevos (los seis campos obligatorios en el alta, los cinco editables, la matriz de kilometraje × rol, la normalización del número de motor y los dos filtros nuevos incluido su valor inválido).
- Agente `endpoint-docs`: actualizar los schemas OA de `StoreVehicleRequest`, `UpdateVehicleRequest` y `VehicleResource`, los parámetros de query de `index` en el controller, y regenerar `storage/api-docs/api-docs.json`.
- Entrega final: `references/vehicles-api.md` con el resumen de integración para el frontend, señalando el corte incompatible del alta.

---

## Criterios de aceptación

### Migración y modelo

- [ ] La migración corre sobre una tabla `vehicles` **con filas** sin error y sin backfill manual.
- [ ] Un vehículo existente antes de la migración queda con `condition = 'used'`, `kilometers_per_gallon = 1.00`, `purchase_price = 1.00`, `monthly_insurance_cost = 1.00`, `mileage = 1` y `engine_number = null`.
- [ ] El `down()` de la migración quita las seis columnas y deja la tabla como estaba.
- [ ] `VehicleCondition` tiene exactamente dos casos: `new` y `used`.
- [ ] `Vehicle::create()` con las seis columnas nuevas las persiste (están en el `#[Fillable]`).
- [ ] Un `Vehicle` recuperado de base devuelve `condition` como instancia de `VehicleCondition`, `mileage` como `int` y los tres decimales como cadena de dos decimales.

### Alta (`POST /api/vehicles`)

- [ ] Un alta con los trece campos válidos responde **201** y persiste los seis nuevos.
- [ ] Omitir cualquiera de los seis campos nuevos responde **422** con el mensaje en español de ese campo.
- [ ] `condition` fuera del enum (`antiguo`) responde **422**.
- [ ] `kilometers_per_gallon`, `purchase_price` o `monthly_insurance_cost` en `0` responden **422**; en `0.01` se aceptan.
- [ ] `mileage` en `0` se **acepta**; en `-1` o en `120000.5` responde **422**.
- [ ] `engine_number` de más de 50 caracteres responde **422**.
- [ ] Un alta con `engine_number = 'abc123'` persiste `ABC123`.
- [ ] Un alta con `condition = 'new'` y `mileage = 90000` responde **201**: no hay validación cruzada.
- [ ] Dos vehículos activos de la misma empresa pueden registrarse con **el mismo `engine_number`**, los dos con 201.
- [ ] El vehículo sigue naciendo con `status = active` y `condition` no influye en ello.

### Edición (`PATCH /api/vehicles/{vehicle}`)

- [ ] Un `administrator` cambia los seis campos y responde **200** con los valores nuevos.
- [ ] Un `carrier` cambia los **cinco** campos que no son el kilometraje y responde **200**.
- [ ] Un `carrier` que envía `mileage` **igual** al actual responde **200** y el resto del cuerpo se aplica.
- [ ] Un `carrier` que envía `mileage` **distinto** al actual responde **403** con `Solo un administrador puede modificar el kilometraje del vehículo`.
- [ ] Tras ese 403, **ningún** campo del cuerpo quedó guardado (ni `brand`, ni la imagen: el vehículo está exactamente como antes).
- [ ] Un `administrator` puede **bajar** el kilometraje y responde 200.
- [ ] Enviar `mileage` como `"120000"` (cadena) sobre un vehículo que ya tiene `120000` no dispara el 403.
- [ ] Omitir un campo no lo modifica; enviarlo vacío responde **422**.
- [ ] `engine_number` en `null` responde **422**.
- [ ] `engine_number = 'xyz789'` persiste `XYZ789`.
- [ ] Cambiar `condition` no altera `status`, y cambiar `status` no altera `condition`.
- [ ] Reactivar un vehículo `inactive` sigue revalidando **solo la placa**: un número de motor duplicado no bloquea la reactivación.

### Listado (`GET /api/vehicles`)

- [ ] `?condition=new` devuelve solo los vehículos nuevos; `?condition=used`, solo los usados.
- [ ] `?condition=antiguo` devuelve el **listado completo**, no una lista vacía ni un 422.
- [ ] `?engineNumber=abc` casa con un vehículo cuyo `engine_number` es `XABC123` (parcial y case-insensitive).
- [ ] `?engineNumber=` (vacío) devuelve el listado completo.
- [ ] Un vehículo con `engine_number = null` **no** aparece en ningún resultado de `?engineNumber=`.
- [ ] Los filtros nuevos se combinan con `status`, `carrierId` y `limit` sin interferir entre ellos.
- [ ] A un `carrier`, `?carrierId=` se le sigue ignorando y el ámbito de su empresa se mantiene con los filtros nuevos activos.

### Respuesta

- [ ] `VehicleResource` devuelve `condition`, `kilometersPerGallon`, `purchasePrice`, `monthlyInsuranceCost`, `mileage` y `engineNumber`, en camelCase.
- [ ] `mileage` viaja como **entero** y los tres decimales como **cadena** de dos decimales (`"185000.00"`).
- [ ] `engineNumber` es `null` en un vehículo anterior a la migración y el cliente no revienta.
- [ ] Los ocho campos que ya devolvía SPEC 04 siguen presentes y con el mismo nombre y tipo.

### Alcance y no-regresión

- [ ] `VehicleController` y `routes/vehicles.php` no tienen ni un cambio.
- [ ] No existe `ensureEngineNumberIsAvailable()` ni índice único sobre `engine_number`.
- [ ] No existe ninguna ruta `/{vehicle}/mileage` ni tabla de historial.
- [ ] Ningún test de otra spec se rompe: `php artisan test --compact` pasa entero.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] Swagger regenerado: los seis campos aparecen en los tres schemas y los dos filtros nuevos en los parámetros de `index`.

---

## Decisiones tomadas y descartadas

### `condition` es una columna nueva, no una ampliación de `status`

**Descartado:** añadir `new` y `used` como casos de `VehicleStatus`.

Habría evitado una columna, y mezcla dos ejes que no tienen nada que ver: un vehículo puede estar `under_repair` y ser `new` al mismo tiempo, y `status` ya gobierna la baja lógica del `DELETE` y la unicidad condicional de la placa. Meter ahí la condición de compra habría hecho que "dar de baja un vehículo nuevo" fuera una contradicción de estados.

**Consecuencia asumida:** el dominio tiene dos campos que en español se leen como "estado" (`status` y `condition`), y el front tiene que distinguirlos. Se paga con documentación: los dos schemas OA lo dicen explícitamente.

### El permiso del kilometraje vive en el service, no en un middleware

**Descartado:** una ruta aparte `PATCH /api/vehicles/{vehicle}/mileage` con `role:administrator`.

Era la forma canónica del proyecto —el permiso por ruta, resuelto antes de llegar al controller— y obliga al front a partir un formulario en dos peticiones que pueden fallar por separado. El `PATCH` general ya es alcanzable por `carrier` y `administrator`; lo único que cambia es que un campo del cuerpo tiene su propia regla.

Es el **primer precedente de autorización por campo** en el proyecto, y por eso la comprobación se escribe explícitamente en el service y se documenta en el PHPDoc del contrato: quien implemente `VehicleServiceInterface` mañana tiene que replicarla.

### Mandar el mismo kilometraje no es un cambio

**Descartado:** que un `carrier` reciba 403 por el mero hecho de que la clave `mileage` venga en el cuerpo.

Es lo correcto para un front que oculta el campo pero reenvía el formulario completo: el usuario no está intentando nada, solo mandó lo que ya había. La comprobación es sobre el **valor**, no sobre la presencia de la clave.

**Contraste deliberado con SPEC 11:** allí mandar el mismo salario es 400 porque cada `PATCH` tiene que dejar una fila de bitácora y una fila que no cambia nada es ruido. Aquí no hay bitácora, así que el mismo valor es simplemente un no-op silencioso. Dos reglas opuestas, cada una justificada por si hay historial detrás o no.

### El 403 del kilometraje aborta el `PATCH` completo

**Descartado:** ignorar `mileage` y aplicar el resto del cuerpo con 200.

Es lo que el proyecto ya hace con el `carrierId` que un `carrier` manda en un listado, y ahí se puede permitir porque es un filtro, no un dato. Aquí sería peor: el front recibe 200, se queda con la respuesta y cree que guardó un kilometraje que el servidor descartó. Fallar entero es más ruidoso y más honesto.

Por eso la comprobación va **antes** de subir la imagen: un `PATCH` condenado no puede dejar un archivo huérfano en el bucket, exactamente por el mismo motivo por el que la placa se valida antes del `storeImage()` en el alta.

### El número de motor no es único

**Descartado durante la definición:** aplicarle la unicidad condicional de la placa (`ensureEngineNumberIsAvailable`, único entre vehículos no `inactive`).

Se descartó en la clarificación: el número de motor es un dato de ficha, no un identificador, y un duplicado no rompe nada en el negocio. Quitarlo simplifica tres cosas de golpe: no hay comprobación en el alta, no hay comprobación en la edición, y la reactivación de un vehículo sigue revalidando **solo la placa** — que era el punto donde la unicidad doble se habría vuelto molesta, porque un vehículo desactivado no podría volver al servicio por un número de motor mal capturado en otra empresa.

### El número de motor es `nullable` y no se rellena

**Descartado:** backfill con un texto fijo (`PENDIENTE`) o con un texto único por fila (`PENDIENTE-{id}`).

Un texto fijo llena el inventario de números de motor falsos e indistinguibles; uno único por fila es peor, porque parece un dato real. `null` dice exactamente lo que pasa —"esta fila es anterior a la spec y no se capturó"— y se filtra de un vistazo.

**Consecuencia asumida:** `engine_number` es la única columna de las seis que puede ser `null`, y el Resource lo devuelve así. El cliente tiene que tolerarlo. Por la API no se llega nunca a ese estado: es `required` en el alta y no se puede vaciar en la edición.

### Las otras cinco columnas llevan `default` de relleno, y se queda puesto

**Descartado:** columnas `NOT NULL` sin default y backfill en la propia migración con un `UPDATE`.

Es equivalente en resultado y más frágil de escribir. Con el `default` la migración es una sola llamada declarativa y las filas viejas quedan resueltas por la base.

**Descartado:** retirar el default en una segunda migración, una vez capturados los datos. No aporta nada: el FormRequest exige los seis campos, así que el default no se aplica jamás a un vehículo creado por la API; solo protege una inserción directa a base de datos, que es exactamente cuando quieres que haya un valor.

**Consecuencia asumida:** un `purchase_price` de `1.00` en producción significa "no capturado", y nada en la base lo distingue de un vehículo que de verdad costó un quetzal. El alta acepta `0.01` como mínimo, así que la distinción es por convención, no por regla.

### Los seis campos son obligatorios en el alta

**Descartado:** entrar como opcionales durante una transición y endurecerlos en una spec posterior.

Rompe al cliente actual de `POST /api/vehicles`, que pasa de siete campos a trece, y se asume a propósito: un inventario con la mitad de las fichas vacías no sirve para lo que se está construyendo, y una "transición" sin fecha se queda para siempre. La incompatibilidad se señala en `references/vehicles-api.md`.

### El kilometraje se captura una vez y esta spec no lo mueve

**Descartado:** la regla de que el kilometraje no puede bajar.

Estaba sobre la mesa y se retiró: un odómetro se reemplaza, un dato se captura mal y el `administrator` necesita poder corregirlo hacia abajo. Impedirlo habría obligado a inventar un endpoint de corrección para deshacer lo que el propio endpoint no deja hacer.

**Descartado:** el endpoint dedicado `PATCH /api/vehicles/{vehicle}/mileage` y su bitácora. El kilometraje real se va a actualizar desde otras funcionalidades (viajes, mantenimientos), y esas van a traer su propia forma de escribirlo. Adelantar aquí un endpoint suelto sería decidir por ellas.

### Rendimiento en km/galón, no en litros por 100 km

**Descartado:** `liters_per_100km` y "autonomía total del tanque en km".

Se elige km/galón porque es la unidad en la que se compra combustible en Guatemala y en la que ya razona SPEC 06 (`fuel_prices`), lo que deja el dato listo para un cálculo de costo por kilómetro el día que se necesite. La autonomía total se descarta porque no es convertible sin conocer la capacidad del tanque, que no se guarda.

### Del seguro solo se guarda cuánto se paga al mes

**Descartado:** la suma asegurada, y la póliza completa (aseguradora, número, vigencia, deducible).

Se pidió el costo mensual explícitamente. La suma asegurada es un dato distinto, no derivable de la prima, y la póliza completa es un submodelo con su propio ciclo de vida y sus propias fechas — si hace falta, es una tabla `vehicle_insurances`, no cinco columnas más en `vehicles`.

### Filtro exacto para la condición, parcial para el número de motor

`condition` es un enum de dos valores: la coincidencia exacta es la única que tiene sentido. `engineNumber` es un identificador largo que la gente recuerda a medias, así que va `LIKE %term%`.

Que la columna se guarde en mayúsculas hace el filtro case-insensitive **gratis**, sin `ILIKE` — el mismo truco que el `search` de Products. Es la razón práctica de normalizar, más allá de la consistencia con la placa.

Los dos son **tolerantes** por convención del proyecto: un valor inválido se ignora en vez de vaciar el listado.

### Los campos financieros no se ocultan por rol

Un `carrier` ve `purchasePrice` y `monthlyInsuranceCost` de los vehículos de su empresa, que son suyos, y un `administrator` los ve todos. El `manager` y el `pilot` no alcanzan el dominio de vehículos en absoluto, así que no hay a quién ocultárselos.

**Descartado:** filtrar campos dentro del Resource según el rol. Sería el primer Resource del proyecto con salida variable, y no hay ningún rol que hoy justifique la complejidad.

---

## Riesgos identificados

### El alta deja de funcionar para el cliente actual

`POST /api/vehicles` pasa de siete campos obligatorios a trece. **En cuanto esto se despliegue, cualquier front que no haya actualizado su formulario recibe 422 en todas las altas.** No hay periodo de gracia y no lo hay a propósito.

**Mitigación:** desplegar backend y frontend coordinados, y dejar el corte escrito en primera línea de `references/vehicles-api.md`. Es el riesgo más probable de esta spec y el más fácil de olvidar, porque la edición y el listado sí siguen siendo compatibles y dan una falsa sensación de que todo lo es.

### Los valores de relleno se confunden con datos reales

Un vehículo heredado queda con `purchase_price = 1.00` y `monthly_insurance_cost = 1.00`. Nada en la base marca esas filas como "sin capturar", y un informe que sume el valor del inventario dará un total silenciosamente equivocado.

**Mitigación parcial:** `engine_number = null` es la única marca fiable de fila heredada, porque la API nunca produce ese estado. Cualquier reporte que necesite distinguir debe apoyarse en esa columna, no en los importes. Capturar las fichas pendientes es trabajo manual y queda fuera de esta spec.

### La regla del kilometraje es invisible desde las rutas

Quien abra `routes/vehicles.php` ve `role:carrier,administrator` en el `update` y concluye que un `carrier` puede editar todo el cuerpo. La restricción del kilometraje no aparece en ninguna ruta ni en ningún middleware: vive dentro de `VehicleService::updateVehicle()`.

**Mitigación:** documentarla en el PHPDoc de `VehicleServiceInterface` —que es lo que lee quien vaya a sustituir la implementación—, en el schema OA de `UpdateVehicleRequest` y en el resumen de integración. Y un test que la fije, para que no se pierda en un refactor.

### Un `mileage` mal enviado por el front hace daño en manos de un `administrator`

La regla protege al `carrier`, no al `administrator`, que puede subir y bajar el kilometraje sin restricción. Si el formulario del front envía `mileage: 0` porque el input oculto llegó vacío, un `administrator` **borra el kilometraje real sin ningún aviso** — y sin bitácora, no hay forma de recuperar el valor anterior.

**Mitigación:** ninguna en el backend, es una consecuencia aceptada de descartar tanto la regla de no-decrecimiento como el historial. Lo que reduce la exposición es que el campo va oculto en el formulario y que el no-op de "mismo valor" cubre el caso normal. Si esto llega a ocurrir, es el argumento para traer la bitácora en su propia spec.

### El filtro `engineNumber` no puede usar índice

`LIKE %term%` con comodín a la izquierda fuerza recorrido de tabla. Con el volumen actual de `vehicles` es irrelevante; con decenas de miles de filas y sin `carrier_id` acotando (el caso del `administrator`) empezaría a notarse.

**Mitigación:** ninguna hoy, y está bien así — poner un índice GIN con `pg_trgm` para un buscador que todavía nadie usa es optimizar a ciegas. Queda anotado por si el listado se vuelve lento.

### Dos `PATCH` concurrentes sobre el mismo vehículo

La comparación del kilometraje lee el valor actual y decide sin bloquear la fila. Dos ediciones simultáneas pueden pisarse: la segunda compara contra un valor ya obsoleto.

**Impacto bajo y asumido:** el `updateVehicle` de SPEC 04 ya tiene exactamente el mismo comportamiento con la placa y el estado, no hay bitácora que quede inconsistente, y el escenario exige dos administradores editando el mismo vehículo en el mismo segundo. Añadir un `lockForUpdate` aquí y no en el resto del método sería una garantía a medias.
