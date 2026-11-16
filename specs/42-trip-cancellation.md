# SPEC 42 — Cancelación del viaje

> **Estado:** Aprobado
> **Depende de:** SPEC 24, SPEC 27 (`27-trip-fuels.md`), SPEC 29, SPEC 31, SPEC 35, SPEC 37, SPEC 39
> **Fecha:** 2026-11-16
> **Objetivo:** Añadir el estado `cancelled` a `TripStatus` y una ruta `PATCH /api/trips/{trip}/cancel` con la que `administrator` y `export` cancelan un viaje `pending`, asignado o no, dejándolo como registro de solo lectura.

---

## Por qué existe esta spec

SPEC 24 dejó fuera `cancelled` a propósito. El PHPDoc de `TripStatus` lo dice: un viaje que no se hará se borra, y `SoftDeletes` ya lo cuenta.

Esta spec **revierte esa decisión**, porque borrar mezcla dos cosas distintas:

- **Un viaje creado por error.** Es basura y debe desaparecer. Sigue siendo el `DELETE` (soft delete): 404 al leerlo.
- **Un viaje real que no se hará.** Es historial: tuvo cliente, orden y quizá tripulación, carga de combustible, bonificación y seguro. Es la cancelación nueva: sigue visible en listados, detalle, dashboard y Excel, pero ya no se puede escribir.

Las dos vías conviven. Un viaje cancelado se puede borrar después, para no bloquear la limpieza de errores.

Depende de las demás specs porque cada dominio hijo (cargas, viáticos, gastos emergentes, productos terminados) tiene su propia guarda de estado. Hoy esas guardas solo miran `pending`, `in_route` o `finished`, así que un `cancelled` se colaría por todas. Las push de SPEC 35 ganan además un cuarto tipo, y el dashboard de SPEC 29 una cuarta clave en `byStatus`.

---

## Alcance

**Dentro:**

- **Enum `TripStatus`**:
  - Cuarto caso, `Cancelled = 'cancelled'`, con `label()` «Cancelado».
  - El PHPDoc de la clase deja de decir que no existe a propósito y explica la diferencia con el `DELETE`.
- **Migración aditiva sobre `trips`**: `cancelled_at` (timestamp) y `cancelled_by` (FK a `users`, sin `cascade`). Las dos son nullable, sin default y sin backfill.
- **`Trip`**:
  - Las dos columnas entran en `#[Fillable]`.
  - Cast `datetime` para `cancelled_at`.
  - Relación `cancelledBy()`.
  - Un párrafo en el PHPDoc de la clase.
- **Ruta `PATCH /api/trips/{trip}/cancel`**:
  - Middleware `role:administrator,export`, sin `carrier.required`.
  - Sin cuerpo ni FormRequest, como `/start`.
  - Se declara antes del `apiResource`. `routes/trips.php` pasa de diecinueve a **veinte** rutas.
- **`TripService::cancel(User, int)`** en el contrato y en el service.
  - Guardas, en orden:
    1. 404 «El viaje no existe».
    2. 400 «El viaje ya fue eliminado».
    3. 400 «El viaje ya fue cancelado».
    4. 400 «Solo se puede cancelar un viaje pendiente».
  - Escribe `status = cancelled`, `cancelled_at = now()` y `cancelled_by` del token, todo en un solo `update()` y con `lockForUpdate`, como `assign()`.
  - No toca la tripulación, la bonificación, el seguro, las cargas, los viáticos ni las líneas de producto.
  - Responde **200** «Viaje cancelado correctamente» con `TripResource`.
- **Push `trip.cancelled`**:
  - Nuevo caso en `TripNotificationType`.
  - Destinatario: los tokens del `pilot_id`, solo si el viaje tenía piloto.
  - Texto: «Viaje cancelado» / «El viaje con orden {order} fue cancelado».
  - Se despacha con `SendTripNotification::dispatchAfterResponse()` tras el `update()`.
- **El viaje cancelado es de solo lectura.** Toda escritura posterior responde **400 «El viaje está cancelado»**, mediante un helper nuevo `ensureTripIsNotCancelled()` que **no** vive en `resolveWritableTrip()`. Afecta a:
  - `PATCH /api/trips/{trip}` (el general).
  - `/assignment`, `/start` y `/finish`.
  - `POST /{trip}/fuels` y `PATCH /trip-fuels/{id}/confirm`.
  - `POST /{trip}/expenses` y `PATCH /trip-expenses/{id}/confirm`.
  - `POST /{trip}/emergency-expenses` y `PATCH`/`DELETE /trip-emergency-expenses/{id}`.
  - `POST`, `PATCH` y `DELETE /trip-finished-products`.
- **El `DELETE /api/trips/{trip}` sigue permitido** sobre un viaje cancelado.
- **`UpdateTripRequest`**: `status` acepta solo `pending`, `in_route` y `finished`. Mandar `cancelled` es **422**: solo se cancela por la ruta.
- **Resources**:
  - `TripResource` gana `cancelledAt` (`d-m-Y h:i:s A` o `null`) y `cancelledByName` (string o `null`). Pasa de 47 a **49** claves; el detalle `GET /{trip}`, de 48 a **50**.
  - `TripListResource` no gana claves: solo cambia `statusLabel`, que sale «Cancelado».
- **Listados**:
  - Los cancelados salen por defecto en `GET /api/trips` y se filtran con `?status=cancelled`. El filtro ya usa `TripStatus::tryFrom()`, así que funciona sin tocarlo.
  - Un `carrier` sigue viendo los que asignó su empresa.
  - Los cancelados sin asignar salen de su vista, porque la bolsa solo muestra `pending`.
- **Dashboard**: `GET /api/dashboard/trips` gana `byStatus.cancelled`, la cuarta clave fija, con `0` si no hay ninguno.
- Anotaciones OpenAPI:
  - Controller: la acción `cancel`.
  - Schemas de `TripResource`, `TripsSummaryResource` y del enum de `status` en los Resources y en `UpdateTripRequest`.
  - Regenerar `storage/api-docs/api-docs.json`.
- **Tests**:
  - Feature: `TripTest` (ruta, roles, guardas y solo lectura del viaje) y uno por dominio hijo con su 400.
  - Feature: `TripNotificationTest` (la push) y `DashboardTest` (la clave nueva).
  - Unit: `TripServiceTest` (`cancel()`).
- Actualizar `CLAUDE.md` (Trips, matriz de roles, conteos de claves y rutas) y `references/trips-api.md`.

**Fuera de alcance (para otras specs):**

- Descancelar o reabrir un viaje cancelado.
- Motivo de la cancelación (`cancellation_reason`).
- Cancelar un viaje `in_route` o `finished`.
- Que cancele el `carrier` que tomó el viaje, o el piloto.
- Liberar la tripulación al cancelar (`pilot_id`/`vehicle_id` a `null`) o devolver el viaje a la bolsa.
- Push a quien no sea el piloto asignado (dueño de la empresa, administradores).
- Columnas `cancelledAt`/`cancelledByName` en `TripListResource` o en los Excel.
- Ocultar los cancelados por defecto en `GET /api/trips`.
- Excluir los cancelados del cálculo de algún agregado del dashboard distinto de `byStatus`.
- Máquina de estados completa para el `PATCH` general: el hueco de SPEC 24 entre `pending`, `in_route` y `finished` sigue igual.
- Bitácora de cambios de estado.

---

## Modelo de datos

Dos columnas nuevas en `trips`, un caso nuevo en dos enums y ninguna tabla nueva.

### Migración aditiva sobre `trips`

```php
// database/migrations/2026_11_16_xxxxxx_add_cancellation_to_trips_table.php
Schema::table('trips', function (Blueprint $table) {
    $table->timestamp('cancelled_at')->nullable()->after('end_date');
    $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users');
});
```

- Las dos son **nullable y sin default**, y **se escriben juntas o no se escriben**. `null` significa «no cancelado», y no hay otra lectura posible.
- La FK no lleva `cascade`, como el resto de `trips`.
- `trips.status` sigue siendo un `string` con default `pending` y sin `CHECK`, así que el valor `cancelled` no necesita tocar la columna.
- `down()` borra la FK y las dos columnas.

### `TripStatus`

```php
case Pending = 'pending';
case InRoute = 'in_route';
case Finished = 'finished';
case Cancelled = 'cancelled';

// label(): self::Cancelled => 'Cancelado'
```

Todos los sitios que leen `TripStatus::cases()` heredan el caso nuevo sin tocarlos:

- El filtro `status` de `GET /api/trips`.
- Los `enum` de las tools `trips` y `export_trips` del asistente.
- `statusLabel` de `TripListResource`.
- La columna «Estado» de los dos Excel.

### `TripNotificationType`

```php
case Cancelled = 'trip.cancelled';
```

| Tipo | Destinatarios | Título | Cuerpo |
|---|---|---|---|
| `trip.cancelled` | tokens del `pilot_id` (ninguno si es `null`) | «Viaje cancelado» | «El viaje con orden {order} fue cancelado» |

- `data` = `{ type: "trip.cancelled", tripId: "<id>" }`, los dos como string, igual que los otros tres tipos.
- Si el viaje no tenía piloto, `notify()` no encuentra destinatarios y no llama a FCM.

### `Trip`

- `cancelled_at` y `cancelled_by` entran en `#[Fillable]`.
- `casts()`: `'cancelled_at' => 'datetime'`.
- Relación `cancelledBy(): BelongsTo<User>` sobre `cancelled_by`.
- `TripService::RELATIONS`, que carga el detalle, gana `cancelledBy`. `LIST_RELATIONS` no cambia.

### Respuesta de `PATCH /api/trips/{trip}/cancel`

```json
{
  "statusCode": 200,
  "message": "Viaje cancelado correctamente",
  "data": { "...": "TripResource, 49 claves", "status": "cancelled", "cancelledAt": "16-11-2026 03:12:40 PM", "cancelledByName": "Ana López" }
}
```

### `TripResource` (49 claves; 50 en `GET /{trip}`)

Dos claves nuevas justo después de `endDate`:

| Clave | Tipo | Valor |
|---|---|---|
| `cancelledAt` | `string \| null` | `cancelled_at` en `d-m-Y h:i:s A` |
| `cancelledByName` | `string \| null` | `cancelledBy?->name` |

- Orden: `…, startDate, endDate, cancelledAt, cancelledByName, polyline, …`.
- Sin cambio por rol: `shipment` y `pilot` las ven.
- `TripListResource` sigue en 22 claves.

### `GET /api/dashboard/trips`

`byStatus` pasa de tres a **cuatro claves fijas**: `pending`, `inRoute`, `finished` y `cancelled`. Su suma sigue siendo igual a `total`.

### Guarda de solo lectura

Helper privado `ensureTripIsNotCancelled(Trip $trip): void` → `BadRequestError('El viaje está cancelado')`.

- **No vive en `resolveWritableTrip()`**, que también usa `destroy()`.
- Cada dominio hijo repite la comprobación en su propio service, igual que hoy repiten la de `finished`.
- **Regla de colocación**: va **después** de la guarda de ámbito o de propiedad (el 403), y **justo antes** de la guarda de estado que la acción ya tenía. Así un tercero sigue recibiendo 403 y nunca se entera de que el viaje está cancelado.

| Acción | Va antes de |
|---|---|
| `PATCH /{trip}` (general) | la revalidación de catálogos (no tiene 403) |
| `/assignment` | «Solo se puede asignar un viaje pendiente» |
| `/start` | «El viaje ya fue iniciado» |
| `/finish` | «El viaje ya fue finalizado» |
| `POST /{trip}/fuels` · `POST /{trip}/expenses` | la guarda de `finished` |
| `/trip-fuels/{id}/confirm` · `/trip-expenses/{id}/confirm` | la reconfirmación idempotente (200 sin escribir) |
| `POST /{trip}/emergency-expenses` | «Solo se pueden registrar gastos emergentes en un viaje en ruta» |
| `PATCH`/`DELETE /trip-emergency-expenses/{id}` | «No se pueden modificar los gastos emergentes de un viaje pendiente» |
| `POST`/`PATCH`/`DELETE /trip-finished-products` | «Solo se pueden modificar los productos de un viaje pendiente» |

Los mensajes de las guardas existentes de esta tabla se toman de CLAUDE.md. En la implementación se usan los literales de cada service, y la regla de colocación es la que manda.

### `UpdateTripRequest`

```php
'status' => ['sometimes', 'required', Rule::enum(TripStatus::class)->except([TripStatus::Cancelled])],
```

- Mensaje: «El estado no es válido; para cancelar un viaje usa la acción de cancelar».

---

## Plan de implementación

Cada paso deja la suite verde y la API funcionando.

1. **Migración y modelo.**
   - `php artisan make:migration add_cancellation_to_trips_table --table=trips` con las dos columnas.
   - Migrar en local y en la base de tests.
   - `Trip`: `#[Fillable]`, cast de `cancelled_at`, relación `cancelledBy()` y párrafo en el PHPDoc.
   - Correr `TripTest`: sigue verde, porque nada lee las columnas.
2. **Enum `TripStatus`.**
   - Caso `Cancelled`, `label()` «Cancelado» y PHPDoc de la clase reescrito: ya no dice que no existe a propósito y explica la diferencia con el `DELETE`.
   - `UpdateTripRequest`: `Rule::enum(...)->except([TripStatus::Cancelled])` con su mensaje.
   - `DashboardService` y `TripsSummaryResource`: cuarta clave `byStatus.cancelled`, con su schema OA.
   - Tests:
     - `PATCH` con `status: cancelled` → 422.
     - `GET /api/trips?status=cancelled` filtra.
     - `statusLabel` «Cancelado».
     - `byStatus` con sus cuatro claves y suma igual a `total`.
3. **`TripService::cancel()` y la ruta.**
   - Método en `TripServiceInterface` (PHPDoc + `@throws`) y en `TripService` con `#[Override]`: `DB::transaction` + `resolveWritableTrip($id, lock: true)`, las cuatro guardas en orden y un solo `update()`.
   - `RELATIONS` gana `cancelledBy`.
   - `TripController::cancel()` con su atributo OA.
   - Ruta `PATCH /{trip}/cancel` con `role:administrator,export`, declarada antes del `apiResource`.
   - Tests en `TripServiceTest`:
     - Cancela un viaje libre y uno asignado.
     - Escribe las tres columnas.
     - No toca la tripulación, la bonificación, el seguro ni las cargas.
     - Las cuatro guardas, en orden.
   - Tests en `TripTest`:
     - Matriz de roles: 200 para `administrator`/`export`, 403 para los otros cinco y 401 sin token.
     - 404, borrado, ya cancelado, `in_route` y `finished`.
4. **Resources.**
   - `TripResource`: `cancelledAt` y `cancelledByName` tras `endDate`, con `#[OA\Property]` y el texto del schema a 49 claves.
   - Actualizar `tripResourceKeys()` y `tripDetailKeys()` en `TripTest`.
   - Tests:
     - La respuesta de `/cancel` trae las dos claves con valor.
     - Un viaje no cancelado las trae en `null`.
     - `TripListResource` sigue en 22 claves.
5. **Solo lectura en `TripService`.**
   - Helper `ensureTripIsNotCancelled()`, llamado desde `update()`, `assign()`, `start()` y `finish()` en la posición de la tabla. `destroy()` no lo llama.
   - Tests:
     - Las cuatro acciones sobre un cancelado → 400 «El viaje está cancelado».
     - Un `carrier` ajeno sigue recibiendo 403 en `/assignment`.
     - `DELETE` de un cancelado → 200.
6. **Solo lectura en los dominios hijos.** Un paso por service, cada uno con su test Feature del 400 y uno del 403 previo:
   - `TripFuelService`: `store` y `confirm`.
   - `TripExpenseService`: `store` y `confirm`.
   - `TripEmergencyExpenseService`: `store`, `update` y `destroy`.
   - `TripFinishedProductService`: `store`, `update` y `destroy`.
7. **Push `trip.cancelled`.**
   - Caso en `TripNotificationType`, más destinatarios y texto en `TripNotificationService`.
   - `SendTripNotification::dispatchAfterResponse()` al final de `cancel()`.
   - Tests en `TripNotificationTest`:
     - Viaje asignado → una entrega por token del piloto, con su título, cuerpo y `data`.
     - Viaje libre → ningún envío.
     - Con `Bus::fake()`, `/cancel` despacha el job.
     - Una guarda que falla no despacha nada.
8. **Documentación y cierre.**
   - `php artisan l5-swagger:generate`.
   - `vendor/bin/pint --dirty --format agent`.
   - Suite completa `php artisan test --compact`.
   - Actualizar `CLAUDE.md`:
     - Sección Trips: enum, tabla de rutas fijas, veinte rutas, 49/50 claves y solo lectura.
     - Matriz de roles: `export` cancela.
     - SPEC 35: cuarto tipo de push.
     - SPEC 29: `byStatus`.
     - Lista de dominios y specs.
9. **Referencia para el frontend.** Actualizar `references/trips-api.md` con:
   - La ruta nueva, sus roles y sus guardas.
   - Las dos claves nuevas del `TripResource`.
   - El 422 del `PATCH` con `status: cancelled`.
   - El 400 «El viaje está cancelado» en cada escritura.
   - La push `trip.cancelled`.
   - `byStatus.cancelled`.

---

## Criterios de aceptación

**Base de datos y enum**

- [ ] `trips` tiene `cancelled_at` (timestamp) y `cancelled_by` (FK a `users`, sin `cascade`), ambas nullable.
- [ ] Las filas anteriores a la migración quedan en `null` en las dos columnas.
- [ ] `TripStatus::Cancelled->value === 'cancelled'` y `TripStatus::Cancelled->label() === 'Cancelado'`.

**`PATCH /api/trips/{trip}/cancel`**

- [ ] `administrator` y `export` cancelan un viaje `pending` libre y responden 200 «Viaje cancelado correctamente».
- [ ] `administrator` y `export` cancelan un viaje `pending` asignado y responden 200.
- [ ] Tras cancelar, `status` es `cancelled`, `cancelled_at` tiene la hora del servidor y `cancelled_by` es el usuario del token.
- [ ] Cancelar no cambia `pilot_id`, `vehicle_id`, `assigned_by`, `bonus`, `cargo_insurance` ni las filas de `trip_fuels`, `trip_expenses` y `trip_finished_products`.
- [ ] `carrier`, `pilot`, `manager`, `user` y `shipment` reciben 403; sin token, 401.
- [ ] Un viaje inexistente responde 404 «El viaje no existe».
- [ ] Un viaje borrado responde 400 «El viaje ya fue eliminado».
- [ ] Un viaje ya cancelado responde 400 «El viaje ya fue cancelado».
- [ ] Un viaje `in_route` o `finished` responde 400 «Solo se puede cancelar un viaje pendiente».
- [ ] Un cuerpo enviado se ignora: la respuesta no cambia y `cancelled_by` sigue siendo el usuario del token.

**Solo lectura**

- [ ] Sobre un viaje cancelado responden 400 «El viaje está cancelado»:
  - [ ] El `PATCH` general.
  - [ ] `/assignment`, `/start` y `/finish`.
  - [ ] `POST /fuels` y la confirmación de carga.
  - [ ] `POST /expenses` y la confirmación de viático.
  - [ ] `POST`, `PATCH` y `DELETE` de gastos emergentes.
  - [ ] `POST`, `PATCH` y `DELETE` de líneas de producto terminado.
- [ ] Un `carrier` de otra empresa sigue recibiendo 403 (no 400) en `/assignment`, `POST /fuels` y `POST /expenses` de un viaje cancelado.
- [ ] Un piloto no asignado sigue recibiendo 403 (no 400) en `/start`, `/finish` y las dos confirmaciones.
- [ ] `DELETE /api/trips/{trip}` sobre un viaje cancelado responde 200 y lo borra.
- [ ] Todas las lecturas de un viaje cancelado (`GET /{trip}`, `/fuels`, `/expenses`, `/emergency-expenses`, `/positions`, `/timeouts`, `trip-finished-products`) siguen respondiendo como antes.
- [ ] `GET /{trip}/cost` de un viaje cancelado responde 400 «El costo solo está disponible para viajes finalizados».
- [ ] `PATCH /api/trips/{trip}` con `status: cancelled` responde 422.

**Resources y listados**

- [ ] La respuesta de `/cancel` y los demás endpoints del `TripResource` devuelven 49 claves, con `cancelledAt` y `cancelledByName` inmediatamente después de `endDate`.
- [ ] `GET /api/trips/{trip}` devuelve 50 claves (49 para el `pilot`).
- [ ] Un viaje no cancelado devuelve `null` en `cancelledAt` y `cancelledByName`.
- [ ] `GET /api/trips` sigue devolviendo 22 claves por viaje e incluye los cancelados sin filtro.
- [ ] `GET /api/trips` devuelve `statusLabel: "Cancelado"` para los cancelados.
- [ ] `GET /api/trips?status=cancelled` devuelve solo los cancelados.
- [ ] Un `carrier` ve en el listado el viaje cancelado que asignó su empresa.
- [ ] Un `carrier` no ve un viaje cancelado que nunca se asignó.
- [ ] La columna «Estado» de `GET /api/reports/trips` muestra «Cancelado», sin columnas nuevas.

**Dashboard**

- [ ] `GET /api/dashboard/trips` devuelve `byStatus` con cuatro claves (`pending`, `inRoute`, `finished`, `cancelled`), con `0` si no hay filas.
- [ ] La suma de las cuatro claves de `byStatus` es igual a `total`.

**Push**

- [ ] Cancelar un viaje asignado envía una entrega por cada token del piloto, con título «Viaje cancelado», cuerpo «El viaje con orden {order} fue cancelado» y `data` `{ type: "trip.cancelled", tripId }`, ambos string.
- [ ] Cancelar un viaje sin piloto no llama a `PushNotificationServiceInterface::send()`.
- [ ] Un `/cancel` que falla con 4xx no despacha `SendTripNotification`.

**Documentación y cierre**

- [ ] `storage/api-docs/api-docs.json` documenta la ruta `/cancel`, el valor `cancelled` del enum, las dos claves de `TripResource` y `byStatus.cancelled`.
- [ ] `php artisan test --compact` pasa completo.
- [ ] `vendor/bin/pint --dirty --test` no reporta cambios en los archivos tocados.

---

## Decisiones

- **Sí:** un estado `cancelled` en vez de seguir usando el `DELETE`.
  - Borrar hace desaparecer el viaje (404), y un viaje real que no se hará es historial.
  - Revierte la decisión de SPEC 24, que el PHPDoc de `TripStatus` dejaba escrita.
- **Sí:** `DELETE` y cancelar conviven, y un cancelado se puede borrar. Borrar sigue siendo la vía para un viaje creado por error, y prohibirlo sobre un cancelado bloquearía la limpieza sin ganar nada.
- **Sí:** una ruta propia `PATCH /{trip}/cancel`, sin cuerpo. Es el mismo patrón que `/start` y `/finish`: quién puede hacerlo se lee en el archivo de rutas y la fecha la pone el servidor.
- **No:** cancelar desde el `PATCH` general con `status: cancelled`.
  - No escribiría `cancelled_at` ni `cancelled_by`.
  - Heredaría el hueco de SPEC 24: el `PATCH` no comprueba el estado de origen.
  - Por eso ahí es 422.
- **Sí:** solo `administrator` y `export`, los mismos que crean y editan viajes.
- **No:** el `carrier` que tomó el viaje. Soltar un viaje no es cancelarlo, y esa capacidad va en otra spec si llega.
- **Sí:** solo desde `pending`. Un `in_route` ya tiene rastro, paradas y quizá gastos emergentes, y un `finished` tiene costo. Cancelarlos abriría preguntas que nadie hizo.
- **Sí:** se cancela también un `pending` asignado, y la tripulación, la bonificación, el seguro, las cargas, los viáticos y las líneas quedan intactos como registro.
- **No:** liberar `pilot_id`/`vehicle_id` ni devolver el viaje a la bolsa. Un cancelado no vuelve a ofrecerse, y vaciar la tripulación borraría el registro de quién lo tenía.
- **Sí:** `cancelled_at` y `cancelled_by`, escritos juntos por el servidor. Sin ellos no se sabe cuándo ni quién canceló, y `updated_at` lo pisaría cualquier edición posterior.
- **No:** `cancellation_reason`. El usuario no lo pidió. Si llega, es una columna aditiva más.
- **Sí:** irreversible y de solo lectura: toda escritura posterior es 400 «El viaje está cancelado». El viaje queda como registro, y un cancelado que acepta cargas o viáticos dejaría dinero colgando de un viaje que no existió.
- **No:** un endpoint para descancelar. Si un viaje vuelve a hacerse, se crea uno nuevo.
- **Sí:** la guarda en un helper aparte, no en `resolveWritableTrip()`, porque `destroy()` también la usa y debe seguir funcionando.
- **Sí:** la guarda de cancelado va después del 403 de ámbito o propiedad. Un tercero no debe enterarse del estado de un viaje que no es suyo.
- **Sí:** «El viaje ya fue cancelado» separado de «Solo se puede cancelar un viaje pendiente». Así un reintento del front se explica solo.
- **Sí:** push `trip.cancelled` solo al piloto asignado. Él tenía el viaje en su teléfono. El administrador o `export` que cancela ya lo sabe.
- **No:** push al dueño de la empresa. No se pidió y es una decisión aparte de destinatarios.
- **Sí:** `cancelledAt` y `cancelledByName` en `TripResource`, tras `endDate`, junto a las otras marcas de tiempo del ciclo de vida.
- **No:** las dos claves en `TripListResource`. `statusLabel` ya dice «Cancelado», y el listado no necesita quién canceló.
- **Sí:** los cancelados salen por defecto en `GET /api/trips`. Son historial, y `?status=cancelled` los separa sin parámetros nuevos.
- **Sí:** `byStatus.cancelled` como cuarta clave fija, con `0`. Mantiene la regla de SPEC 29 de que las claves de `byStatus` salen siempre y suman `total`.
- **No:** excluir los cancelados de `total` ni de los desgloses del dashboard. Cambiaría el significado de todos los bloques. Solo `byStatus` los separa.
- **No:** columnas nuevas en los Excel. «Estado» ya sale de `label()`.
- **No:** una máquina de estados completa para el `PATCH` general. Sigue siendo el hueco declarado de SPEC 24, y esta spec solo cierra la entrada y la salida de `cancelled`.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El `PATCH` del administrador puede llevar un viaje a `in_route` o `finished` sin pasar por `/start`. Ese viaje ya no se puede cancelar. | Comportamiento esperado: solo se cancela un `pending`. El hueco de SPEC 24 sigue declarado, y el administrador puede devolver el viaje a `pending` y cancelarlo después. |
| El frontend no conoce `cancelled` y un `switch` sobre `status` cae en su rama por defecto | `references/trips-api.md` lo anuncia. `statusLabel` ya trae «Cancelado» en el listado, y el front despliega junto al back. |
| `byStatus` pasa de tres a cuatro claves y un front que valida la forma exacta lo rechaza | Cambio aditivo documentado en el Swagger y en la referencia. Es el mismo tipo de cambio que han hecho los Resources en cada spec aditiva. |
| Algún dominio hijo se queda sin la guarda y un cancelado acepta una escritura | La guarda se repite en cada service, en el paso 6 del plan, y cada escritura tiene su test del 400. Hay una ruta por fila en los criterios de aceptación. |
| Los tests que cuentan claves (`tripResourceKeys()`, `tripDetailKeys()`) o que fijan las tres claves de `byStatus` se rompen | El plan los actualiza en los pasos 2 y 4, en el mismo commit que el cambio que los rompe. |
| La push llega a un teléfono cuyo token ya murió | `TripNotificationService` borra los tokens rechazados y no lanza nunca, igual que con los otros tres tipos (SPEC 35). |

---

## Lo que **no** entra en esta spec

- Descancelar o reabrir un viaje.
- Motivo de la cancelación.
- Cancelar un viaje `in_route` o `finished`.
- Cancelación por el `carrier` o el piloto.
- Liberar la tripulación o devolver el viaje a la bolsa.
- Push a quien no sea el piloto asignado.
- `cancelledAt`/`cancelledByName` en `TripListResource` o en los Excel.
- Ocultar los cancelados por defecto en el listado o excluirlos de los agregados del dashboard.
- Máquina de estados para el `PATCH` general.
- Bitácora de cambios de estado.

Cada una de ellas, si llega, va en su propia spec.
