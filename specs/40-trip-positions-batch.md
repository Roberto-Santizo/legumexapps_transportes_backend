# SPEC 40 — Lote de posiciones del viaje

> **Estado:** Implementado
> **Depende de:** SPEC 24, SPEC 26, SPEC 27 (`27-trip-timeouts.md`)
> **Fecha:** 2026-10-06
> **Objetivo:** Hacer que `POST /api/trips/{trip}/positions` reciba un arreglo de hasta 1000 puntos, cada uno con la hora del dispositivo, para que el piloto que estuvo sin señal reenvíe el tramo perdido.

---

## Por qué existe esta spec

SPEC 26 dejó el lote fuera **a propósito**. Lo anotó como deuda: «Un piloto que estuvo sin señal pierde el tramo». Esta spec reabre dos decisiones de SPEC 26:

- **Un punto por petición** pasa a **un arreglo por petición**. Es un cambio incompatible y no hay periodo de gracia, igual que en SPEC 13, 21 y 25.
- **`recorded_at` lo pone el servidor** pasa a **`recorded_at` lo manda el dispositivo**. Con un lote, `now()` daría la misma hora a todos los puntos. Eso rompería el orden del rastro, la detección de paradas (SPEC 27) y la distancia y las horas reales (SPEC 32). La falsificación que temía SPEC 26 se acota con dos límites: nada en el futuro y nada anterior al arranque del viaje.

Las demás reglas del `POST` no cambian:

- Las cuatro guardas, en el mismo orden.
- El piso de 5 s, ahora medido entre las horas del dispositivo.
- La emisión por websocket, ahora de un solo punto: el último escrito.
- La detección de paradas, una vez por punto escrito.

---

## Alcance

**Dentro:**

- **Nuevo cuerpo del `POST /api/trips/{trip}/positions`:** `{ positions: [{ latitude, longitude, recordedAt }] }`.
  - El cuerpo de un solo punto (`{ latitude, longitude }`) **deja de valer** y responde 422 sobre `positions`.
  - Misma ruta, mismo middleware (`role:pilot`) y mismo controller.
- **`StoreTripPositionRequest` reescrito:**
  - `positions`: `required|array|min:1|max:1000`.
  - `positions.*.latitude`: `required|numeric|between:-90,90`.
  - `positions.*.longitude`: `required|numeric|between:-180,180`.
  - `positions.*.recordedAt`: `required`, ISO 8601 con zona obligatoria, con o sin milisegundos, y **no posterior a `now() + 60 s`**.
  - Mensajes en español que nombran la posición del punto, por ejemplo «La latitud del punto 3 es obligatoria».
  - Un solo punto inválido es **422 para el lote entero**.
- **Guardas del service, en orden:**
  - Primero, las cuatro de SPEC 26 sin cambios: 404 si el viaje no existe, 400 si está borrado, 403 si es ajeno, 400 si no está en ruta.
  - Después, la guarda nueva: **400 «La hora de un punto es anterior al inicio del viaje»** si algún `recordedAt` es anterior a `start_date`.
  - Cualquier error, sea de validación o de guarda, **no guarda nada**.
- **Procesamiento del lote en `TripPositionService`:**
  1. Ordenar el arreglo por `recordedAt`. No se exige que llegue ordenado.
  2. Dentro de **una `DB::transaction`**, bloquear la fila del viaje con `lockForUpdate` y leer el último punto guardado.
  3. Descartar en silencio los puntos con `recordedAt` **menor o igual** al último punto guardado del viaje. Así un reintento de la cola es idempotente.
  4. Aplicar el **piso de 5 s por `recordedAt`**, contra el último punto guardado y entre puntos consecutivos conservados. Los que quedan por debajo se descartan en silencio.
  5. Insertar los conservados y llamar a `TripTimeoutServiceInterface::trackPosition()` por cada uno, en orden, dentro de la misma transacción: si la detección falla a la mitad, no queda ningún punto.
  6. Después del commit, **emitir `TripPositionUpdated` solo con el último punto escrito**, si se escribió alguno. Sigue en `try/catch` con `Log::error`, como en SPEC 26.
- **`recorded_at` sale del `recordedAt` del punto**, convertido a la zona de la app. `pilot_id` sigue saliendo del token.
- **Respuesta resumida.** Se crea un Resource nuevo que envuelve un array, con cuatro claves:
  - `received`: cuántos puntos llegaron.
  - `saved`: cuántos se escribieron.
  - `discarded`: cuántos se descartaron.
  - `lastPosition`: el último punto del rastro tras la petición, con las 5 claves de `TripPositionResource`. Nunca es `null`.
  - **201** si `saved >= 1`; **200** si todos se descartaron.
- **El contrato cambia:** `create()` se sustituye por un método que recibe el lote y devuelve el resumen.
- Actualización del Swagger (`StoreTripPositionRequest`, controller y schema de la respuesta), de `CLAUDE.md` y de `references/trip-positions-api.md`.

**Fuera de alcance (para otras specs):**

- **Aceptar puntos de un viaje `finished`.** Un tramo que llega después de `/finish` se rechaza con 400 entero, y la app debe vaciar su cola antes de cerrar. Recalcular `traveled_polyline`, km y horas reabriría SPEC 28 y SPEC 32.
- **Guardar los puntos válidos de un lote con errores.** No hay respuestas parciales.
- **Insertar puntos anteriores al último guardado y reordenar el rastro.**
- **Mantener el cuerpo de un solo punto o una ruta `/batch` aparte.**
- **Un evento de websocket por punto, o un evento de lote.** El payload de `TripPositionUpdated` sigue con sus 6 claves.
- **Validación geográfica:** cercanía a la polilínea, salto físicamente imposible o puntos fuera de Guatemala.
- **Telemetría:** velocidad, rumbo, precisión o batería.
- **Rate limiting o 429.**
- **Cambios en `GET /{trip}/positions`, `TripPositionResource`, el detalle del viaje, el dashboard o el asistente.**

---

## Modelo de datos

Esta spec **no crea tablas, columnas, migraciones, modelos ni enums**. Reutiliza `trip_positions` de SPEC 26 tal cual:

- **`recorded_at`** cambia de origen: era `now()` del servidor y ahora es el `recordedAt` del dispositivo. El tipo de la columna no cambia.
- **`created_at`** sigue siendo la hora de llegada al servidor. Esto ya estaba previsto en `specs/26-trip-live-tracking.md:116`.
- **El índice `(trip_id, recorded_at)`** sigue sirviendo a las dos consultas del dominio.

### Cuerpo del `POST`

```json
{
  "positions": [
    { "latitude": 14.628074, "longitude": -90.522554, "recordedAt": "2026-10-06T14:32:05-06:00" },
    { "latitude": 14.628301, "longitude": -90.522901, "recordedAt": "2026-10-06T20:32:10.123Z" }
  ]
}
```

```php
// StoreTripPositionRequest::rules()
'positions'              => ['required', 'array', 'min:1', 'max:1000'],
'positions.*.latitude'   => ['required', 'numeric', 'between:-90,90'],
'positions.*.longitude'  => ['required', 'numeric', 'between:-180,180'],
'positions.*.recordedAt' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP', 'before_or_equal:{now + 60 s}'],
```

### Contrato

`create()` desaparece y lo sustituye `storePositions()`:

```php
/**
 * @param  array{positions: list<array{latitude: float|string, longitude: float|string, recordedAt: string}>}  $data
 * @return array{received: int, saved: int, discarded: int, lastPosition: TripPosition}
 *
 * @throws NotFoundError|BadRequestError|ForbiddenError
 */
public function storePositions(User $user, int $tripId, array $data): array;
```

`lastPosition` nunca es `null`:

- Si se escribió al menos un punto, es el último escrito.
- Si todo se descartó, es el último punto ya guardado. Ese punto existe siempre, porque un punto solo se descarta por compararse con otro.

### Respuesta

Resource nuevo `App\Http\Resources\TripPosition\TripPositionBatchResource`. Envuelve el array del service, como hacen `TripsSummaryResource` y `TripCostResource`:

```json
{
  "statusCode": 201,
  "message": "Posiciones registradas correctamente",
  "data": {
    "received": 20,
    "saved": 17,
    "discarded": 3,
    "lastPosition": { "id": 981, "latitude": "14.62830100", "longitude": "-90.52290100", "recordedAt": "06-10-2026 02:32:10 PM", "pilotId": 12 }
  }
}
```

### Convenciones

- `recordedAt` se convierte a `config('app.timezone')` antes de guardarse.
- Los milisegundos se truncan, porque la columna guarda segundos.
- Dos puntos con el mismo segundo los resuelve el piso de 5 s: el segundo se descarta.
- `discarded` suma los puntos descartados por ser anteriores o iguales al último guardado y los descartados por el piso. No se desglosan.

---

## Plan de implementación

1. **Resource del resumen.** Crear `app/Http/Resources/TripPosition/TripPositionBatchResource.php`:
   - Envuelve `array{received, saved, discarded, lastPosition}`.
   - `lastPosition` se pinta con `TripPositionResource`.
   - Lleva su schema OA `TripPositionBatchResource`.
   - Nadie lo usa todavía, así que la suite sigue verde.
2. **Método nuevo en el contrato y en el service, junto a `create()`.** Añadir `storePositions()` a `TripPositionServiceInterface` (PHPDoc de array shapes y `@throws`) y a `TripPositionService` con `#[Override]`. Pasos del método:
   - Resolver el viaje con `resolveReportableTrip()`, que ya aplica las cuatro guardas.
   - Parsear los `recordedAt` y convertirlos a la zona de la app.
   - Guarda nueva: 400 si algún punto es anterior a `start_date`.
   - Ordenar por `recordedAt`.
   - Abrir `DB::transaction`, hacer `lockForUpdate` sobre la fila del viaje y leer `lastPositionFor()` **dentro** de la transacción.
   - Filtrar los puntos `<=` al último guardado y aplicar el piso de 5 s entre consecutivos conservados.
   - Por cada punto conservado, hacer `TripPosition::create()` y `trackPosition()`, en orden.
   - Después del commit, `broadcastPosition()` solo con el último escrito.

   `create()` sigue intacto y sigue en uso, así que nada se rompe. Unit tests nuevos en `tests/Unit/TripPositionServiceTest.php` para `storePositions()`.
3. **Cambio de contrato HTTP.** Es el paso incompatible y va en un solo commit:
   - Reescribir `StoreTripPositionRequest` con las reglas `positions.*` y los `messages()` en español que nombran el índice del punto.
   - En `TripPositionController::store`, llamar a `storePositions()` y responder `TripPositionBatchResource`: 201 si `saved >= 1`, 200 si no.
   - Migrar al cuerpo nuevo **todos** los `postJson(".../positions")` de `tests/Feature/TripPositionTest.php` (19), `tests/Feature/TripTimeoutTest.php` (5) y `tests/Feature/TripTest.php` (3), y ajustar sus aserciones al resumen. Los tests que dependen del piso por `now()` pasan a fijar `recordedAt` explícito.
   - Correr esos tres archivos.
4. **Retirar `create()`.** Borrarlo de `TripPositionServiceInterface` y de `TripPositionService`. Migrar o eliminar sus casos en `tests/Unit/TripPositionServiceTest.php`. Comprobar con `grep` que nadie lo llama.
5. **Feature tests del lote.** En `tests/Feature/TripPositionTest.php`, uno por criterio de aceptación:
   - 422 de forma: cuerpo viejo, vacío, más de 1000 puntos, punto inválido en medio, `recordedAt` sin zona, `recordedAt` en el futuro.
   - 400 por punto anterior a `start_date`.
   - Arreglo desordenado.
   - Descarte por anteriores y por piso.
   - Reintento idempotente.
   - 200 con todo descartado.
   - Un solo evento con `Event::fake()`.
   - Paradas abiertas y cerradas dentro de un mismo lote (en `TripTimeoutTest.php`).
   - Rollback si `trackPosition()` lanza.
6. **Swagger.** Actualizar los atributos OA de `StoreTripPositionRequest`, de `TripPositionController::store` (respuestas 201/200 con `TripPositionBatchResource`, 400 nuevo) y de los textos que hoy dicen «un punto por petición» y «recordedAt no se acepta». Correr `php artisan l5-swagger:generate`.
7. **Formato y documentación.**
   - `vendor/bin/pint --dirty --format agent`.
   - Actualizar `CLAUDE.md`, sección «Seguimiento en vivo (SPEC 26)»: el lote, `recordedAt` del dispositivo, el piso por hora del dispositivo, el resumen y un solo evento por lote.
   - Actualizar la mención a `create()` en la descripción del dominio.
   - Escribir `references/trip-positions-api.md`, que incluya cómo vaciar la cola ante el 400 por hora anterior al arranque y antes de `/finish`.

---

## Criterios de aceptación

**Validación (422, no se guarda nada):**

- [x] El cuerpo viejo `{ latitude, longitude }` responde **422** sobre `positions`.
- [x] `positions` vacío o ausente responde **422**.
- [x] Un lote de **1001** puntos responde **422**. Uno de **1000** se acepta.
- [x] Un lote de 20 puntos con el punto 10 sin `latitude` responde **422**, con un mensaje que nombra ese punto, y `trip_positions` no gana ninguna fila.
- [x] `recordedAt` sin zona horaria (`2026-10-06T14:32:05`) responde **422**.
- [x] `recordedAt` con milisegundos y `Z` (`2026-10-06T20:32:05.123Z`) se acepta.
- [x] `recordedAt` a más de 60 s en el futuro responde **422**. A 30 s en el futuro se acepta.
- [x] Un cuerpo inválido sobre un viaje inexistente responde **422**, no 404: la validación corre antes que las guardas.

**Guardas (orden de SPEC 26 intacto):**

- [x] Las cuatro guardas responden igual que antes, en el mismo orden: viaje inexistente 404 → borrado 400 → ajeno 403 → no `in_route` 400.
- [x] Un viaje `finished` responde **400** al lote entero y no guarda nada.
- [x] Un lote con un punto anterior a `start_date` responde **400 «La hora de un punto es anterior al inicio del viaje»** y no guarda nada.

**Procesamiento:**

- [x] `recorded_at` de cada fila es el `recordedAt` del punto convertido a la zona de la app, no `now()`.
- [x] `pilot_id` sale del token. Un `pilotId` en el punto se ignora.
- [x] Un lote enviado desordenado se guarda en orden de `recordedAt`: los `id` crecen con la hora.
- [x] Los puntos con `recordedAt` menor o igual al último guardado se descartan y cuentan en `discarded`.
- [x] Reenviar el mismo lote dos veces responde **200** la segunda vez, con `saved: 0`, y no crea filas nuevas.
- [x] En un lote con puntos a 0, 3, 6 y 12 s, se guardan los de 0, 6 y 12 s, y el de 3 s cuenta en `discarded`.
- [x] El piso compara contra el último punto guardado: un lote cuyo primer punto está a 3 s del último guardado descarta ese primer punto.
- [x] El último punto guardado se lee dentro de la transacción, después de un `lockForUpdate` sobre la fila del viaje. Lo comprueba un Unit test con `DB::getQueryLog()`, que busca el `FOR UPDATE` sobre `trips` antes del `SELECT` a `trip_positions`.

**Paradas (SPEC 27):**

- [x] Un lote de puntos quietos (< 5 m) abre **una** parada anclada en el punto anterior, con `started_at` igual al `recorded_at` del dispositivo.
- [x] Un lote que se queda quieto y luego se aleja abre y cierra la parada en la misma petición, con `ended_at` igual al `recordedAt` del punto que se alejó.
- [x] Si `trackPosition()` lanza a mitad del lote, la petición falla y `trip_positions` y `trip_timeouts` quedan como antes: la transacción hace rollback.

**Websocket:**

- [x] Un lote que escribe 17 puntos emite **un solo** `TripPositionUpdated`, con el último punto escrito.
- [x] Un lote que no escribe nada no emite ningún evento.
- [x] Con el broadcast fallando, los puntos se guardan igual, la respuesta es 201 y queda un `Log::error`.

**Respuesta:**

- [x] Con al menos un punto escrito, responde **201** con `data` de exactamente cuatro claves: `received`, `saved`, `discarded` y `lastPosition`.
- [x] Con todo descartado, responde **200**, y `lastPosition` es el último punto ya guardado.
- [x] Siempre se cumple `received === saved + discarded`.
- [x] `lastPosition` tiene las 5 claves de `TripPositionResource`.

**Sin regresiones:**

- [x] `GET /api/trips/{trip}/positions`, `TripPositionResource` (5 claves) y el payload de `TripPositionUpdated` (6 claves) no cambian de forma.
- [x] `TripPositionServiceInterface` ya no declara `create()`, y ningún archivo de `app/` lo llama.
- [ ] `php artisan test --compact` pasa completa. *(3950/3951: el único fallo, `TripTimeoutTest` › «no toca ninguna parada al iniciar el viaje», ya falla en `main` antes de esta spec —el test no confirma ninguna carga de combustible y `/start` la exige desde SPEC 27—; no toca posiciones.)*
- [x] `storage/api-docs/api-docs.json` documenta el cuerpo `positions` y la respuesta resumida.

---

## Decisiones tomadas y descartadas

**Forma del contrato**

- **Sí: solo el arreglo, en la misma ruta.** Es un cambio incompatible sin periodo de gracia, con el precedente de SPEC 13, 21 y 25. La app móvil se publica a la vez.
- **No: aceptar las dos formas, un punto o un arreglo.** Habría dos validaciones y dos caminos en el service para el mismo dato.
- **No: una ruta `/positions/batch` aparte.** Habría dos rutas de escritura para la misma tabla, y la de un punto sería un lote de uno.

**Hora del punto**

- **Sí: `recordedAt` del dispositivo, obligatorio por punto.** Esto reabre la decisión de SPEC 26. Con `now()`, todos los puntos de un lote compartirían la misma hora, y se romperían el orden del rastro, las paradas y las métricas de SPEC 32.
- **Sí: dos límites contra la falsificación.**
  - No más de 60 s en el futuro (422). La tolerancia cubre la deriva del reloj del teléfono.
  - No antes de `start_date` (400). Esta guarda va en el service porque necesita el viaje.
- **Sí: ISO 8601 con zona obligatoria, con o sin milisegundos.** Una hora sin zona es ambigua. La de JS, `toISOString()`, tiene que pasar.
- **No: epoch en milisegundos.** Es ilegible en los logs y en el Swagger.

**Errores y atomicidad**

- **Sí: un error invalida el lote entero (422 o 400) y no guarda nada.** La app reintenta la cola completa de todos modos.
- **No: guardar los válidos y reportar los inválidos.** Obligaría a la app a reconstruir su cola a partir de una respuesta parcial.
- **Sí: inserción y `trackPosition()` en una sola `DB::transaction`.** Una parada mal detectada es un dato equivocado. Por eso, si la detección falla, el lote no queda a medias.
- **Sí: `lockForUpdate` sobre la fila del viaje, con el último punto leído dentro de la transacción.** Dos lotes concurrentes del mismo piloto (un reintento mientras el primero sigue en curso) se serializan: el segundo espera y descarta lo repetido. Sin el bloqueo, los dos leerían el mismo último punto y el rastro y las paradas quedarían intercalados.
- **Sí: el broadcast va fuera de la transacción, después del commit.** Así nunca se emite un punto que luego se deshace.

**Orden y descarte**

- **Sí: el servidor ordena el arreglo por `recordedAt`.** No se exige orden de llegada, porque una cola de reintentos puede mezclarse.
- **Sí: se descartan en silencio los puntos `<=` al último guardado.** Hace idempotente el reintento. También protege la detección de paradas, que asume que cada punto nuevo es el más reciente.
- **No: insertar puntos viejos y reordenar.** Rompería `positionBefore()` y las paradas ya abiertas o cerradas.
- **No: responder 422 a los puntos viejos.** Un reintento legítimo fallaría para siempre.
- **Sí: el piso de 5 s se mide entre horas del dispositivo, no contra `now()`.** Contra `now()`, un lote de una hora de antigüedad pasaría entero o se descartaría entero, según cuándo llegue.
- **Sí: `discarded` es un solo contador, sin desglose.** Para la app, los dos motivos significan lo mismo: «ya lo tengo o sobraba».

**Salida**

- **Sí: se emite solo el último punto escrito.** El mapa salta a la posición actual, y el tramo se recupera con `GET /{trip}/positions`. Mil eventos a Reverb dentro de la petición del piloto son caros.
- **No: un evento por punto, ni un evento de lote.** El segundo además cambiaría el payload de 6 claves.
- **Sí: la respuesta es un resumen `{ received, saved, discarded, lastPosition }`.** Es ligero y le sirve a la app para vaciar su cola.
- **No: devolver la lista de puntos escritos.** Serían hasta mil objetos que la app ya tiene.
- **Sí: 201 si se escribió al menos uno, 200 si no.** Es la misma regla que en SPEC 26.

**Límites**

- **Sí: un máximo de 1000 puntos por lote.** Equivale a unos 83 min sin señal con el piso de 5 s. Con más tiempo sin señal, la app parte la cola en varios lotes.
- **Sí: se mantiene la guarda `in_route`.** Un tramo que llega después de `/finish` se pierde. La app debe vaciar su cola antes de cerrar el viaje. Aceptarlo obligaría a recalcular `traveled_polyline`, km y horas, y eso reabriría SPEC 28 y SPEC 32.
- **Sí: `create()` desaparece del contrato.** No quedan consumidores, porque el canal y el dashboard solo leen.

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| **Reloj del teléfono atrasado.** Un punto anterior a `start_date` produce un 400 en el lote entero. Si la app reintenta igual, su cola queda **atascada para siempre**. | El mensaje es específico, y el `references/trip-positions-api.md` indica que, ante ese 400, la app debe descartar de su cola los puntos anteriores al arranque y reintentar. |
| **Reloj del teléfono adelantado** (dentro de la tolerancia de 60 s). Se guarda un punto «del futuro», y durante ese margen los puntos reales posteriores se descartan como `<=` al último guardado. | Riesgo aceptado: la ventana está acotada a 60 s. El hueco que deja es menor que el de un tramo sin señal. |
| **Dos lotes concurrentes del mismo piloto.** Por ejemplo, un reintento mientras el primero sigue en curso. | `lockForUpdate` sobre la fila del viaje y último punto leído dentro de la transacción: el segundo lote espera al primero y luego descarta lo repetido. |
| **Transacción larga.** 1000 puntos dan unas 1000 inserciones y unas 2–3 consultas de `trackPosition()` por punto, con el viaje bloqueado mientras tanto. | El tope de 1000 la acota. Solo bloquea a otros lotes del mismo viaje; ninguna lectura queda bloqueada. Si en producción resulta lenta, la inserción masiva va en otra spec. |
| **Viaje cerrado sin vaciar la cola.** Si el piloto pulsa `/finish` con puntos pendientes, ese tramo se pierde y las métricas de SPEC 32 salen cortas. | Decisión declarada. La referencia del frontend pide vaciar la cola antes de `/finish`. |
| **Versiones viejas de la app.** Una app sin actualizar recibe 422 en cada punto y el viaje deja de tener rastro. | Publicación coordinada con la app. No hay periodo de gracia, con el precedente de SPEC 13/21/25. |
| **Tramo fabricado.** Con la hora del dispositivo, un piloto puede enviar un recorrido inventado dentro de la ventana `[start_date, now + 60 s]`. | Riesgo aceptado, el mismo de SPEC 26: no hay validación geográfica y solo afecta al viaje propio. |
| **El mapa en vivo no ve el tramo recuperado.** Solo se emite el último punto, así que la línea salta en recta hasta que el front recarga el `GET`. | Documentado en la referencia: al recibir un evento muy alejado del anterior, el front puede recargar el rastro. |

---

## Lo que **no** entra en esta spec

- Aceptar puntos de un viaje `finished` ni recalcular `traveled_polyline`, km u horas.
- Guardar parcialmente un lote con errores.
- Insertar puntos anteriores al último guardado.
- Mantener el cuerpo de un solo punto o una ruta `/batch`.
- Emitir un evento por punto o un evento de lote.
- Validación geográfica, telemetría o rate limiting.
- Inserción masiva (`insert()` en bloque) en lugar de `create()` por punto.
- Cualquier cambio en `GET /{trip}/positions`, `TripPositionResource`, el detalle del viaje, el dashboard o el asistente.

Cada uno de estos puntos, si llega, irá en su propia spec.
