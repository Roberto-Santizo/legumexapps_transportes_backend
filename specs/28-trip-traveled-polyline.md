# SPEC 28 — Polilínea del recorrido real del viaje

> **Estado:** Aprobado
> **Depende de:** SPEC 16, SPEC 24, SPEC 26
> **Fecha:** 2026-09-13
> **Objetivo:** Al finalizar un viaje (`PATCH /api/trips/{trip}/finish`), codificar todo el rastro de `trip_positions` en una polilínea persistida en la columna nueva `trips.traveled_polyline` y exponerla en `TripResource` como `traveledPolyline` + `traveledPoints`.

Es la **tercera spec aditiva sobre `trips`** tras las dos SPEC 27, y la primera que junta dos cosas que hasta hoy vivían separadas: la `polyline` **prevista** que el frontend resuelve con `GET /api/places/directions` (SPEC 24) y el rastro **real** que el piloto reporta punto a punto (SPEC 26). No compara las dos: solo deja la segunda en el mismo formato y en la misma fila que la primera.

Depende de SPEC 16 por el formato de polilínea y el `PolylineDecoder` (el encoder nuevo es su espejo), de SPEC 24 por `finish()` y `TripResource`, y de SPEC 26 por `trip_positions`.

---

## Alcance

**Dentro:**

- Migración aditiva sobre `trips`: columna `traveled_polyline` (`TEXT`, nullable), sin default y sin backfill.
- `Trip` gana `traveled_polyline` en `#[Fillable]`; sin cast (es texto) y sin relación `positions()`.
- Clase nueva `App\Services\Place\PolylineEncoder` con `encode(list<array{0: float, 1: float}>): string`, espejo exacto de `PolylineDecoder::decode()` (precisión 1e5, 5 decimales, mismo formato del proveedor).
- `TripService::finish()` lee todas las `trip_positions` del viaje en orden `recorded_at asc, id asc`, las codifica y escribe `traveled_polyline` **en el mismo `update()`** que ya fija `end_date` y `status`. Sin puntos → `null`. Cada `/finish` recodifica el rastro completo.
- `TripResource` gana dos claves, colocadas justo después de `points`: `traveledPolyline` (string o `null`) y `traveledPoints` (pares `[lat, lng]` decodificados con `PolylineDecoder`; `[]` si la polilínea es `null`). Pasa de 35 a **37 claves**.
- Anotaciones OpenAPI de las dos claves y regeneración de `storage/api-docs/api-docs.json`.
- Tests: Unit `PolylineEncoderTest` (round-trip con `PolylineDecoder`, sin BD ni red), Feature en `TripTest` para `/finish` con y sin puntos, y las dos claves nuevas en `TripResource`.
- `references/trips-api.md` actualizado con las dos claves.

**Fuera de alcance (para otras specs):**

- Backfill de los viajes ya `finished`: quedan con `traveled_polyline = null`.
- Actualizar la polilínea en cada `POST /{trip}/positions` o exponerla mientras el viaje está `in_route`: para eso siguen el websocket y `GET /{trip}/positions`.
- Exigir al menos un punto para finalizar: `/finish` no cambia de guardas.
- Simplificación (Douglas-Peucker), colapso de puntos repetidos o filtrado del rastro.
- Cambios en `TripListResource`, `GET /trips/current`, `TripPositionResource` o el payload de `TripPositionUpdated`.
- Aceptar `traveled_polyline` en el `POST`, el `PATCH` general o cualquier body.
- Comparación entre `polyline` (prevista) y `traveled_polyline` (real): desvíos, distancia recorrida, alertas.
- Un `GET /api/trips/{trip}/traveled-route` o cualquier ruta nueva.

---

## Modelo de datos

Una columna nueva en una tabla existente y ninguna tabla nueva.

### Migración aditiva sobre `trips`

```php
// database/migrations/2026_09_13_xxxxxx_add_traveled_polyline_to_trips_table.php
Schema::table('trips', function (Blueprint $table) {
    $table->text('traveled_polyline')->nullable()->after('polyline');
});
```

- `TEXT` como `polyline`: un rastro de horas a un punto cada 15 s supera con holgura un `varchar`.
- `nullable` y **sin default**: `null` significa «el viaje no ha terminado» o «terminó sin un solo punto» o «terminó antes de SPEC 28». La API no distingue los tres casos, igual que `pilotDpiImage` en SPEC 25.
- Sin índice: nadie filtra ni ordena por ella.

### `Trip`

- `traveled_polyline` entra en `#[Fillable]`, junto a `polyline`.
- Sin cast: es una cadena opaca en el formato del proveedor, como `polyline`.
- **No** gana `positions()`: `finish()` consulta `TripPosition::query()` directamente, con el precedente de `closeOpenTimeout()` sobre `TripTimeout`.

### `PolylineEncoder` (sin persistencia)

```php
// app/Services/Place/PolylineEncoder.php
final class PolylineEncoder
{
    /** @param list<array{0: float, 1: float}> $points pares [lat, lng] */
    public static function encode(array $points): string;
}
```

- Entrada en `[lat, lng]`, el mismo orden que devuelve `PolylineDecoder::decode()` y en que habla toda la API.
- Redondea a 5 decimales (`1e5`) antes de calcular deltas: las coordenadas de `trip_positions` tienen 8 y pierden ~1 m. Pérdida aceptada.
- Lista vacía → cadena vacía `''`. Es `finish()` quien traduce «sin puntos» a `null`, no el encoder.
- Invariante que fija el test: `PolylineDecoder::decode(PolylineEncoder::encode($p)) === $p` para cualquier `$p` ya redondeado a 5 decimales.

### `TripResource` (37 claves)

Dos claves nuevas, insertadas entre `points` y `observations`:

| Clave | Tipo | Valor |
|---|---|---|
| `traveledPolyline` | `string \| null` | `trips.traveled_polyline` tal cual |
| `traveledPoints` | `list<[lat, lng]>` | `PolylineDecoder::decode($this->traveled_polyline ?? '')` → `[]` si es `null` |

Orden final: `…, polyline, points, traveledPolyline, traveledPoints, observations, …`.

---

## Plan de implementación

Cada paso deja la suite verde y la API funcionando.

1. **Migración.** `php artisan make:migration add_traveled_polyline_to_trips_table --table=trips`: `text('traveled_polyline')->nullable()->after('polyline')`. Migrar en local y en la base de tests.
2. **Modelo.** Añadir `traveled_polyline` al `#[Fillable]` de `Trip` y un párrafo al PHPDoc de la clase: qué guarda, cuándo se escribe (`/finish`) y qué significa `null`.
3. **`PolylineEncoder`.** Crear `app/Services/Place/PolylineEncoder.php` (`final`, estático, mismas constantes de precisión que el decoder). Crear `tests/Unit/PolylineEncoderTest.php`: lista vacía → `''`; un punto; varios puntos con deltas negativos; round-trip con `PolylineDecoder`; entrada con 8 decimales queda a 5 tras el round-trip. Correr solo ese archivo.
4. **`TripService::finish()`.** Antes del `update()`, leer `TripPosition::query()->where('trip_id')->orderBy('recorded_at')->orderBy('id')->get(['latitude', 'longitude'])`, mapear a pares `[lat, lng]` como `float`, codificar y añadir `'traveled_polyline' => $encoded !== '' ? $encoded : null` al mismo array del `update()`. Método privado `encodeTraveledRoute(Trip $trip): ?string` con PHPDoc que explique por qué no hay relación `positions()` ni transacción nueva.
5. **Tests del service y del endpoint.** En `tests/Unit/TripServiceTest.php` y `tests/Feature/TripTest.php`: finish con tres puntos → columna codificada y `traveledPoints` con los tres pares en orden; finish sin puntos → `null` y `[]`; segundo `/finish` con rastro nuevo → 400 «El viaje ya fue finalizado» y la polilínea del primer cierre queda intacta (ver nota en los criterios); el `PATCH` general con `traveledPolyline` en el body no la toca.
6. **`TripResource`.** Añadir `traveledPolyline` y `traveledPoints` entre `points` y `observations`, con sus `#[OA\Property]` y el texto del schema actualizado (35 → 37 claves). Ajustar los tests que cuentan claves del Resource. Verificar que `TripListResource` sigue en 15.
7. **Documentación.** `php artisan l5-swagger:generate`; `vendor/bin/pint --dirty --format agent`; suite completa `php artisan test --compact`.
8. **Referencia para el frontend.** Actualizar `references/trips-api.md` con las dos claves, cuándo dejan de ser `null`/`[]` y la pérdida de precisión a 5 decimales.

---

## Criterios de aceptación

- [ ] `trips` tiene la columna `traveled_polyline` (`TEXT`, nullable) y las filas anteriores a la migración quedan en `null`.
- [ ] `PolylineEncoder::encode([])` devuelve `''`.
- [ ] `PolylineDecoder::decode(PolylineEncoder::encode($points)) === $points` para cualquier lista de pares con 5 decimales.
- [ ] `PolylineEncoder::encode()` sobre pares con 8 decimales produce, al decodificar, los mismos pares redondeados a 5.
- [ ] `PATCH /api/trips/{trip}/finish` con N posiciones guarda una polilínea cuyo `decode()` devuelve N pares en orden `recorded_at asc, id asc`.
- [ ] `PATCH /api/trips/{trip}/finish` sin posiciones responde 200, guarda `traveled_polyline = null` y devuelve `traveledPolyline: null`, `traveledPoints: []`.
- [ ] La polilínea se escribe en el mismo `update()` que `end_date` y `status`; no se abre ninguna `DB::transaction` nueva en `finish()`.
- [ ] Un segundo `/finish` sobre un viaje ya cerrado responde 400 («El viaje ya fue finalizado») y conserva la `traveled_polyline` del primer cierre aunque haya puntos nuevos.
  > **Nota (implementación):** el escenario «segundo `/finish` tras un `PATCH` de admin a `in_route`» es teórico: el `PATCH` general no limpia `end_date` (SPEC 24) y la guarda de `finish()` es `end_date !== null`, así que por la API no hay segundo cierre. La sobrescritura del rastro completo queda como comportamiento del código (cada `finish()` recodifica desde cero) sin test que la ejercite; si algún día `end_date` se puede limpiar, el test se añade entonces.
- [ ] `TripResource` devuelve 37 claves, con `traveledPolyline` y `traveledPoints` inmediatamente después de `points`, en los siete endpoints que lo usan.
- [ ] `TripListResource` sigue devolviendo 15 claves; `GET /api/trips` y `GET /api/trips/current` no cambian de forma.
- [ ] Mandar `traveledPolyline` o `traveled_polyline` en el `POST` o en el `PATCH` general se ignora con la respuesta habitual y no escribe la columna.
- [ ] `POST /api/trips/{trip}/positions` no toca `traveled_polyline` (sigue `null` mientras el viaje está `in_route`).
- [ ] El payload de `TripPositionUpdated` sigue en seis claves y `TripPositionResource` en cinco.
- [ ] `storage/api-docs/api-docs.json` documenta las dos claves nuevas.
- [ ] `php artisan test --compact` pasa completo y `vendor/bin/pint --test` no reporta cambios.

---

## Decisiones

- **Sí:** columna persistida, escrita **solo en `/finish`**. Un solo encode por viaje, y el `POST /positions` —la ruta más caliente del proyecto— no cambia ni una línea.
- **No:** recodificar en cada `POST /positions`. O(n) por punto para un dato que nadie lee hasta que el viaje termina; mientras está `in_route` ya existen el websocket y `GET /{trip}/positions`.
- **No:** campo calculado en lectura desde `trip_positions`. Arrastraría miles de filas en cada `GET /trips/{trip}`; `points` puede calcularse en lectura porque parte de una cadena ya en la fila, no de otra tabla.
- **Sí:** nombre `traveled_polyline` / `traveledPolyline` / `traveledPoints`. Convive con `polyline`/`points` (la prevista) y el adjetivo dice cuál es cuál sin renombrar lo publicado.
- **Sí:** exponer cadena **y** pares decodificados, con la simetría de `polyline`/`points`. El front ya pinta `points`; no gana nada decodificando por su cuenta.
- **Sí:** solo `TripResource`. `TripListResource` sigue sin polilíneas por la misma razón de SPEC 24: un `limit=100` no decodifica cien rastros.
- **Sí:** `PolylineEncoder` en `app/Services/Place/`, junto al decoder. El formato es del proveedor, y el conocimiento del formato no sale de ese directorio (regla de SPEC 16).
- **Sí:** precisión 1e5 (5 decimales), perdiendo ~1 m frente a los 8 decimales de `trip_positions`. Es el formato que el front ya decodifica; el rastro exacto sigue en `trip_positions`.
- **Sí:** codificar los puntos **tal cual**, en `recorded_at asc, id asc`, sin colapsar repetidos ni simplificar. La polilínea es el rastro fiel; simplificar es del front y las paradas ya las cuenta `trip_timeouts`.
- **Sí:** `/finish` sin puntos guarda `null` y no bloquea. El rastro es opcional hoy y `/finish` no gana guardas.
- **Sí:** cada `/finish` recodifica el rastro completo y sobrescribe. La columna significa «todo lo reportado hasta el último finish»; el hueco de la máquina de estados es de SPEC 24, no de esta.
- **Sí:** misma llamada a `update()` que `end_date`/`status`, sin `DB::transaction` nueva. Un UPDATE atómico basta; abrir transacción solo para eso cambiaría la forma de `finish()` sin ganar nada.
- **Sí:** `finish()` consulta `TripPosition::query()` directamente y `Trip` **no** gana `positions()`. Precedente doble: `closeOpenTimeout()` sobre `TripTimeout` y la razón de SPEC 26 para no crear la relación.
- **No:** backfill de viajes ya `finished`. Precedente de SPEC 21/25/27; si hace falta, es un comando en otra spec.
- **No:** aceptar `traveled_polyline` en ningún body. Es dato derivado del rastro, nunca del cliente, como `start_date`/`end_date`.
- **No:** `encode()` devuelve `null` para lista vacía. El encoder es una función pura del formato (`''` es la codificación válida de cero puntos); la semántica de `null` es del dominio y vive en `finish()`.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Rastro muy largo (viaje de 12 h a un punto cada 15 s ≈ 2 900 puntos) carga miles de filas en memoria en un `/finish` | Se leen solo `latitude`/`longitude` con `get([...])`, sin modelos completos; ~3 000 filas caben con holgura. `TEXT` no acota el tamaño de la cadena. Si algún día se reporta cada segundo, el chunking es de otra spec. |
| Deriva entre encoder y decoder (un cambio en uno sin el otro) | El test de round-trip `decode(encode($p)) === $p` en `PolylineEncoderTest` falla ante cualquier asimetría. Ambos comparten constantes en el mismo directorio. |
| El `PATCH` del administrador devuelve el viaje a `pending` y lo borra; la polilínea queda como historial huérfano | Es el hueco declarado de SPEC 24 (sin máquina de estados); esta spec no lo abre ni lo cierra. Un viaje borrado sigue con su `traveled_polyline`, igual que conserva su rastro en `trip_positions`. |
| Un `/finish` que falla a mitad (p. ej. al cerrar la parada) deja `traveled_polyline` escrita con `end_date` y `status` pero sin parada cerrada | Ya ocurre hoy con `end_date`/`status` vs `closeOpenTimeout()`: sin transacción, por decisión explícita de esta spec y de SPEC 27. El siguiente `/finish` es 400 («ya fue finalizado»), así que no se reintenta; la parada la cierra a mano quien opere la base. |

---

## Lo que **no** entra en esta spec

- Backfill de los viajes ya finalizados.
- Polilínea en vivo (actualizada por cada `POST /positions` o expuesta mientras el viaje está `in_route`).
- Exigir puntos para finalizar.
- Simplificación o filtrado del rastro.
- Cambios en `TripListResource`, `GET /trips/current`, `TripPositionResource` o el evento `TripPositionUpdated`.
- `traveled_polyline` en cualquier body.
- Comparación entre ruta prevista y ruta real (desvíos, distancia, alertas).
- Rutas nuevas.

Cada una de ellas, si llega, va en su propia spec.
