# SPEC 27 — Cargas de combustible del viaje

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 06, SPEC 24
> **Fecha:** 2026-09-10
> **Objetivo:** Publicar el dominio `TripFuel` —las cargas de combustible que la empresa transportista registra sobre un viaje y el piloto asignado confirma—, exigiendo la primera de ellas en la misma llamada que asigna piloto y vehículo.

Es **un dominio nuevo con tabla propia**, y no dos columnas en `trips`, porque un viaje puede recibir combustible más de una vez y una columna lo impediría para siempre.

Depende de SPEC 06 solo por el **enum** `App\Enums\FuelType`, no por la tabla: el tipo elegido no se comprueba contra `fuel_prices` y no se guarda ningún precio.

Hereda dos precedentes de SPEC 26 y rompe uno: anida las rutas de escritura y lectura bajo `{trip}` como hizo con `/positions`, pero **el piloto no queda fuera de la lectura** —aquí el dato es suyo— y además **actúa sobre las filas**, que es lo que en las posiciones no ocurría.

---

## Alcance

**Dentro:**

- **Tabla nueva `trip_fuels`**, una fila por carga: `trip_id`, `gallons` (`decimal(8,2)`), `fuel_type`, `loaded_at` (`timestamp` **nullable**), `confirmed_by` (nullable), `registered_by` y `timestamps`. FK sin `cascade`, como el resto del proyecto.
- **Ciclo de vida de dos estados, sin columna `status`**: una carga nace **sin confirmar** (`loaded_at = null`) y se confirma una sola vez. El estado se deriva de `loaded_at`, igual que `currentValue` se deriva en SPEC 17 en vez de guardarse.
- **Dominio `TripFuel` completo** en su subcarpeta: `TripFuelServiceInterface`, `TripFuelService`, `TripFuelProvider`, `StoreTripFuelRequest`, `TripFuelResource` y `TripFuelController`. El service inyecta `TripServiceInterface` **por constructor** para no duplicar la matriz de ámbito de SPEC 24, exactamente como hace `TripPositionService`.
- **Tres rutas nuevas**, repartidas en dos archivos:

  | Ruta | Middleware | Efecto |
  |---|---|---|
  | `POST /api/trips/{trip}/fuels` | `role:carrier` | Registra una carga sin confirmar |
  | `GET /api/trips/{trip}/fuels` | `jwt.auth` | Listado del viaje, acotado por ámbito |
  | `PATCH /api/trip-fuels/{tripFuel}/confirm` | `role:pilot` | `loaded_at = now()`, `confirmed_by = ` el piloto |

  Las dos primeras viven en `routes/trips.php` —que pasa de once a trece rutas—, con `TripFuelController`, siguiendo a `TripPositionController`. La tercera vive en `routes/trip-fuels.php`, archivo nuevo incluido desde `routes/api.php`: no se anida porque el id de la carga ya identifica el viaje.
- **`PATCH /api/trips/{trip}/assignment` pasa de dos campos a cuatro**, los cuatro obligatorios: `pilotId`, `vehicleId`, `fuelGallons` y `fuelType`. Es **cambio incompatible sin periodo de gracia**, como el `POST` de vehículos en SPEC 13 y el de destinos en SPEC 21.
- **La primera carga la crea `assign()` dentro de su propia transacción**, detrás del `lockForUpdate` que ya tiene: ningún viaje queda asignado con cero cargas. **Reasignar añade otra fila**, no pisa la anterior — y por eso una reasignación deja rastro donde piloto y vehículo no lo dejan.
- **`fuelGallons`** se valida `required|numeric|min:0.01`, sin ninguna validación cruzada: no se compara contra `vehicles.kilometers_per_gallon`, ni contra la distancia, ni contra un techo de negocio. **`fuelType`** se valida contra los cuatro casos del enum con `Rule::enum`; fuera del enum es 422. Cada carga lleva **su propio tipo**: dos cargas del mismo viaje pueden no coincidir.
- **Cuatro guardas del `POST`, y su orden es contrato**, con el precedente literal de SPEC 26: viaje inexistente → **404**; borrado → **400**; el viaje no lo asignó la empresa de quien llama (o no está asignado) → **403**; el viaje está `finished` → **400**. Se carga en `pending` **y en `in_route`** —una recarga en carretera es el caso real—, nunca después.
- **Confirmar es del piloto asignado y solo suyo**: cualquier otro piloto recibe 403, y los otros tres roles no alcanzan la ruta. **Sin cuerpo y sin FormRequest**, como `/start` y `/finish` de SPEC 24: el efecto único es la fecha del servidor. Confirmar no comprueba el `status` del viaje.
- **Reconfirmar es 200 sin escribir nada**, devolviendo la carga con su `loaded_at` original: `loaded_at` se escribe una vez y no se pisa. Silencio deliberado, con el precedente del piso de 15 segundos de SPEC 26.
- **`PATCH /api/trips/{trip}/start` gana una guarda**, la cuarta y última: **400 «Debes confirmar al menos una carga de combustible antes de iniciar el viaje»** si el viaje no tiene ninguna fila en `trip_fuels` con `loaded_at` distinto de `null`. Es la **segunda modificación de esta spec a un endpoint de SPEC 24** y la que convierte la confirmación del piloto en un requisito real y no en un trámite: sin ella, `loaded_at` sería una fecha que nadie mira.

  Va **después** de las tres guardas que ya tiene —viaje borrado, no eres el piloto asignado, ya fue iniciado—, para que reintentar sobre un viaje en curso siga diciendo «El viaje ya fue iniciado» y no hable de combustible.

  Consecuencia buscada: **el piloto se bloquea a sí mismo hasta confirmar**. Y consecuencia no buscada: si la empresa no registra ninguna carga, el viaje **no puede arrancar por ninguna vía** —el administrador tampoco puede desbloquearlo, porque su `PATCH` no toca `trip_fuels`—; la única salida es que el `carrier` haga el `POST` y el piloto confirme.
- **Lectura con el ámbito de SPEC 24, incluido el piloto asignado**: `administrator` y `manager` todo, el `carrier` lo que alcanza su empresa, el `pilot` sus propios viajes. Se aparta a propósito del veto al piloto en `GET /trips/{trip}/positions`, porque aquí el dato es suyo.
- **`totalGallons` en la raíz del sobre** del listado: suma de los galones de las cargas **confirmadas**, calculada sobre la consulta clonada **antes** de paginar y presente también sin `limit`. Precedente literal de `totalAmount` en SPEC 14: es dato de negocio, no metadata del paginador.
- **`TripResource` pasa de 34 a 35 claves** con `totalFuelGallons`, misma suma y misma regla —solo confirmadas—, resuelta con `withSum` para no cargar las filas.
- Orden fijo del listado `id ASC` —`loaded_at` puede ser `null` y no sirve para ordenar— y paginación opt-in acotada a `[10, 100]`.
- Tests Pest (Feature de las tres rutas nuevas y de `/assignment`, Unit del service) y Swagger regenerado.
- Resumen de integración para el frontend en `references/trip-fuels-api.md`.

**Fuera de alcance (para specs futuras):**

- **Costo del combustible.** No se guarda ni el precio vigente ni el total en quetzales. `fuel_prices` no se consulta en ningún punto y `TripFuelService` **no** inyecta `FuelPriceServiceInterface`.
- **Exigir que el tipo tenga precio vigente.** Se puede cargar `diesel` aunque ningún `FuelPrice` de ese tipo esté `active`. `fuelType` es una etiqueta, no una llave foránea.
- **Corregir o borrar una carga.** No hay `PATCH` de galones ni `DELETE`: la tabla es **append-only**, como `trip_positions`. Una carga mal tecleada se queda ahí para siempre y solo se puede compensar registrando otra — y los galones no admiten negativos, así que ni eso. Es el hueco más grande que deja la spec.
- **Desconfirmar.** `loaded_at` no vuelve a `null` por ninguna vía.
- **Galones realmente recibidos.** El piloto confirma o no confirma; no reporta una cantidad distinta ni una observación. No hay discrepancia entre asignado y recibido porque no hay dos números.
- **Consumo real y rendimiento.** Nadie registra cuánto se gastó, y `vehicles.kilometers_per_gallon` no se cruza con nada.
- **Guarda de combustible en `/finish`.** Cerrar un viaje no comprueba nada: una carga registrada y jamás confirmada no impide terminarlo.
- **Notificaciones y tiempo real.** Registrar o confirmar una carga no emite por Reverb ni manda correo. El canal `trips.{tripId}` sigue llevando solo posiciones.
- **Combustible en el listado de viajes.** `TripListResource` queda en 15 claves, `GET /trips/current` no cambia de forma y `GET /api/trips` no gana filtro por combustible: sus filtros siguen siendo los nueve de SPEC 24.
- **Que el administrador cargue o confirme.** El `PATCH` general de `trips` no acepta nada de combustible, y ni `administrator` ni `manager` alcanzan las dos rutas de escritura.
- **Backfill.** Los viajes asignados antes de esta spec se quedan con cero cargas, `totalFuelGallons` en `0.00` y **no podrán arrancar** hasta que su empresa registre una carga con el `POST` y el piloto la confirme. No hay script de relleno a propósito.
- **Listado global de cargas.** No existe `GET /api/trip-fuels`: el índice va siempre viaje → cargas.

---

## Modelo de datos

Esta spec crea **una tabla, un modelo y una factory**, y toca **un service ya publicado** (`TripService`) y **un Resource** (`TripResource`). No crea ningún enum: reutiliza `App\Enums\FuelType` de SPEC 06.

### 1. Tabla `trip_fuels`

```php
Schema::create('trip_fuels', function (Blueprint $table) {
    $table->id();

    /** Sin cascade, como el resto del proyecto: borrar el viaje es baja lógica y no toca el rastro. */
    $table->foreignId('trip_id')->constrained('trips');

    /** Galones asignados en esta carga. Siempre > 0: no hay cargas negativas ni correcciones. */
    $table->decimal('gallons', 8, 2);

    /** Uno de los cuatro casos de FuelType. Por CARGA, no por viaje: dos cargas pueden diferir. */
    $table->string('fuel_type');

    /**
     * El único nullable de la tabla, y el que define el ciclo de vida: null = sin confirmar.
     * Lo pone el servidor con now() al confirmar, nunca el dispositivo, y no se pisa jamás.
     */
    $table->timestamp('loaded_at')->nullable();
    /** El piloto que confirmó. Nace null y se escribe junto a loaded_at, nunca por separado. */
    $table->foreignId('confirmed_by')->nullable()->constrained('users');

    /** El usuario de la empresa que registró la carga. En la primera fila, el que asignó el viaje. */
    $table->foreignId('registered_by')->constrained('users');

    $table->timestamps();

    /** La única consulta del dominio: las cargas de un viaje, en orden de registro. */
    $table->index('trip_id');
});
```

**Por qué no hay columna `status`.** Los dos estados son «sin confirmar» y «confirmada», y `loaded_at` ya los distingue sin ambigüedad. Una columna aparte podría contradecir a la fecha.

**Por qué el orden es `id ASC` y no por fecha.** `loaded_at` es nullable y `created_at` empata entre dos cargas del mismo segundo. El `id` es la cronología real de registro, con el mismo argumento por el que la bitácora de salarios de SPEC 11 se ordena por `id desc`.

### 2. Modelo `App\Models\TripFuel`

```php
/** @use HasFactory<TripFuelFactory> */
#[Fillable(['trip_id', 'gallons', 'fuel_type', 'loaded_at', 'confirmed_by', 'registered_by'])]
class TripFuel extends Model
{
    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'loaded_at' => 'datetime',
        ];
    }

    // trip(), confirmedBy(), registeredBy(): BelongsTo
}
```

`gallons` **no se castea**: sale del Resource formateado a dos decimales, como `price` en SPEC 17 y `salary` en SPEC 11.

`Trip` **no gana** una relación `fuels()` visible en su Resource, igual que `Vehicle` no ganó `expenses()` ni `Trip` ganó `positions()`. Sí gana la relación en el modelo, porque `withSum` la necesita para `totalFuelGallons`.

### 3. Cuerpo de `PATCH /api/trips/{trip}/assignment`

De dos campos a cuatro, los cuatro obligatorios:

```json
{
  "pilotId": 12,
  "vehicleId": 8,
  "fuelGallons": 45.5,
  "fuelType": "diesel"
}
```

### 4. Cuerpo de `POST /api/trips/{trip}/fuels`

Los dos campos de la carga, sin el viaje —va en la URL— y sin autor —sale del token—:

```json
{
  "gallons": 20,
  "fuelType": "diesel"
}
```

### 5. `TripFuelResource` — ocho claves

```
id, tripId, gallons, fuelType, isConfirmed, loadedAt, confirmedByName, registeredByName
```

- `gallons`: string de dos decimales (`"45.50"`).
- `fuelType`: valor **crudo del enum en inglés**, sin traducir, como `LocationType` en SPEC 21 y `TripStatus` en SPEC 24.
- `isConfirmed`: booleano **derivado** de `loaded_at !== null`. No tiene columna.
- `loadedAt`: `d-m-Y h:i:s A` o `null`, con el formato de fecha del resto del dominio.
- `confirmedByName`: `null` mientras no se confirme.

### 6. Lo que cambia en `TripResource`

Pasa de 34 a 35 claves con **`totalFuelGallons`**: string de dos decimales, suma de `gallons` de las cargas **confirmadas** del viaje, `"0.00"` cuando no hay ninguna. Se resuelve con `withSum` acotado a `loaded_at IS NOT NULL` desde `TripService`, no cargando las filas.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo. El orden no es negociable en un punto: **el cambio incompatible de `/assignment` (paso 10) va al final de la parte funcional**, cuando el dominio ya existe. Al revés dejaría la asignación escribiendo en una tabla que aún no está.

1. **Migración `create_trip_fuels_table`.** Las siete columnas y el índice de la sección anterior. Verificación: `php artisan migrate` y `php artisan migrate:rollback` corren limpios.

2. **Modelo `App\Models\TripFuel` y su factory.** `#[Fillable]`, `casts()` con `FuelType` y `datetime`, y las tres `BelongsTo` (`trip`, `confirmedBy`, `registeredBy`). La factory nace **sin confirmar** (`loaded_at` y `confirmed_by` en `null`) y con un estado `confirmed()` que pone las dos. Verificación: `TripFuel::factory()->create()` y `TripFuel::factory()->confirmed()->create()`.

3. **`Trip::fuels()`** (`HasMany`). Es la única línea que esta spec añade al modelo `Trip`; ningún Resource la expone todavía.

4. **`TripFuelServiceInterface` + `TripFuelProvider`**, registrado en `bootstrap/providers.php`, con un `TripFuelService` que inyecta `TripServiceInterface` por constructor y aún no implementa nada. Verificación: `php artisan route:list` sigue corriendo y el contenedor resuelve el contrato.

5. **`TripFuelService::getTripFuels()`.** Resuelve el viaje con `TripServiceInterface::getTripById()` —que ya aplica el ámbito de SPEC 24 y lanza 404 o 403 por su cuenta—, lista por `id ASC`, pagina opt-in `[10, 100]` y calcula `totalGallons` sobre la consulta clonada antes de paginar, acotado a `loaded_at IS NOT NULL`.

6. **`TripFuelService::create()`** con sus cuatro guardas en orden: 404 inexistente, 400 borrado, 403 fuera de la empresa asignataria o viaje sin asignar, 400 `finished`. Escribe `registered_by` desde el usuario autenticado.

7. **`TripFuelService::confirm()`.** Resuelve la carga —404 si no existe—, exige que el piloto autenticado sea el `pilot_id` del viaje (403), y si `loaded_at` ya tiene valor devuelve la carga tal cual con 200 sin escribir. Si no, escribe `loaded_at = now()` y `confirmed_by` en la misma llamada.

8. **`StoreTripFuelRequest`, `TripFuelResource` y `TripFuelController`**, con los tres métodos (`index`, `store`, `confirm`) y su `try/catch` a `ResponseHandler`. El controller inyecta el contrato por parámetro de método y añade `totalGallons` a mano al array de `data` del listado, como hace `VehicleExpenseController` con `totalAmount`.

9. **Las tres rutas.** `POST` y `GET /api/trips/{trip}/fuels` en `routes/trips.php`, declaradas junto a las de posiciones y **antes** del `apiResource`; `routes/trip-fuels.php` nuevo con la ruta de confirmación, incluido desde `routes/api.php`. Verificación: `php artisan route:list --path=api/trip` muestra trece rutas bajo `trips` y una bajo `trip-fuels`.

10. **Cambio incompatible en `/assignment`.** `AssignTripRequest` pasa a cuatro campos con sus mensajes en español, y `TripService::assign()` inserta la primera carga **dentro de la transacción que ya tiene**, después de `ensureCrewIsAssignable()` y antes de devolver. Verificación: asignar un viaje deja exactamente una fila en `trip_fuels`; reasignarlo deja dos.

11. **Guarda de arranque en `TripService::start()`.** Cuarta comprobación, tras «El viaje ya fue iniciado»: consulta `exists()` sobre `trip_fuels` acotada a `loaded_at IS NOT NULL`, y lanza `BadRequestError` con el mensaje literal. Verificación: un viaje asignado y con una carga sin confirmar responde 400; confirmarla y repetir responde 200.

12. **`totalFuelGallons` en `TripResource`.** El `withSum` acotado a las confirmadas se añade donde `TripService` carga `RELATIONS`, de modo que las siete rutas que devuelven el detalle lo traigan sin consulta extra. `TripListResource` no se toca.

13. **Tests Pest**, delegados al agente `feature-tests`: Feature de las tres rutas nuevas —roles, ámbito, las cuatro guardas del `POST`, la idempotencia de la confirmación— más los casos de `/assignment` y `/start` que cambian, y Unit de `TripFuelService`.

14. **Swagger**, delegado al agente `endpoint-docs`: `TripFuelController`, `StoreTripFuelRequest`, `TripFuelResource`, el `AssignTripRequest` reescrito y la clave nueva de `TripResource`. Regenerar `storage/api-docs/api-docs.json`.

15. **`references/trip-fuels-api.md`** con el resumen de integración para el frontend, incluida la advertencia de que `/assignment` es cambio incompatible y de que `totalFuelGallons` solo cuenta lo confirmado.

Tras cada paso que toque PHP: `vendor/bin/pint --dirty --format agent`.

---

## Criterios de aceptación

**Asignación**

- [ ] `PATCH /api/trips/{trip}/assignment` sin `fuelGallons` o sin `fuelType` responde **422**.
- [ ] `fuelType` fuera de los cuatro casos del enum responde **422**.
- [ ] `fuelGallons` en `0`, negativo o no numérico responde **422**.
- [ ] Una asignación válida responde 200 y deja **exactamente una** fila en `trip_fuels`, con `loaded_at` y `confirmed_by` en `null` y `registered_by` igual al usuario que asignó.
- [ ] Reasignar el mismo viaje `pending` deja **dos** filas: la anterior no se pisa ni se borra.
- [ ] Si la asignación falla por cualquiera de sus guardas (403 o 400), **no queda ninguna fila** en `trip_fuels`.

**Registrar una carga**

- [ ] `POST /api/trips/{trip}/fuels` con un `{trip}` inexistente responde **404**.
- [ ] Sobre un viaje borrado responde **400**, incluso si además es de otra empresa.
- [ ] Sobre un viaje sin asignar, o asignado por otra empresa, responde **403**.
- [ ] Sobre un viaje `finished` responde **400**; sobre uno `pending` y sobre uno `in_route` responde **201**.
- [ ] Un `administrator`, un `manager` o un `pilot` reciben **403** por el middleware `role:carrier`.
- [ ] Dos cargas del mismo viaje pueden tener `fuelType` distinto y ambas se guardan.

**Confirmar**

- [ ] `PATCH /api/trip-fuels/{tripFuel}/confirm` con la carga de un viaje del piloto autenticado responde 200 y escribe `loaded_at` con la hora del servidor y `confirmed_by` con su id.
- [ ] La misma llamada repetida responde **200 sin cambiar `loaded_at`** y sin crear nada.
- [ ] Un piloto que no es el `pilot_id` del viaje recibe **403**.
- [ ] Un `carrier`, un `administrator` o un `manager` reciben **403** por el middleware `role:pilot`.
- [ ] Un `{tripFuel}` inexistente responde **404**.
- [ ] La ruta acepta cuerpo vacío y **no existe** ningún campo que lo pise: mandar `loadedAt` o `gallons` no cambia nada.

**Arranque**

- [ ] `PATCH /api/trips/{trip}/start` sobre un viaje **sin ninguna carga** responde **400** con «Debes confirmar al menos una carga de combustible antes de iniciar el viaje», y el viaje sigue `pending` con `start_date` en `null`.
- [ ] Con una carga registrada pero **sin confirmar**, responde igualmente **400**.
- [ ] Con **al menos una** carga confirmada, responde **200** y el viaje pasa a `in_route`.
- [ ] Sobre un viaje ya iniciado, el mensaje sigue siendo **«El viaje ya fue iniciado»**, no el de combustible.
- [ ] Un piloto que no es el asignado sigue recibiendo **403** antes de que se mire el combustible.
- [ ] `PATCH /api/trips/{trip}/finish` **no** comprueba nada de combustible: un viaje `in_route` se cierra aunque tenga cargas sin confirmar.

**Lectura**

- [ ] `GET /api/trips/{trip}/fuels` devuelve las cargas en orden `id ASC`.
- [ ] El sobre trae `totalGallons` en la raíz, con la suma de las **confirmadas**, y lo trae **también sin `limit`**.
- [ ] Con `?limit=10` sobre un viaje de 25 cargas, `totalGallons` sigue siendo el total del viaje, no el de la página.
- [ ] Un viaje sin cargas confirmadas devuelve `totalGallons` igual a `"0.00"`.
- [ ] El `pilot` asignado **sí** ve el listado de su viaje; sobre un viaje ajeno recibe **403**.
- [ ] Un `carrier` recibe **403** sobre un viaje que no alcanza su ámbito de SPEC 24.
- [ ] Cada elemento trae las ocho claves, con `isConfirmed` en `false` y `loadedAt`/`confirmedByName` en `null` mientras no se confirme.

**Contrato de `trips`**

- [ ] `TripResource` devuelve **35 claves**, con `totalFuelGallons` sumando solo las confirmadas.
- [ ] `TripListResource` sigue devolviendo **15 claves** y `GET /api/trips/current` no cambia de forma.
- [ ] `GET /api/trips` no acepta ningún filtro de combustible: mandarlo se ignora y devuelve el listado completo.
- [ ] El `PATCH` general de un viaje con `fuelGallons` o `fuelType` en el cuerpo responde **200** y no escribe nada en `trip_fuels`.

**Suite**

- [ ] `php artisan test --compact` pasa entera, incluidos los tests de SPEC 24 y SPEC 26 sin modificar más que los casos de `/assignment` y `/start`.

---

## Decisiones

**Sobre la forma del dominio**

- **Sí:** tabla propia `trip_fuels`, una fila por carga. Un viaje puede recibir combustible más de una vez, y dos columnas en `trips` lo habrían impedido para siempre.
- **No:** dos columnas `fuel_gallons` y `fuel_type` en `trips`. Fue el primer diseño de esta spec y se descartó por lo anterior. Habría sido más barata y habría durado hasta la primera recarga en carretera.
- **No:** columna `status` en `trip_fuels`. `loaded_at` ya distingue los dos estados sin ambigüedad, y una columna aparte podría contradecir a la fecha.
- **Sí:** `TripFuelService` inyecta `TripServiceInterface` **por constructor**. Reusa la matriz de ámbito de SPEC 24 en vez de reescribirla; precedente exacto de `TripPositionService` (SPEC 26) y, antes, del `FreightRateService` que inyectaba zonas hasta SPEC 15.

**Sobre las rutas**

- **Sí:** `POST` y `GET` anidados en `/api/trips/{trip}/fuels`. Una carga sin su viaje no significa nada y `{trip}` ya es el parámetro del grupo — mismo argumento que abrió la primera ruta anidada del proyecto en SPEC 26.
- **Sí:** la confirmación **no** se anida (`PATCH /api/trip-fuels/{tripFuel}/confirm`). El id de la carga ya identifica el viaje, así que `{trip}` sería un parámetro de adorno y la ruta sería la más profunda del proyecto.
- **No:** confirmar todas las cargas pendientes de un viaje en una sola llamada. Más cómodo para el móvil, pero impide confirmar una y no otra, que es justo el caso en que la confirmación sirve de algo.
- **No:** `GET /api/trip-fuels` global. El índice va siempre viaje → cargas; un listado de todas las cargas del país no responde ninguna pregunta.

**Sobre la asignación**

- **Sí:** los cuatro campos obligatorios en `/assignment`, como **cambio incompatible sin periodo de gracia**. Es la petición literal —«al asignar piloto y camión se le tiene que asignar combustible»— y el proyecto ya rompió contratos así en SPEC 13, SPEC 19, SPEC 21 y SPEC 25.
- **No:** dejar `/assignment` en dos campos y que el combustible entre solo por el `POST`. Habría permitido viajes asignados con cero cargas, que es exactamente lo que la spec existe para impedir.
- **Sí:** la primera carga se inserta **dentro de la transacción de `assign()`**, detrás del `lockForUpdate` que ya existe. Si el `INSERT` falla, la asignación entera se deshace: no hay viaje asignado sin su carga.
- **Sí:** reasignar **añade** fila en vez de pisarla. La reasignación deja así un rastro que piloto y vehículo no dejan — el único historial de esta spec, y sale gratis.

**Sobre la confirmación**

- **Sí:** confirmar es del **piloto asignado y solo suyo**, sin cuerpo y sin FormRequest. Confirmar es dar fe de que recibió lo asignado; si mandara una cantidad distinta, dejaría de ser una confirmación y habría dos números que cuadrar.
- **Sí:** reconfirmar responde **200 sin escribir**, no 400. `loaded_at` es un hecho que ya ocurrió y repetir la llamada no lo cambia; un móvil con mala señal reintenta y no debe ver un error. Precedente del piso de 15 segundos de SPEC 26, que también calla.
- **No:** desconfirmar. `loaded_at` no vuelve a `null` por ninguna vía.
- **Sí:** la confirmación **no mira el `status` del viaje**. Un piloto puede confirmar una carga de un viaje ya `finished` —papeleo atrasado—, y prohibirlo solo crearía filas imposibles de cerrar.

**Sobre el arranque**

- **Sí:** `/start` exige **al menos una carga confirmada**, con su 400 propio. Sin esta guarda, `loaded_at` sería una fecha que nadie mira y la confirmación, un trámite opcional.
- **Sí:** la guarda va **después** de «El viaje ya fue iniciado», para que un reintento sobre un viaje en curso no hable de combustible.
- **No:** guarda equivalente en `/finish`. Cerrar un viaje que ya se hizo no debe depender de que alguien completara el papeleo.

**Sobre los totales y la lectura**

- **Sí:** `totalGallons` y `totalFuelGallons` suman **solo las confirmadas**. El número que importa es cuánto combustible llegó de verdad al camión.
- **Consecuencia aceptada:** un viaje recién asignado muestra `0.00` teniendo ya una carga registrada. Se decidió con esa lectura delante; el listado del viaje enseña las cargas sin confirmar, así que el `0.00` es explicable.
- **No:** exponer los dos números por separado (`totalGallons` y `totalConfirmedGallons`). Más completo y más superficie que testear, para una pregunta que hoy nadie hace.
- **Sí:** `totalGallons` va en la **raíz del sobre** y también sin `limit`. Es dato de negocio, no metadata del paginador; precedente literal de `totalAmount` en SPEC 14.
- **Sí:** el **piloto asignado lee** el listado de su viaje. Se aparta a propósito de SPEC 26, donde el piloto quedó fuera del `GET` de posiciones: allí él es el emisor y leerse a sí mismo no aporta; aquí el dato es sobre él y lo necesita.
- **Sí:** orden `id ASC`. `loaded_at` es nullable y `created_at` empata entre dos cargas del mismo segundo.

**Sobre lo que no se guarda**

- **No:** precio ni costo del combustible. `fuel_prices` no se consulta y `TripFuelService` no inyecta su contrato. Guardar un precio congelado convertiría esta spec en contabilidad.
- **No:** exigir que el `fuelType` tenga un `FuelPrice` vigente. Como el precio no se guarda, la comprobación no protegería nada y bloquearía asignaciones legítimas por un catálogo desactualizado.
- **No:** validación cruzada contra `vehicles.kilometers_per_gallon`. `trips` no guarda la distancia —solo la `polyline`—, así que calcular los galones necesarios exigiría decodificarla en cada asignación.
- **No:** `PATCH` ni `DELETE` de una carga. La tabla es append-only, como `trip_positions`. Es una decisión con coste conocido: **una carga mal tecleada se queda ahí para siempre** y los galones no admiten negativos, así que ni siquiera se puede compensar.
- **No:** backfill. Los viajes asignados antes de esta spec se quedan con cero cargas y no arrancan hasta que su empresa registre una y el piloto la confirme.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **Una carga mal tecleada es permanente.** Sin `PATCH` ni `DELETE`, un `450` en vez de `45` queda en la tabla y contamina `totalGallons` para siempre. Y desde que `/start` mira el combustible, ese total ya no es solo informativo. | Ninguna por API: se corrige tocando la base. Queda escrito en `references/trip-fuels-api.md` para que el frontend confirme la cantidad antes de mandar el `POST`. Si duele, la spec que añada el `DELETE` ya tiene el precedente del gasto de vehículo (SPEC 14). |
| **Un viaje puede quedar bloqueado sin poder arrancar.** Si la empresa no registra ninguna carga, o el piloto no confirma la que hay, `/start` responde 400 y **nadie más puede desbloquearlo**: el administrador no toca `trip_fuels` por ninguna ruta. | Es el efecto buscado, no un fallo. La salida existe y es corta —`POST` del `carrier` + `confirm` del piloto—, y ambas rutas siguen abiertas con el viaje en `pending`. El mensaje del 400 dice literalmente qué falta. |
| **El cambio incompatible de `/assignment` rompe el frontend en el instante del despliegue.** Un cliente que siga mandando dos campos recibe 422 en todas sus asignaciones. | El paso 10 del plan es un commit aparte, así que puede desplegarse por separado y coordinado con el frontend. Sin periodo de gracia, como SPEC 13, SPEC 19, SPEC 21 y SPEC 25. |
| **`totalFuelGallons` en `"0.00"` con cargas registradas se lee como un error.** Es el estado normal de todo viaje recién asignado, hasta que el piloto confirma. | El listado del viaje muestra las cargas sin confirmar con `isConfirmed: false`, así que el frontend puede explicar el cero en vez de mostrarlo a secas. Documentado en el resumen de integración. |
| **Los viajes asignados antes de esta spec no arrancan.** Tienen cero cargas y la guarda nueva de `/start` los frena. | El `POST` acepta viajes `pending` **y** `in_route`, así que cualquiera de ellos puede ganar su carga y confirmarla sin tocar la base. No hay script de relleno a propósito: rellenar inventaría combustible que nadie entregó. |
| **Dos cargas simultáneas del mismo viaje.** Nada las serializa: el `POST` no toma lock. | No hace falta: son filas independientes y sumar es conmutativo. Dos cargas idénticas seguidas son legítimas —dos camionadas iguales— y no hay índice único que lo impida, con el mismo criterio que las posiciones repetidas de SPEC 26. |

---

## Lo que **no** entra en esta spec

- Costo del combustible, precio congelado y cualquier cifra en quetzales.
- Corregir o borrar una carga; desconfirmar una ya confirmada.
- Galones realmente recibidos distintos de los asignados, y cualquier observación del piloto.
- Consumo real, rendimiento y cruce con `vehicles.kilometers_per_gallon`.
- Notificaciones, correo y emisión por Reverb al registrar o confirmar.
- Combustible en `TripListResource`, en `GET /trips/current` y en los filtros de `GET /api/trips`.
- Guarda de combustible en `/finish`.
- Listado global `GET /api/trip-fuels`.
- Backfill de los viajes ya asignados.

Cada uno, si llega, va en su propia spec.
