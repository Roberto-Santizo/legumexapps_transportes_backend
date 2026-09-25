# SPEC 37 — Productos terminados del viaje

> **Estado:** Implementado
> **Depende de:** SPEC 22, SPEC 24, SPEC 36
> **Fecha:** 2026-09-25
> **Objetivo:** Registrar en una tabla propia (`trip_finished_products`) cuántas cajas de cada producto terminado del cliente lleva un viaje: al menos una línea obligatoria en el alta del viaje, y después un dominio `TripFinishedProduct` propio para listarlas por `tripId` y para añadir, editar o borrar líneas mientras el viaje siga `pending`.

---

## Alcance

**Dentro:**

- **Tabla nueva `trip_finished_products`**: `trip_id`, `finished_product_id`, `boxes` (entero), `registered_by`, `timestamps`. Índice único `(trip_id, finished_product_id)`. Sin `deleted_at`: el borrado es físico.
- **Dominio `TripFinishedProduct` completo**: modelo + factory, `TripFinishedProductServiceInterface`, `TripFinishedProductService` (inyecta `TripServiceInterface` por constructor), `TripFinishedProductProvider`, `IndexTripFinishedProductRequest`, `StoreTripFinishedProductRequest`, `UpdateTripFinishedProductRequest`, `TripFinishedProductResource`, `TripFinishedProductController` y `routes/trip-finished-products.php`.
- **Cuatro rutas bajo `/api/trip-finished-products`, sin anidar**:
  - `GET /?tripId=`: **sin paginar** nunca. Sin `tripId`, 422. Viaje inexistente o borrado, 404. Lectura con el ámbito de `getTripById()`: incluye al `pilot` asignado, a `user` y a `shipment`, y al `carrier` dentro de su empresa (fuera de ámbito, 403).
  - `POST /`: body `{ tripId, finishedProductId, boxes }`, añade una línea a un viaje existente.
  - `PATCH /{tripFinishedProduct}`: **solo `boxes`**. Cambiar de producto es borrar la línea y crear otra.
  - `DELETE /{tripFinishedProduct}`: borrado físico.
- **Escritura (`POST`, `PATCH`, `DELETE`) con `role:administrator,export`**. El resto de roles recibe 403.
- **Toda escritura de líneas exige el viaje en `pending`**. Si no lo está, 400; si está borrado, 400.
- **Validaciones de una línea**, cada una con su 400 desde el service:
  - El producto terminado no debe estar borrado; calco de `ensureClientIsActive()`.
  - El `client_id` del producto debe ser el `client_id` del viaje.
  - El producto no debe estar ya en el viaje. Además de la regla del service, lo respalda el índice único.
- **`DELETE` de la última línea del viaje: 400.** Un viaje siempre tiene al menos una línea.
- **`boxes`**: `required|integer|min:1`, con un `max` que evite el 500 de Postgres.
- **Cambios en `POST /api/trips`**: gana `products: [{ finishedProductId, boxes }]` como `required|array|min:1`, con `finishedProductId` `distinct` (repetido, 422). Pasa de 14 a **15 campos obligatorios**, un cambio incompatible sin periodo de gracia. `TripService::create()` pasa a correr en `DB::transaction` y escribe las líneas con el modelo directamente, sin inyectar el contrato nuevo, para no cerrar un ciclo en el contenedor. Aplica las mismas tres validaciones de línea, con 400.
- **Cambios en `PATCH /api/trips/{trip}`**: `products` en el body se ignora en silencio. Cambiar `clientId` en un viaje que tiene líneas responde **400** «No se puede cambiar el cliente de un viaje con productos terminados».
- **Producto terminado borrado después**: la línea sigue listándose con su `code`, `name`, etc. `finishedProduct()` lee `withTrashed()`.
- **`TripFinishedProductResource`** con las claves del modelo de datos.
- OpenAPI y regeneración de `api-docs.json`; tests Feature y Unit; `references/trip-finished-products-api.md`; actualización de `CLAUDE.md`.

**Fuera de alcance (para specs futuras):**

- Backfill de los viajes existentes: salen con listado vacío.
- Claves nuevas en `TripResource` o `TripListResource`, como `products` o `totalBoxes`: ninguno de los dos cambia de forma.
- Tarimas calculadas (`boxes / boxes_per_pallet`), pesos o importes.
- Guarda en `DELETE /api/finished-products/{id}` por estar en viajes, y guarda extra en `DELETE /api/clients/{id}`.
- Rastro de ediciones de líneas y cantidad recibida o entregada.
- Tool del asistente, exportación a Excel, filtros de `GET /api/trips` por producto y notificaciones push.
- Paginación del listado de líneas y un listado global sin `tripId`.

---

## Modelo de datos

### Tabla `trip_finished_products`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | `bigIncrements` | |
| `trip_id` | `foreignId` → `trips.id` | Inmutable · sin `cascade` |
| `finished_product_id` | `foreignId` → `finished_products.id` | Inmutable · sin `cascade` |
| `boxes` | `integer` | `>= 1` · único campo editable |
| `registered_by` | `foreignId` → `users.id` | Sale del usuario autenticado · no se reescribe en el `PATCH` |
| `created_at` / `updated_at` | `timestamps` | |

- Índice único `(trip_id, finished_product_id)`. También sirve a la única consulta del listado.
- Sin `deleted_at` ni `status`.

### Modelo `TripFinishedProduct`

- `use HasFactory`.
- `#[Fillable(['trip_id', 'finished_product_id', 'boxes', 'registered_by'])]`.
- `casts()`: `boxes` → `integer`.
- Relaciones:
  - `trip()` (`BelongsTo<Trip>`).
  - `finishedProduct()` (`BelongsTo<FinishedProduct>`, **`withTrashed()`**).
  - `registeredBy()` (`BelongsTo<User>`).
- La factory crea el viaje y el producto terminado **con el mismo `client_id`**.
- `Trip` gana `finishedProducts(): HasMany<TripFinishedProduct>`, que solo usan la guarda del `clientId` y la de «última línea». Ningún Resource de `Trip` la expone.

### Bodies

`POST /api/trips` (campo nuevo):

```json
{
  "products": [
    { "finishedProductId": 4, "boxes": 960 },
    { "finishedProductId": 7, "boxes": 120 }
  ]
}
```

| Campo | Reglas |
|---|---|
| `products` | `required\|array\|min:1` |
| `products.*.finishedProductId` | `required\|integer\|distinct\|exists:finished_products,id` |
| `products.*.boxes` | `required\|integer\|min:1\|max:999999` |

`POST /api/trip-finished-products`:

| Campo | Reglas |
|---|---|
| `tripId` | `required\|integer\|exists:trips,id` |
| `finishedProductId` | `required\|integer\|exists:finished_products,id` |
| `boxes` | `required\|integer\|min:1\|max:999999` |

`PATCH /api/trip-finished-products/{id}`: `boxes` `required|integer|min:1|max:999999`. El cuerpo vacío es 422, con el precedente del salario (SPEC 11). `tripId` y `finishedProductId` se ignoran en silencio.

`GET /api/trip-finished-products`: `tripId` `required|integer` (sin él, 422). Un id inexistente o borrado es 404 desde el service, no desde `exists:`.

### `TripFinishedProductResource` (10 claves)

```json
{
  "id": 1,
  "tripId": 12,
  "finishedProductId": 4,
  "code": "BRO-IQF-10",
  "name": "BRÓCOLI FLORETE IQF",
  "presentation": "10.00",
  "boxesPerPallet": "96.50",
  "boxes": 960,
  "registeredByName": "Admin",
  "createdAt": "25-09-2026 10:15:00 AM"
}
```

- `code`, `name`, `presentation` y `boxesPerPallet` se leen en vivo del producto terminado, sin copia. Si se edita el producto, cambia lo que muestran los viajes que lo llevan.
- Orden del listado: `id ASC`.

### Contrato `TripFinishedProductServiceInterface`

| Método | Devuelve |
|---|---|
| `getTripFinishedProducts(User $user, int $tripId)` | `Collection` (404 inexistente o borrado · 403 fuera de ámbito) |
| `createTripFinishedProduct(array $data, User $user)` | `TripFinishedProduct` |
| `updateTripFinishedProduct(int $id, array $data)` | `TripFinishedProduct` |
| `deleteTripFinishedProduct(int $id)` | `TripFinishedProduct` (ya borrada) |

### Guardas y su orden (contrato)

- **`POST` de línea:**
  1. Viaje borrado: 400 «El viaje ya fue eliminado».
  2. Viaje no `pending`: 400 «Solo se pueden modificar los productos de un viaje pendiente».
  3. Producto borrado: 400 «El producto terminado seleccionado ya fue eliminado».
  4. Cliente distinto: 400 «El producto terminado no pertenece al cliente del viaje».
  5. Repetido: 400 «El producto terminado ya está en el viaje».
- **`PATCH` de línea:** 404 «La línea de producto no existe» → pasos 1 y 2 anteriores.
- **`DELETE` de línea:** 404 → pasos 1 y 2 → 400 «El viaje debe tener al menos un producto terminado».
- **`POST /api/trips`:** después de las guardas de catálogo actuales, por cada línea, los pasos 3 y 4. El repetido lo atrapa antes `distinct` con 422.
- **`PATCH /api/trips/{trip}`:** si `clientId` cambia y el viaje tiene líneas, 400 «No se puede cambiar el cliente de un viaje con productos terminados». Reenviar el mismo `clientId` no es cambio.

---

## Plan de implementación

1. **Migración, modelo y factory.** Ejecutar `php artisan make:model TripFinishedProduct -mf`. La migración sigue la tabla, con el índice único `(trip_id, finished_product_id)`. El modelo lleva `#[Fillable]`, `casts()` y las tres relaciones, con `finishedProduct()` en `withTrashed()`. La factory usa el mismo cliente para el viaje y para el producto. `Trip` gana `finishedProducts()`. Verificación: `php artisan migrate` corre limpio.
2. **Contrato, service y provider.**
   - `TripFinishedProductServiceInterface` con PHPDoc de array shapes.
   - `TripFinishedProductService` con `#[Override]` y `TripServiceInterface` por constructor. Helpers: `resolveWritableTrip()` (400 borrado, 400 no `pending`), `ensureFinishedProductIsActive()`, `ensureFinishedProductMatchesClient()`, `ensureFinishedProductIsNotInTrip()`, `ensureTripKeepsOneLine()` y `resolveTripFinishedProduct()` (404).
   - El listado usa `getTripById()` para heredar el ámbito, con eager loading de `finishedProduct` y `registeredBy`.
   - `TripFinishedProductProvider` se registra en `bootstrap/providers.php`.
3. **FormRequests y Resource.** `IndexTripFinishedProductRequest`, `StoreTripFinishedProductRequest` y `UpdateTripFinishedProductRequest`, con `messages()` en español. `TripFinishedProductResource` con las 10 claves.
4. **Controller y rutas.** `TripFinishedProductController` con `try/catch` → `ResponseHandler` y el service por parámetro de método. `routes/trip-finished-products.php` tiene `GET /` con `jwt.auth` a secas y `POST`, `PATCH /{tripFinishedProduct}` y `DELETE /{tripFinishedProduct}` con `role:administrator,export`. Se incluye desde `routes/api.php`. Verificación: `php artisan route:list --path=trip-finished-products` muestra cuatro rutas.
5. **Alta del viaje.**
   - `StoreTripRequest` gana `products`, `products.*.finishedProductId` y `products.*.boxes`, con sus mensajes.
   - `TripService::create()` pasa a `DB::transaction`: valida por línea (producto no borrado y del mismo cliente), crea el viaje y luego las líneas con `TripFinishedProduct::query()->create()`, con `registered_by` del usuario autenticado.
   - Hay que actualizar los tests existentes de `POST /api/trips` y los helpers de payload, que ahora necesitan `products`.
6. **`PATCH` del viaje.** En `TripService::update()`, si el `clientId` cambia y existe al menos una línea, 400. `products` no entra en `UPDATABLE_FIELDS`.
7. **Tests.**
   - `TripFinishedProductTest` (Feature):
     - Roles de lectura y escritura, y 401 sin token.
     - 422 sin `tripId`, 404 con un viaje inexistente o borrado, 403 fuera de ámbito.
     - Las cinco guardas del `POST` en orden, 400 en `PATCH`/`DELETE` con el viaje no `pending`, 400 al borrar la última línea.
     - `withTrashed()` del producto y que el `PATCH` ignora `tripId`/`finishedProductId`.
     - El número de consultas del listado no crece con N.
   - Casos nuevos en `TripTest`:
     - `products` ausente, vacío o repetido → 422.
     - Producto borrado o de otro cliente → 400 sin crear el viaje (rollback).
     - 201 crea las N líneas.
     - `PATCH` con `clientId` distinto y líneas → 400.
   - `TripFinishedProductServiceTest` (Unit).
8. **OpenAPI.** Atributos en el Controller nuevo, los FormRequests y el Resource. Añadir `products` al schema de `StoreTripRequest`. Ejecutar `php artisan l5-swagger:generate`.
9. **Documentación.**
   - En `CLAUDE.md`: el dominio en la lista, una subsección bajo Trips, la matriz de roles, las rutas fijas y la nota del `POST` de 15 campos.
   - `references/trip-finished-products-api.md`.
   - Ejecutar `vendor/bin/pint --dirty --format agent`.

---

## Criterios de aceptación

- [x] `php artisan migrate` crea `trip_finished_products` con el índice único `(trip_id, finished_product_id)` y sin `deleted_at`.
- [x] `php artisan route:list --path=trip-finished-products` muestra exactamente cuatro rutas.
- [x] Sin token, las cuatro rutas responden 401.
- [x] `POST /api/trips` sin `products`, con `products: []`, con `finishedProductId` repetido, con `boxes: 0` o con `boxes: 1.5` responde **422**.
- [x] `POST /api/trips` con un producto borrado o de otro cliente responde **400** y no crea ni el viaje ni ninguna línea.
- [x] `POST /api/trips` válido con dos productos responde 201, y `GET /api/trip-finished-products?tripId=` devuelve esas dos líneas en orden `id ASC`.
- [x] `TripResource` sigue en 42/43 claves y `TripListResource` en 19.
- [x] `PATCH /api/trips/{trip}` con un `clientId` distinto en un viaje con líneas responde **400**; con el mismo `clientId`, 200. `products` en el body se ignora.
- [x] `GET /api/trip-finished-products` sin `tripId` responde **422**; con un viaje inexistente o borrado, **404**; fuera del ámbito del `carrier`, **403**.
- [x] El listado responde 200 a `administrator`, `manager`, `export`, `user`, `shipment`, al `pilot` asignado y al `carrier` dentro de su ámbito. Nunca pagina, ni siquiera con `?limit=`.
- [x] Un viaje sin líneas (anterior a la spec) responde 200 con `data: []`.
- [x] `POST`, `PATCH` y `DELETE` de líneas responden 2xx a `administrator` y `export`, y **403** a los otros cinco roles.
- [x] `POST` de línea responde 400 en este orden: viaje borrado → viaje no `pending` → producto borrado → cliente distinto → producto ya en el viaje.
- [x] `PATCH` de línea cambia solo `boxes`, ignora `tripId` y `finishedProductId` y no reescribe `registered_by`. Con cuerpo vacío responde **422**.
- [x] `PATCH` y `DELETE` de línea responden **400** si el viaje está `in_route` o `finished`, y **404** si la línea no existe.
- [x] `DELETE` de la única línea de un viaje responde **400**; con dos líneas, borra una con 200 y la fila desaparece de la tabla.
- [x] Borrar el producto terminado después de crear la línea no la oculta: sigue saliendo con su `code` y su `name`.
- [x] `TripFinishedProductResource` devuelve exactamente las 10 claves. `presentation` y `boxesPerPallet` salen como string de dos decimales y `boxes` como entero.
- [x] El número de consultas del listado no crece con el número de líneas (`DB::getQueryLog()`).
- [x] `api-docs.json` regenerado incluye las cuatro rutas y `products` en el `POST /api/trips`.
- [x] `php artisan test --compact --filter="TripFinishedProduct|TripTest|TripServiceTest"` en verde y `vendor/bin/pint --dirty --format agent` sin cambios pendientes.

---

## Decisiones tomadas y descartadas

- **Sí:** dominio propio `TripFinishedProduct` con tabla `trip_finished_products`. Una línea se edita después del alta y merece sus capas completas, no un `belongsToMany` escondido en `Trip`.
- **No:** llamar a la tabla `trip_products`. Se confundiría con `Product` (SPEC 07), que no tiene relación.
- **Sí:** rutas sin anidar, con `tripId` como query param obligatorio (422 sin él, 404 inexistente). Decisión explícita del usuario para no romper la convención REST. Precedente: `vehicle-expenses` (SPEC 14) y `accessory-characteristics` (SPEC 18).
- **No:** `/api/trips/{trip}/finished-products`. Anidar habría sido el precedente de `/fuels` y `/expenses`, pero se descartó.
- **Sí:** listado sin paginación nunca, como `FreightRate`. Un viaje lleva pocas líneas.
- **Sí:** `boxes` entero `min:1`. Son cajas físicas.
- **Sí:** el producto debe ser del cliente del viaje (400). Un SKU de otro cliente en su contenedor es un error de captura.
- **Sí:** producto repetido → 422 (`distinct`) en el alta del viaje y 400 en el `POST` de línea, con índice único detrás. **No:** sumar cajas en silencio ni permitir filas duplicadas.
- **Sí:** producto borrado → 400 al escribir, calco de `ensureClientIsActive()` (SPEC 36). Borrado después, la línea lo muestra con `withTrashed()`.
- **Sí:** mínimo una línea en el alta (`min:1`), y el `DELETE` de la última es 400. El invariante «todo viaje nuevo tiene productos» se mantiene toda su vida.
- **Sí:** toda escritura de líneas, `POST` incluido, solo con el viaje en `pending`. Una vez en ruta, la carga ya salió.
- **Sí:** el `PATCH` de línea solo toca `boxes`. Cambiar de producto es borrar y crear.
- **Sí:** el `PATCH` del viaje ignora `products` y responde 400 si cambia `clientId` teniendo líneas. **No:** permitirlo en silencio, porque las líneas quedarían de otro cliente.
- **Sí:** `TripService::create()` escribe las líneas con el modelo, dentro de una transacción nueva. **No:** inyectar `TripFinishedProductServiceInterface`, que ya inyecta `TripServiceInterface`; cerraría un ciclo. Precedente: `closeOpenTimeout()`.
- **Sí:** lectura con el ámbito de `getTripById()`, incluido el `pilot` asignado. Son las cajas que lleva; al revés que `/positions` y `/timeouts`.
- **Sí:** escritura `administrator` y `export`, los mismos que crean viajes.
- **Sí:** el Resource lee `code`, `name`, `presentation` y `boxesPerPallet` en vivo. **No:** copiarlos en la línea. Editar el producto cambia lo que muestran sus viajes.
- **No:** `products` ni `totalBoxes` en `TripResource`/`TripListResource`. Decisión explícita del usuario: Resource aparte.
- **No:** tarimas calculadas ni guarda en `DELETE /api/finished-products/{id}`.
- **No:** backfill. Los viajes anteriores listan `[]`.

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| `POST /api/trips` pasa a 15 campos obligatorios: el frontend actual recibe 422 hasta que mande `products` | Cambio incompatible aceptado sin periodo de gracia, con el precedente de SPEC 13/21/25/30. Se anuncia en `references/trip-finished-products-api.md`. |
| Los viajes anteriores a la spec tienen cero líneas y rompen el invariante «al menos una» | Se acepta: el listado devuelve `[]`. En esos viajes el `DELETE` no aplica porque no hay nada que borrar, y un `POST` de línea los repara. |
| El `PATCH` del administrador puede devolver un viaje de `finished` a `pending` (hueco de SPEC 24) y reabrir la edición de líneas | Hueco heredado y declarado. Esta spec no añade máquina de estados. |
| Un producto de un cliente borrado sigue asignable si el propio producto no está borrado | Coherente con SPEC 36, que no bloquea el borrado de clientes. La guarda de cliente del viaje ya exige que el cliente del viaje no esté borrado al crearlo. |
| Editar `presentation` o `boxesPerPallet` de un producto cambia retroactivamente lo que muestran los viajes ya finalizados | Decisión aceptada: sin copia en la línea. Si hace falta historial, irá en otra spec. |
| Carrera entre dos `DELETE` simultáneos que dejen el viaje en cero líneas | `lockForUpdate` sobre las líneas del viaje dentro de una transacción en el `DELETE`, antes de contar. |
| Carrera entre dos `POST` del mismo producto | El índice único la corta. El service traduce la violación a 400 en vez de un 500. |

---

## Lo que **no** está en esta spec

- Backfill de líneas para los viajes existentes.
- `products` o `totalBoxes` dentro de `TripResource` o `TripListResource`.
- Tarimas calculadas, pesos, importes o cantidades recibidas.
- Guarda en `DELETE /api/finished-products/{id}` o `DELETE /api/clients/{id}` por líneas de viaje.
- Rutas anidadas bajo `/api/trips/{trip}/…` y listado global sin `tripId`.
- Paginación del listado de líneas.
- Tool del asistente, exportación a Excel, filtros de viajes por producto y notificaciones push.

Cada uno de esos, si llega, va en su propia spec.
