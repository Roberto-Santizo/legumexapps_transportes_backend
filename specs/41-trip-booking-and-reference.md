# SPEC 41 — Referencia y booking del viaje

> **Estado:** Aprobado
> **Depende de:** SPEC 24, SPEC 37
> **Fecha:** 2026-11-16
> **Objetivo:** Guardar en `trips` el `booking` de la naviera (obligatorio por la API) y una `reference` comercial opcional, ambos texto y no únicos, escritos por `POST`/`PATCH /api/trips`, expuestos en `TripResource` y `TripListResource` y buscables con `search`.

Sexta spec aditiva sobre `trips`, sin dominio nuevo: dos columnas, cero clases y cuatro claves. Sigue el precedente de SPEC 30: columnas nullable en la base de datos y `booking` exigido por la API en el alta, así que `null` en `booking` significa exactamente «viaje anterior a SPEC 41».

Depende de SPEC 24 por `Trip`, sus FormRequests, sus dos Resources y `Trip::normalizeReference()`, y de SPEC 37 porque el `POST` vigente tiene 15 campos obligatorios (con `products`) y esta spec lo lleva a 16.

---

## Alcance

**Dentro:**

- Migración aditiva sobre `trips`: `booking` y `reference`, ambas `string(255)`, `nullable`, sin default, sin índice y sin backfill, después de `container`.
- `Trip` gana las dos columnas en `#[Fillable]`, sin cast, y un párrafo en el PHPDoc de la clase (qué es cada una y qué significa `null`).
- `TripFactory` genera `booking` siempre y `reference` a veces (`null` o valor).
- **Normalización**: las dos pasan por `Trip::normalizeReference()` —trim, espacios colapsados y MAYÚSCULAS—, igual que `order` y `container`, en `prepareForValidation()`.
- `StoreTripRequest`:
  - `booking` `required|string|max:255`. El `POST` pasa de 15 a **16 campos obligatorios**: cambio incompatible sin periodo de gracia, como SPEC 13/21/25/30/37.
  - `reference` `sometimes|nullable|string|max:255`.
  - Un `booking` de solo espacios es 422 (`booking.required`, porque la normalización lo deja vacío).
  - Una `reference` vacía o de solo espacios se guarda como **`null`**.
  - Mensajes en español.
- `UpdateTripRequest`:
  - `booking` `sometimes|required|string|max:255`: no se puede vaciar.
  - `reference` `sometimes|nullable|string|max:255`: mandar `null` o `""` la borra (`array_key_exists`, como `description` en `Zone`).
- `TripService`:
  - Las dos columnas entran en el array de `create()` y en `UPDATABLE_FIELDS`.
  - El filtro `search` de `GET /api/trips` pasa a buscar con `LIKE` sobre `order`, `container`, **`booking`** y **`reference`**, en el mismo grupo `OR`.
- `TripResource` gana `booking` y `reference` (string o `null`) justo después de `container`: de 45 a **47 claves** (el detalle `GET /{trip}` de 46 a **48**). Visibles también para `shipment` —no son dinero—.
- `TripListResource` gana las mismas dos justo después de `container`: de 20 a **22 claves** (también en `GET /trips/current`).
- Las tools `trip` y `trips` del asistente las heredan de los Resources sin tocar `app/Ai/`.
- Anotaciones OpenAPI en los dos FormRequests y los dos Resources; regenerar `storage/api-docs/api-docs.json`.
- Tests:
  - Feature en `TripTest`: validación del `POST`/`PATCH`, normalización, `reference` → `null`, claves en detalle y listado, `search`.
  - Unit en `TripServiceTest`: persistencia en `create`/`update`.
- `CLAUDE.md` (sección Trips) y `references/trips-api.md` actualizados.

**Fuera de alcance (para otras specs):**

- Backfill de `booking` en los viajes existentes: quedan en `null`.
- Unicidad de cualquiera de las dos (global, por cliente o por naviera).
- Validar el formato del booking contra la naviera (prefijos, longitud fija, dígito verificador).
- Filtros dedicados `?booking=`/`?reference=` y orden por ellas.
- Columnas en el Excel de `GET /api/reports/trips` (SPEC 38) y en `export_trips` del asistente.
- `TripInRouteResource` del dashboard y cualquier agregado en `/api/dashboard`.
- Textos de las notificaciones push (SPEC 35).
- Que `/assignment`, `/start` o `/finish` lean o escriban las columnas.
- Rutas nuevas.

---

## Modelo de datos

Dos columnas nuevas en una tabla existente y ninguna tabla nueva.

### Migración aditiva sobre `trips`

```php
// database/migrations/2026_11_16_xxxxxx_add_booking_and_reference_to_trips_table.php
Schema::table('trips', function (Blueprint $table) {
    $table->string('booking')->nullable()->after('container');
    $table->string('reference')->nullable()->after('booking');
});
```

- `string(255)`, mismo tipo que `order` y `container`. El `max:255` del FormRequest coincide con el tope, así que desbordar es 422 y no 500 de Postgres.
- **`nullable` y sin default** en las dos:
  - En `booking`, `null` significa exactamente «viaje anterior a SPEC 41»: por la API no se puede crear un viaje sin booking ni vaciarlo.
  - En `reference`, `null` significa «sin referencia», legítimo en cualquier viaje.
- Sin índice único (se repiten) y sin índice de búsqueda: el `LIKE '%…%'` no lo usaría, igual que hoy con `order` y `container`.
- `down()` borra las dos columnas.

### `Trip`

- `booking` y `reference` entran en `#[Fillable]`, junto a `container`.
- Sin cast. Ninguna relación nueva.
- `Trip::normalizeReference()` no cambia: gana dos consumidores, y su PHPDoc pasa de «`order` y `container`» a los cuatro campos.

### Cuerpo del `POST` y del `PATCH`

| Campo | `POST` | `PATCH` | Reglas comunes |
|---|---|---|---|
| `booking` | `required` | `sometimes`, `required` | `string`, `max:255` |
| `reference` | `sometimes`, `nullable` | `sometimes`, `nullable` | `string`, `max:255` |

- Normalización en `prepareForValidation()` de ambos FormRequests: `booking` y `reference` entran en el mismo `foreach` que `order` y `container`. Ejemplo: `"  bk-2026   001  "` → `"BK-2026 001"`.
- `reference` que la normalización deja en `""` se fusiona como `null` antes de validar. El service también convierte `""` a `null`, para que una llamada directa no guarde una cadena vacía.
- Mensajes:
  - «El booking es obligatorio»
  - «El booking debe ser texto»
  - «El booking no puede superar los 255 caracteres»
  - «La referencia debe ser texto»
  - «La referencia no puede superar los 255 caracteres»

### Búsqueda (`GET /api/trips?search=`)

El término se sigue normalizando con `Trip::normalizeReference()`. El grupo `OR` pasa de dos a cuatro columnas:

```php
$builder->where('order', 'LIKE', "%{$search}%")
    ->orWhere('container', 'LIKE', "%{$search}%")
    ->orWhere('booking', 'LIKE', "%{$search}%")
    ->orWhere('reference', 'LIKE', "%{$search}%");
```

Como las cuatro se guardan en mayúsculas, la búsqueda sigue siendo insensible a mayúsculas. El grupo va entre paréntesis para no romper el ámbito por rol, que se aplica antes.

### `TripResource` (47 claves; 48 en `GET /{trip}`)

Dos claves nuevas justo después de `container`:

| Clave | Tipo | Valor |
|---|---|---|
| `booking` | `string \| null` | `$this->booking` |
| `reference` | `string \| null` | `$this->reference` |

Orden: `…, destination, container, booking, reference, transport, recolectionDate, …`. Sin cambio por rol: `shipment` las ve.

### `TripListResource` (22 claves)

Las mismas dos, justo después de `container`:

`…, locationName, container, booking, reference, recolectionDate, …`.

### `TripFactory`

- `booking`: siempre, un código en mayúsculas (`fake()->bothify('BK-########')`).
- `reference`: `null` la mitad de las veces y un código en mayúsculas la otra mitad.
- Sin estados nuevos.

---

## Plan de implementación

Cada paso deja la suite verde y la API funcionando.

1. **Migración.**
   - `php artisan make:migration add_booking_and_reference_to_trips_table --table=trips` con las dos columnas del modelo de datos.
   - Migrar en local y en la base de tests.
2. **Modelo y factory.**
   - `booking` y `reference` al `#[Fillable]` de `Trip`.
   - Párrafo en el PHPDoc de la clase y PHPDoc de `normalizeReference()` ampliado a los cuatro consumidores.
   - Los dos valores en `TripFactory`.
   - Correr `TripTest` y `TripServiceTest`: siguen verdes porque nada los lee todavía.
3. **`TripService`: escritura.**
   - Los dos campos en el array de `create()` y en `UPDATABLE_FIELDS`, con `""` → `null` para `reference`.
   - Tests en `tests/Unit/TripServiceTest.php`:
     - `create` persiste las dos columnas.
     - `create` con `reference` `""` guarda `null`.
     - `update` reescribe `booking`.
     - `update` con `reference` `null` la borra.
     - `update` sin las dos claves no las toca.
4. **`TripService`: búsqueda.**
   - Ampliar el grupo `OR` de `search` a `booking` y `reference`.
   - Tests en `TripTest`:
     - `?search=` encuentra por booking y por referencia, sin distinguir mayúsculas.
     - Un viaje con `reference` `null` no rompe la búsqueda.
     - El ámbito por rol se sigue aplicando: un `carrier` no ve, por booking, un viaje ajeno ya asignado.
5. **FormRequests.**
   - `StoreTripRequest`: `booking` `required` y `reference` `sometimes|nullable`, con mensajes, normalización en `prepareForValidation()` y schema OA (15 → 16 obligatorios + el opcional).
   - `UpdateTripRequest`: las mismas reglas en su forma `sometimes`, con mensajes y schema OA.
   - Actualizar los cuerpos de `POST` en `TripTest` y en cualquier otro test que cree viajes por HTTP.
   - Casos nuevos:
     - `POST` sin `booking`, con `booking` de solo espacios, no texto o de más de 255 caracteres → 422.
     - `POST` sin `reference` → 201 con `null`.
     - `POST` con `reference` de solo espacios → 201 con `null`.
     - Normalización de las dos a mayúsculas con espacios colapsados.
     - Dos viajes con el mismo `booking` y la misma `reference` → ambos 201.
     - `PATCH` con `booking` `null` o `""` → 422.
     - `PATCH` con `reference` `null` → 200 y la borra.
6. **Resources.**
   - `TripResource`: las dos claves tras `container`, con `#[OA\Property]` y texto del schema a 47 claves.
   - `TripListResource`: las dos tras `container`, schema a 22 claves.
   - Actualizar `tripResourceKeys()`, `tripDetailKeys()` y el helper de claves del listado en `TripTest`.
   - Tests:
     - `GET /{trip}`, `GET /trips` y `GET /trips/current` traen las dos claves.
     - Un viaje con las columnas en `null` devuelve `null`.
     - `shipment` las ve con valor.
   - Comprobar que `TripInRouteResource` no cambia.
7. **Documentación y cierre.**
   - `php artisan l5-swagger:generate`.
   - `vendor/bin/pint --dirty --format agent`.
   - Suite completa `php artisan test --compact`.
   - Actualizar `CLAUDE.md` (sección Trips: columnas, conteo de claves y `search`).
8. **Referencia para el frontend.** Actualizar `references/trips-api.md` con:
   - `booking` obligatorio y `reference` opcional en el `POST`.
   - Sus reglas en el `PATCH`.
   - La normalización.
   - Las claves nuevas en detalle y listado.
   - Que `search` ahora cubre las cuatro columnas.

---

## Criterios de aceptación

**Base de datos**

- [ ] `trips` tiene `booking` y `reference`, ambas `varchar(255)` nullable, sin índice único.
- [ ] Las filas anteriores a la migración quedan en `null` en las dos columnas.

**`POST /api/trips`**

- [ ] Sin `booking` responde 422 con «El booking es obligatorio».
- [ ] Con `booking` de solo espacios responde 422 con «El booking es obligatorio».
- [ ] Con `booking` o `reference` no texto, o de más de 255 caracteres, responde 422 con el mensaje en español del campo.
- [ ] Con los 16 campos obligatorios y sin `reference` responde 201 y guarda `reference` en `null`.
- [ ] Con `reference` `""` o de solo espacios responde 201 y guarda `null`.
- [ ] Con `booking: "  bk-2026   001  "` guarda `"BK-2026 001"`; `reference` se normaliza igual.
- [ ] Dos viajes con el mismo `booking` y la misma `reference` se crean ambos con 201.

**`PATCH /api/trips/{trip}`**

- [ ] Con `booking` `null` o `""` responde 422.
- [ ] Con un `booking` nuevo responde 200 y lo guarda normalizado.
- [ ] Con `reference: null` responde 200 y la deja en `null`.
- [ ] Sin `booking` ni `reference` responde 200 y no toca las dos columnas.

**Otras rutas de escritura**

- [ ] `PATCH /{trip}/assignment`, `/start` y `/finish` no tocan las dos columnas.

**Búsqueda**

- [ ] `GET /api/trips?search=bk-2026` encuentra un viaje por su booking.
- [ ] `?search=` con parte de la referencia, en minúsculas, encuentra un viaje por su referencia.
- [ ] Un `carrier` que busca por el booking de un viaje asignado a otra empresa recibe una lista vacía.

**Resources**

- [ ] `GET /api/trips/{trip}` devuelve 48 claves (47 para el `pilot`), con `booking` y `reference` inmediatamente después de `container`.
- [ ] Los demás endpoints de `TripResource` devuelven 47 claves.
- [ ] `GET /api/trips` y `GET /api/trips/current` devuelven 22 claves por viaje, con las dos nuevas inmediatamente después de `container`.
- [ ] Un viaje con las columnas en `null` devuelve `null` en las dos claves, en ambos Resources.
- [ ] El rol `shipment` recibe `booking` y `reference` con su valor.
- [ ] `TripInRouteResource`, `TripPositionResource`, el payload de `TripPositionUpdated` y el Excel de `GET /api/reports/trips` no cambian.

**Factory, documentación y cierre**

- [ ] `TripFactory` genera siempre un `booking` y a veces una `reference`.
- [ ] `storage/api-docs/api-docs.json` documenta los dos campos en los dos FormRequests y los dos Resources.
- [ ] `php artisan test --compact` pasa completo.
- [ ] `vendor/bin/pint --dirty --test` no reporta cambios en los archivos tocados.

---

## Decisiones

- **Sí:** `booking` nullable en la base de datos y obligatorio por la API. Precedente de SPEC 30/32: no se inventan datos para las filas viejas, y por la API ningún viaje nuevo queda sin booking, así que `null` significa exactamente «anterior a SPEC 41».
- **No:** `NOT NULL` con backfill de relleno (`''` o `'SIN BOOKING'`). Un valor inventado no se distingue de uno real y contamina la búsqueda.
- **No:** `NOT NULL` con `default('')`. La cadena vacía se volvería un segundo «sin dato» junto a `null`.
- **Sí:** `reference` opcional en el `POST` y borrable en el `PATCH` con `null`. Es un dato comercial que no todos los viajes tienen.
- **Sí:** `reference` vacía o de solo espacios → `null`. Un solo valor para «sin referencia», al revés que el `booking` de solo espacios, que es 422.
- **Sí:** ninguna de las dos es única, ni global ni por cliente/naviera. Un booking puede cubrir varios contenedores y, por tanto, varios viajes. Mismo criterio que `order` y `container`.
- **Sí:** normalizar con `Trip::normalizeReference()` (MAYÚSCULAS + espacios colapsados). Son códigos comerciales como `order`/`container`, y así la búsqueda es insensible a mayúsculas sin `ILIKE`.
- **No:** solo `trim` como `destination`/`transport`. Dejaría bookings que solo difieren en mayúsculas y una búsqueda que no los encuentra.
- **No:** una `normalizeBooking()` propia. Son dos campos más del mismo modelo con la misma regla; el PHPDoc de `normalizeReference()` ya justifica no duplicarla.
- **Sí:** `string(255)` con `max:255`, como `order`/`container`. No hay un formato de naviera común que justifique un tope menor.
- **No:** validar el formato del booking (prefijo, longitud, dígito verificador). Cada naviera usa uno distinto y nadie lo pidió.
- **Sí:** en `TripResource` **y** en `TripListResource`, junto a `container`. Son dos escalares baratos y en el listado se leen al lado del contenedor.
- **Sí:** visibles para `shipment`. No son dinero, que es lo único que ese rol pierde.
- **Sí:** ampliar `search` a las cuatro columnas en el mismo grupo `OR`. Buscar un viaje por su booking es el uso natural, sin añadir parámetros.
- **No:** filtros dedicados `?booking=`/`?reference=`. `search` ya cubre el caso; si se necesita coincidencia exacta, va en otra spec.
- **No:** columnas en el Excel de SPEC 38 ni en `export_trips` del asistente. Cada exportación tiene su propia matriz de columnas y alinearlas ya es deuda declarada.
- **No:** índices para la búsqueda. `LIKE '%…%'` no usa un B-tree, y `order`/`container` tampoco los tienen.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El frontend en producción sigue mandando el `POST` de 15 campos al desplegar esta spec | Cambio incompatible declarado, sin periodo de gracia, como SPEC 13/21/25/30/37. El 422 nombra el campo en español; el despliegue del front va junto al del back y `references/trips-api.md` lo dice. |
| Viajes anteriores a SPEC 41 salen con `booking: null` aunque la API diga que es obligatorio | El front trata `null` como «sin booking», igual que `estimatedKilometers` en viajes previos a SPEC 30. Un `administrator`/`export` puede completarlo por `PATCH`. Sin backfill por decisión. |
| Un `search` corto (por ejemplo `"B"`) coincide con más viajes ahora que mira cuatro columnas | Comportamiento esperado de un `LIKE` amplio. El listado sigue paginando y el ámbito por rol se aplica antes del filtro. |
| Los tests que cuentan claves (`tripResourceKeys()`, `tripDetailKeys()`, listado) y los que crean viajes por `POST` se rompen | El plan los actualiza en los pasos 5 y 6, en el mismo commit que el cambio que los rompe. |

---

## Lo que **no** entra en esta spec

- Backfill de `booking` en los viajes existentes.
- Unicidad de `booking` o `reference`.
- Validar el formato del booking contra la naviera.
- Filtros dedicados `?booking=`/`?reference=` y orden por ellas.
- Columnas en el Excel de `GET /api/reports/trips` y en `export_trips` del asistente.
- `TripInRouteResource` del dashboard y agregados en `/api/dashboard`.
- Textos de las notificaciones push.
- Rutas nuevas.

Cada una de ellas, si llega, va en su propia spec.
