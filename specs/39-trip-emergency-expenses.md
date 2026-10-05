# SPEC 39 — Gastos emergentes del viaje

> **Estado:** Implementado
> **Depende de:** SPEC 05, SPEC 19, SPEC 24, SPEC 31, SPEC 33
> **Fecha:** 2026-10-03
> **Objetivo:** Publicar el dominio `TripEmergencyExpense` —los gastos imprevistos que surgen con el viaje en ruta (una llanta pinchada, una grúa), registrados por el transportista con un comprobante opcional—, separado de los viáticos, sumado al costeo del viaje y consultable y exportable a Excel desde el asistente.

Es un **dominio nuevo con tabla propia** y nace como **hermano de `TripExpense` (SPEC 31), no como su calco**. El viático es dinero que la empresa **entrega** antes o durante el viaje y que el piloto **confirma** haber recibido. El gasto emergente es dinero que **se gastó** por un imprevisto en carretera, y lo registra el transportista cuando el piloto le avisa por fuera del sistema. De ahí las tres diferencias de fondo:

- **No hay confirmación.** No existen `received_at`, `confirmed_by` ni ruta `/confirm`, y el total suma todas las filas.
- **Solo nace con el viaje `in_route`.** Un imprevisto de carretera no ocurre en un viaje pendiente.
- **No es append-only.** Al no haber una segunda firma que proteger, se corrige con `PATCH` y se borra con `DELETE` físico, también con el viaje `finished`, porque la factura suele llegar después de terminar.

El comprobante sigue el precedente de la factura del gasto de vehículo (SPEC 19). Se guarda tal cual llega con `storeUpload()` y se borra del bucket junto con la fila.

---

## Alcance

**Dentro:**

- **Tabla nueva `trip_emergency_expenses`**, una fila por gasto. Columnas: `trip_id`, `amount` (`decimal(10,2)`, GTQ por convención), `description` (`string`, **obligatoria**), `receipt` (`string` **nullable**, key completa del archivo), `registered_by` y `timestamps`. FK sin `cascade` e índice `trip_id`. Sin `status`, sin `deleted_at`, sin `received_at` y sin `confirmed_by`.
- **Dominio `TripEmergencyExpense` completo** en su subcarpeta:
  - `TripEmergencyExpenseServiceInterface`, `TripEmergencyExpenseService` y `TripEmergencyExpenseProvider`.
  - `StoreTripEmergencyExpenseRequest`, `UpdateTripEmergencyExpenseRequest`, `TripEmergencyExpenseResource` y `TripEmergencyExpenseController`.
  - El service inyecta `TripServiceInterface` y `FileStorageServiceInterface` **por constructor**.
- **Cuatro rutas nuevas**, repartidas en dos archivos:

  | Ruta | Middleware | Efecto |
  |---|---|---|
  | `POST /api/trips/{trip}/emergency-expenses` | `role:carrier,administrator` | Registra un gasto |
  | `GET /api/trips/{trip}/emergency-expenses` | `role:` todos salvo `shipment` | Listado del viaje, acotado por ámbito |
  | `PATCH /api/trip-emergency-expenses/{tripEmergencyExpense}` | `role:carrier,administrator` | Corrige monto, descripción o comprobante |
  | `DELETE /api/trip-emergency-expenses/{tripEmergencyExpense}` | `role:carrier,administrator` | Borrado físico de la fila y del archivo |

  - Las dos primeras viven en `routes/trips.php`, después de las de `expenses`. El archivo pasa de diecisiete a **diecinueve** rutas.
  - Las dos últimas viven en `routes/trip-emergency-expenses.php`, incluido desde `routes/api.php`. No se anidan porque el id del gasto ya identifica el viaje; es el precedente de `/trip-expenses/{id}/confirm`.
- **Ámbito de escritura, calco de `ensureCarrierTookTheTrip()` (SPEC 31)**:
  - El `carrier` solo escribe en los viajes que tomó su empresa. En cualquier otro, 403 «No puedes registrar gastos emergentes en un viaje que no tomó tu empresa transportista».
  - El `administrator` escribe en cualquier viaje asignado.
  - Esta regla vale igual para `POST`, `PATCH` y `DELETE`.
- **Cuatro guardas del `POST`, en este orden**:
  1. Viaje inexistente → **404**.
  2. Viaje borrado → **400**.
  3. Empresa ajena, o viaje sin asignar → **403** (el `administrator` recibe **400** «El viaje aún no fue asignado»).
  4. El viaje no está `in_route` → **400** «Solo se pueden registrar gastos emergentes en un viaje en ruta». Vale tanto para `pending` como para `finished`.
- **Guardas de `PATCH` y `DELETE`, en este orden**:
  1. Gasto inexistente → **404** «El gasto emergente no existe».
  2. Viaje borrado → **400**.
  3. Empresa ajena → **403**.
  4. Viaje `pending` → **400**. Solo se alcanza por el hueco de SPEC 24, cuando el `PATCH` del administrador devuelve un viaje a `pending`.
  - Con el viaje en `in_route` o `finished` se permite.
- **Validación del alta**:
  - `amount`: `required|numeric|min:0.01|max:99999999.99`.
  - `description`: `required|string|max:255`, con solo `trim`.
  - `receipt`: `sometimes|nullable|file|mimes:jpg,jpeg,png,pdf|max:3072`. El cuerpo es `multipart/form-data` cuando lleva archivo.
- **`PATCH`**:
  - `amount` y `description` como `sometimes|required`.
  - `receipt` **reemplaza** el comprobante; el anterior se borra del bucket **después** de guardar la fila.
  - `removeReceipt=true` lo quita, y también lo borra del bucket después de guardar.
  - `receipt` junto con `removeReceipt=true` es **422**.
  - `trip_id` y `registered_by` son inmutables: si se mandan, se ignoran en silencio.
  - Un cuerpo vacío es **200 sin escribir**.
- **`DELETE` físico**: borra la fila y el objeto del bucket, como el gasto de vehículo de SPEC 19, y responde 200 con el recurso borrado.
- **El comprobante se guarda tal cual llega** con `storeUpload()` en `trip-emergency-expenses/`, sin pasar por `ImageProcessorServiceInterface`.
- **Lectura**:
  - Ámbito de SPEC 24 vía `getTripById()`, **incluido el piloto asignado**. `shipment` recibe 403 del middleware y del service, porque es dinero.
  - Orden `id ASC`, paginación opt-in `[10, 100]`, sin filtros.
- **`totalAmount` en la raíz del sobre** del listado: suma de **todos** los gastos del viaje (no hay confirmación), calculada sobre la consulta clonada antes de paginar.
- **`TripResource` gana `totalEmergencyExpensesAmount`** justo después de `totalExpensesAmount`, resuelta con `withSum`/`loadSum`. Pasa de 42 a **43 claves** (44 en `GET /{trip}` con `positions`). Para `shipment` sale `"0.00"`. `Trip` gana `emergencyExpenses()` solo para esa suma. `TripListResource` no cambia.
- **Costeo (SPEC 33)**:
  - `TripCostService` gana el componente `emergencyExpenses: { count, subtotal }`, entre `expenses` y `pilot`, con un agregado de una sola consulta. `totalCost` lo suma.
  - `TripCostResource` pasa de 8 a **9 claves**.
  - Los viáticos siguen en `expenses` sin mezclarse.
- **Asistente: dos tools nuevas**, y `DashboardAssistant` pasa de catorce a **dieciséis**:
  - `trip_emergency_expenses` en `app/Ai/Tools/Trip/`, hija de `TripNestedTool`, que pagina como `trip_expenses`.
  - `export_trip_emergency_expenses` en `app/Ai/Tools/Report/`, con `tripId` obligatorio y sobre un método nuevo `ReportServiceInterface::exportTripEmergencyExpenses()`. Es calco de `export_vehicle_expenses`: genera el `.xlsx`, lo sube a `reports/{uuid}.xlsx` y devuelve `{ fileName, url, rows, total, truncated, totalAmount }`.
  - El prompt del agente aprende a distinguir viáticos de gastos emergentes.
- Tests Pest (Feature de las cuatro rutas, Unit del service, costeo, `TripResource`, las dos tools y `ReportService`), Swagger regenerado, `references/trip-emergency-expenses-api.md` y `CLAUDE.md`.

**Fuera de alcance (para specs futuras):**

- **El aviso del piloto**: no hay ruta del piloto, ni estado «reportado», ni push al transportista. El piloto avisa por fuera del sistema.
- **Categorías**, tanto para gastos emergentes como para viáticos: `description` es el único texto.
- **Registrar en `pending` o `finished`.**
- **Aprobación, reembolso o liquidación** al piloto.
- **Vincular el gasto a `vehicle_expenses`**: una llanta pinchada no se imputa al mantenimiento del vehículo.
- **Reportes fuera del asistente**: el endpoint descargable, el reporte por rango de fechas o entre viajes, las columnas en `GET /api/reports/trips` (SPEC 38) y el listado global `GET /api/trip-emergency-expenses`.
- **Dashboard (SPEC 29)**, filtros en `GET /api/trips`, cambios en `TripListResource`, websocket y bitácora de ediciones.

---

## Modelo de datos

### 1. Tabla `trip_emergency_expenses`

| Columna | Tipo | Nota |
|---|---|---|
| `id` | bigint | |
| `trip_id` | FK `trips` | sin cascade, con índice; inmutable |
| `amount` | `decimal(10,2)` | > 0; sin cast, el Resource formatea |
| `description` | `string(255)` | obligatoria; solo `trim` |
| `receipt` | `string` nullable | key completa (`trip-emergency-expenses/{uuid}.pdf`), nunca la URL |
| `registered_by` | FK `users` | el usuario autenticado del alta; el `PATCH` no lo reescribe |
| `timestamps` | | `created_at` es la fecha del gasto a efectos de la API |

### 2. Modelo `App\Models\TripEmergencyExpense`

- `#[Fillable(['trip_id', 'amount', 'description', 'receipt', 'registered_by'])]`.
- Relaciones `trip()` y `registeredBy()`.
- Sin `casts()`: como `AccessoryCharacteristic`, no tiene fechas propias ni enums.
- Factory con un estado `withReceipt()`.
- `Trip::emergencyExpenses(): HasMany`. Sirve solo para el `withSum`/`loadSum`; ningún Resource expone las filas.

### 3. Contrato `TripEmergencyExpenseServiceInterface`

```php
/** @param array{limit?: string|null} $filters
 *  @return array{emergencyExpenses: LengthAwarePaginator<int, TripEmergencyExpense>|Collection<int, TripEmergencyExpense>, totalAmount: string} */
public function getTripEmergencyExpenses(User $user, int $tripId, array $filters): array;

/** @param array{amount: float|string, description: string, receipt?: UploadedFile|null} $data */
public function create(User $user, int $tripId, array $data): TripEmergencyExpense;

/** @param array{amount?: float|string, description?: string, receipt?: UploadedFile|null, removeReceipt?: bool} $data */
public function update(User $user, int $tripEmergencyExpenseId, array $data): TripEmergencyExpense;

public function delete(User $user, int $tripEmergencyExpenseId): TripEmergencyExpense;
```

Los nombres siguen a `TripExpenseServiceInterface` (`getTripExpenses`, `create`). Cada método documenta en su PHPDoc los `@throws` de las guardas del Alcance.

### 4. Cuerpos

- `POST /api/trips/{trip}/emergency-expenses`: `amount` (obligatorio), `description` (obligatorio) y `receipt` (archivo, opcional). Va como `multipart/form-data` si lleva archivo y como JSON si no.
- `PATCH /api/trip-emergency-expenses/{id}`: `amount`, `description`, `receipt` y `removeReceipt`, todos opcionales. El archivo usa la misma mecánica multipart que el `PATCH` de vehículos.
- `DELETE`: sin cuerpo.

### 5. `TripEmergencyExpenseResource` — nueve claves

| Clave | Valor |
|---|---|
| `id` | entero |
| `tripId` | entero |
| `amount` | string de dos decimales |
| `description` | string |
| `receiptUrl` | URL absoluta o `null` |
| `receiptType` | `jpg` \| `png` \| `pdf` \| `null`, derivada de la extensión de la key, como `invoiceType` en SPEC 19 |
| `registeredByName` | string |
| `createdAt` | `d-m-Y h:i:s A` |
| `updatedAt` | `d-m-Y h:i:s A`; delata que el gasto se corrigió |

Listado: `{ statusCode, message, data: [...], totalAmount: "350.00" }`, más `total/currentPage/lastPage` si viaja `limit`.

### 6. Cambios en `TripResource`

- Clave 40, `totalEmergencyExpensesAmount`, entre `totalExpensesAmount` y `createdAt`.
- Es un string de dos decimales; vale `"0.00"` sin gastos, y siempre `"0.00"` para `shipment`.
- Total: **43** claves, y **44** en `GET /{trip}` con `positions`.
- Se resuelve en `TripService::RELATIONS` y en `resolveWritableTrip()` con un tercer `withSum`/`loadSum`, sin filtro, porque no hay confirmación.

### 7. Cambios en el costeo (SPEC 33)

```json
"emergencyExpenses": { "count": 2, "subtotal": "850.00" }
```

- Va entre `expenses` y `pilot`; `TripCostResource` queda con **9 claves**.
- `totalCost = fuel + expenses + emergencyExpenses + pilot + vehicle`, sumando los subtotales ya redondeados.
- Sin gastos: `{ "count": 0, "subtotal": "0.00" }`, nunca `null`.
- Añade una consulta de agregado. El número de consultas sigue fijo y no crece con las filas.

### 8. `ReportServiceInterface::exportTripEmergencyExpenses()`

```php
/** @param array{tripId: int} $filters
 *  @return array{fileName: string, url: string, rows: int, total: int, truncated: bool, totalAmount: string} */
public function exportTripEmergencyExpenses(User $user, array $filters): array;
```

- Llama a `getTripEmergencyExpenses()` sin `limit`, así que el ámbito, el 403 de `shipment` y el 404 son los mismos que en el listado.
- `fileName`: `gastos-emergentes-viaje-{tripId}-{Y-m-d-His}.xlsx`. La key sigue siendo `reports/{uuid}.xlsx`.
- Cabeceras (`TRIP_EMERGENCY_EXPENSE_HEADERS`): `Id`, `Monto (Q)`, `Descripción`, `Comprobante`, `Registrado por`, `Creado`, `Actualizado`.
- `Monto (Q)` va como número para que Excel sume, y `Comprobante` lleva la URL pública o queda vacío.
- Tope `MAX_ROWS` (5000), como las otras dos exportaciones.

### 9. Tools del asistente

- **`trip_emergency_expenses`**: argumentos `tripId` (obligatorio) y `limit`. Devuelve `{ totalAmount, total, returned, emergencyExpenses: [...] }`, con las filas tal como salen de `TripEmergencyExpenseResource`.
- **`export_trip_emergency_expenses`**: argumento `tripId` (obligatorio). Devuelve la salida de `exportTripEmergencyExpenses()`.

---

## Plan de implementación

1. Crear la rama `spec-39-trip-emergency-expenses`.
2. Migración `create_trip_emergency_expenses_table`, modelo `TripEmergencyExpense`, factory con `withReceipt()` y `Trip::emergencyExpenses()`. Es aditivo, nada lo consume todavía, y la suite sigue verde.
3. Crear `TripEmergencyExpenseServiceInterface`, `TripEmergencyExpenseService` y `TripEmergencyExpenseProvider`, y registrar el provider en `bootstrap/providers.php`.
   - El service implementa los cuatro métodos con sus guardas.
   - El ámbito sale de `TripServiceInterface::getTripById()` y `resolveWritableTrip()`.
   - El archivo pasa por `storeUpload()`/`delete()`.
4. Crear `StoreTripEmergencyExpenseRequest` y `UpdateTripEmergencyExpenseRequest`, con `messages()` en español y su schema OA.
5. Crear `TripEmergencyExpenseResource` (nueve claves, schema OA) y `TripEmergencyExpenseController`, con `try/catch` → `ResponseHandler` y atributos OA en las cuatro acciones.
6. Rutas:
   - `POST`/`GET` en `routes/trips.php`, antes del `apiResource`.
   - Archivo nuevo `routes/trip-emergency-expenses.php` con `PATCH`/`DELETE`, incluido desde `routes/api.php`.
   - Comprobar con `php artisan route:list --path=emergency`.
7. Tests `TripEmergencyExpenseTest` (Feature) y `TripEmergencyExpenseServiceTest` (Unit): las cuatro rutas, el orden de las guardas, el ciclo del archivo con `fakeDefaultDisk()` y la matriz de roles.
8. `TripResource`:
   - Agregar `totalEmergencyExpensesAmount` en la clave 40, con `"0.00"` para `shipment`.
   - Agregar el tercer `withSum` en `TripService::RELATIONS`, y el `loadSum` en `resolveWritableTrip()` y en todo sitio que hoy cargue `totalExpensesAmount`.
   - Actualizar en `TripTest` los helpers `tripResourceKeys()` y `tripDetailKeys()`.
9. Costeo:
   - `TripCostService::resolveEmergencyExpenses()` y el bloque `emergencyExpenses` en `TripCostResource`, entre `expenses` y `pilot`.
   - Sumarlo a `totalCost`.
   - Actualizar `TripCostTest`/`TripCostServiceTest`, incluido el `DB::getQueryLog()` (una consulta más, fija).
10. `ReportServiceInterface::exportTripEmergencyExpenses()` y su implementación, con `TRIP_EMERGENCY_EXPENSE_HEADERS`. Ampliar `ReportServiceTest`.
11. Tools `TripEmergencyExpensesTool` (`app/Ai/Tools/Trip/`) y `ExportTripEmergencyExpensesTool` (`app/Ai/Tools/Report/`):
    - Registrarlas en `DashboardAssistant`, que pasa a dieciséis tools.
    - Ajustar el prompt para distinguir viáticos de gastos emergentes.
    - Ampliar `TripToolsTest` y `ReportToolsTest`.
12. Ejecutar `php artisan l5-swagger:generate` y `vendor/bin/pint --dirty --format agent`.
13. Marcar la spec como `Implementado`, crear `references/trip-emergency-expenses-api.md` y actualizar `CLAUDE.md`:
    - La lista de dominios.
    - Las rutas de `routes/trips.php` y la excepción de no anidar.
    - Las 43/44 claves de `TripResource`, el bloque del costeo y las dieciséis tools.

---

## Criterios de aceptación

**Registrar**

- [x] `POST /api/trips/{trip}/emergency-expenses` responde según la situación del viaje:
  - 404 si no existe.
  - 400 si está borrado, aunque sea ajeno.
  - 403 para un `carrier` si el viaje está sin asignar o es de otra empresa.
  - 400 para el `administrator` si está sin asignar.
  - 400 «Solo se pueden registrar gastos emergentes en un viaje en ruta» si está `pending` o `finished`.
  - 201 si está `in_route`.
- [x] `manager`, `pilot`, `export`, `user` y `shipment` reciben 403 del middleware. Un `carrier` sin empresa recibe 403 del service.
- [x] Las validaciones del alta:
  - `amount` ausente, en `0`, negativo, no numérico o mayor que `99999999.99` responde **422**.
  - `description` ausente, en blanco o de más de 255 caracteres responde **422**.
  - `receipt` con otro tipo de archivo o de más de 3 MB responde **422**.
- [x] Sin `receipt`, la fila guarda `receipt = null` y el Resource devuelve `receiptUrl: null`. Con `receipt`, el archivo queda en el disco falso bajo `trip-emergency-expenses/`, byte a byte igual al subido, y `receiptType` coincide con su extensión.
- [x] `registered_by` es el usuario autenticado. `description` se guarda con solo `trim`.

**Corregir**

- [x] `PATCH` responde según la situación:
  - 404 «El gasto emergente no existe».
  - 400 con el viaje borrado.
  - 403 si el gasto es de otra empresa.
  - 400 con el viaje `pending`.
  - 200 con el viaje `in_route` o `finished`.
- [x] El `PATCH` toca `amount` y `description`. `tripId`, `trip_id` y `registered_by` en el cuerpo se ignoran con 200.
- [x] Un `receipt` nuevo reemplaza la key en la fila, y el archivo anterior ya no está en el disco.
- [x] `removeReceipt=true` deja `receipt = null` y borra el archivo.
- [x] `receipt` junto con `removeReceipt=true` responde 422 y no toca nada.
- [x] Un cuerpo vacío responde 200 y no cambia `updated_at`.

**Borrar**

- [x] `DELETE` aplica las mismas guardas que el `PATCH`.
- [x] Con éxito, la fila desaparece y su archivo ya no está en el disco.
- [x] Responde 200 con las nueve claves del gasto borrado.

**Lectura**

- [x] El listado sale en orden `id ASC`, con nueve claves por fila y con paginación opt-in `[10, 100]`.
- [x] `totalAmount` aparece en la raíz con y sin `limit`, suma **todas** las filas del viaje y se calcula antes de paginar.
- [x] El piloto asignado lee; un piloto ajeno recibe 403. Un `carrier` fuera de su ámbito recibe 403 y `shipment` recibe 403.
- [x] Un viaje sin gastos responde 200 con `data` vacío y `totalAmount: "0.00"`. Un viaje inexistente o borrado responde 404.

**Viáticos intactos**

- [x] `GET /{trip}/expenses`, su `totalAmount` y `totalExpensesAmount` no cambian cuando el viaje tiene gastos emergentes.

**Contrato de trips**

- [x] `TripResource` devuelve **43 claves**, con `totalEmergencyExpensesAmount` después de `totalExpensesAmount`.
- [x] `GET /{trip}` devuelve **44 claves** (42 + esta + `positions`); el `pilot` ve 43.
- [x] `totalEmergencyExpensesAmount` sale `"0.00"` para `shipment`.
- [x] `TripListResource` sigue en 20 claves.

**Costeo**

- [x] `GET /{trip}/cost` incluye `emergencyExpenses: { count, subtotal }` entre `expenses` y `pilot`, y `TripCostResource` tiene 9 claves.
- [x] `totalCost` cuadra con la suma de los cinco subtotales.
- [x] Sin gastos, el bloque vale `{ count: 0, subtotal: "0.00" }`.
- [x] El número de consultas, medido con `DB::getQueryLog()`, no crece con la cantidad de gastos emergentes.

**Asistente y Excel**

- [x] `DashboardAssistant` expone dieciséis tools.
- [x] `trip_emergency_expenses` devuelve las filas y `totalAmount` del viaje. Un viaje fuera de ámbito vuelve como `{"error": ...}`.
- [x] `export_trip_emergency_expenses` deja un `.xlsx` en `reports/` con las siete cabeceras, el monto como número y la URL del comprobante, y devuelve `totalAmount`.
- [x] Con `maxRows` bajado, `truncated` es `true` y `total` cuenta todas las filas.

**Suite**

- [ ] `php artisan test --compact` pasa entera. **Nota:** 3891/3892; la única falla (`TripTimeoutTest` › «no toca ninguna parada al iniciar el viaje») es previa a esta rama, la misma que anotó SPEC 38: hace `/start` sin carga de combustible confirmada (guarda de SPEC 27).
- [x] `api-docs.json` está regenerado e incluye las cuatro rutas.

---

## Decisiones

- **Sí: tabla y dominio propios.** Viático y gasto emergente tienen flujos opuestos: uno se entrega y el otro se gasta, y uno se confirma y el otro no. Una columna `type` en `trip_expenses` obligaría a que `received_at`/`confirmed_by` y la ruta `/confirm` valgan para un tipo y no para el otro.
- **No: columna `type` en `trip_expenses`.** Mismo motivo, y además ensuciaría `totalExpensesAmount` y el bloque `expenses` del costeo, que hoy significan «viáticos confirmados».
- **Sí: sin confirmación.** El transportista registra lo que el piloto le avisó. No hay segunda firma, y el total suma todas las filas.
- **No: aviso del piloto dentro del sistema.** Un `POST` del piloto o un push abren un flujo y estados nuevos («reportado» → «registrado»), y merecen su propia spec.
- **Sí: registrar solo `in_route`.** Un imprevisto de carretera no ocurre en un viaje pendiente. En uno finalizado, lo que llega tarde es la corrección, y para eso está el `PATCH`.
- **Sí: `PATCH` y `DELETE` también en `finished`.** La factura de la llanta llega después del cierre. El costo (SPEC 33) se calcula en vivo y asume que puede moverse, como ya pasa con el seguro del vehículo.
- **No: append-only.** Tenía sentido en el viático para proteger lo que confirmó el piloto. Aquí no hay nada que proteger.
- **Sí: `DELETE` físico que arrastra el archivo.** Es el precedente de SPEC 19: un gasto mal tecleado es basura, no historial.
- **Sí: comprobante opcional y guardado tal cual.** Una factura recortada a 800×800 es ilegible (SPEC 19, SPEC 25).
- **Sí: `receipt` y no `invoice`.** No siempre es una factura fiscal: puede ser la foto de un recibo de una llantera.
- **Sí: `description` obligatoria.** Sin categorías, es lo único que dice qué pasó.
- **No: categorías.** El usuario lo pidió explícitamente. Ni para estos gastos ni para los viáticos.
- **No: `occurred_at`.** `created_at` alcanza mientras el registro sea cercano al hecho. Añadirlo después es una migración aditiva.
- **Sí: `PATCH`/`DELETE` sin anidar.** El id del gasto identifica el viaje. Es el precedente de `/trip-expenses/{id}/confirm`.
- **Sí: el piloto asignado lee y `shipment` no.** Igual que el viático: el dato es sobre su viaje, y `shipment` no ve dinero.
- **Sí: bloque propio en el costeo y no sumado a `expenses`.** El usuario pidió diferenciarlos. Mezclarlos en el costo borraría esa diferencia justo donde más importa.
- **Sí: exportación por viaje desde el asistente.** Es calco de `export_vehicle_expenses` y reutiliza el método del listado.
- **No: reporte por rango de fechas o endpoint descargable.** Requiere un listado global que el dominio no tiene. Va en otra spec.
- **No: imputar a `vehicle_expenses`.** Una llanta pinchada es un costo del viaje y no mantenimiento del vehículo. Vincularlos duplicaría el dinero en dos reportes.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **El `PATCH` con archivo en `multipart/form-data` no se parsea**: PHP no llena `$_FILES` en un `PATCH` real. | Se usa la misma mecánica que el `PATCH` de vehículos (`POST` + `_method=PATCH`), y `references/` lo documenta para el front. |
| **Archivos huérfanos** si falla el guardado de la fila tras subir el comprobante. | Orden procesar → subir → persistir. Si falla el `save`, se borra lo recién subido con `delete()` (que nunca lanza). El archivo anterior se borra solo **después** de guardar. |
| **El costo de un viaje cerrado cambia** si se corrige o borra un gasto emergente después de finalizar. | Es una decisión aceptada. `updatedAt` en el Resource delata la corrección. No hay bitácora. |
| **`totalEmergencyExpensesAmount` sale `"0.00"` en la respuesta de `/finish`**, igual que `totalFuelGallons` y `totalExpensesAmount`: `finish()` no recarga las sumas. | Comportamiento heredado y no corregido. El detalle `GET /{trip}` sí lo trae bien. |
| **Cambio de forma en `TripResource` (42 → 43) y `TripCostResource` (8 → 9).** | Las dos claves son nuevas y nada se renombra ni se quita: un cliente que ignore claves desconocidas no se rompe. |
| **El modelo del asistente confunde viáticos con gastos emergentes.** | Las dos tools llevan descripciones que se excluyen mutuamente, y el prompt fija la distinción. |

---

## Lo que **no** entra en esta spec

- Aviso del piloto: ruta propia, estado «reportado» o push al transportista.
- Categorías de gastos emergentes o de viáticos.
- Registrar en `pending` o `finished`.
- Aprobación, reembolso o liquidación al piloto.
- Vinculación con `vehicle_expenses`.
- Endpoint descargable, reporte por rango de fechas, columnas en `GET /api/reports/trips` y listado global.
- Dashboard, filtros en `GET /api/trips`, cambios en `TripListResource`, websocket y bitácora de ediciones.

Cada uno, si llega, va en su propia spec.
