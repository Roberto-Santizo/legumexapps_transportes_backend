# SPEC 36 — Catálogo de productos terminados

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 22
> **Fecha:** 2026-09-25
> **Objetivo:** Publicar un catálogo de SKUs de productos terminados —`code` único global, `name`, `presentation` y `boxes_per_pallet` numéricos, y un cliente obligatorio y activo—, legible por todos los roles salvo `pilot`, editable solo por `administrator` y `export`, y con borrado `SoftDeletes` sin vuelta atrás.

Depende de **SPEC 01** (guard JWT, `UserRole`, middleware `role:`) y de **SPEC 22** (`Client`, al que apunta `client_id`). No depende de SPEC 07: `FinishedProduct` **no tiene ninguna relación con `Product`**; comparten la palabra, no el dominio. `Product` es la mercancía que cotiza en `freight_rates`; un producto terminado es una presentación empacada de un cliente.

Es el **quinto dominio con `SoftDeletes`** (tras `FreightRate`, `Client`, `ShippingLine` y `Trip`) y el **primer catálogo cuyo `name` no es único ni se normaliza más allá de las mayúsculas**.

---

## Alcance

**Dentro:**

- **Tabla nueva `finished_products`** con `id`, `code`, `name`, `presentation`, `boxes_per_pallet`, `client_id`, `registered_by`, `timestamps` y `deleted_at`. **Ninguna tabla existente se toca.**
- **Ningún enum nuevo.** Un producto terminado existe o está borrado.
- **Dominio `FinishedProduct` completo** con las capas del proyecto: modelo `FinishedProduct` con factory, `FinishedProductServiceInterface` (`app/Interfaces/FinishedProduct/`), `FinishedProductService` (`app/Services/FinishedProduct/`), `FinishedProductProvider` registrado en `bootstrap/providers.php`, `StoreFinishedProductRequest` y `UpdateFinishedProductRequest` (`app/Http/Requests/FinishedProduct/`), `FinishedProductResource` (`app/Http/Resources/FinishedProduct/`), `FinishedProductController` y `routes/finished-products.php`, incluido desde `routes/api.php`.
- **Cinco rutas bajo `/api/finished-products`**: `apiResource('/')->parameters(['' => 'finishedProduct'])` con `index`, `store`, `show`, `update` y `destroy`. **Ninguna ruta fija** (no hay `/toggle-status` ni `/restore`).
- **Ninguna ruta lleva `carrier.required`**: es un catálogo nacional.
- **Lectura (`index`, `show`)** con `role:` + `UserRole::allExcept(UserRole::Pilot)`: `administrator`, `manager`, `carrier`, `export`, `user` y `shipment`. El `pilot` recibe **403**.
- **Escritura (`store`, `update`, `destroy`)** con `role:administrator,export`. El resto recibe **403**.
- **`code` único global**, trim + MAYÚSCULAS con `FinishedProduct::normalizeCode()`, `max:15`, **sin espacios** (`regex:/^\S+$/u` → 422). El borrado **no libera** el `code`. Duplicado → **400 desde el service** (`ensureCodeIsAvailable($code, $ignoreId)`, lee `withTrashed()`), sin regla `unique` en el FormRequest.
- **`name` sin unicidad**, `max:255`, normalizado **solo a MAYÚSCULAS** (`mb_strtoupper`) con `FinishedProduct::normalizeName()`: ni trim ni colapso de espacios.
- **`presentation` y `boxes_per_pallet`** numéricos, `decimal(10,2)`, obligatorios en el alta, `min:0.01`, `max:99999999.99`. Sin unidad documentada.
- **`client_id` obligatorio** en el alta y **editable** en el `PATCH`. El cliente debe existir (`exists:clients,id` → 422) y **no estar borrado** (400 «El cliente seleccionado ya fue eliminado» desde el service).
- **`PATCH`** acepta los cinco campos de negocio como `sometimes`. Cuerpo vacío → 200 sin cambios.
- **`SoftDeletes`**: el listado excluye las borradas; `GET /{id}` de una borrada es **404**; `PATCH` o `DELETE` sobre una es **400 «El producto terminado ya fue eliminado»** (`resolveWritableFinishedProduct()` con `withTrashed()`). Sin `restore`.
- **`registered_by`** sale del usuario autenticado y no se reescribe en el `update`.
- **Listado** con filtros tolerantes `search` (`LIKE` sobre `code` **y** `name`, agrupado, término en mayúsculas) y `clientId` (no numérico se ignora), orden `id ASC`, paginación opt-in `[10, 100]`.
- **`FinishedProductResource` con 11 claves** (detalle en el modelo de datos).
- **Borrar un cliente no se bloquea** por tener productos terminados: `ClientService` no cambia. Los SKU del cliente borrado siguen visibles con su `clientName`.
- Anotaciones OpenAPI y regeneración de `api-docs.json` (agente `endpoint-docs`); tests Pest Feature + Unit (agente `feature-tests`).
- Resumen de integración para el frontend en `references/finished-products-api.md`.

**Fuera de alcance (para specs futuras):**

- Cualquier relación con `Product` (SPEC 07) o con `freight_rates`.
- Relación con `trips`: ningún viaje gana líneas de producto terminado ni `finished_product_id`.
- Guarda en `DELETE /api/clients/{id}` por productos terminados.
- Unidad de medida de `presentation` (columna o enum).
- `restore`, `?trashed=true` o listado de borrados.
- Tool del asistente (`app/Ai/`) y exportación a Excel.
- Imagen del producto, peso bruto/neto, código de barras, precio.
- `Client` **no gana** relación `finishedProducts()` y `ClientResource` no cambia.

---

## Modelo de datos

### Tabla `finished_products`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | `bigIncrements` | |
| `code` | `string(15)` | **Único global** (índice único) · trim + MAYÚSCULAS · sin espacios · el borrado no lo libera |
| `name` | `string(255)` | **Sin índice único** · solo MAYÚSCULAS |
| `presentation` | `decimal(10,2)` | Obligatorio · `> 0` |
| `boxes_per_pallet` | `decimal(10,2)` | Obligatorio · `> 0` |
| `client_id` | `foreignId` → `clients.id` | Obligatorio · índice · **sin `cascade`** |
| `registered_by` | `foreignId` → `users.id` | Del usuario autenticado · sin `cascade` |
| `created_at` / `updated_at` | `timestamps` | |
| `deleted_at` | `softDeletes` | |

### Modelo `FinishedProduct`

- `use HasFactory, SoftDeletes`.
- `#[Fillable(['code', 'name', 'presentation', 'boxes_per_pallet', 'client_id', 'registered_by'])]`.
- Relaciones: `client()` (`BelongsTo<Client>`, **`withTrashed()`** para que `clientName` salga aunque el cliente esté borrado) y `registeredBy()` (`BelongsTo<User>`).
- `casts()`: `presentation` y `boxes_per_pallet` → `decimal:2`.
- Estáticos: `normalizeCode(string): string` (`mb_strtoupper(trim())`) y `normalizeName(string): string` (`mb_strtoupper()` a secas).
- Factory `FinishedProductFactory` con `client_id` desde `Client::factory()`, `registered_by` desde `User::factory()`, `code` único aleatorio de hasta 15 caracteres sin espacios.

### Cuerpo del `POST`

```json
{
  "code": "BRO-IQF-10",
  "name": "Brócoli florete IQF",
  "presentation": 10,
  "boxesPerPallet": 96.5,
  "clientId": 3
}
```

| Campo | `POST` | `PATCH` |
|---|---|---|
| `code` | `required\|string\|max:15\|regex:/^\S+$/u` | `sometimes\|required\|…` |
| `name` | `required\|string\|max:255` | `sometimes\|required\|…` |
| `presentation` | `required\|numeric\|min:0.01\|max:99999999.99` | `sometimes\|required\|…` |
| `boxesPerPallet` | `required\|numeric\|min:0.01\|max:99999999.99` | `sometimes\|required\|…` |
| `clientId` | `required\|integer\|exists:clients,id` | `sometimes\|required\|…` |

### `FinishedProductResource` (11 claves)

```json
{
  "id": 1,
  "code": "BRO-IQF-10",
  "name": "BRÓCOLI FLORETE IQF",
  "presentation": "10.00",
  "boxesPerPallet": "96.50",
  "clientId": 3,
  "clientName": "FRESH FOODS INC",
  "registeredByName": "Admin",
  "createdAt": "25-09-2026 10:15:00 AM",
  "updatedAt": "25-09-2026 10:15:00 AM",
  "deletedAt": null
}
```

- Decimales como string de dos decimales; fechas `d-m-Y h:i:s A`.
- `deletedAt` con fecha solo en la respuesta del `DELETE`.

### Contrato `FinishedProductServiceInterface`

| Método | Devuelve |
|---|---|
| `getFinishedProducts(array $filters)` | `Collection\|LengthAwarePaginator` |
| `getFinishedProductById(int $id)` | `FinishedProduct` (404 si no existe o borrado) |
| `createFinishedProduct(array $data, User $user)` | `FinishedProduct` |
| `updateFinishedProduct(int $id, array $data)` | `FinishedProduct` |
| `deleteFinishedProduct(int $id)` | `FinishedProduct` (ya borrado) |

---

## Plan de implementación

1. **Migración y modelo.** `php artisan make:model FinishedProduct -mf`: migración `finished_products` según la tabla, modelo con `SoftDeletes`, `#[Fillable]`, `casts()`, `client()` (`withTrashed()`), `registeredBy()`, `normalizeCode()`, `normalizeName()`, y factory. `php artisan migrate` corre limpio.
2. **Contrato, service y provider.** `FinishedProductServiceInterface` con PHPDoc de array shapes; `FinishedProductService` con `#[Override]`, `resolvePerPage()` (`MIN_PER_PAGE = 10`, `MAX_PER_PAGE = 100`), filtros `search`/`clientId`, orden `id ASC`, `ensureCodeIsAvailable()` (`withTrashed()`), `ensureClientIsActive()` (400 si borrado), `resolveWritableFinishedProduct()` (404 inexistente / 400 borrado). `FinishedProductProvider` registrado en `bootstrap/providers.php`.
3. **FormRequests.** `StoreFinishedProductRequest` y `UpdateFinishedProductRequest` con las reglas de la tabla, `messages()` en español y normalización de `code` y `name` en `prepareForValidation()` (solo si vienen).
4. **Resource.** `FinishedProductResource` con las 11 claves, cargando `client` y `registeredBy` (eager loading en el service, sin N+1 en el listado).
5. **Controller y rutas.** `FinishedProductController` con `try/catch` → `ResponseHandler`, service por parámetro de método, `PaginatedResource` en el listado. `routes/finished-products.php` con prefijo `finished-products`, grupo de lectura `role:` + `UserRole::allExcept(UserRole::Pilot)` y grupo de escritura `role:administrator,export`; incluido desde `routes/api.php`. `php artisan route:list --path=finished-products` muestra cinco rutas.
6. **Tests.** Agente `feature-tests`: `FinishedProductTest` (Feature: roles de lectura/escritura, 403 al `pilot`, validación 422, `code` duplicado 400 incluido contra borrado, cliente borrado 400 en `POST` y `PATCH`, 404/400 de borrados, filtros, paginación, que borrar un cliente con SKUs no se bloquea, N+1) y `FinishedProductServiceTest` (Unit).
7. **OpenAPI.** Agente `endpoint-docs`: atributos en Controller, FormRequests y Resource; `php artisan l5-swagger:generate`.
8. **Documentación.** Sección `FinishedProduct` en `CLAUDE.md` (lista de dominios + subsección en Catálogos nacionales + matriz de roles) y `references/finished-products-api.md`. `vendor/bin/pint --dirty --format agent`.

---

## Criterios de aceptación

- [x] `php artisan migrate` crea `finished_products` con las columnas, el índice único de `code`, el índice de `client_id` y `deleted_at`; `name` no tiene índice único.
- [x] `php artisan route:list --path=finished-products` muestra exactamente cinco rutas.
- [x] `GET /api/finished-products` y `GET /{id}` responden 200 a `administrator`, `manager`, `carrier`, `export`, `user` y `shipment`, y **403** a `pilot`.
- [x] `POST`, `PATCH` y `DELETE` responden 2xx a `administrator` y `export`, y **403** a los otros cinco roles.
- [x] Sin token, las cinco rutas responden 401.
- [x] `POST` válido responde **201** con las 11 claves; `code` `" bro-iqf-10 "` queda `BRO-IQF-10` y `name` `"Brócoli florete"` queda `BRÓCOLI FLORETE`.
- [x] `name` con espacios dobles o en los extremos se guarda sin trim ni colapso (solo en mayúsculas).
- [x] Dos productos con el mismo `name` se crean ambos con 201.
- [x] `code` con un espacio interior responde **422**.
- [x] `code` duplicado responde **400**, también cuando la fila que lo ocupa está borrada.
- [x] `presentation` o `boxesPerPallet` en `0`, negativos o no numéricos responden **422**; `presentation: 10` sale como `"10.00"`.
- [x] Sin `clientId` o con uno inexistente responde **422**; con un cliente borrado responde **400** en `POST` y en `PATCH`.
- [x] `PATCH` con `clientId` de otro cliente activo responde 200 y cambia `clientId`/`clientName`.
- [x] `PATCH` con cuerpo vacío responde 200 sin cambios; `registered_by` no se reescribe.
- [x] `DELETE` responde 200 con `deletedAt` con fecha; después el producto no sale en el listado y `GET /{id}` es **404**.
- [x] `PATCH` o `DELETE` sobre un producto borrado responde **400** «El producto terminado ya fue eliminado»; sobre un id inexistente, **404**.
- [x] `DELETE /api/clients/{id}` de un cliente con productos terminados responde 200 (no se bloquea), y sus productos siguen listándose con su `clientName`.
- [x] `?search=` filtra por `code` y `name`; `?clientId=` filtra por cliente; `?clientId=abc` se ignora.
- [x] Sin `limit` devuelve la colección completa; `?limit=5` pagina de 10; `?limit=500` pagina de 100; `total/currentPage/lastPage` en la raíz del sobre.
- [x] El número de consultas del listado no crece con N (test con `DB::getQueryLog()`).
- [x] `api-docs.json` regenerado incluye los cinco endpoints.
- [x] `php artisan test --compact --filter=FinishedProduct` en verde y `vendor/bin/pint --dirty --format agent` sin cambios pendientes.

---

## Decisiones tomadas y descartadas

- **Sí:** dominio `FinishedProduct` separado de `Product`. Son conceptos distintos (SKU empacado de un cliente vs. mercancía que cotiza flete); acoplarlos arrastraría `freight_rates` a un catálogo que no cotiza.
- **No:** relación con `Product`. Decisión explícita del usuario.
- **Sí:** `SoftDeletes` sin `restore`, como `Client`/`ShippingLine`. Un SKU borrado no vuelve.
- **No:** `status` booleano + `toggle-status`. Descartado a favor de `SoftDeletes`.
- **Sí:** `code` único global que el borrado no libera, duplicado 400 desde el service y sin regla `unique` en el FormRequest (no vería las filas borradas y reventaría con 500 contra el índice). Precedente: `Client`.
- **Sí:** `code` sin espacios (422). Se rechaza, no se arregla, como `Client::normalizeCode()`.
- **No:** unicidad de `name`, ni global ni por cliente. Decisión explícita del usuario.
- **Sí:** `name` solo a MAYÚSCULAS, sin trim ni colapso de espacios. Decisión explícita; rompe con `normalizeName()` del resto de catálogos a propósito.
- **Sí (durante la implementación):** excluir `api/finished-products*` del middleware global `TrimStrings` en `bootstrap/app.php`. Sin eso Laravel recortaba el `name` antes de `prepareForValidation()` y el «sin trim» solo se cumplía llamando al service directo. Un `name` de solo espacios sigue siendo 422.
- **Sí:** `presentation` y `boxes_per_pallet` como `decimal(10,2)`, `min:0.01`. Cero no es una presentación ni una paletización válida; el `max` evita un 500 de Postgres.
- **No:** columna o enum de unidad para `presentation`. La unidad es fija por convención y no se documenta.
- **Sí:** `client_id` obligatorio, editable y exigido **no borrado** (400). Un SKU siempre pertenece a un cliente vigente al escribirlo.
- **No:** guarda en `DELETE /api/clients/{id}`. Con `SoftDeletes` en ambos lados, la FK sigue válida y `client()` con `withTrashed()` mantiene `clientName`.
- **Sí:** lectura para todos los roles salvo `pilot`, incluidos `user` y `shipment`. Primer catálogo que se aparta de `UserRole::allExcept(User, Shipment)`.
- **Sí:** escritura `administrator` y `export`, como `clients`/`shipping-lines`/`locations` en la matriz de roles.
- **No:** `carrier.required`. Catálogo nacional.
- **No:** tool del asistente en esta spec.

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| `name` sin trim: `"BRÓCOLI "` y `"BRÓCOLI"` conviven y el `search` puede no casar exacto | Decisión aceptada; `search` usa `LIKE %term%`, así que los encuentra a ambos. El front puede trimear si lo necesita. |
| `code` borrado queda reservado para siempre: un SKU mal borrado no se puede recrear | Mensaje 400 dice «que puede haber sido eliminado»; corregir es tocar la base, como en `Client`. |
| Borrar un cliente deja SKUs activos colgando de un cliente invisible en la API | Aceptado: `clientName` sigue saliendo; editar esos SKUs sin cambiar a un `clientId` activo no se bloquea, pero sí al intentar mandar el mismo `clientId` borrado (400). |
| Ruta de lectura abierta a `user`/`shipment` diverge del resto de catálogos y los tests de N+1 comparados | Test de roles explícito en `FinishedProductTest` y fila nueva en la matriz de `CLAUDE.md`. |

---

## Lo que **no** está en esta spec

- Relación con `Product` (SPEC 07) o con `freight_rates`.
- Líneas de producto terminado en un viaje.
- Guarda en el borrado de clientes.
- Unidad de medida de `presentation`.
- `restore` o listado de borrados.
- Tool del asistente y exportación a Excel.
- Imagen, pesos, código de barras o precio del SKU.

Cada una de ellas, si llega, va en su propia spec.
