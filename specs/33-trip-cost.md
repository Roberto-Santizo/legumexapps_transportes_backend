# SPEC 33 — Costo total del viaje

> **Estado:** Aprobado
> **Depende de:** SPEC 06, SPEC 11, SPEC 13, SPEC 24, SPEC 27 (`27-trip-fuels.md`), SPEC 31, SPEC 32
> **Fecha:** 2026-09-22
> **Objetivo:** Publicar `GET /api/trips/{trip}/cost`, un desglose calculado en lectura del costo en GTQ de un viaje **finalizado**: combustible confirmado a precio histórico, viáticos confirmados, y salario del piloto y seguro del vehículo prorrateados por horas.

Es el **primer dominio del proyecto que solo lee y solo calcula**: `TripCost` no tiene tabla, ni migración, ni modelo, ni FormRequest, ni columna que persistir. `Dashboard` (SPEC 29) ya leía de otros dominios sin tabla propia, pero devolvía conteos y sumas de columnas existentes; aquí **el número no existe en ninguna parte** hasta que alguien lo pide, como el `currentValue` de SPEC 17 o el `durationMinutes` de SPEC 27, pero a escala de cuatro dominios.

Cierra la pregunta que las cinco specs aditivas sobre `trips` dejaron abierta. SPEC 27 guardó **galones sin precio**, SPEC 31 guardó **viáticos**, SPEC 13 guardó el **seguro mensual** del vehículo, SPEC 11 guardó el **salario mensual** del piloto con su bitácora y SPEC 32 guardó las **horas reales**. Todas las piezas del costo ya están en la base: faltaba el endpoint que las multiplique y las sume.

Lo que **no** hace es convertirse en contabilidad. No hay depreciación del vehículo, no se imputan `vehicle_expenses`, no hay ingreso ni margen —`freight_rates` cotiza por libra y `trips` no guarda peso—, y el número no se persiste ni se congela: se recalcula en cada lectura a partir de datos históricos que no se mueven.

---

## Alcance

**Dentro:**

- **Dominio `TripCost` sin tabla**: `TripCostServiceInterface` (`app/Interfaces/TripCost/`), `TripCostService` (`app/Services/TripCost/`), `TripCostProvider` registrado en `bootstrap/providers.php`, `TripCostResource` (`app/Http/Resources/TripCost/`) y `TripCostController`. Sin migración, sin modelo, sin factory, sin enum y **sin FormRequest** (no hay cuerpo ni un solo query param).
- **Una ruta nueva**: `GET /api/trips/{trip}/cost`, con `jwt.auth` a secas, declarada en `routes/trips.php` —que pasa de dieciséis a **diecisiete** rutas— antes del `apiResource`, junto a `/positions`, `/fuels`, `/timeouts` y `/expenses`.
- **El service inyecta `TripServiceInterface` por constructor** para heredar el ámbito de SPEC 24, como `TripFuelService`, `TripExpenseService` y `TripPositionService`.
- **Cuatro componentes de costo**, todos en GTQ y como string de dos decimales:

  | Componente | Fórmula | Fuente |
  |---|---|---|
  | `fuel` | Σ (`gallons` × precio vigente en `loaded_at`) de las cargas **confirmadas** | `trip_fuels` + `fuel_prices` |
  | `expenses` | Σ `amount` de los viáticos **confirmados** | `trip_expenses` |
  | `pilot` | `salario mensual / 720 × traveled_hours` | `carrier_pilot_salary_histories` / `carrier_pilots` + `trips` |
  | `vehicle` | `monthly_insurance_cost / 720 × traveled_hours` | `vehicles` + `trips` |

- **Precio del combustible histórico**: por cada carga confirmada, el `fuel_prices` de ese `fuel_type` con el `created_at` más reciente que no supere su `loaded_at`. Solo se cotizan cargas confirmadas, y una carga confirmada siempre tiene `loaded_at`, así que no hay caso sin fecha.
- **Salario histórico**: el `new_salary` de la fila de `carrier_pilot_salary_histories` más reciente (orden `id desc`) con `created_at <= trips.start_date`, del pivote `carrier_pilots` que une al piloto del viaje con la empresa de `assigned_by`. Sin ninguna fila que cumpla, el `salary` actual del pivote.
- **Mes de 720 horas** (30 × 24) para los dos prorrateos: el salario y el seguro se reparten sobre el mes completo, sin jornada laboral ni redondeo a días.
- **Solo viajes `finished`**: en `pending` o `in_route` la respuesta es **400** «El costo solo está disponible para viajes finalizados».
- **Cuatro guardas, en el orden del proyecto**: viaje inexistente → **404**; borrado → **404** (lectura, como `GET /{trip}`); fuera del ámbito de SPEC 24 → **403**; no `finished` → **400**.
- **403 a cualquier `pilot`**, incluido el asignado, resuelto en el service y no en el middleware, como `/positions` y `/timeouts`: el desglose expone su salario.
- **Un dato que falta vale `0.00`, nunca un error**: piloto o vehículo sin asignar, `salary` en `null`, `traveled_hours` en `null` (viaje cerrado antes de SPEC 32) o carga sin precio capturado para su fecha. El insumo ausente sale como `null` en el desglose para que se vea.
- **`TripCostResource`**: cuatro bloques con sus insumos y su subtotal, más `totalCost`. El detalle del combustible se agrupa **por tipo de combustible**.
- **Decimocuarta tool del asistente**: `trip_cost` en `app/Ai/Tools/Trip/`, sobre `TripCostServiceInterface`, con `tripId` obligatorio vía `TripNestedTool`.
- Anotaciones OpenAPI en Controller y Resource, y regeneración de `storage/api-docs/api-docs.json`.
- Tests Pest: `TripCostTest` (Feature de la ruta), `TripCostServiceTest` (Unit de las cuatro fórmulas y los casos sin dato) y `TripCostToolTest` —o la sección correspondiente en `TripToolsTest`—.
- Resumen de integración para el frontend en `references/trip-costs-api.md`.

**Fuera de alcance (para specs futuras):**

- **Depreciación del vehículo.** Decisión explícita del usuario: `purchase_price` no entra en el costo, ni siquiera prorrateada.
- **Mantenimiento imputado.** Los `vehicle_expenses` del vehículo no se reparten entre los viajes de su rango de fechas: la fecha no prueba a qué viaje pertenece un gasto.
- **Ingreso y margen.** No hay tarifa, ni peso, ni facturación al cliente; `freight_rates` cotiza por libra y `trips` no guarda libras.
- **Persistir el costo.** Ninguna columna nueva en `trips`, ni snapshot en `/finish`, ni caché.
- **Costo de viajes en curso.** Un `in_route` no tiene costo parcial: es 400.
- **Costo por kilómetro** y cualquier otro derivado del derivado.
- **Agregados en el dashboard**: `/api/dashboard` no gana costo por empresa, por mes ni por vehículo.
- **Listado de costos** (`GET /api/trips/costs`), filtros u orden por costo en `GET /api/trips`.
- **Exportación a Excel**: `ReportService` no gana un `export_trip_costs`.
- **Cambios en `TripResource` y `TripListResource`**: siguen en 42 y 19 claves.
- **Moneda.** Todo es GTQ por convención, como en el resto del proyecto; no hay columna ni parámetro de moneda.
- **Backfill de precios de combustible** para cargas anteriores al primer `fuel_prices` de su tipo.

---

## Modelo de datos

**Esta spec no introduce ninguna estructura persistida.** Sin migración, sin tabla, sin columna nueva y sin modelo: el costo se calcula en cada lectura a partir de `trips`, `trip_fuels`, `fuel_prices`, `trip_expenses`, `carrier_pilots`, `carrier_pilot_salary_histories` y `vehicles`. Lo que sigue son las estructuras **en memoria**: el array que devuelve el service y el JSON que produce el Resource.

### 1. Contrato

```php
// app/Interfaces/TripCost/TripCostServiceInterface.php
public function getTripCost(User $user, int $tripId): array;
```

Un solo método, con `User` como primer parámetro —como `DashboardServiceInterface`— y el array de abajo como salida. Devuelve `array`, no un modelo: el `TripCostResource` envuelve el array, como `TripsSummaryResource` en SPEC 29.

```php
array{
    trip: Trip,
    traveledHours: float|null,
    fuel: array{
        gallons: float,
        byType: list<array{fuelType: string, gallons: float, pricePerGallon: float|null, amount: float}>,
        subtotal: float,
    },
    expenses: array{count: int, subtotal: float},
    pilot: array{monthlySalary: float|null, subtotal: float},
    vehicle: array{monthlyInsuranceCost: float|null, subtotal: float},
    totalCost: float,
}
```

### 2. Las cuatro fórmulas

Constante de clase `TripCostService::MONTHLY_HOURS = 720` (30 × 24), el único sitio que conoce la convención del mes.

| Componente | Fórmula | Cuando falta el insumo |
|---|---|---|
| `fuel` | Por cada tipo: `round(Σ gallons × pricePerGallon, 2)`. `subtotal` = suma de los importes ya redondeados | Sin precio capturado para esa fecha → `pricePerGallon: null` y `amount: 0.00` |
| `expenses` | `round(Σ amount, 2)` de los viáticos con `received_at IS NOT NULL` | Sin viáticos → `count: 0`, `subtotal: 0.00` |
| `pilot` | `round(monthlySalary / 720 × traveledHours, 2)` | `monthlySalary` o `traveledHours` en `null` → `subtotal: 0.00` |
| `vehicle` | `round(monthlyInsuranceCost / 720 × traveledHours, 2)` | `monthlyInsuranceCost` o `traveledHours` en `null` → `subtotal: 0.00` |

`totalCost` = suma de los cuatro subtotales **ya redondeados**, para que el desglose cuadre con el total a la vista. Nunca se suma en crudo y se redondea al final.

### 3. Resolución del precio del galón

Por cada carga confirmada, el precio es el de la fila de `fuel_prices` de su `fuel_type` con el `created_at` más alto que no supere su `loaded_at`; el `status` de esa fila **no importa**, porque una fila inactiva es precisamente el precio que estuvo vigente en su momento. Dos cargas del mismo tipo en fechas distintas pueden cotizarse a precios distintos, y por eso `byType` agrupa por tipo pero la multiplicación es **por carga**.

Solo se cotizan cargas confirmadas, y una carga confirmada siempre tiene `loaded_at`: no existe el caso «carga sin fecha a la que buscarle precio».

### 4. Resolución del salario

1. El pivote: la fila de `carrier_pilots` que une `trips.pilot_id` con la empresa de `trips.assigned_by` (`User::currentCarrier()`, la fuente de verdad de SPEC 03).
2. La bitácora: el `new_salary` de la fila de `carrier_pilot_salary_histories` de ese pivote con `created_at <= trips.start_date`, la más reciente por `id desc` (el orden de SPEC 11, que no empata entre dos cambios del mismo segundo).
3. Sin ninguna fila que cumpla, el `salary` actual del pivote.
4. Sin pivote —el piloto ya no está vinculado a esa empresa— o con `salary` en `null`, el insumo es `null`.

### 5. Consultas

El costo se resuelve con **un número fijo de consultas, independiente del número de cargas, viáticos o posiciones**:

1. El viaje, por `TripServiceInterface::getTripById($user, $tripId)` — trae el ámbito, las ocho relaciones y las dos sumas ya existentes.
2. Las cargas confirmadas (`gallons`, `fuel_type`, `loaded_at`).
3. Los `fuel_prices` de los tipos presentes, ordenados por `created_at`; el emparejamiento con cada `loaded_at` se hace **en PHP**, no con una subconsulta por carga.
4. El agregado de viáticos confirmados (`count` y `sum`).
5. El pivote `carrier_pilots`.
6. La bitácora de salario de ese pivote.

Las consultas 2 a 6 se saltan cuando no aplican: un viaje sin piloto no consulta pivote ni bitácora.

### 6. `TripCostResource`

Siete claves de primer nivel, cuatro de ellas objetos. Todo importe y todo número decimal sale como **string de dos decimales**, como `gallons` en SPEC 27 y `estimatedKilometers` en SPEC 30.

```json
{
  "tripId": 42,
  "order": "ORD-1024",
  "traveledHours": "2.50",
  "fuel": {
    "gallons": "35.00",
    "byType": [
      { "fuelType": "diesel", "gallons": "35.00", "pricePerGallon": "38.50", "amount": "1347.50" }
    ],
    "subtotal": "1347.50"
  },
  "expenses": { "count": 2, "subtotal": "450.00" },
  "pilot": { "pilotId": 7, "pilotName": "Juan Pérez", "monthlySalary": "4500.00", "subtotal": "15.63" },
  "vehicle": { "vehicleId": 3, "plate": "C-123BCD", "monthlyInsuranceCost": "350.00", "subtotal": "1.22" },
  "totalCost": "1814.35"
}
```

- `traveledHours` sale una sola vez en la raíz: es el mismo multiplicador para `pilot` y `vehicle`, y repetirlo en los dos bloques invitaría a creer que pueden diferir. `null` en un viaje cerrado antes de SPEC 32.
- `byType` es **lista vacía** si no hay cargas confirmadas, no `null`.
- `pilotId`, `pilotName`, `vehicleId` y `plate` son `null` en un viaje `finished` sin tripulación —posible solo por el `PATCH` del administrador, hueco declarado de SPEC 24—.
- `fuelType` sale con el valor crudo del enum en inglés, como en `TripFuelResource`.

---

## Plan de implementación

Cada paso deja la suite verde y la API funcionando.

1. **Rama.** `spec-33-trip-cost`, creada por `/spec-impl`.

2. **Contrato, service y provider.** `TripCostServiceInterface` con `getTripCost(User $user, int $tripId): array` y el PHPDoc del array shape. `TripCostService` inyecta `TripServiceInterface` por constructor y resuelve **solo las guardas**: el viaje por `getTripById()` (404 inexistente o borrado, 403 fuera de ámbito), `ForbiddenError` si el rol es `pilot`, `BadRequestError` si el `status` no es `finished`; devuelve los cuatro bloques en cero. `TripCostProvider` registrado en `bootstrap/providers.php`. En `tests/Unit/TripCostServiceTest.php`: las cuatro guardas y su orden, incluido que un viaje borrado y ajeno dé 404 y no 403.

3. **Combustible.** Cargar las cargas confirmadas, cargar los `fuel_prices` de los tipos presentes en una consulta y emparejar cada `loaded_at` con su precio en PHP; agrupar por tipo y sumar. Tests: dos cargas del mismo tipo a precios distintos por fecha; una carga sin precio capturado para su fecha → `pricePerGallon: null` y `amount: 0.00`; una carga sin confirmar no suma ni aparece; un viaje sin cargas → `byType: []` y `subtotal: 0.00`; el precio usado sale de una fila `inactive` cuando esa era la vigente.

4. **Viáticos.** Agregado de `count` y `sum` sobre los confirmados. Tests: los sin confirmar no suman; sin viáticos → `0` y `"0.00"`.

5. **Piloto y vehículo.** `MONTHLY_HOURS = 720`, resolución del pivote y de la bitácora de salario, y los dos prorrateos. Tests: salario vigente al `start_date` frente a un cambio posterior (el costo no se mueve); sin fila de bitácora anterior al viaje → salario actual del pivote; piloto desvinculado → `monthlySalary: null` y subtotal `0.00`; `salary` en `null` → `0.00`; `traveled_hours` en `null` → los dos subtotales en `0.00` con los insumos visibles; un caso aritmético conocido (`4500 / 720 × 2.5 = 15.63`) y el seguro con la misma base.

6. **Resource, controller y ruta.** `TripCostResource` con los tres schemas OA y el formato de string de dos decimales; `TripCostController@show` con el service por parámetro de método y `try/catch` → `ResponseHandler`; la ruta en `routes/trips.php` antes del `apiResource`. En `tests/Feature/TripCostTest.php`: 200 con las siete claves y la forma de los cuatro bloques; 404, 403 y 400 de las guardas; 403 al `pilot` asignado; `administrator`, `manager` y `carrier` dueño en 200; `carrier` ajeno en 403; el dataset de middleware del archivo con la ruta nueva; y un `DB::getQueryLog()` que comprueba que el número de consultas no crece con el número de cargas ni de viáticos.

7. **Tool del asistente.** `TripCostTool` en `app/Ai/Tools/Trip/`, hija de `TripNestedTool`, sobre `TripCostServiceInterface`, registrada en `DashboardAssistant` como decimocuarta tool. Test con `DashboardAssistant::fake([...])` y un `ToolCall` que ejecute el `handle()` real, más la aserción de que un `pilot` no llega ahí (el endpoint del asistente ya lo veta por middleware).

8. **Documentación y cierre.** `php artisan l5-swagger:generate`; `vendor/bin/pint --dirty --format agent`; suite completa con `php artisan test --compact`.

9. **Referencia para el frontend.** `references/trip-costs-api.md`: la ruta, las siete claves, que solo atiende viajes `finished`, el veto al piloto, la convención del mes de 720 horas, qué significa un insumo en `null` frente a un subtotal en `0.00`, y que el costo **no incluye** depreciación ni mantenimiento.

---

## Criterios de aceptación

**Guardas y ámbito**

- [ ] `GET /api/trips/{trip}/cost` con un id inexistente responde **404**.
- [ ] Con un viaje borrado responde **404**, también cuando además es de otra empresa.
- [ ] Un `carrier` fuera del ámbito de SPEC 24 recibe **403**; `administrator`, `manager` y el `carrier` que tomó el viaje reciben **200**.
- [ ] Cualquier `pilot` recibe **403**, incluido el piloto asignado al viaje.
- [ ] Un viaje `pending` o `in_route` responde **400** «El costo solo está disponible para viajes finalizados», aunque tenga cargas y viáticos confirmados.
- [ ] El orden de las guardas es 404 → 403 → 400: un viaje `in_route` de otra empresa responde 403, no 400.

**Combustible**

- [ ] Una carga confirmada de 35 galones con precio vigente de 38.50 en su `loaded_at` produce `amount: "1347.50"`.
- [ ] Dos cargas del mismo tipo con `loaded_at` a ambos lados de un cambio de precio se cotizan a precios distintos y se suman en un solo elemento de `byType`.
- [ ] El precio usado sale de la fila de `fuel_prices` vigente en esa fecha aunque hoy esté `inactive`.
- [ ] Una carga sin confirmar no aparece en `byType` ni suma en `gallons` ni en `subtotal`.
- [ ] Una carga cuyo `loaded_at` es anterior a cualquier `fuel_prices` de su tipo sale con `pricePerGallon: null` y `amount: "0.00"`, y la respuesta sigue siendo 200.
- [ ] Un viaje sin cargas confirmadas devuelve `byType: []`, `gallons: "0.00"` y `subtotal: "0.00"`.

**Viáticos**

- [ ] `count` y `subtotal` cuentan solo los viáticos con `received_at` no nulo.
- [ ] Un viaje sin viáticos confirmados devuelve `count: 0` y `subtotal: "0.00"`.
- [ ] `subtotal` coincide con `totalExpensesAmount` de `TripResource` para el mismo viaje.

**Piloto y vehículo**

- [ ] Con `traveled_hours = 2.50` y salario mensual 4500.00, `pilot.subtotal` es `"15.63"`.
- [ ] Con `traveled_hours = 2.50` y seguro mensual 350.00, `vehicle.subtotal` es `"1.22"`.
- [ ] Un cambio de salario posterior al `start_date` del viaje **no** altera el costo: se usa el `new_salary` vigente en esa fecha.
- [ ] Sin ninguna fila de bitácora anterior al `start_date` se usa el `salary` actual del pivote.
- [ ] Un piloto ya no vinculado a la empresa del viaje da `monthlySalary: null` y `subtotal: "0.00"`, con 200.
- [ ] Un piloto con `salary` en `null` da `monthlySalary: null` y `subtotal: "0.00"`.
- [ ] Un viaje finalizado antes de SPEC 32 (`traveled_hours` en `null`) devuelve `traveledHours: null` y los dos subtotales en `"0.00"`, con los insumos `monthlySalary` y `monthlyInsuranceCost` visibles y con valor.
- [ ] Un viaje `finished` sin piloto ni vehículo devuelve `pilotId`, `pilotName`, `monthlySalary`, `vehicleId`, `plate` y `monthlyInsuranceCost` en `null`, los dos subtotales en `"0.00"` y 200.

**Total y formato**

- [ ] `totalCost` es la suma exacta de los cuatro subtotales tal como salen en la respuesta.
- [ ] Todo importe, `gallons` y `traveledHours` salen como string de dos decimales; `count` sale como entero.
- [ ] `fuelType` sale con el valor crudo del enum en inglés.
- [ ] La respuesta tiene exactamente siete claves de primer nivel.

**Contrato del proyecto**

- [ ] `TripResource` sigue en **42** claves y `TripListResource` en **19**; ninguna de las dos gana costo.
- [ ] `routes/trips.php` declara **diecisiete** rutas y `/cost` va antes del `apiResource`.
- [ ] No existe ninguna migración nueva ni ninguna columna nueva en `trips`.
- [ ] El número de consultas de la petición no crece con el número de cargas, viáticos ni posiciones del viaje (`DB::getQueryLog()`).

**Asistente**

- [ ] La tool `trip_cost` devuelve el mismo desglose que el endpoint para un viaje finalizado, con `tripId` obligatorio.
- [ ] La tool sobre un viaje `in_route` devuelve `{"error": ...}` con el mensaje del 400, no una excepción.
- [ ] `DashboardAssistant` expone **catorce** tools.

**Cierre**

- [ ] `storage/api-docs/api-docs.json` documenta la ruta y el schema del Resource.
- [ ] `php artisan test --compact` pasa completo y `vendor/bin/pint --dirty --test` no reporta cambios.

---

## Decisiones

- **Sí:** calculado en lectura, sin columnas ni snapshot. Los cuatro insumos son históricos y no se mueven —precio vigente en `loaded_at`, salario vigente en `start_date`, `traveled_hours` ya cerrado—, así que persistir el resultado solo añadiría una copia que puede quedar desincronizada. Precedente: `currentValue` de SPEC 17 y `durationMinutes` de SPEC 27.
- **No:** congelar el costo en `/finish` con columnas nuevas, como hizo SPEC 32 con la distancia. Allí la fuente (`trip_positions`) es grande y se recorre una vez; aquí son cuatro números baratos y estables.
- **Sí:** solo viajes `finished`, 400 en cualquier otro estado. Un costo parcial obligaría a definir la duración de un viaje en curso —`now() − start_date`, que sube en cada refresco— y a que el front distinguiera «va por 1 800» de «costó 1 800». Se prefiere un único número, siempre completo.
- **No:** costo parcial con bandera `isFinal`. Es la alternativa razonable y se descartó por lo anterior; si hace falta seguir un viaje en curso, es otra spec y el contrato ya existe.
- **Sí:** precio del combustible histórico por `loaded_at`. El costo de un viaje cerrado no puede moverse cuando el administrador capture el precio del mes siguiente. Funciona retroactivamente sobre las cargas ya registradas, sin backfill y sin tocar `trip_fuels`.
- **No:** precio vigente hoy. Es una línea de código menos y reescribe el pasado cada semana.
- **No:** columna `price_per_gallon` en `trip_fuels` escrita al confirmar. Es lo contablemente más fiel —el precio que se pagó de verdad— pero es cambio de esquema en una tabla declarada append-only y deja en `null` todas las cargas anteriores. El historial de `fuel_prices` ya responde la misma pregunta.
- **Sí:** el `status` de la fila de `fuel_prices` se ignora al cotizar. Una fila `inactive` es exactamente el precio que estuvo vigente; filtrarla por `active` daría el precio de hoy para todas las fechas.
- **Sí:** salario vigente al `start_date`, leído de `carrier_pilot_salary_histories`. Coherencia con el combustible: si un insumo tiene historial, se usa el historial.
- **Sí:** respaldo en el `salary` actual del pivote cuando no hay fila de bitácora anterior al viaje. La alternativa —el `previous_salary` de la fila más antigua— es más literal pero suele ser `null`, y dejaría en `0.00` a todos los viajes previos al primer cambio de sueldo.
- **Sí:** mes de 720 horas, constante `MONTHLY_HOURS`. Reparte el sueldo sobre el mes real y no sobre una jornada laboral que este dominio no modela: un viaje de madrugada no puede costar el triple que el mismo viaje de día.
- **No:** prorrateo por días con mínimo de un día. Más parecido a cómo se paga de verdad, pero un viaje de dos horas cargaría un día entero de sueldo y dos viajes del mismo día cobrarían dos.
- **No:** mes de 240 horas laborales. Infla la hora tres veces y castiga los viajes largos.
- **Sí:** leer `traveled_hours` de la columna, sin recalcular `end_date − start_date`. Un solo camino para el número; los viajes cerrados antes de SPEC 32 quedan con los dos prorrateos en `0.00` y el insumo visible, que es información honesta y no un error.
- **Sí:** solo galones y viáticos **confirmados**. Mismo criterio que `totalFuelGallons` y `totalExpensesAmount`, así que el costo no contradice a `TripResource`. Lo no confirmado todavía no se ha incurrido.
- **Sí:** un insumo que falta vale `0.00` y se muestra como `null` en el desglose. El endpoint nunca falla por un dato de catálogo incompleto, y el front puede avisar porque ve el hueco. Un 400 por «el piloto no tiene salario» dejaría sin costo a un viaje que sí gastó combustible.
- **No:** depreciación del vehículo. Decisión explícita del usuario. Habría exigido inventar una vida útil y un valor residual que ninguna tabla guarda; `annual_depreciation` existe en `accessories`, no en `vehicles`.
- **No:** imputar `vehicle_expenses` al viaje por rango de fechas. Un cambio de llantas del martes no pertenece a ninguno de los tres viajes de ese martes en particular; imputarlo por fecha inventaría un vínculo que la tabla no tiene. `vehicle_id` es la única relación real y es del vehículo, no del viaje.
- **No:** ingreso, margen o rentabilidad. `freight_rates` cotiza por libra y `trips` no guarda peso: no hay forma de calcular el ingreso del viaje con lo que existe hoy.
- **Sí:** dominio propio `TripCost` sin tabla. `TripService` ya es el service más grande del proyecto y pasaría a conocer `fuel_prices` y `carrier_pilot_salary_histories`, dos dominios con los que hoy no habla. Precedente de `Dashboard`: capas completas sin migración.
- **Sí:** `TripServiceInterface` por constructor para el ámbito. Tercera repetición del patrón de `TripPositionService`, `TripFuelService` y `TripExpenseService`; la matriz de ámbito de SPEC 24 se escribe una vez.
- **Sí:** 403 a cualquier `pilot`, resuelto en el service. El desglose revela su salario mensual. Mismo trato que `/positions` y `/timeouts`, y al revés que `/fuels` y `/expenses`, donde el dato es suyo.
- **Sí:** 404 para el viaje borrado, no 400. Es una ruta de lectura y sigue a `GET /{trip}`; el 400 de `resolveWritableTrip()` es de las rutas de escritura.
- **Sí:** `totalCost` como suma de los subtotales ya redondeados. El desglose tiene que cuadrar con el total a la vista; sumar en crudo y redondear al final produce respuestas donde los cuatro números no dan el quinto.
- **Sí:** un emparejamiento de precios en PHP con una sola consulta a `fuel_prices`. Una subconsulta por carga sería N+1 en la ruta que más filas puede tener; el precedente es el N+1 resuelto a mano de SPEC 29.
- **Sí:** tool `trip_cost` en el asistente. El costo es justo lo que se pregunta en lenguaje natural («¿cuánto costó el viaje 42?»), el contrato ya existe y el ámbito por rol viaja con él. El endpoint del asistente ya veta al `pilot` por middleware.
- **No:** agregados de costo en `/api/dashboard`, listado de costos, filtros por costo y exportación a Excel. Cada uno multiplica el alcance y ninguno hace falta para responder la pregunta de esta spec.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El número se lee como «lo que costó el viaje» cuando en realidad es **costo directo**: sin depreciación, sin mantenimiento, sin llantas, sin peajes y sin administración | El desglose nombra sus cuatro componentes y no ofrece ningún otro; `references/trip-costs-api.md` declara en su primera línea qué **no** incluye. Ampliarlo es aditivo y no rompe la forma de la respuesta. |
| `fuel_prices.created_at` es el momento en que el administrador **capturó** el precio, no el momento desde el que rigió. Si captura el viernes el precio del lunes, las cargas de esa semana se cotizan al precio anterior | Es el modelo que SPEC 06 publicó: no hay columna de vigencia. Se acepta y se declara. Una fecha de vigencia separada es un cambio en el dominio de precios, no en este. |
| Una carga anterior al primer precio capturado de su tipo aporta `0.00` en silencio y baja el total sin avisar | `pricePerGallon: null` en el desglose es la señal, y `gallons` sí suma: un bloque con galones y sin importe es visible a simple vista. |
| El salario prorrateado de un viaje corto es simbólico (4 500 / 720 × 3 h = 18.75 GTQ) y no se parece a lo que la empresa paga por ese viaje | La base está en una sola constante, `MONTHLY_HOURS`; cambiarla a días o a jornada laboral es una línea en otra spec. La decisión y su aritmética están escritas. |
| Los viajes cerrados antes de SPEC 32 devuelven `pilot` y `vehicle` en `0.00` y su total parece anormalmente bajo | `traveledHours: null` en la raíz lo explica, y los dos insumos salen con su valor real para que se vea que lo que falta son las horas, no el sueldo. |
| Un piloto que se cambió de empresa pierde el pivote y su salario deja de resolverse en viajes que sí condujo | `monthlySalary: null` con 200. Reconstruir el vínculo histórico exigiría una bitácora de altas y bajas que `carrier_pilots` no tiene. |
| Sin caché y con seis consultas por viaje, un frontend que pinte el costo de cien viajes en un listado dispararía seiscientas consultas | Por eso no existe `GET /api/trips/costs` ni clave de costo en `TripListResource`: es un endpoint de detalle, uno por pantalla. Si el listado lo necesita, se diseña con su propia consulta agregada en otra spec. |

---

## Lo que **no** entra en esta spec

- Depreciación del vehículo y cualquier otro costo fijo que no sea el seguro.
- Imputación de `vehicle_expenses` al viaje.
- Ingreso, margen, rentabilidad y tarifa cobrada al cliente.
- Persistir o congelar el costo: ninguna columna nueva en `trips`.
- Costo de viajes `pending` o `in_route`.
- Costo por kilómetro y cualquier otro ratio.
- Agregados de costo en `/api/dashboard`.
- Listado de costos, filtros u orden por costo en `GET /api/trips`.
- Exportación a Excel del costo.
- Moneda distinta de GTQ.
- Cambios en `TripResource`, `TripListResource`, `TripInRouteResource` o el payload de `TripPositionUpdated`.

Cada una de ellas, si llega, va en su propia spec.
