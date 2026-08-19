# SPEC 16 — Ruta por carretera hacia un destino registrado

> **Estado:** Aprobado
> **Depende de:** SPEC 01, SPEC 12, SPEC 15
> **Fecha:** 2026-08-19
> **Objetivo:** Añadir al dominio `Place` un tercer endpoint de solo lectura que calcula la ruta por carretera desde un punto `lat,lng` hasta un destino registrado (`Location`) y devuelve distancia en kilómetros, duración en horas y la polilínea, codificada y decodificada en pares `[lat, lng]`.

Depende de **SPEC 01** por el guard JWT y el middleware `jwt.auth`, el único que protege esta ruta: sin `role:` y sin `carrier.required`, igual que los otros dos endpoints del dominio. De **SPEC 12** por todo lo demás — el contrato `PlaceServiceInterface`, su implementación `GooglePlacesService`, la credencial `services.google_places.key`, el `ServiceUnavailableError` y la regla de que ningún archivo fuera de `app/Services/Place/` nombra a Google. Y de **SPEC 15** por el modelo `Location`, del que salen las coordenadas del destino y cuyo `status` decide si la ruta se traza.

Es la **segunda llamada saliente del proyecto** y la primera a una API de Google que no es Places: `computeRoutes` de la Routes API. Comparte credencial con la búsqueda de direcciones y **se factura aparte**.

No persiste nada, no crea ninguna tabla y **no cotiza nada**: no toca `freight_rates`, no conoce vehículos ni pilotos, y el origen es un par de coordenadas sueltas, no una entidad del sistema. Su consumidor es el front, que ya tiene el `locationId` del destino y la posición de origen, y quiere dibujar la línea en el mapa con su distancia y su tiempo.

---

## Alcance

**Dentro:**

- **Ninguna migración, ningún modelo, ninguna tabla y ningún enum.** Como SPEC 12, esto es un proxy de lectura: la ruta se calcula, se devuelve y se olvida.
- **Ninguna variable de entorno nueva.** La llamada usa `services.google_places.key`, la misma credencial de SPEC 12. `.env.example` no cambia.
- **Tercer método en `PlaceServiceInterface`**, con su PHPDoc de array shape y sus `@throws`, implementado por `GooglePlacesService`:
  `getDirections(float $originLatitude, float $originLongitude, float $destinationLatitude, float $destinationLongitude): array`.
  El contrato ve **cuatro números y nada más**: no conoce `Location`, no conoce Eloquent y no consulta la base. Un proveedor sustituto implementa tres métodos, ninguno de ellos atado a una tabla del proyecto.
- **Llamada nueva a `POST https://routes.googleapis.com/directions/v2:computeRoutes`**, con constantes de clase propias en `GooglePlacesService`: URL, field mask `routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline`, `travelMode: DRIVE`, `routingPreference: TRAFFIC_UNAWARE`, `polylineQuality: OVERVIEW`, `units: METRIC`, `languageCode: es`, `regionCode: GT` y `computeAlternativeRoutes: false`. Mismo `timeout(10)` y **sin reintentos**, por la misma razón: cada llamada se paga.
- **`App\Services\Place\PolylineDecoder`**, clase `final` con `decode(string $polyline): array` estático, que traduce la polilínea codificada de Google a `list<array{0: float, 1: float}>` en pares `[lat, lng]`. Es el único algoritmo de esta spec que se prueba **sin red**, igual que la geometría de zonas se prueba sin PostGIS.
- **Un endpoint, `GET /api/places/directions?locationId=&lat=&lng=`**, con `jwt.auth` a secas — sin `role:` y sin `carrier.required`, como los otros dos del dominio. Se declara **antes** del `apiResource`: si no, el comodín `{place}` captura `/directions` y la ruta responde 404 buscando un lugar llamado "directions" en Google.
- `GetDirectionsRequest` valida los tres parámetros con `messages()` en español: `locationId` → `required|integer|exists:locations,id`; `lat` → `required|numeric|between:-90,90`; `lng` → `required|numeric|between:-180,180`. **Los tres son obligatorios y un 422 nunca llega a Google.**
- **Método nuevo `getActiveLocationById(int $id): Location` en `LocationServiceInterface` y `LocationService`** — 404 si la fila no existe, **400 si existe pero está inactiva**. Es la **única modificación a código de specs anteriores**, y sale del contrato de SPEC 15, no del de Places.
- `PlaceController::directions()` recibe **los dos contratos por parámetro de método** —`PlaceServiceInterface` y `LocationServiceInterface`—, resuelve el destino activo, pasa sus coordenadas al proveedor y devuelve el `DirectionsResource`. Sin más lógica que ese encadenado.
- `DirectionsResource` con seis claves en camelCase: `locationId`, `locationName`, `distanceKilometers`, `durationHours`, `polyline` y `points`. Envuelve un array, no un modelo, como `PlaceResource` y `FreightQuoteResource`.
- **Orden de fallo, y cada paso con su mensaje:** `locationId` inexistente → 422; destino inactivo → 400; Google sin rutas → 404; cualquier otro fallo del proveedor → 503 con el mismo mensaje genérico de SPEC 12.
- Ampliación del doble `tests/Doubles/InMemoryPlaceService.php` con el tercer método, para que el Feature test siga pasando entero sin una sola petición HTTP.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`, más `tests/Unit/PolylineDecoderTest.php` escrito con el paso que lo introduce.
- Resumen de integración para el frontend, añadido a `references/places-api.md`.

**Fuera de alcance (para specs futuras):**

- **Cotizar.** Este endpoint no toca `freight_rates`, no lee el precio del combustible y no devuelve ningún importe. Que un flete se cobre por kilómetro recorrido, si algún día se quiere, es otra spec.
- **Vehículo, piloto y viaje.** El origen es un par de coordenadas sueltas: no se asocia a un `Vehicle`, a un `User` ni a un viaje programado, y nada de eso se persiste.
- **Rutas alternativas** (`computeAlternativeRoutes`), **waypoints intermedios**, paradas múltiples y optimización del orden de paradas.
- **Matriz de distancias** (`computeRouteMatrix`): un origen y un destino por llamada. Calcular la ruta a diez destinos son diez llamadas del cliente.
- **Origen como destino registrado.** El origen nunca es un `locationId`: siempre son coordenadas. Tampoco hay ruta inversa ni viaje redondo.
- **Tráfico en vivo, hora de salida o de llegada y ETA.** `TRAFFIC_UNAWARE` significa que la misma consulta devuelve lo mismo a las 3 de la mañana y en hora pico.
- **Modos que no sean `DRIVE`:** a pie, bicicleta, moto y transporte público.
- **Preferencias de trazado:** evitar peajes, autopistas o ferries, y restricciones de vehículo por peso, altura o tipo de carga.
- **Instrucciones paso a paso.** No hay `legs`, ni `steps`, ni maniobras, ni texto de navegación: la respuesta es una línea, no un itinerario narrado.
- **Simplificar la polilínea por cuenta propia** (Douglas-Peucker o similar) y pedir `HIGH_QUALITY`. Se pide `OVERVIEW` y se devuelve tal cual.
- **Caché de rutas y persistencia del resultado.** Ni tabla, ni historial, ni «últimas rutas consultadas».
- **Throttle, cuotas, límite por usuario y métricas de gasto.** Siguen fuera, como en SPEC 12, y ahora sobre una llamada que cuesta más.
- **Un segundo proveedor implementado**, idioma configurable y unidades imperiales.
- **Validar que el origen o la ruta caen dentro de una zona de SPEC 08.** Se traza la ruta a donde sea.

---

## Modelo de datos

Esta spec **no crea ninguna tabla, ningún modelo, ninguna migración y ningún enum**. Lo que se documenta aquí son las estructuras en memoria —la forma del contrato, lo que se le manda al proveedor, lo que devuelve el Resource— porque son el contrato que cualquier proveedor futuro tendrá que respetar.

### 1. Constantes nuevas en `GooglePlacesService`

```php
private const ROUTES_URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

/** Ni un campo más: 'routes.*' factura al tier más caro y ata la respuesta a lo que Google decida devolver. */
private const DIRECTIONS_FIELD_MASK = 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline';

private const TRAVEL_MODE = 'DRIVE';

/** SKU Essentials: duración estática por límites de velocidad, misma respuesta a cualquier hora. */
private const ROUTING_PREFERENCE = 'TRAFFIC_UNAWARE';

/** Menos puntos que HIGH_QUALITY al mismo precio; la línea se ve angulosa al hacer mucho zoom. */
private const POLYLINE_QUALITY = 'OVERVIEW';

private const UNITS = 'METRIC';

private const NO_ROUTE_MESSAGE = 'No se encontró una ruta hacia el destino';
```

La credencial, el `timeout`, el `languageCode` y el `regionCode` **se reutilizan** de SPEC 12; no se duplican.

### 2. El tercer método del contrato

```php
/**
 * Ruta por carretera entre dos puntos.
 *
 * @return array{distanceKilometers: float, durationHours: float, polyline: string, points: list<array{0: float, 1: float}>}
 *
 * @throws \App\Errors\NotFoundError  Si no existe ninguna ruta por carretera entre los dos puntos.
 * @throws \App\Errors\ServiceUnavailableError  Si el proveedor falla o responde algo inesperado.
 */
public function getDirections(
    float $originLatitude,
    float $originLongitude,
    float $destinationLatitude,
    float $destinationLongitude,
): array;
```

El contrato **no devuelve `locationId` ni `locationName`**: no sabe que existe un destino registrado. Esas dos claves las pone el Resource a partir del `Location` que resolvió el controller.

`points` nunca es `null` ni una lista vacía: una ruta sin puntos es una respuesta rota y sale como 503, igual que un lugar sin coordenadas en SPEC 12.

### 3. Lo que se le manda a Google

```http
POST https://routes.googleapis.com/directions/v2:computeRoutes
X-Goog-Api-Key: <key>
X-Goog-FieldMask: routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline

{
  "origin":      { "location": { "latLng": { "latitude": 14.6248, "longitude": -90.5152 } } },
  "destination": { "location": { "latLng": { "latitude": 13.9276, "longitude": -90.7853 } } },
  "travelMode": "DRIVE",
  "routingPreference": "TRAFFIC_UNAWARE",
  "polylineQuality": "OVERVIEW",
  "computeAlternativeRoutes": false,
  "units": "METRIC",
  "languageCode": "es",
  "regionCode": "GT"
}
```

Y lo que contesta, con el field mask pedido:

```json
{ "routes": [ { "distanceMeters": 104321, "duration": "6300s", "polyline": { "encodedPolyline": "ktqhB|xnd@..." } } ] }
```

**`duration` llega como cadena con sufijo `s`**, no como número: es la convención de `google.protobuf.Duration`. Se parsea quitando la `s` y validando que lo que queda es numérico; cualquier otra forma es 503.

**Cuando no hay ruta por carretera, Google responde 200 con `{}`** —la clave `routes` ausente— o con `"routes": []`. Los dos casos son el mismo **404**, no un 503: el proveedor funcionó, simplemente no hay camino.

### 4. Conversión de unidades

```php
$distanceKilometers = round($distanceMeters / 1000, 2);   // 104321 → 104.32
$durationHours      = round($durationSeconds / 3600, 2);  //   6300 →   1.75
```

Se redondea **a dos decimales y solo al final**, como el `total` de la cotización de SPEC 09. Dos decimales de hora son 36 segundos de resolución, de sobra para una duración que ya es una estimación estática; dos de kilómetro son 10 metros sobre una ruta de cientos.

Los dos son **números** en el JSON, no cadenas: no son dinero y no hay cast `decimal:` de por medio.

### 5. `PolylineDecoder`

```php
namespace App\Services\Place;

final class PolylineDecoder
{
    /**
     * Decodifica una polilínea de Google a pares [lat, lng].
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function decode(string $polyline): array;
}
```

Implementa el algoritmo estándar de Google: valores enteros con signo en zigzag, troceados en grupos de 5 bits, acumulados como deltas y escalados por `1e5`. Devuelve **lista vacía** para una cadena vacía; nunca lanza.

Sale en **`[lat, lng]`**, el mismo orden que la API ya usa para las zonas de SPEC 08 — la API habla en `[lat, lng]`, y que Google codifique internamente en ese mismo orden es coincidencia, no contrato.

Vive en `app/Services/Place/` porque decodificar el formato del proveedor es conocimiento del proveedor. Es `final` y estática porque no tiene estado ni dependencias, como `Zone::pairsToWkt()`.

### 6. `DirectionsResource`

```php
[
    'locationId',          // 7        — del Location resuelto, no del proveedor
    'locationName',        // 'PUERTO QUETZAL'
    'distanceKilometers',  // 104.32   — número
    'durationHours',       // 1.75     — número
    'polyline',            // 'ktqhB|xnd@...'  — la cadena codificada, tal cual
    'points',              // [[14.6248, -90.5152], [14.6231, -90.5148], ...]
]
```

`polyline` y `points` viajan **los dos** a propósito: la cadena para las librerías de mapa que la consumen directa, los pares para dibujar a mano o para calcular sobre ella sin decodificar en el cliente. Es duplicación consciente de la misma información.

Ninguna clave lleva fechas, así que el formato `d-m-Y h:i:s A` del resto del proyecto no aplica.

### 7. Ampliación de `LocationServiceInterface`

```php
/**
 * Devuelve el destino activo que coincide con el id.
 *
 * Lanza NotFoundError si la fila no existe y BadRequestError si existe pero está
 * inactiva: un destino dado de baja no traza rutas, igual que no cotiza.
 */
public function getActiveLocationById(int $id): Location;
```

Es lo único que esta spec toca de SPEC 15, y va en el contrato de destinos —no en el de Places— porque «qué destino se puede usar» es conocimiento del dominio `Location`.

---

## Plan de implementación

Cada paso deja el sistema arrancable y la suite en verde.

### Paso 1 — `PolylineDecoder`

`app/Services/Place/PolylineDecoder.php` con `decode()` estático, y `tests/Unit/PolylineDecoderTest.php` con él.

Va primero porque **no depende de nada**: ni de Google, ni del contrato, ni de la base. Es el equivalente de `ZoneGeometryTest` — la pieza que se puede verificar entera sin levantar una petición.

*Verificación (unit, sin red):* una polilínea conocida de Google decodifica a sus coordenadas esperadas con 5 decimales; una cadena vacía devuelve `[]`; una polilínea de un solo punto devuelve un solo par; los valores negativos —longitudes de Guatemala, todas negativas— salen con su signo.

### Paso 2 — `getActiveLocationById()`

El método nuevo en `LocationServiceInterface` y en `LocationService`, con su `#[Override]`.

Va aislado y antes que nada más, porque es **lo único que toca código de SPEC 15**.

*Verificación (unit):* un destino activo se devuelve; uno inactivo lanza `BadRequestError`; un id inexistente lanza `NotFoundError`. El resto de métodos de `LocationService` siguen comportándose igual.

### Paso 3 — El contrato y su doble

`getDirections()` en `PlaceServiceInterface` con su PHPDoc, e **inmediatamente** el tercer método en `tests/Doubles/InMemoryPlaceService.php` y un stub en `GooglePlacesService`.

Los tres cambios van en el mismo paso a la fuerza: añadir un método a una interfaz deja a sus implementaciones sin cumplir el contrato, y eso es un error fatal de PHP que tumba la suite entera, no un test en rojo.

*Verificación:* el stub de `GooglePlacesService` lanza `ServiceUnavailableError` hasta el paso siguiente. `php artisan test --compact` sigue verde; ningún test llama todavía al método nuevo.

### Paso 4 — La llamada a `computeRoutes`

`getDirections()` en `GooglePlacesService`: constantes nuevas, `POST` a `computeRoutes` con el field mask acotado, parseo de `distanceMeters` y de la duración `"6300s"`, decodificación de la polilínea con el `PolylineDecoder` del paso 1, y la traducción de errores.

Reutiliza tal cual los privados de SPEC 12: `send()` para los fallos de transporte y `decode()` para el cuerpo. La rama nueva es el 404 cuando no hay rutas.

**El unit test va con este paso**, con `Http::fake()` por escenario: ruta normal, `routes` ausente, `routes: []`, `duration` sin el sufijo `s`, `duration` no numérica, `polyline` ausente o vacía, `distanceMeters` ausente, timeout, 403 de clave rechazada, 429 de cuota y 500 de Google.

*Verificación:* la respuesta normal devuelve las cuatro claves con `distanceKilometers` y `durationHours` como `float` y `points` no vacío; `routes` ausente y `routes: []` devuelven `NotFoundError`; todas las demás formas rotas devuelven `ServiceUnavailableError`; la clave no aparece en la URL; se hace **exactamente una** petición saliente por llamada, también cuando falla.

### Paso 5 — FormRequest

`app/Http/Requests/Place/GetDirectionsRequest.php` con las tres reglas y `messages()` en español.

*Verificación:* los tres parámetros ausentes dan 422 y **ninguna petición sale a Google**.

### Paso 6 — Resource

`app/Http/Resources/Place/DirectionsResource.php` con las seis claves, envolviendo un array como `PlaceResource`.

### Paso 7 — Controller y ruta

`PlaceController::directions()` con `try/catch` → `ResponseHandler` y los dos contratos por parámetro de método, y la ruta `GET /directions` en `routes/places.php` **antes** del `apiResource`.

*Verificación:* `php artisan route:list --path=places` lista **tres** rutas, y `/api/places/directions` no cae en `places.show`.

### Paso 8 — Formato

`vendor/bin/pint --dirty --format agent`.

### Paso 9 — Tests

Disparar el agente `feature-tests` con el dominio `Place`, indicándole que **el dominio no tiene modelo, factory ni tabla propios**, que el único modelo que interviene es `Location` (con su factory y sus estados `active()`/`inactive()`), y que no debe generar casos de `store`, `update` ni `destroy`.

`tests/Feature/PlaceTest.php` crece con: 401 sin token, 200 con los cuatro roles y con un `carrier` sin empresa, 422 por cada parámetro, 400 del destino inactivo, 404 del destino sin ruta, 503 del proveedor caído, y el 200 completo con `points` decodificados — todo con `InMemoryPlaceService` bindeado, sin una sola petición HTTP.

### Paso 10 — Documentación

Disparar el agente `endpoint-docs` con el dominio `Place` y regenerar `storage/api-docs/api-docs.json`. La documentación debe dejar claro que **este endpoint no cotiza**, que `durationHours` es una estimación **sin tráfico**, y que `polyline` y `points` son la misma línea en dos formatos.

Cerrar añadiendo el endpoint a `references/places-api.md`.

---

## Criterios de aceptación

**`PolylineDecoder`**

- [ ] Una polilínea codificada conocida decodifica a los pares `[lat, lng]` esperados con 5 decimales.
- [ ] Una cadena vacía devuelve `[]` y no lanza.
- [ ] Las longitudes negativas salen con su signo, no en valor absoluto.
- [ ] Los pares salen en orden `[lat, lng]`, no `[lng, lat]`.
- [ ] El test unitario pasa **sin red y sin base de datos**.

**Contrato**

- [ ] `PlaceServiceInterface` declara `getDirections()` con las cuatro coordenadas como `float`.
- [ ] El contrato **no recibe ni devuelve** `locationId`, `Location` ni nada de Eloquent.
- [ ] `GooglePlacesService` e `InMemoryPlaceService` implementan los **tres** métodos.
- [ ] Bindeando `InMemoryPlaceService`, `tests/Feature/PlaceTest.php` pasa entero sin una sola petición HTTP.

**`getActiveLocationById()`**

- [ ] Un destino activo se devuelve.
- [ ] Un destino inactivo lanza `BadRequestError` → **400**.
- [ ] Un id inexistente lanza `NotFoundError` → **404**.
- [ ] Los demás métodos de `LocationService` responden igual que antes de esta spec.

**`GET /api/places/directions`**

- [ ] Con `locationId`, `lat` y `lng` válidos responde **200** con exactamente `locationId`, `locationName`, `distanceKilometers`, `durationHours`, `polyline` y `points`.
- [ ] `distanceKilometers` y `durationHours` son **números** en el JSON, no cadenas, con 2 decimales.
- [ ] `104321` metros salen como `104.32`; `"6300s"` sale como `1.75`.
- [ ] `points` es una lista de pares `[lat, lng]` y **nunca** viene vacía en un 200.
- [ ] `polyline` es la cadena codificada de Google, sin modificar.
- [ ] `locationId` y `locationName` corresponden al destino de la base, no a nada que devuelva el proveedor.
- [ ] Se manda **un solo** `POST` a `https://routes.googleapis.com/directions/v2:computeRoutes`.
- [ ] El cuerpo enviado lleva `origin` y `destination` anidados como `location.latLng`, `travelMode: DRIVE`, `routingPreference: TRAFFIC_UNAWARE`, `polylineQuality: OVERVIEW`, `computeAlternativeRoutes: false`, `units: METRIC`, `languageCode: es` y `regionCode: GT`.
- [ ] La cabecera `X-Goog-FieldMask` vale `routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline`; **nunca `*`** ni `routes.*`.
- [ ] La clave viaja en `X-Goog-Api-Key` y **no aparece en la URL**.
- [ ] El `destination` que se manda son las coordenadas del `Location`, no las del origen ni al revés.

**Validación**

- [ ] `locationId`, `lat` o `lng` ausentes responden **422** y **no se hace ninguna petición a Google**.
- [ ] `locationId` inexistente responde **422** por `exists:locations,id`, no 404.
- [ ] `lat` fuera de `[-90, 90]` y `lng` fuera de `[-180, 180]` responden **422**.
- [ ] `lat=0&lng=0` es válido y llega a Google: cero no es «ausente».
- [ ] Los mensajes del 422 están en español.
- [ ] Parámetros adicionales (`travelMode`, `polylineQuality`, `limit`) se **ignoran** y no alteran la petición al proveedor.

**Fallos, en orden**

- [ ] Destino **inactivo** responde **400** con mensaje propio, y **no se llama a Google**.
- [ ] Google responde 200 sin la clave `routes` → **404** «No se encontró una ruta hacia el destino».
- [ ] Google responde 200 con `routes: []` → el **mismo 404**.
- [ ] Timeout, error de conexión, `401`/`403`, `429` y `500` de Google → **503** con el mensaje genérico de SPEC 12.
- [ ] Una `duration` sin sufijo `s`, no numérica o ausente → **503**, no una duración en `0`.
- [ ] Un `distanceMeters` ausente o no numérico → **503**.
- [ ] Una `polyline` ausente o vacía → **503**, no un `points: []` en un 200.
- [ ] Ningún 503 filtra el cuerpo de error de Google, la URL ni la clave.
- [ ] Ninguna excepción de Guzzle escapa al `catch` genérico del controller: el cliente nunca ve un 500 por un fallo del proveedor.
- [ ] Un fallo genera **exactamente una** petición saliente: no hay reintentos.

**Autorización**

- [ ] Sin token responde **401** con el sobre estándar.
- [ ] Responde 200 con token de `administrator`, `carrier`, `pilot` y `manager`.
- [ ] Un `carrier` **sin empresa registrada** lo alcanza: la ruta no lleva `carrier.required`.

**Aislamiento del proveedor**

- [ ] Ningún archivo fuera de `app/Services/Place/` menciona `Http::`, `googleapis.com`, `X-Goog-`, `computeRoutes` ni `encodedPolyline`.
- [ ] `PlaceController`, `DirectionsResource`, `GetDirectionsRequest` y `routes/places.php` no nombran a Google.
- [ ] `.env.example` **no cambia**: no hay credencial nueva.

**Cierre**

- [ ] `php artisan route:list --path=places` muestra **tres** rutas, y `/api/places/directions` resuelve a `directions`, no a `places.show`.
- [ ] `php artisan test --compact` pasa la suite entera, incluidas las quince specs anteriores.
- [ ] Con `Http::preventStrayRequests()` activo, la suite sigue verde: ningún test sale a la red.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `/api/documentation` muestra los tres endpoints del dominio, con el 404, el 400 y el 503 documentados y distinguidos.

---

## Decisiones tomadas y descartadas

### Embebido en `Place`, no un dominio propio

**Descartado:** un dominio `Directions` con su contrato, su provider, su controller y su `routes/directions.php`.

Habría separado dos APIs de Google que se facturan y fallan por separado, y era la opción que este documento recomendaba al principio. Se rechazó porque para el consumidor esto es **la misma capacidad**: el front ya está en `/api/places` buscando y resolviendo direcciones, y trazar la ruta al destino elegido es el paso siguiente del mismo flujo. Un dominio nuevo con un solo endpoint habría duplicado contrato, provider, doble de test y tag de Swagger para tres métodos que comparten credencial, timeout, traducción de errores y mensaje genérico de 503.

**Consecuencia asumida:** `PlaceServiceInterface` tiene tres métodos y cualquier proveedor sustituto los implementa los tres. Un proveedor que solo supiera buscar direcciones no encaja sin lanzar en el tercero.

### La misma credencial para las dos APIs

**Descartado:** `GOOGLE_ROUTES_API_KEY` aparte, que habría permitido restringir cada clave a su API en la consola de Google y que la caída de una no arrastrara a la otra.

Se prefirió una sola variable: es un despliegue menos que configurar y un secreto menos que rotar. **El riesgo se asume y está anotado abajo**: revocar o agotar esa clave apaga la búsqueda y las rutas a la vez.

### El contrato ve cuatro números, no un `locationId`

**Descartado:** `getDirections(int $locationId, float $lat, float $lng)` con `GooglePlacesService` inyectando `LocationServiceInterface` por constructor, como hacía `FreightRateService` con `ZoneServiceInterface` en SPEC 09.

Habría dejado el controller con una sola llamada, pero ata el contrato del proveedor a la tabla `locations`: un `MapboxPlacesService` tendría que aprender a resolver destinos activos para poder sustituirlo, y eso no es capacidad del proveedor, es dominio del proyecto. El contrato recibe cuatro `float` y devuelve una ruta; quién es ese destino y si está activo lo decide `LocationService`.

**Consecuencia asumida:** `PlaceController::directions()` encadena dos servicios. Es orquestación —resolver, delegar, responder—, no lógica de negocio, y por eso sigue cabiendo en un controller de este proyecto.

### `getActiveLocationById()` en el contrato de destinos, no en el de Places

«Un destino inactivo no se usa» es una regla del dominio `Location`, no de las direcciones. Ponerla en `LocationServiceInterface` la deja disponible para el siguiente consumidor que la necesite, y mantiene esta spec con **una sola modificación** a código publicado.

### Destino inactivo es 400, no 404

Un destino dado de baja **existe**: está en el listado, tiene tarifas y se puede reactivar con `/toggle-status`. Contestar 404 diría que no existe y mandaría al front a buscar un id que sí es válido. Es la misma decisión que la cotización de SPEC 15 y que el precio de combustible ya inactivo de SPEC 06: fila viva, uso prohibido, 400 con su mensaje.

### `locationId` inexistente es 422, no 404

Sale de `exists:locations,id` en el FormRequest, igual que en la cotización de SPEC 15. La consecuencia deliberada es que **un id inexistente y un id inactivo responden distinto** —422 y 400—, y que el 422 llega con el formato `{ message, errors }` de Laravel en vez del sobre del proyecto. Se acepta por consistencia con el resto de endpoints que ya validan destinos.

### Sin ruta es 404, no 503

Google responde 200 con `routes` ausente o vacío cuando no hay camino por carretera —un destino en una isla, en otro continente, o un origen en mitad del mar—. El proveedor **funcionó**: el 503 mentiría y haría que el front reintentara algo que va a fallar igual las veces que quiera. 404 con mensaje propio dice lo que pasa: no hay ruta, cambia el origen.

Es la contrapartida de la decisión inversa de SPEC 12, donde una búsqueda sin resultados es 200 con lista vacía: allí no encontrar nada es el caso normal mientras el usuario escribe; aquí, con un destino ya elegido, no encontrar ruta es excepcional.

### `TRAFFIC_UNAWARE`, no tráfico en vivo

**Descartado:** `TRAFFIC_AWARE`, que da la duración con el tráfico del momento.

Cuesta bastante más —sube del SKU Essentials al Pro—, y devuelve una respuesta que **cambia cada vez que se consulta**: dos usuarios mirando la misma ruta con media hora de diferencia verían tiempos distintos, y ninguno de los dos podría contrastar nada. Para planificar y estimar, una duración estable por límites de velocidad es más útil que una precisa e irrepetible.

**Consecuencia asumida:** `durationHours` es una estimación optimista. No sirve como ETA y la documentación tiene que decirlo.

### `OVERVIEW`, no `HIGH_QUALITY`

Las dos cuestan lo mismo, así que esto no es dinero: es peso. Una ruta de 100 km en alta calidad decodifica a miles de puntos y convierte una respuesta de 1 KB en una de más de 100 KB, sobre una línea que el front dibuja encima de un mapa donde la diferencia no se ve salvo con mucho zoom.

**Descartado también:** simplificar la polilínea por cuenta propia con Douglas-Peucker. Es una decisión con parámetro propio —la tolerancia— y merece su spec el día que `OVERVIEW` no baste; pedirle a Google la versión ligera es gratis y no añade código.

### `polyline` y `points`, los dos

Es la misma línea dos veces, y es duplicación consciente. La cadena la consumen directas las librerías de mapa; los pares sirven para dibujar a mano, para recortar, para medir o para cualquier cosa que el front quiera hacer sin arrastrar un decodificador.

**Descartado:** devolver solo `points` y ahorrar la cadena. Obligaría a re-codificar en el cliente para cualquier librería que espere el formato de Google, que es lo que la mitad de ellas espera.

**Descartado:** devolver solo `polyline`. Es lo barato en bytes, pero mete un algoritmo de decodificación en el front por cada plataforma que consuma la API.

### El decodificador es una clase aparte

**Descartado:** un método privado dentro de `GooglePlacesService`.

Habría funcionado, pero solo se podría probar a través de un `Http::fake()`: cada caso del algoritmo —cadena vacía, un punto, deltas negativos— exigiría montar una respuesta falsa del proveedor para verificar treinta líneas de aritmética. Como clase `final` con un método estático se prueba directa, igual que `Zone::pairsToWkt()` se prueba sin PostGIS.

Vive en `app/Services/Place/` y no en un helper genérico porque el formato es de Google: es conocimiento del proveedor, y la regla de esta spec es que ese conocimiento no sale de esa carpeta.

### Un origen suelto, no un vehículo ni un piloto

El origen son dos números. **Descartado:** aceptar un `vehicleId` o un `pilotId` y resolver su posición, que era la extensión obvia.

Este endpoint es geometría, no operación: no sabe quién viaja, no registra que se consultó y no deja rastro. El día que haya viajes programados con un vehículo asignado y una posición conocida, ese dominio llamará a este con las coordenadas que ya tenga.

### Sin caché, otra vez

Misma razón que en SPEC 12 y una más: los términos de Google prohíben almacenar el contenido de una ruta, y una ruta calculada sin tráfico solo es estable mientras la carretera no cambie. Cachear aquí exige decidir qué se guarda y cuánto vive, y eso es una spec, no un párrafo.

### Sin `limit`, sin paginación, sin filtros

El endpoint devuelve **un** objeto. No hay listado, así que no hay nada que paginar y `limit` se ignora, como en los otros dos endpoints del dominio.

---

## Riesgos identificados

### Una sola clave para dos APIs facturadas por separado

Compartir `services.google_places.key` entre Places y Routes significa que **cualquier cosa que le pase a esa clave apaga las dos cosas a la vez**: si se revoca, si se agota la cuota, si alguien la restringe en la consola a Places y se olvida de Routes, o si un bucle de peticiones de rutas —que cuestan más que una búsqueda— quema el presupuesto del mes, el usuario se queda sin buscar direcciones **y** sin trazar rutas, con el mismo 503 genérico para ambas.

**Mitigación:** ninguna dentro del código, y es la consecuencia aceptada de la decisión. Queda como norma operativa: si la clave se restringe por API en la consola de Google, **hay que habilitar las dos**, y el día que el gasto duela, separar las claves es una variable de entorno nueva y un `config()` distinto, no un rediseño. Que el contrato sea uno solo no impide que la implementación lea dos credenciales.

### Cada llamada cuesta más que una búsqueda, y sigue sin haber tope

Un usuario autenticado puede pedir rutas en bucle. No hay throttle, ni límite por usuario, ni contador, exactamente igual que en SPEC 12 — pero aquí cada llamada se factura al SKU de rutas, más caro que una búsqueda de texto, y **el 422 previo protege menos**: un `locationId` válido y unas coordenadas cualesquiera son triviales de generar en masa, mientras que un `search` de tres letras al menos exige escribir algo.

**Mitigación:** parcial. El field mask acotado y `TRAFFIC_UNAWARE` mantienen cada llamada en el tramo barato de su API, y la validación previa corta las peticiones malformadas antes de salir. Lo que no hay es tope. La salida natural el día que duela es la misma que en SPEC 12 — un `throttle:` sobre el grupo de rutas de `places` —, y ahora hay más razón para ponerlo.

### `durationHours` se va a leer como un ETA

Se devuelve una duración en horas junto a una distancia y una línea sobre un mapa. Todo en esa respuesta invita a tratarla como «a qué hora llega el camión», y **no lo es**: `TRAFFIC_UNAWARE` calcula sobre límites de velocidad, sin tráfico, sin paradas, sin descansos y sin la diferencia entre un camión cargado y un coche. En un trayecto real por carretera guatemalteca el número se va a quedar corto, sistemáticamente y siempre en la misma dirección.

**Mitigación:** decirlo donde se lee. La documentación del endpoint marca la duración como estimación sin tráfico, y el front debería etiquetarla igual. Si algún día se necesita un ETA de verdad, la salida es `TRAFFIC_AWARE` con su hora de salida y su coste — y es una decisión distinta, no un ajuste.

### `OVERVIEW` es una apuesta sobre el peso que nadie ha medido

Se eligió la polilínea ligera para que la respuesta no pese cientos de kilobytes, pero **no hay ninguna medición real** de cuántos puntos devuelve `OVERVIEW` en una ruta larga de este país. Si resulta que para 300 km sigue siendo demasiado detalle, la respuesta seguirá siendo pesada; y si resulta que es demasiado poco, la línea se verá cortando curvas al hacer zoom.

**Mitigación:** el cambio entre `OVERVIEW` y `HIGH_QUALITY` es **una constante de clase**, sin coste distinto y sin tocar nada más. Se fija en el Paso 4 mirando la respuesta real de una ruta larga, y se corrige después con un solo cambio de línea si el front se queja.

### Un cambio en la forma de la respuesta rompe el endpoint entero, y los tests no lo verán

Igual que en SPEC 12: se invalida la respuesta completa cuando falta un campo, así que el día que Google renombre `encodedPolyline`, cambie el formato de `duration` o mueva `distanceMeters`, el endpoint devuelve **503 para todo el mundo a la vez**. Y aquí hay un campo más frágil que en Places: `duration` llega como la cadena `"6300s"`, un formato de protobuf que el parseo asume; cualquier variante —`"6300.5s"`, un objeto, un entero pelado— cae en el 503.

**Mitigación:** parcial y la misma. Los `Http::fake()` son fixtures congelados con la forma de hoy y seguirán verdes mientras producción está caída. El 503 es al menos inmediato y visible en vez de devolver distancias en cero. La defensa real es leer los avisos de deprecación de Google.

### El origen no se valida contra nada real

`lat` y `lng` solo se comprueban contra su rango. Un origen en mitad del Pacífico pasa la validación, sale a Google y vuelve como **404 «no se encontró una ruta»**, que es correcto pero no dice que el problema era el punto de partida. Nada comprueba que el origen esté en Guatemala, ni en tierra firme, ni cerca de una carretera.

**Mitigación:** ninguna, y es deliberada. Validar el origen contra las zonas de SPEC 08 metería PostGIS en un dominio que no lo conoce, y además excluiría orígenes legítimos fuera de zona cubierta. El 404 con su mensaje es suficiente; el front sabe qué punto mandó.

### Añadir un método a `PlaceServiceInterface` rompe todo hasta que se implementa

No es un riesgo de producción sino del desarrollo, y vale la pena escribirlo: en cuanto el método entra en la interfaz, `GooglePlacesService` e `InMemoryPlaceService` dejan de cumplir el contrato y PHP tira un **error fatal** que tumba la suite entera, no un test en rojo.

**Mitigación:** el Paso 3 mete los tres cambios juntos —interfaz, doble y stub del service— y no se da por terminado hasta que la suite vuelve a verde.

---

## Lo que **no** entra en esta spec

Repetición deliberada de lo ya dicho en el alcance:

- Cotizar, calcular importes o tocar `freight_rates`.
- Vehículo, piloto, viaje programado y cualquier persistencia del resultado.
- Rutas alternativas, waypoints intermedios, paradas múltiples y optimización de paradas.
- Matriz de distancias (`computeRouteMatrix`) y varios destinos por llamada.
- Origen como `locationId`, ruta inversa y viaje redondo.
- Tráfico en vivo, hora de salida o llegada y ETA real.
- Modos de viaje distintos de `DRIVE`.
- Evitar peajes, autopistas o ferries, y restricciones por peso, altura o carga.
- Instrucciones paso a paso, `legs`, `steps` y texto de navegación.
- Simplificación propia de la polilínea y `HIGH_QUALITY`.
- Caché de rutas e historial de consultas.
- Throttle, cuotas, límite por usuario y métricas de gasto.
- Un segundo proveedor implementado, idioma configurable y unidades imperiales.
- Validar el origen o la ruta contra las zonas de SPEC 08.

Cada uno de ellos, si entra, va en su propia spec.
