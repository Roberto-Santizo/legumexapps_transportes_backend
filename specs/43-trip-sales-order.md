# SPEC 43 — Orden de venta del viaje

> **Estado:** Aprobado
> **Depende de:** SPEC 24, SPEC 38, SPEC 41
> **Fecha:** 2026-11-17
> **Objetivo:** Guardar en `trips` una `ov` (orden de venta) opcional, de texto y no única, escrita por `POST`/`PATCH /api/trips`, expuesta en `TripResource` y `TripListResource`, buscable con `search` y exportada como segunda columna del Excel de `GET /api/reports/trips`.

Octava spec aditiva sobre `trips`, sin dominio nuevo: una columna, cero clases, dos claves y una columna de Excel. Copia de `reference` de SPEC 41 en todo lo que toca al viaje.

Depende de SPEC 24 por `Trip`, sus FormRequests, sus dos Resources y `Trip::normalizeReference()`. Depende de SPEC 41 porque `ov` reusa su mecanismo `OPTIONAL_REFERENCE_FIELDS` y se coloca después de `reference`. Depende de SPEC 38 porque modifica `TRIP_REPORT_HEADERS` y `tripReportRow()`.

---

## Alcance

**Dentro:**

- Migración aditiva sobre `trips`: columna `ov` `string(255)`, `nullable`, sin default, sin índice y sin backfill, `after('reference')`.
- `Trip`:
  - `ov` entra en `#[Fillable]`, junto a `reference`, y sin cast.
  - El PHPDoc de la clase gana un párrafo: qué es la orden de venta y que `null` significa «sin OV», legítimo en cualquier viaje.
  - El PHPDoc de `normalizeReference()` pasa de cuatro a cinco consumidores.
- `TripFactory` genera `ov` la mitad de las veces y `null` la otra mitad.
- **Normalización**: con `Trip::normalizeReference()` (trim, espacios colapsados, MAYÚSCULAS), en `prepareForValidation()` de los dos FormRequests y otra vez en el service. Un valor vacío o de solo espacios se guarda como **`null`**, también en una llamada directa al service.
- `StoreTripRequest`: `ov` `sometimes|nullable|string|max:255`. El `POST` **sigue en 16 campos obligatorios**, así que no hay cambio incompatible. Mensajes en español.
- `UpdateTripRequest`: `ov` `sometimes|nullable|string|max:255`. Mandar `null` o `""` la borra con 200. Sin la clave, no se toca.
- `TripService`:
  - `ov` entra en `REFERENCE_FIELDS` y en `OPTIONAL_REFERENCE_FIELDS`, en el array de `create()` y en `UPDATABLE_FIELDS`.
  - El `search` de `GET /api/trips` pasa a cinco columnas en el mismo grupo `OR`: `order`, `container`, `booking`, `reference` y **`ov`**.
- `TripResource` gana `ov` (string o `null`) justo después de `reference`: de 49 a **50 claves**, y de 50 a **51** en el detalle `GET /{trip}`. También la ve `shipment`, porque no es dinero.
- `TripListResource` gana `ov` justo después de `reference`: de 22 a **23 claves**, también en `GET /trips/current`.
- **Excel de `GET /api/reports/trips` (SPEC 38)**: columna **`OV`** en la **segunda posición** para todos los roles, entre «Orden» y «Cliente».
  - `PRODUCT_ROLES` pasan de 14 a **15 columnas**, con «Producto»/«Cajas» en las posiciones 4 y 5.
  - `carrier` y `user` pasan de 12 a **13 columnas**.
  - En un viaje con N líneas de producto, la OV se repite en sus N filas, igual que el resto de datos del viaje. `null` sale como celda vacía.
- Las tools `trip` y `trips` del asistente reciben `ov` **de forma pasiva**, porque pintan `TripResource`/`TripListResource`. No se toca `app/Ai/`.
- Anotaciones OpenAPI en los dos FormRequests y los dos Resources; regenerar `storage/api-docs/api-docs.json`.
- Tests:
  - Feature en `TripTest`: validación, normalización, `""` → `null`, borrado por `PATCH`, claves en detalle y listado, `search`.
  - Unit en `TripServiceTest`.
  - `ReportTest` y `ReportServiceTest`: encabezados exactos y posición de `OV` en las dos matrices por rol.
- `CLAUDE.md` (secciones Trips y Reporte) y `references/trips-api.md` actualizados.

**Fuera de alcance (para otras specs):**

- **Asistente**: la columna en `export_trips` (`ReportService::exportTrips()`), cualquier cambio en `app/Ai/` y en el prompt.
- Backfill de `ov` en los viajes existentes.
- Unicidad, ni global ni por cliente.
- Validar el formato de la OV.
- Filtro dedicado `?ov=` y orden por OV.
- `TripInRouteResource`, agregados en `/api/dashboard` y textos de las notificaciones push.
- Que `/assignment`, `/start`, `/finish` o `/cancel` lean o escriban la columna.
- Rutas nuevas.

---

## Modelo de datos

Una columna nueva en una tabla existente. No hay tabla nueva.

### Migración aditiva sobre `trips`

```php
// database/migrations/2026_11_17_xxxxxx_add_ov_to_trips_table.php
Schema::table('trips', function (Blueprint $table) {
    $table->string('ov')->nullable()->after('reference');
});
```

- `string(255)`, el mismo tipo que `order`, `container`, `booking` y `reference`. El `max:255` del FormRequest coincide con el tope, así que un desborde es 422 y no un 500 de Postgres.
- **`nullable` y sin default.** `null` significa «sin OV», legítimo en cualquier viaje, sea anterior o posterior a SPEC 43. Tiene la misma semántica que `reference` y no la de `booking`.
- No lleva índice único porque la OV se repite. Tampoco índice de búsqueda: `LIKE '%…%'` no lo usaría, igual que hoy con las otras cuatro columnas.
- `down()` borra la columna.

### `Trip`

- `ov` entra en `#[Fillable]` justo después de `reference`. Sin cast y sin relación nueva.
- `Trip::normalizeReference()` no cambia de código. Gana un quinto consumidor, y su PHPDoc pasa a nombrar `order`, `container`, `booking`, `reference` y `ov`.

### Cuerpo del `POST` y del `PATCH`

| Campo | `POST` | `PATCH` | Reglas comunes |
|---|---|---|---|
| `ov` | `sometimes`, `nullable` | `sometimes`, `nullable` | `string`, `max:255` |

- Se normaliza en `prepareForValidation()` de los dos FormRequests, dentro del mismo `foreach` que las otras cuatro. Ejemplo: `"  ov-2026   15  "` → `"OV-2026 15"`.
- Si la normalización deja un `""`, se fusiona como `null` antes de validar. En el service, `ov` entra en `OPTIONAL_REFERENCE_FIELDS = ['reference', 'ov']`, así que `normalizeOptionalReference()` convierte ausente, `""` o blanco en `null` también en una llamada directa.
- Mensajes:
  - «La orden de venta debe ser texto»
  - «La orden de venta no puede superar los 255 caracteres»

### Búsqueda (`GET /api/trips?search=`)

```php
$builder->where('order', 'LIKE', "%{$search}%")
    ->orWhere('container', 'LIKE', "%{$search}%")
    ->orWhere('booking', 'LIKE', "%{$search}%")
    ->orWhere('reference', 'LIKE', "%{$search}%")
    ->orWhere('ov', 'LIKE', "%{$search}%");
```

El término se sigue normalizando con `Trip::normalizeReference()`, y el grupo sigue entre paréntesis después del ámbito por rol.

### `TripResource` (50 claves; 51 en `GET /{trip}`)

| Clave | Tipo | Valor |
|---|---|---|
| `ov` | `string \| null` | `$this->ov` |

Orden: `…, container, booking, reference, ov, transport, …`. No cambia por rol: `shipment` la ve.

### `TripListResource` (23 claves)

Orden: `…, container, booking, reference, ov, recolectionDate, …`. También aplica a `GET /trips/current`.

### Excel de `GET /api/reports/trips`

`TRIP_REPORT_HEADERS` gana `'OV'` en la segunda posición:

```php
private const array TRIP_REPORT_HEADERS = [
    'Orden', 'OV', 'Cliente', 'Producto', 'Cajas', 'Naviera', 'Destino', 'Contenedor',
    'Punto de Partida', 'Fecha de recolección', 'Fecha de embarque', 'Booking',
    'Punto de llegada', 'Transporte', 'Status',
];
```

- `tripReportRow()` pone `$trip->ov` justo después de `$trip->order`. `null` sale como celda vacía, como ya ocurre con el resto.
- `TRIP_PRODUCT_HEADERS` y el `array_diff` no cambian. `PRODUCT_ROLES` reciben 15 columnas y `carrier`/`user`, 13. En los dos casos `OV` es la segunda.
- La tool `export_trips` (`TRIP_HEADERS`) no cambia.

### `TripFactory`

`ov` vale `null` la mitad de las veces y la otra mitad un código en mayúsculas (`fake()->bothify('OV-######')`). No hay estados nuevos.

---

## Plan de implementación

Cada paso deja la suite en verde y la API funcionando.

1. **Migración.**
   - `php artisan make:migration add_ov_to_trips_table --table=trips` con la columna del modelo de datos.
   - Migrar en local y en la base de tests.
2. **Modelo y factory.**
   - `ov` al `#[Fillable]` de `Trip`.
   - Párrafo nuevo en el PHPDoc de la clase y PHPDoc de `normalizeReference()` ampliado a cinco consumidores.
   - `ov` en `TripFactory`.
   - Correr `TripTest` y `TripServiceTest`: siguen en verde porque nada lee la columna todavía.
3. **`TripService`: escritura.**
   - `ov` en `REFERENCE_FIELDS`, `OPTIONAL_REFERENCE_FIELDS`, el array de `create()` y `UPDATABLE_FIELDS`.
   - Tests en `tests/Unit/TripServiceTest.php`:
     - `create` persiste `ov` normalizada.
     - `create` sin `ov`, o con `""`, guarda `null`.
     - `update` la reescribe.
     - `update` con `null` la borra.
     - `update` sin la clave no la toca.
4. **`TripService`: búsqueda.**
   - Añadir `ov` al grupo `OR` de `search`.
   - Tests en `TripTest`:
     - `?search=` encuentra un viaje por su OV, en minúsculas.
     - Un viaje con `ov` `null` no rompe la búsqueda.
     - Un `carrier` que busca por la OV de un viaje asignado a otra empresa recibe una lista vacía.
5. **FormRequests.**
   - `StoreTripRequest` y `UpdateTripRequest`: regla `sometimes|nullable|string|max:255`, normalización en `prepareForValidation()` con `""` → `null`, mensajes y propiedad OA.
   - Tests en `TripTest`:
     - `POST` sin `ov` → 201 con `null`.
     - `POST` con `ov` de solo espacios → 201 con `null`.
     - `ov` no texto, o de más de 255 caracteres → 422 con el mensaje en español.
     - Normalización a mayúsculas con espacios colapsados.
     - Dos viajes con la misma `ov` → ambos 201.
     - `PATCH` con `ov: null` o `""` → 200 y la borra.
6. **Resources.**
   - `TripResource` y `TripListResource`: `ov` después de `reference`, con `#[OA\Property]` y el texto del schema actualizado a 50 y 23 claves.
   - Actualizar `tripResourceKeys()`, `tripDetailKeys()` y el helper de claves del listado en `TripTest`.
   - Tests:
     - `GET /{trip}`, `GET /trips` y `GET /trips/current` traen `ov`.
     - Un viaje con `ov` `null` devuelve `null`.
     - `shipment` la ve con su valor.
7. **Excel.**
   - `'OV'` en la segunda posición de `TRIP_REPORT_HEADERS` y `$trip->ov` en la segunda posición de `tripReportRow()`.
   - Actualizar los encabezados esperados en `ReportTest` y `ReportServiceTest`.
   - Tests:
     - Un `PRODUCT_ROLE` recibe 15 columnas con `OV` en la B.
     - `carrier`/`user` reciben 13 columnas con `OV` en la B.
     - Un viaje con N líneas repite la OV en sus N filas.
     - Un viaje sin OV deja la celda vacía.
     - `exportTrips()` (la tool) no cambia de columnas.
8. **Documentación y cierre.**
   - `php artisan l5-swagger:generate`.
   - `vendor/bin/pint --dirty --format agent`.
   - Suite completa con `php artisan test --compact`.
   - Actualizar `CLAUDE.md`: sección Trips (columna, conteo de claves y `search`) y sección del reporte de SPEC 38 (15/13 columnas).
9. **Referencia para el frontend.** Actualizar `references/trips-api.md` con:
   - `ov` opcional en `POST`/`PATCH` y que se borra con `null`.
   - La normalización.
   - La clave nueva en detalle y listado.
   - `search` sobre cinco columnas.
   - La columna `OV` del Excel.

---

## Criterios de aceptación

**Base de datos**

- [ ] `trips` tiene `ov` `varchar(255)` nullable, sin índice único, inmediatamente después de `reference`.
- [ ] Las filas anteriores a la migración quedan con `ov` en `null`.

**`POST /api/trips`**

- [ ] Sin `ov` responde 201 y guarda `null`.
- [ ] Con `ov` `""` o de solo espacios responde 201 y guarda `null`.
- [ ] Con `ov` no texto responde 422 con «La orden de venta debe ser texto».
- [ ] Con `ov` de más de 255 caracteres responde 422 con «La orden de venta no puede superar los 255 caracteres».
- [ ] Con `ov: "  ov-2026   15  "` guarda `"OV-2026 15"`.
- [ ] Dos viajes con la misma `ov` se crean ambos con 201.
- [ ] El `POST` sigue exigiendo los mismos 16 campos obligatorios de SPEC 41.

**`PATCH /api/trips/{trip}`**

- [ ] Con una `ov` nueva responde 200 y la guarda normalizada.
- [ ] Con `ov: null` o `ov: ""` responde 200 y la deja en `null`.
- [ ] Sin la clave `ov` responde 200 y no toca la columna.

**Otras rutas de escritura**

- [ ] `PATCH /{trip}/assignment`, `/start`, `/finish` y `/cancel` no tocan la columna.

**Búsqueda**

- [ ] `GET /api/trips?search=` con parte de la OV, en minúsculas, encuentra el viaje.
- [ ] Un `carrier` que busca por la OV de un viaje asignado a otra empresa recibe una lista vacía.

**Resources**

- [ ] `GET /api/trips/{trip}` devuelve 51 claves (50 para el `pilot`), con `ov` inmediatamente después de `reference`.
- [ ] Los demás endpoints de `TripResource` devuelven 50 claves.
- [ ] `GET /api/trips` y `GET /api/trips/current` devuelven 23 claves por viaje, con `ov` inmediatamente después de `reference`.
- [ ] Un viaje con `ov` `null` devuelve `null` en los dos Resources.
- [ ] El rol `shipment` recibe `ov` con su valor.
- [ ] `TripInRouteResource`, `TripPositionResource` y el payload de `TripPositionUpdated` no cambian.

**Excel de `GET /api/reports/trips`**

- [ ] Para `administrator`, `manager`, `export` y `shipment` hay 15 columnas, con encabezado `OV` en la segunda y «Producto»/«Cajas» en la cuarta y la quinta.
- [ ] Para `carrier` y `user` hay 13 columnas, con encabezado `OV` en la segunda.
- [ ] Un viaje con N líneas de producto repite su OV en las N filas.
- [ ] Un viaje sin OV deja vacía la celda de la segunda columna.
- [ ] El Excel de la tool `export_trips` no cambia de columnas.

**Factory, documentación y cierre**

- [ ] `TripFactory` genera a veces una `ov` y a veces `null`.
- [ ] `storage/api-docs/api-docs.json` documenta `ov` en los dos FormRequests y en los dos Resources.
- [ ] `php artisan test --compact` pasa completo.
- [ ] `vendor/bin/pint --dirty --test` no reporta cambios en los archivos tocados.

---

## Decisiones

- **Sí:** `ov` opcional en el `POST` y borrable en el `PATCH` con `null`/`""`. Es un dato comercial que no todos los viajes tienen al crearse, igual que `reference`.
- **No:** obligatoria por la API como `booking`. El usuario la definió como nullable desde el alta.
- **Sí:** `""` o solo espacios → `null`. Un solo valor para «sin OV», como `reference`.
- **Sí:** no única, ni global ni por cliente. Una orden de venta puede cubrir varios viajes.
- **Sí:** normalizar con `Trip::normalizeReference()` (MAYÚSCULAS y espacios colapsados). Es un código comercial como `order` y `booking`, y así la búsqueda no distingue mayúsculas sin `ILIKE`.
- **No:** solo `trim`, como `destination`. Dejaría OV que solo difieren en mayúsculas y una búsqueda que no las encuentra.
- **No:** columna numérica. Un código comercial puede llevar prefijo o guiones.
- **Sí:** reusar `REFERENCE_FIELDS` y `OPTIONAL_REFERENCE_FIELDS`. Es un campo más del mismo modelo con la misma regla que `reference`, y no amerita un `normalizeOv()` propio.
- **Sí:** nombre de columna y de clave `ov`, sin traducir a `salesOrder`. Es la sigla que usa negocio y la que pidió el usuario. El PHPDoc y los mensajes la explican como «orden de venta».
- **Sí:** `ov` en `TripResource` **y** `TripListResource`, después de `reference`. Es un escalar barato y en el listado se lee junto a las demás referencias comerciales.
- **Sí:** visible para `shipment`, porque no es dinero.
- **Sí:** ampliar `search` a cinco columnas en el mismo grupo `OR`. Buscar un viaje por su OV es el uso natural, sin parámetros nuevos.
- **No:** filtro dedicado `?ov=`. `search` cubre el caso.
- **Sí:** columna `OV` en el Excel de SPEC 38, en la **segunda posición** y para **todos los roles**. Lo pidió negocio, y la OV no es dinero ni producto, así que no entra en la matriz por rol.
- **Sí:** encabezado `OV`, no «Orden de venta». Lo pidió el usuario.
- **No:** columna en `export_trips` del asistente. El asistente va en otra spec, y alinear sus columnas con las del reporte ya es deuda declarada.
- **Sí:** las tools `trip`/`trips` ven `ov` de forma pasiva, sin tocar `app/Ai/`. Pasó lo mismo con `booking`, y recortarla implicaría tocar el asistente, que queda fuera.
- **No:** backfill. Un viaje viejo sin OV es indistinguible de uno nuevo sin OV, y eso es correcto: en los dos casos no la tiene.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Quien lea el Excel por posición de columna (macros, fórmulas o importaciones en otra herramienta) se rompe, porque `OV` en la B corre todas las columnas siguientes una posición | Cambio pedido por negocio. Se avisa en `references/trips-api.md` y en el PR. El reporte nunca prometió posiciones estables: SPEC 38 ya varía entre 14 y 12 columnas según el rol. |
| Los tests que cuentan claves (`tripResourceKeys()`, `tripDetailKeys()`, listado) y los que fijan los encabezados del Excel se rompen | El plan los actualiza en los pasos 6 y 7, en el mismo commit que el cambio. |
| Un `search` corto coincide con más viajes al mirar cinco columnas | Es el comportamiento esperado de un `LIKE` amplio. El listado pagina y el ámbito por rol se aplica antes. |
| El asistente empieza a mencionar la OV sin que su prompt sepa qué es | Mismo caso que `booking` en SPEC 41. El nombre de la clave y el contexto bastan, y explicarla en el prompt va en la spec del asistente. |

---

## Lo que **no** entra en esta spec

- La columna en `export_trips` del asistente, y cualquier cambio en `app/Ai/` o en su prompt.
- Backfill de `ov` en los viajes existentes.
- Unicidad de `ov`.
- Validar el formato de la OV.
- Filtro dedicado `?ov=` y orden por OV.
- `TripInRouteResource`, agregados en `/api/dashboard` y textos de las notificaciones push.
- Rutas nuevas.

Cada una de ellas, si llega, va en su propia spec.
