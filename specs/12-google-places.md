# SPEC 12 — Búsqueda de direcciones con Google Places

> **Estado:** Implementado
> **Depende de:** SPEC 01
> **Fecha:** 2026-08-17
> **Objetivo:** Exponer dos endpoints de solo lectura que consultan la API de Google Places para buscar direcciones por texto y resolver las coordenadas de la dirección elegida, detrás de un contrato sustituible por otro proveedor.

Depende de **SPEC 01** por el guard JWT y el middleware `jwt.auth`: los dos endpoints están abiertos a cualquier usuario autenticado, sin rol ni empresa.

Es la primera spec del proyecto que **sale a una API de terceros**. Hasta ahora la única dependencia externa era S3 (SPEC 05), y llega a través de un contrato del framework; aquí el backend hace peticiones HTTP salientes por su cuenta, paga por cada una y depende de un servicio que puede estar caído. Por eso adopta el mismo patrón que SPEC 05: un contrato por capacidad (`PlaceServiceInterface`) y una implementación por proveedor (`GooglePlacesService`), sustituible sin tocar nada más.

Es también la **primera spec sin tabla, sin modelo y sin migración**. No persiste nada: es un proxy de lectura.

Su consumidor natural es SPEC 09 — el usuario escribe su destino, elige una dirección, obtiene `latitude` y `longitude`, y con eso llama a `GET /api/freight-rates/quote`. Esa cadena la arma el front; esta spec **no** cotiza nada.

---

## Alcance

**Dentro:**

- **Ninguna migración, ningún modelo y ninguna tabla.** Esta spec no persiste nada: las dos rutas son proxies de lectura sobre Google Places.
- Credencial nueva en `config/services.php` bajo `services.google_places.key`, leída de `GOOGLE_PLACES_API_KEY`, y documentada en `.env.example`.
- **Contrato sustituible**, siguiendo SPEC 05: `App\Interfaces\Place\PlaceServiceInterface` con dos métodos, e implementación `App\Services\Place\GooglePlacesService` bindeada por `App\Providers\Place\PlaceProvider`. El contrato nombra la **capacidad**, no al proveedor.
- El PHPDoc del contrato escribe las reglas de sustitución igual que los contratos de almacenamiento: qué acepta cada método, qué garantiza devolver y qué excepciones de `App\Errors` puede lanzar. Cualquier implementación que las cumpla entra en el `bind` sin tocar nada más.
- **Dos endpoints, los dos con `jwt.auth` a secas** — sin `role:` y sin `carrier.required`:
  - `GET /api/places?search=` — hasta **10** direcciones, cada una con su `id` y su dirección formateada.
  - `GET /api/places/{place}` — la dirección y las coordenadas de ese `id`.
- `{place}` es el **place id de Google** (`ChIJ...`), una cadena opaca. No se valida su forma: si no corresponde a ningún lugar, es 404.
- `SearchPlacesRequest` valida la query: `search` → `required|string|min:3|max:200`, con `messages()` en español. **Un `search` inválido es 422 y no llega a Google**, que cobra por llamada.
- `PlacePredictionResource` (`id`, `formattedAddress`) y `PlaceResource` (`id`, `formattedAddress`, `latitude`, `longitude`), los dos en camelCase y dentro del sobre `{ statusCode, message, data }` de siempre.
- **Field mask acotado en las dos llamadas**: `places.id,places.formattedAddress` en la búsqueda y `id,formattedAddress,location` en el detalle. Nunca `*`.
- **Sesgo a Guatemala, no restricción:** la búsqueda manda `languageCode: 'es'` y `regionCode: 'GT'`. Prioriza resultados guatemaltecos sin excluir los de fuera.
- `pageSize: 10` fijo en la petición a Google.
- **Clase de error nueva `App\Errors\ServiceUnavailableError` (503)**, mapeada en `ResponseHandler`. Es la primera vez que el proyecto necesita decir "el fallo no es tuyo".
- **Todo fallo del proveedor sale como 503** con mensaje en español: timeout, error de conexión, clave inválida o rechazada, cuota agotada, 5xx de Google y respuesta con forma inesperada.
- **`timeout` de 10 segundos** en las dos llamadas, sin reintentos. Es cuánto espera el backend a Google, no una espera impuesta al usuario: **no hay límite de búsquedas ni throttle de ningún tipo**.
- Una búsqueda sin resultados responde **200 con `data: []`**; un `place` inexistente o mal formado responde **404**.
- `routes/places.php` incluido desde `routes/api.php`, declarado como `apiResource` con `->only(['index', 'show'])`.
- **Ningún archivo fuera de `app/Services/Place/` menciona `Http::`, `googleapis.com`, `X-Goog-` ni el nombre Google**, igual que ninguno fuera de `app/Services/Storage/` menciona `Storage::`.
- `Http::fake()` y `Http::preventStrayRequests()` globales en `tests/Pest.php`: ningún test sale a la red ni gasta cuota, ni siquiera por accidente.
- Doble del contrato en `tests/Doubles/` para probar la sustituibilidad y los caminos de error sin hablar HTTP.
- Tests Pest y documentación Swagger delegados a los agentes `feature-tests` y `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **Caché de resultados.** Los términos de Google prohíben almacenar datos de un lugar más de 30 días — el `place_id` es la única excepción —, así que un caché exige su propia decisión sobre qué se guarda y por cuánto.
- **Guardar destinos frecuentes o favoritos.** No hay tabla `places` ni historial de búsquedas.
- **La API de Autocomplete** (`places:autocomplete`) y los session tokens que abaratan el flujo escribir→elegir. Aquí se usa `places:searchText`, que es lo que ya usas.
- **Paginación de resultados** y `nextPageToken`. Diez y se acabó.
- **Campos extra del lugar:** `displayName`, `addressComponents`, `types`, horarios, teléfono, fotos y reseñas.
- **Geocodificación inversa** (coordenadas → dirección) y cálculo de rutas, distancias o matrices de distancia.
- **`locationRestriction` con el bounding box de Guatemala.** Se prefiere el sesgo, que no rompe una búsqueda fronteriza legítima.
- **Un segundo proveedor implementado.** El contrato permite cambiarlo; esta spec solo trae el de Google.
- **Idioma configurable por usuario.** `es` es fijo.
- **Límite de búsquedas por usuario, cuotas, throttle y control de gasto.** Cualquier autenticado puede buscar cuantas veces quiera.
- **Métricas, alertas o registro del consumo** de la API de Google.
- **Enlazar la búsqueda con la cotización de SPEC 09.** El front encadena los dos endpoints; el backend no.
- **Validar que el punto devuelto cae dentro de una zona registrada** (SPEC 08). Google puede devolver una dirección que ninguna zona cubre, y esta spec la devuelve igual.

---

## Modelo de datos

Esta spec **no crea ninguna tabla, ningún modelo, ninguna migración y ningún enum**. Lo único que aparece son estructuras en memoria: la configuración, las dos formas que devuelve el contrato y los dos Resources. Se documentan aquí porque son el contrato que cualquier proveedor futuro tendrá que respetar.

### 1. Configuración

```php
// config/services.php
'google_places' => [
    'key' => env('GOOGLE_PLACES_API_KEY'),
],
```

```dotenv
# .env.example
GOOGLE_PLACES_API_KEY=
```

**La credencial es lo único que sale de `config`.** El resto —URL base, timeout, field masks, `pageSize`, `languageCode` y `regionCode`— son constantes de clase de `GooglePlacesService`, como `SIDE` y `JPEG_QUALITY` en SPEC 05: son conocimiento del proveedor, no configuración del entorno.

```php
final class GooglePlacesService implements PlaceServiceInterface
{
    private const BASE_URL = 'https://places.googleapis.com/v1/places';
    private const TIMEOUT_SECONDS = 10;
    private const PAGE_SIZE = 10;
    private const LANGUAGE_CODE = 'es';
    private const REGION_CODE = 'GT';
    private const SEARCH_FIELD_MASK = 'places.id,places.formattedAddress';
    private const DETAIL_FIELD_MASK = 'id,formattedAddress,location';
}
```

### 2. El contrato

```php
namespace App\Interfaces\Place;

interface PlaceServiceInterface
{
    /**
     * @return list<array{id: string, formattedAddress: string}>  Vacío si no hay coincidencias.
     * @throws \App\Errors\ServiceUnavailableError  Si el proveedor falla o responde algo inesperado.
     */
    public function searchPlaces(string $search): array;

    /**
     * @return array{id: string, formattedAddress: string, latitude: float, longitude: float}
     * @throws \App\Errors\NotFoundError  Si el id no corresponde a ningún lugar.
     * @throws \App\Errors\ServiceUnavailableError  Si el proveedor falla o responde algo inesperado.
     */
    public function getPlaceById(string $placeId): array;
}
```

Las claves ya viajan en **camelCase** desde el contrato: no hay modelo Eloquent de por medio, así que no hay ninguna traducción snake_case → camelCase que hacer. `searchPlaces()` devuelve lista vacía, **nunca `null`**, y `getPlaceById()` devuelve siempre las cuatro claves o lanza.

Son las reglas de sustitución: un `MapboxPlacesService` que devolviera `null` en vez de `[]`, o que omitiera `latitude` cuando el proveedor no la trae, rompería a sus consumidores y no sería sustituible.

### 3. Lo que se le manda a Google

```http
POST https://places.googleapis.com/v1/places:searchText
X-Goog-Api-Key: <key>
X-Goog-FieldMask: places.id,places.formattedAddress

{ "textQuery": "zona 4 guatemala", "languageCode": "es", "regionCode": "GT", "pageSize": 10 }
```

```http
GET https://places.googleapis.com/v1/places/ChIJ...
X-Goog-Api-Key: <key>
X-Goog-FieldMask: id,formattedAddress,location
```

La búsqueda es `POST` **hacia Google** aunque el endpoint del proyecto sea `GET`: lo exige `searchText`. La clave viaja en la cabecera `X-Goog-Api-Key`, nunca en la query, que acabaría en logs y en el historial de cualquier proxy.

La respuesta de la búsqueda trae `{ "places": [ { "id": ..., "formattedAddress": ... } ] }`, y **omite la clave `places` por completo** cuando no hay coincidencias. La del detalle trae `{ "id", "formattedAddress", "location": { "latitude", "longitude" } }`.

### 4. Resources

```php
// PlacePredictionResource — un elemento del listado
[
    'id',                 // 'ChIJk4h8_Q6ii4ARZ4gGpXY8bJ0'
    'formattedAddress',   // '5a Avenida 12-38, Zona 4, Ciudad de Guatemala'
]

// PlaceResource — el detalle
[
    'id',
    'formattedAddress',
    'latitude',           // 14.6248  — float, no cadena
    'longitude',          // -90.5152
]
```

El detalle sale **plano**, no anidado en `geometry.location` como el formato legacy: `latitude` y `longitude` son lo que el front pasa como `lat` y `lng` a `GET /api/freight-rates/quote` de SPEC 09.

Las coordenadas viajan como **número**, no como cadena. No son dinero y no hay cast `decimal:` de por medio: son lo que devolvió Google, tal cual.

Ninguno de los dos Resources tiene fechas, así que el formato `d-m-Y h:i:s A` de SPEC 07–11 no aplica aquí.

### 5. La clase de error nueva

```php
namespace App\Errors;

class ServiceUnavailableError extends ApiException
{
    public function getStatusCode(): int
    {
        return 503;
    }
}
```

Se suma al mapeo de `ResponseHandler::error()` junto a las cinco que ya existen. Es la única modificación de esta spec a código de specs anteriores.

---

## Plan de implementación

Cada paso deja el sistema arrancable y la suite en verde.

### Paso 1 — Credencial

`services.google_places.key` en `config/services.php` leyendo `GOOGLE_PLACES_API_KEY`, y la variable documentada en `.env.example` con valor vacío.

*Verificación:* `php artisan config:show services.google_places` la muestra, y la suite existente sigue verde.

### Paso 2 — `ServiceUnavailableError`

`app/Errors/ServiceUnavailableError.php` extendiendo `ApiException` con `getStatusCode(): 503`, y su rama en el mapeo de `ResponseHandler::error()`.

Va aislado y antes que nada más, porque es lo único que toca código de specs anteriores.

*Verificación:* un unit test lanza la excepción a `ResponseHandler::error()` y comprueba que sale un 503 con el sobre `{ statusCode, message, data }` de siempre. Las cinco excepciones existentes siguen mapeando a lo mismo.

### Paso 3 — El contrato

`app/Interfaces/Place/PlaceServiceInterface.php` con `searchPlaces()` y `getPlaceById()`, cada uno con su PHPDoc de array shapes y sus `@throws`. Sin implementación todavía.

### Paso 4 — Cerrar la red en los tests

`Http::fake()` y `Http::preventStrayRequests()` globales en `tests/Pest.php`, junto a `Mail::fake()` y `fakeDefaultDisk()`.

Va **antes** de escribir la implementación, no después: a partir de aquí, cualquier petición saliente que un test no haya declarado explícitamente revienta el test en vez de salir a internet y gastar cuota.

*Verificación:* la suite entera sigue verde — hoy nadie hace peticiones salientes, así que el cambio no debe alterar nada.

### Paso 5 — `GooglePlacesService`, búsqueda y traducción de errores

`searchPlaces()` con el `POST` a `:searchText`, la cabecera `X-Goog-Api-Key`, el field mask acotado, `languageCode`, `regionCode`, `pageSize` y `timeout(10)`, sin reintentos. Aquí va también el método privado que traduce cualquier fallo del proveedor a `ServiceUnavailableError`, que el detalle reutiliza.

**El unit test va con este paso**, antes que las capas HTTP, con `Http::fake()` por escenario: resultados normales, respuesta sin la clave `places`, respuesta con un elemento al que le falta `id` o `formattedAddress`, timeout, 403 de clave rechazada, 429 de cuota y 500 de Google.

*Verificación:* una respuesta sin la clave `places` devuelve `[]`; las cinco formas de fallo devuelven `ServiceUnavailableError` y ninguna deja escapar la excepción original de Guzzle.

### Paso 6 — `GooglePlacesService`, detalle

`getPlaceById()` con el `GET` a `/{id}`, su field mask, y la traducción del 404 y el 400 de Google a `NotFoundError`. Todo lo demás sigue cayendo en el `ServiceUnavailableError` del paso anterior.

*Verificación (unit, con `Http::fake()`):* una respuesta completa devuelve las cuatro claves con `latitude` y `longitude` como `float`; un 404 y un 400 de Google devuelven `NotFoundError`; una respuesta 200 **sin** `location` devuelve `ServiceUnavailableError`, no un `latitude` en `null`.

### Paso 7 — Provider

`app/Providers/Place/PlaceProvider.php` con `bind(PlaceServiceInterface::class, GooglePlacesService::class)`, registrado en `bootstrap/providers.php`.

*Verificación:* `app(PlaceServiceInterface::class)` resuelve un `GooglePlacesService`.

### Paso 8 — FormRequest

`app/Http/Requests/Place/SearchPlacesRequest.php` con `search` → `required|string|min:3|max:200` y `messages()` en español.

### Paso 9 — Resources

`PlacePredictionResource` y `PlaceResource` en `app/Http/Resources/Place/`. Los dos envuelven un array, no un modelo, como `FreightQuoteResource` de SPEC 09.

### Paso 10 — Controller y rutas

`PlaceController` con `try/catch` → `ResponseHandler` y el contrato inyectado **por parámetro de método**. `routes/places.php` con `jwt.auth` en el grupo y el `apiResource` `->only(['index', 'show'])->parameters(['' => 'place'])`, y su `require` en `routes/api.php`.

*Verificación:* `php artisan route:list --path=places` lista **dos** rutas.

### Paso 11 — Doble del contrato

`tests/Doubles/InMemoryPlaceService.php` implementando `PlaceServiceInterface` con un puñado de direcciones fijas y un modo de fallo. Se bindea en el contenedor desde el Feature test.

Es lo que prueba que el contrato es sustituible de verdad: el Feature test completo pasa **sin que exista Google**.

### Paso 12 — Formato

`vendor/bin/pint --dirty --format agent`.

### Paso 13 — Tests

Disparar el agente `feature-tests` con el dominio `Place`, con la instrucción explícita de que **el dominio tiene dos endpoints de solo lectura, sin modelo, sin factory y sin base de datos**: no debe generar casos de `store`, `update` ni `destroy`, ni `RefreshDatabase` para nada que no sea el usuario autenticado.

`tests/Feature/PlaceTest.php` (los dos endpoints, 401 sin token, 422 del `search`, 404 del id inexistente, 503 del proveedor caído y 200 con lista vacía) y `tests/Unit/GooglePlacesServiceTest.php` (el de los pasos 5 y 6, ya escrito).

### Paso 14 — Documentación

Disparar el agente `endpoint-docs` con el dominio `Place` y regenerar `storage/api-docs/api-docs.json`. La documentación debe decir que el `place` de la ruta es el `id` que devuelve la búsqueda, y que el `503` significa que Google no respondió, no que el cliente se equivocó.

---

## Criterios de aceptación

**Configuración y error nuevo**

- [ ] `php artisan config:show services.google_places` muestra la clave leída de `GOOGLE_PLACES_API_KEY`.
- [ ] `.env.example` documenta `GOOGLE_PLACES_API_KEY`.
- [ ] `ResponseHandler::error(new ServiceUnavailableError('…'))` responde **503** con el sobre `{ statusCode, message, data }`.
- [ ] Las cinco excepciones de `App\Errors` anteriores siguen mapeando al mismo status que antes.
- [ ] La clave de Google **no aparece** en la URL de ninguna petición: viaja solo en la cabecera `X-Goog-Api-Key`.

**Búsqueda `GET /api/places?search=`**

- [ ] `search=zona 4 guatemala` responde 200 y devuelve una lista de objetos con **exactamente** `id` y `formattedAddress`.
- [ ] Se manda un solo `POST` a `https://places.googleapis.com/v1/places:searchText`.
- [ ] El cuerpo enviado lleva `textQuery`, `languageCode: 'es'`, `regionCode: 'GT'` y `pageSize: 10`.
- [ ] La cabecera `X-Goog-FieldMask` vale `places.id,places.formattedAddress`; **nunca `*`**.
- [ ] Google devuelve 20 lugares → la respuesta trae como mucho 10.
- [ ] Una respuesta de Google **sin la clave `places`** responde 200 con `data: []`, no 404 ni 500.
- [ ] `search` ausente, vacío, de una o dos letras, o de más de 200 caracteres responde **422**, y **no se hace ninguna petición a Google**.
- [ ] El mensaje del 422 está en español.
- [ ] Un `search` de exactamente 3 caracteres sí llega a Google.
- [ ] Ningún query param adicional (`limit`, `pageSize`, `regionCode`) altera la petición: se ignoran.

**Detalle `GET /api/places/{place}`**

- [ ] Un `place` válido responde 200 con `id`, `formattedAddress`, `latitude` y `longitude`.
- [ ] `latitude` y `longitude` son **números** en el JSON, no cadenas, y no van anidados bajo `geometry` ni `location`.
- [ ] Se manda un solo `GET` a `https://places.googleapis.com/v1/places/{id}` con `X-Goog-FieldMask: id,formattedAddress,location`.
- [ ] Un `place` inexistente responde **404** con mensaje en español.
- [ ] Un `place` con forma inválida —donde Google contesta 400— responde también **404**, con el mismo mensaje.
- [ ] Una respuesta 200 de Google a la que le falta `location` responde **503**, no 200 con coordenadas en `null`.
- [ ] Las coordenadas devueltas sirven tal cual como `lat` y `lng` de `GET /api/freight-rates/quote`.

**Fallos del proveedor**

- [ ] Timeout de Google → **503**.
- [ ] Error de conexión (DNS, red caída) → **503**.
- [ ] `401` o `403` de Google por clave ausente, inválida o sin permisos → **503**, no 401 ni 403 al cliente.
- [ ] `429` por cuota agotada → **503**.
- [ ] `500` de Google → **503**.
- [ ] Respuesta 200 con JSON de forma inesperada → **503**.
- [ ] Los seis casos responden con mensaje **en español**, sin filtrar el cuerpo de error de Google, ni la URL, ni la clave.
- [ ] Ninguna excepción de Guzzle escapa hasta el `catch` genérico del controller: el cliente nunca ve un 500 por un fallo de Google.
- [ ] El timeout es de **10 segundos** y **no hay reintentos**: un fallo genera exactamente una petición saliente, no dos.

**Autorización**

- [ ] Los dos endpoints sin token responden **401** con el sobre estándar.
- [ ] Los dos responden 200 con token de `administrator`, `carrier`, `pilot` y `manager`.
- [ ] Un `carrier` **sin empresa registrada** los alcanza sin problema: ninguna ruta lleva `carrier.required`.

**Sustituibilidad**

- [ ] `app(PlaceServiceInterface::class)` resuelve un `GooglePlacesService`.
- [ ] Bindeando `InMemoryPlaceService` en su lugar, `tests/Feature/PlaceTest.php` pasa **entero** sin que se haga una sola petición HTTP.
- [ ] Una búsqueda con cero coincidencias devuelve `[]` desde el contrato, **nunca `null`**.
- [ ] Ningún archivo fuera de `app/Services/Place/` menciona `Http::`, `googleapis.com`, `X-Goog-` ni `searchText`.
- [ ] `PlaceController` no sabe que el proveedor es Google: no aparece la palabra en el controller, ni en los Resources, ni en el FormRequest, ni en las rutas.

**Cierre**

- [ ] `php artisan test --compact` pasa la suite entera, incluidas las once specs anteriores.
- [ ] Ningún test sale a la red: con `Http::preventStrayRequests()` activo, la suite sigue verde.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `php artisan route:list --path=places` muestra **dos** rutas, `index` y `show`.
- [ ] `/api/documentation` muestra los dos endpoints, con el `503` documentado como fallo del proveedor.

---

## Decisiones tomadas y descartadas

### Contrato por capacidad, implementación por proveedor

**Descartado:** un `PlaceService` plano que llame a Google directamente, como hacen los nueve dominios anteriores.

Habría sido lo consistente con el proyecto y se rechazó porque aquí la dependencia es **externa y reemplazable**: Google sube precios, cambia de API o deja de servir a la región, y ese día el cambio tiene que ser un `bind` distinto y nada más. Es el mismo razonamiento de SPEC 05 con `FileStorageServiceInterface` → `S3FileStorageService`, y por eso el contrato se llama `PlaceServiceInterface` y no `GooglePlacesServiceInterface`.

Las reglas de sustitución van escritas en el PHPDoc —lista vacía y nunca `null`, las cuatro claves siempre presentes o excepción— porque un contrato que no dice qué garantiza no se puede sustituir sin leer la implementación.

### Dos endpoints, no uno solo con coordenadas

**Descartado:** un único `GET /api/places?search=` que ya devolviera `latitude` y `longitude` de los 10 resultados, añadiendo `places.location` al field mask.

Era la opción cómoda para el front —una llamada en vez de dos— y se descartó por costo: `location` sube la búsqueda al SKU **Pro** de Google, y se pagaría por diez juegos de coordenadas de los que el usuario usa exactamente uno. Con dos endpoints, la búsqueda se queda en el SKU barato y las coordenadas se piden una sola vez, cuando el usuario ya eligió.

**Consecuencia asumida:** el front encadena dos llamadas y tiene que guardar el `id` entre una y otra.

### Field mask acotado, nunca `*`

La implementación de referencia en Node pide `X-Goog-FieldMask: '*'` en el detalle. Aquí se pide `id,formattedAddress,location` y nada más, por dos razones: `*` factura al tier más caro que existe —Enterprise + Atmosphere— aunque solo se lean dos campos, y ata la respuesta a lo que Google decida devolver, que cambia sin avisar. Pedir tres campos explícitos hace el costo predecible y el contrato estable.

### `GET` en esta API aunque Google exija `POST`

`places:searchText` solo acepta `POST`. Se traduce igual: hacia fuera esto es una lectura sin efectos, cacheable por el navegador y enlazable, y el resto del proyecto lista con `GET`. Que el proveedor use `POST` es un detalle de implementación que no debe filtrarse a la API pública.

### `ServiceUnavailableError` nuevo, no reciclar el 400

**Descartado:** mandar todos los fallos de Google como `BadRequestError` (400), que era gratis.

Miente: el cliente mandó un `search` perfectamente válido y el que falló fue un tercero. Un front que recibe 400 corrige el formulario; uno que recibe 503 reintenta. **Descartado también** `NotAcceptable` (406), que significa otra cosa.

Es la primera clase de error 5xx del proyecto y probablemente no la última: cualquier integración futura la reutiliza.

### El 404 no distingue "no existe" de "mal formado"

Un `place` inventado y un `place` que ni siquiera tiene forma de place id —donde Google contesta 400— devuelven el **mismo** 404 con el mismo mensaje.

Para esta API los dos casos son "no hay tal lugar", y distinguirlos obligaría a explicarle al cliente la taxonomía de errores de Google. Es la misma decisión que el 404 compartido de `resolvePilot()` en SPEC 11.

### Una búsqueda sin resultados es 200, no 404

Google omite la clave `places` cuando no encuentra nada. Sale como `data: []` con 200: un buscador que no encuentra no es un error, y un 404 obligaría al front a tratar como excepción el caso más normal del mundo — que el usuario todavía no terminó de escribir.

### Una respuesta parcialmente inválida invalida la respuesta entera

Si Google devuelve diez lugares y a uno le falta `formattedAddress`, la llamada responde **503**; no se filtra el elemento roto ni se devuelven nueve.

Es el comportamiento del `safeParse` de la implementación de Node, y es el correcto: una respuesta con la forma cambiada significa que el contrato con el proveedor se rompió, y devolver nueve resultados lo esconde hasta que alguien note que faltan direcciones.

### Sesgo a Guatemala, no restricción

`languageCode: 'es'` + `regionCode: 'GT'` **priorizan** resultados guatemaltecos sin excluir nada.

**Descartado:** `locationRestriction` con el bounding box del país, que los excluiría de verdad. Se rechazó porque rompe una búsqueda fronteriza legítima y porque un bounding box es un rectángulo: recorta El Petén o mete medio Chiapas, según cómo se dibuje.

### Timeout de 10 segundos y sin reintentos

**Descartado:** reintentar los fallos de red y los 5xx.

Sobre un endpoint que cobra por llamada, un reintento duplica el costo de cada fallo, y cuando el fallo es cuota agotada (429), reintentar es tirar dinero contra una puerta cerrada. El front puede volver a buscar en cuanto recibe el 503, que es lo mismo que haría el reintento pero decidido por quien está mirando la pantalla.

El timeout existe para lo contrario de lo que parece: **sin él, un Google colgado deja la petición del usuario esperando hasta que PHP la mate.** Diez segundos y un 503 en español es mejor experiencia que un navegador girando un minuto. No hay ningún límite de búsquedas por usuario.

### `min:3` en el `search`, validado antes de salir

Cada llamada cuesta. Un `search` de una letra devuelve ruido y se paga igual, así que se corta en el FormRequest y **nunca llega a Google**. El debounce del front es la otra mitad de la misma defensa, pero vive en el cliente y el cliente no es de fiar.

### Respuesta plana, no el formato legacy

**Descartado:** replicar `{ result: { formatted_address, geometry: { location: { lat, lng } } } }` del mapper de Node.

Ese formato es el de la API vieja de Google, arrastrado por compatibilidad con un consumidor que aquí no existe. Este proyecto habla camelCase plano dentro del sobre `{ statusCode, message, data }`, y `latitude`/`longitude` en la raíz es exactamente lo que `/api/freight-rates/quote` necesita.

### `Http::preventStrayRequests()` global desde el paso 4

Se activa **antes** de escribir la primera línea del service, no después de los tests. A partir de ahí, cualquier petición saliente que un test no haya declarado revienta el test en vez de salir a internet, gastar cuota y —peor— pasar en la máquina de quien tiene la clave y fallar en CI.

Es la contraparte de `Mail::fake()` y `fakeDefaultDisk()` que ya viven en `tests/Pest.php`.

### Sin caché en esta spec

Cachear es la optimización obvia y se deja fuera a propósito: los términos de Google prohíben almacenar datos de un lugar más de 30 días, con el `place_id` como única excepción. Eso convierte el caché en una decisión con reglas propias —qué se guarda, cuánto vive, qué se purga—, y esa decisión merece su spec, no un párrafo en esta.

---

## Riesgos identificados

### Sin Google no hay búsqueda, y no hay plan B

Es la primera funcionalidad del proyecto que **no funciona sin un tercero**. Si Google está caído, si la cuota se agota o si alguien revoca la clave, los dos endpoints devuelven 503 y no hay forma de elegir un destino. No hay caché del que tirar, no hay proveedor secundario y no hay degradación parcial: se cae entero.

**Mitigación:** parcial y deliberada. El contrato sustituible hace que enchufar un proveedor alternativo sea un `bind` distinto, y el 503 con mensaje en español al menos le dice al usuario que el problema no es suyo. Lo que **no** hay es nada que resuelva la caída mientras dura.

### Cada búsqueda cuesta dinero y nadie lleva la cuenta

Cualquier usuario autenticado puede buscar cuantas veces quiera. Un bucle de peticiones —un script, un front con el debounce mal puesto, una pestaña con reintentos— quema la cuota de todos, y el primer aviso llegará por la factura o por un 503 generalizado cuando Google corte.

**Mitigación:** el `min:3` corta las búsquedas basura antes de salir, el field mask acotado mantiene cada llamada en el SKU barato y la separación en dos endpoints evita pagar diez juegos de coordenadas por cada búsqueda. Lo que no hay es **tope, throttle ni métrica**: ni un límite por usuario, ni un contador, ni una alerta de consumo. Los tres quedaron fuera de alcance a sabiendas, y la salida natural el día que duela es un `throttle:` de Laravel sobre el grupo de rutas — media hora de trabajo, en su propia spec.

### Un despliegue sin clave se ve igual que una caída de Google

`GOOGLE_PLACES_API_KEY` vacía, mal copiada o sin permisos para Places produce un 403 de Google, que esta spec traduce a **503 con el mismo mensaje genérico** que un timeout o una caída real. Un entorno recién levantado va a parecer que tiene a Google caído durante el rato que tarde alguien en sospechar de la variable.

**Mitigación:** ninguna dentro del contrato público — no se filtra el error de Google al cliente a propósito, porque diría más de la cuenta. Queda como norma operativa: **ante un 503 de `/api/places`, lo primero que se revisa es la clave**, y el log del servidor es donde debe estar el detalle. Restringir la clave por IP en la consola de Google es la otra mitad de esta pieza, y es trabajo de infraestructura, no de esta spec.

### El lugar devuelto puede no estar en ninguna zona registrada

Google devuelve cualquier dirección del mundo, y esta spec la entrega sin comprobar nada. El front pasa esas coordenadas a `GET /api/freight-rates/quote` y se lleva un **404 «El punto indicado no pertenece a ninguna zona registrada»** de SPEC 09, ya con la dirección elegida en pantalla.

**Mitigación:** ninguna aquí, y es lo correcto: validar la zona dentro de `/api/places` metería PostGIS y SPEC 08 en un dominio que no sabe nada de cotizaciones, y dejaría al usuario sin poder ni siquiera ver la dirección. El 404 de la cotización ya tiene su mensaje y es el sitio donde el problema significa algo. Filtrar la búsqueda por zonas cubiertas, si algún día se quiere, es una spec propia.

### Un cambio en la forma de la respuesta de Google rompe todo a la vez

La decisión de invalidar la respuesta entera cuando falta un campo hace el fallo ruidoso, que es lo que se quería — pero también significa que el día que Google renombre `location` o `formattedAddress`, los dos endpoints pasan a devolver 503 **para todo el mundo y a la vez**, sin aviso previo.

**Mitigación:** parcial. Los tests con `Http::fake()` no lo van a detectar nunca: sus respuestas son fixtures congelados con la forma de hoy, así que la suite seguirá verde mientras producción está caída. El 503 es al menos inmediato y visible, en vez de devolver direcciones sin coordenadas durante semanas. La defensa real es leer los avisos de deprecación de Google, y eso no lo cubre ningún test.

### El nombre del parámetro de cantidad depende de la versión de la API

`pageSize: 10` es el nombre actual en `places:searchText`; versiones anteriores de la API nueva lo llamaban `maxResultCount`. Si se manda el que no es, Google responde **400** y esta spec lo traduce a 503 — es decir, la búsqueda no funcionaría **nunca**, no de forma intermitente.

**Mitigación:** se confirma contra la respuesta real de Google en el Paso 5, antes de escribir un solo test, y queda fijado como constante de clase en un único sitio. Es el tipo de detalle que la implementación de referencia en Node no tenía que resolver porque no limitaba resultados.

---

## Lo que **no** entra en esta spec

Repetición deliberada de lo ya dicho en el alcance:

- Caché de resultados de búsqueda o de lugares.
- Guardar destinos frecuentes, favoritos o historial de búsquedas: no hay tabla, no hay modelo, no hay migración.
- La API de Autocomplete (`places:autocomplete`) y sus session tokens.
- Paginación de resultados y `nextPageToken`.
- Campos extra del lugar: `displayName`, `addressComponents`, `types`, horarios, teléfono, fotos y reseñas.
- Geocodificación inversa, rutas, distancias y matrices de distancia.
- `locationRestriction` por bounding box de Guatemala.
- Un segundo proveedor implementado: el contrato lo permite, esta spec solo trae Google.
- Idioma configurable por usuario.
- Límite de búsquedas por usuario, throttle, cuotas y control de gasto.
- Métricas, alertas y registro del consumo de la API.
- Encadenar la búsqueda con `GET /api/freight-rates/quote`: eso lo hace el front.
- Validar que el punto devuelto cae dentro de una zona registrada de SPEC 08.

Cada uno de ellos, si entra, va en su propia spec.
