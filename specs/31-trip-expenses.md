# SPEC 31 — Viáticos del viaje

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 24, SPEC 27 (`27-trip-fuels.md`)
> **Fecha:** 2026-09-18
> **Objetivo:** Publicar el dominio `TripExpense` —los viáticos (dinero para gastos del camino) que la empresa transportista entrega al piloto de un viaje y que ese piloto confirma haber recibido—, permitiendo entregar el primero en la misma llamada que asigna piloto y vehículo.

Es **un dominio nuevo con tabla propia** y el **primer calco declarado de otro dominio transaccional**: `TripFuel` (SPEC 27) con dinero en vez de galones. Misma tabla append-only, mismo ciclo de vida de dos estados sin columna `status`, mismas tres rutas, mismas cuatro guardas en el mismo orden, mismo total en la raíz del sobre y misma clave agregada en `TripResource`. Donde SPEC 20 copió a `Location` para un catálogo, aquí se copia a `TripFuel` para un registro de entregas.

Lo que **no** se copia es lo que hacía del combustible un requisito: el viático es **opcional** en `/assignment` y **no bloquea `/start`**. Un viaje arranca con o sin viáticos; el dinero se registra si se entregó y se confirma si se recibió, y nada más.

---

## Alcance

**Dentro:**

- **Tabla nueva `trip_expenses`**, una fila por entrega: `trip_id`, `amount` (`decimal(10,2)`, GTQ por convención), `description` (`string` **nullable**, solo `trim`), `received_at` (`timestamp` **nullable**), `confirmed_by` (nullable), `registered_by` y `timestamps`. FK sin `cascade`, índice `trip_id`.
- **Ciclo de vida de dos estados, sin columna `status`**: nace **sin confirmar** (`received_at = null`) y se confirma una sola vez. `isConfirmed` se deriva en el Resource.
- **Dominio `TripExpense` completo** en su subcarpeta: `TripExpenseServiceInterface`, `TripExpenseService`, `TripExpenseProvider`, `StoreTripExpenseRequest`, `TripExpenseResource` y `TripExpenseController`. El service inyecta `TripServiceInterface` **por constructor**, como `TripFuelService`.
- **Tres rutas nuevas**, repartidas en dos archivos:

  | Ruta | Middleware | Efecto |
  |---|---|---|
  | `POST /api/trips/{trip}/expenses` | `role:carrier` | Registra un viático sin confirmar |
  | `GET /api/trips/{trip}/expenses` | `jwt.auth` | Listado del viaje, acotado por ámbito |
  | `PATCH /api/trip-expenses/{tripExpense}/confirm` | `role:pilot` | `received_at = now()`, `confirmed_by = ` el piloto |

  Las dos primeras viven en `routes/trips.php` —que pasa de catorce a dieciséis rutas—, tras las de `fuels`. La tercera vive en `routes/trip-expenses.php`, incluido desde `routes/api.php`.
- **`PATCH /api/trips/{trip}/assignment` gana dos campos opcionales**: `expenseAmount` (`sometimes|nullable|numeric|min:0.01|max:99999999.99`) y `expenseDescription` (`sometimes|nullable|string|max:255`). **No es cambio incompatible**: un cliente que siga mandando los cuatro campos de SPEC 27 obtiene exactamente lo mismo que antes.
- **Si viaja `expenseAmount`, `assign()` crea el primer viático dentro de su propia transacción**, detrás del mismo `lockForUpdate` y junto a la carga de combustible. Sin él no se crea nada. `expenseDescription` sin `expenseAmount` **se ignora en silencio** (precedente: archivo con `is_invoiced=false` en SPEC 19). Reasignar con monto **añade otra fila**.
- **`amount`** se valida `required|numeric|min:0.01|max:99999999.99` (el tope del `decimal(10,2)`, para que un desbordamiento sea 422 y no 500, como SPEC 30). **`description`** solo `trim`; vacía o solo espacios → `null`.
- **Cuatro guardas del `POST`, en el mismo orden que SPEC 27**: inexistente → **404**; borrado → **400**; el viaje no lo tomó la empresa de quien llama (o no está asignado) → **403** «No puedes registrar viáticos en un viaje que no tomó tu empresa transportista»; `finished` → **400**. Se registra en `pending` **y en `in_route`**.
- **Confirmar es del piloto asignado y solo suyo**; sin cuerpo y sin FormRequest; no mira el `status` del viaje; **reconfirmar es 200 sin escribir**.
- **Lectura con el ámbito de SPEC 24, incluido el piloto asignado.** Orden `id ASC`, paginación opt-in `[10, 100]`, sin filtros.
- **`totalAmount` en la raíz del sobre** del listado: suma de los viáticos **confirmados**, sobre la consulta clonada **antes** de paginar y presente también sin `limit`.
- **`TripResource` pasa de 39 a 40 claves** con `totalExpensesAmount`, justo después de `totalFuelGallons`, resuelta con `withSum`/`loadSum` acotado a `received_at IS NOT NULL`. `Trip` gana `expenses()` solo para eso.
- Tests Pest (Feature de las tres rutas nuevas y de `/assignment`, Unit del service y de `assign()`) y Swagger regenerado.
- Resumen de integración para el frontend en `references/trip-expenses-api.md`.

**Fuera de alcance (para specs futuras):**

- **Guarda en `/start` o `/finish`.** Un viaje arranca y termina sin ningún viático, registrado o confirmado. Es la diferencia de fondo con el combustible.
- **Corregir o borrar un viático.** Tabla **append-only**: sin `PATCH`, sin `DELETE`, sin desconfirmar. Un monto mal tecleado se queda para siempre.
- **Comprobante, categoría, moneda o cantidad realmente recibida.** `description` es el único texto libre; el piloto confirma o no confirma.
- **Liquidación.** No hay gastos reales contra el viático, ni saldo, ni devolución.
- **Listado global, filtros, websocket, correo.** No hay `GET /api/trip-expenses`, `GET /api/trips` no gana filtros, `TripListResource` sigue en 17 claves y el canal `trips.{tripId}` sigue llevando solo posiciones.
- **Dashboard.** SPEC 29 no agrega viáticos: ni en `/trips`, ni en `/trips/in-route`.

---

## Modelo de datos

### 1. Tabla `trip_expenses`

| Columna | Tipo | Nota |
|---|---|---|
| `id` | bigint | |
| `trip_id` | FK `trips` | sin cascade, con índice |
| `amount` | `decimal(10,2)` | > 0; sin cast, el Resource formatea |
| `description` | `string` nullable | solo `trim`; vacío → `null` |
| `received_at` | `timestamp` nullable | `null` = sin confirmar; `now()` del servidor al confirmar |
| `confirmed_by` | FK `users` nullable | se escribe junto a `received_at` |
| `registered_by` | FK `users` | el usuario autenticado, nunca del body |
| `timestamps` | | |

### 2. Modelo `App\Models\TripExpense`

`#[Fillable]` con las seis columnas; `trip()`, `confirmedBy()`, `registeredBy()`; cast `received_at => datetime`. `Trip::expenses(): HasMany`, por la suma y no por el payload.

### 3. Cuerpo de `PATCH /api/trips/{trip}/assignment`

`pilotId`, `vehicleId`, `fuelGallons`, `fuelType` (obligatorios, SPEC 27) + `expenseAmount`, `expenseDescription` (opcionales).

### 4. Cuerpo de `POST /api/trips/{trip}/expenses`

`amount` (obligatorio), `description` (opcional).

### 5. `TripExpenseResource` — ocho claves

`id`, `tripId`, `amount` (string 2 decimales), `description`, `isConfirmed`, `receivedAt` (`d-m-Y h:i:s A` | `null`), `confirmedByName`, `registeredByName`.

### 6. Lo que cambia en `TripResource`

Clave 37 `totalExpensesAmount`, entre `totalFuelGallons` y `createdAt`. Total: 40.

---

## Plan de implementación

1. Rama `spec-31-trip-expenses`.
2. Migración `create_trip_expenses_table`, modelo, factory con estado `confirmed()`, `Trip::expenses()`.
3. `TripExpenseServiceInterface`, `TripExpenseService`, `TripExpenseProvider` registrado en `bootstrap/providers.php`.
4. `StoreTripExpenseRequest`, `TripExpenseResource` (tres schemas OA), `TripExpenseController`, rutas en `routes/trips.php` y `routes/trip-expenses.php`.
5. `TripService`: `confirmedExpensesAmountSum()`, segundo `withSum`/`loadSum`, viático opcional en `assign()`. `AssignTripRequest` con los dos campos. `TripResource` con la clave 37. Docblocks y textos OA de `TripController`.
6. Tests: `TripExpenseTest`, `TripExpenseServiceTest`, sección SPEC 31 en `TripTest` y `TripServiceTest`.
7. `php artisan l5-swagger:generate`; `vendor/bin/pint --dirty --format agent`.
8. Spec `Implementado`, `references/trip-expenses-api.md`, `CLAUDE.md`.

---

## Criterios de aceptación

**Asignación**

- [x] `/assignment` con los cuatro campos de SPEC 27 y sin `expenseAmount` responde 200 y **no** deja ninguna fila en `trip_expenses`.
- [x] Con `expenseAmount` deja **exactamente una** fila sin confirmar, `registered_by` igual al que asignó, y `totalExpensesAmount` en `"0.00"`.
- [x] `expenseDescription` sin `expenseAmount` se ignora con 200 y sin fila.
- [x] `expenseAmount` en `0`, negativo, no numérico o desbordado responde **422** y no asigna nada; `expenseDescription` de más de 255 responde **422**.
- [x] Reasignar con monto deja **dos** filas. Si la asignación falla por una guarda, no queda ninguna.

**Registrar**

- [x] `POST /api/trips/{trip}/expenses`: 404 inexistente; 400 borrado (aunque sea ajeno); 403 sin asignar o de otra empresa; 400 `finished`; 201 en `pending` e `in_route`.
- [x] `administrator`, `manager` y `pilot` reciben 403 del middleware; un `carrier` sin empresa, 403 del service.
- [x] `description` se guarda con solo `trim`; ausente, `null` o en blanco → `null`.

**Confirmar**

- [x] El piloto asignado escribe `received_at` con la hora del servidor y `confirmed_by`; repetir es 200 sin cambiar nada; otro piloto 403; otros roles 403 del middleware; id inexistente 404; cuerpo ignorado; viaje `finished` admitido.

**Arranque**

- [x] `/start` responde 200 sin ningún viático confirmado.

**Lectura**

- [x] Orden `id ASC`; `totalAmount` en la raíz con y sin `limit`, sumando solo confirmados y calculado antes de paginar; `[10, 100]`; el `pilot` asignado lee, el ajeno 403; `carrier` fuera de ámbito 403; bolsa 200 vacío; viaje inexistente o borrado 404; ocho claves.

**Contrato de trips**

- [x] `TripResource` devuelve **40 claves**, `totalExpensesAmount` tras `totalFuelGallons`; `TripListResource` sigue en 17.
- [x] El `PATCH` general con `expenseAmount`/`expenseDescription` responde 200 y no escribe nada.

**Suite**

- [x] `php artisan test --compact` pasa entera salvo un fallo preexistente en `main` (`TripTimeoutTest` «no toca ninguna parada al iniciar el viaje», que arranca sin carga confirmada desde SPEC 27).

---

## Decisiones

- **Tabla propia y no columna.** El usuario pidió «una columna de viáticos», pero un viaje recibe dinero más de una vez y una columna lo impediría; se sigue el argumento de SPEC 27.
- **Opcional en `/assignment`.** El combustible es condición para conducir; el viático no. Exigirlo rompería a los clientes por segunda vez en el mismo endpoint sin ganar nada.
- **Sin guarda en `/start`.** Misma razón: una empresa puede entregar el dinero en efectivo y no registrarlo, y el viaje no puede quedarse bloqueado por papeleo.
- **`received_at` y no `loaded_at`.** El nombre dice lo que confirma el piloto: que recibió el dinero.
- **`max` en el monto.** Tope del `decimal(10,2)` para 422 y no 500, como SPEC 30.
- **Sin categoría ni comprobante.** Un texto libre cubre el caso real; un enum de categorías o una imagen son otra spec.
- **Dashboard intacto.** Agregar viáticos al tablero es otra spec, como lo era el combustible en SPEC 29.

---

## Riesgos

- **Append-only.** Un monto mal tecleado es permanente; el frontend debe confirmar la cantidad antes de mandarla.
- **`totalExpensesAmount` sale `"0.00"` en la respuesta de `/finish`**, como `totalFuelGallons`: `finish()` no recarga las sumas. Comportamiento heredado, no corregido.
- **Dos sumas por detalle.** `getTripById()` y `resolveWritableTrip()` hacen dos subconsultas de agregado; despreciable frente a las ocho relaciones.

---

## Lo que **no** entra en esta spec

Guarda de viáticos en `/start`/`/finish`, corrección o borrado, desconfirmar, comprobantes, categorías, moneda, liquidación de gastos reales, listado global, filtros en `GET /api/trips`, websocket, correo, Dashboard.
