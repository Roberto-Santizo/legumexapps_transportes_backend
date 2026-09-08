# Legumex Transportes — Backend

API REST interna de transportes (Laravel 13 + PHP 8.5). Sin frontend propio: solo expone JSON bajo `/api`. Autenticación con JWT (`php-open-source-saver/jwt-auth`) y documentación OpenAPI con `darkaonline/l5-swagger`. Desde SPEC 26 hay además un **proceso permanente**, `php artisan reverb:start` (`laravel/reverb`), que empuja las posiciones del viaje por websocket: sin él la API funciona entera —los puntos se guardan igual— pero nadie recibe nada.

## Arquitectura por capas

Cada recurso se implementa con la misma cadena de archivos, agrupados en subcarpeta por dominio (`Auth/`, `Carrier/`, etc.):

| Capa | Ubicación | Responsabilidad |
|---|---|---|
| Rutas | `routes/<recurso>.php`, incluido desde `routes/api.php` | Prefijo + `name()` propios; `jwt.auth` + `role:`/`carrier.required` en lo protegido |
| Controller | `app/Http/Controllers/` | `try/catch` → `ResponseHandler`; sin lógica de negocio; atributos `OpenApi\Attributes` |
| FormRequest | `app/Http/Requests/<Dominio>/` | Validación + `messages()` en español; schema OA del body |
| Resource | `app/Http/Resources/<Dominio>/` | Salida en **camelCase** (`emailVerifiedAt`); schema OA del modelo |
| Interface | `app/Interfaces/<Dominio>/` | Contrato del service, con PHPDoc de array shapes |
| Service | `app/Services/<Dominio>/` | Lógica de negocio; lanza errores de `App\Errors`; `#[Override]` en cada método |
| Provider | `app/Providers/<Dominio>/` | `bind(Interface::class, Service::class)`, registrado en `bootstrap/providers.php` |

El service se inyecta **por parámetro del método del controller** (`public function login(LoginRequest $request, AuthServiceInterface $authService)`), no por constructor.

En un `apiResource`, las rutas fijas (`/join`, `/me`, `/me/pilots`, `/current`, `/quote`, `/directions`, `/{pilot}/salary`, `/{trip}/assignment`, `/{trip}/start`, `/{trip}/finish`, `/{trip}/positions`) se declaran **antes** del resource: si no, las captura el comodín `{carrier}`.

**La única ruta anidada del proyecto es `/api/trips/{trip}/positions` (SPEC 26)**: SPEC 14 y SPEC 18 evitaron a propósito `/api/vehicles/{vehicle}/expenses` y `/api/accessories/{accessory}/characteristics`, pero una posición sin su viaje no significa nada y `{trip}` ya era el parámetro del grupo. Vive en `routes/trips.php`, que por eso —y por el `/current` posterior— declara **once** rutas y no ocho.

Dominios ya implementados: `Auth`, `Carrier`, `Vehicle`, `VehicleExpense`, `FuelPrice`, `Product`, `Zone`, `Location`, `FreightRate`, `Pilot`, `Place`, `Accessory`, `AccessoryCharacteristic`, `DeparturePoint`, `Client`, `ShippingLine`, `Trip`, `TripPosition` (SPEC 01–26).

## Respuestas y errores

- Todo pasa por `App\Helpers\ResponseHandler`: sobre `{ statusCode, message, data }`. `success($data, $message, $statusCode)` resuelve `JsonResource` automáticamente y aplana metadata de paginación.
- Los errores de negocio son subclases de `App\Errors\ApiException` (`BadRequestError`, `UnauthorizedError`, `ForbiddenError`, `NotFoundError`, `NotAcceptable`, `ServiceUnavailableError`); el controller los captura y `ResponseHandler::error()` mapea el status. Cualquier otro `Throwable` cae a 500.
- `bootstrap/app.php` renderiza JSON para `api/*` y traduce el `UnauthorizedHttpException` del middleware `jwt.auth` al mismo sobre.
- Mensajes de cara al usuario en español; nombres de código y PHPDoc en inglés.

## Paginación

- Opt-in por query param `limit`: sin `limit` (o no numérico) el service devuelve una `Collection` completa; con `limit` numérico pagina, acotado a `[10, 100]`. Cada service repite `resolvePerPage()` con sus constantes `MIN_PER_PAGE`/`MAX_PER_PAGE`. Dos excepciones: `FreightRate` no pagina nunca, y `Trip` baja su `MIN_PER_PAGE` a **1** (`?limit=5` devuelve páginas de cinco), así que solo le muerde el techo.
- El listado paginado se envuelve en `App\Http\Resources\PaginatedResource` (`new PaginatedResource($paginator, CarrierResource::class)`), y `ResponseHandler` funde `total/currentPage/lastPage` en la raíz del sobre, no bajo `meta`.

## Autenticación y autorización

- Guard `api` de JWT; alias de middleware `jwt.auth`. `JWT_TTL=60` minutos y `JWT_REFRESH_TOKEN_TTL=20160` (14 días) — clave propia `jwt.refresh_token_ttl`, **no** el `jwt.refresh_ttl` del paquete, que solo acota un `JWTAuth::refresh()` que este código nunca llama.
- `User` implementa `JWTSubject` y añade claims `id/name/email/role` + `carrierId/carrierName/carrierCode` (null si no tiene empresa; **informativos para el front, nunca fuente de verdad para autorizar**). Roles en `App\Enums\UserRole` (administrator, carrier, pilot, manager) — solo `pilot` y `carrier` pueden autoregistrarse.
- Flujo: register (sin token, cuenta sin confirmar) → confirm-account → login (emite el par de tokens) → check-status (único endpoint que reemite; no hay `/refresh` ni `/logout`).
- `login` y `check-status` devuelven `{ user, token, refreshToken }`. Los dos JWT llevan los mismos claims y la misma firma; solo cambian el `exp` (60 min vs 14 días) y el claim `tokenType` (`access`/`refresh`), puesto **inline** en la emisión porque `getJWTCustomClaims()` no puede variar por token. Nadie lee `tokenType` todavía: el `refreshToken` vale como `Bearer` en cualquier ruta protegida.
- `AuthService::issueTokens()` es el **único sitio** que toca `claims()` y `factory()->setTTL()`, y restaura el TTL a `config('jwt.ttl')` tras emitir el refresh — el TTL es estado de la petición, no del token. `check-status` reemite desde el usuario (`login($user)`), no con `refresh()`, que arrastraría claims de transportista obsoletos.
- Códigos de 6 dígitos hasheados con vigencia de 1 h en `account_confirmation_tokens` y `password_reset_tokens`.
- Middlewares de autorización (alias en `bootstrap/app.php`), ambos resuelven el usuario con `auth('api')->user()` y **devuelven** `ResponseHandler::error(new ForbiddenError(...))` en vez de lanzar, porque el `try/catch` del controller no ve excepciones de middleware:
  - `role:admin,carrier` — filtro grueso por rol.
  - `carrier.required` (`EnsureUserHasCarrier`) — exige vínculo con una empresa, consultando la BD (`$user->currentCarrier()`), no el claim del token, que puede llevar hasta 1 h obsoleto. `administrator` y `manager` están exentos.
- **Tercera vía de autorización desde SPEC 26**, y la primera que corre fuera del ciclo de una ruta de la API: el callback de `routes/channels.php`, que devuelve `bool` en vez de lanzar. `bootstrap/app.php` monta su ruta a mano con `->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api', 'middleware' => ['jwt.auth']])` → **`POST /api/broadcasting/auth`**, porque el `Broadcast::routes()` por defecto usa el guard `web` y este proyecto no tiene sesión.

## Dominio Carriers

- `Carrier` pertenece a un `User` con rol `carrier` (`owner`); los `pilot` se vinculan por la pivote `carrier_pilots` (`Carrier::pilots()` / `User::pilotCarrier()`). `User::currentCarrier()` es la única fuente de verdad de "¿tiene empresa?" (dueño o piloto).
- Un `carrier` solo puede registrar **una** empresa; el service genera un `code` único de 6 caracteres `[A-Z0-9]` y el piloto se une con `POST /api/carriers/join` mandando ese código (se normaliza a mayúsculas).
- `destroy` **no borra nada**: solo resuelve la empresa para responder 404 si no existe.

## Dominio Vehicles

- `Vehicle` pertenece a un `Carrier`. Enums `App\Enums\VehicleType` (truck, van, trailer, pickup), `VehicleStatus` (active, inactive, under_repair) y `VehicleCondition` (new, used).
- Ámbito: el `administrator` ve todos y puede filtrar por `carrierId`; cualquier otro rol queda acotado a su propia empresa (`resolveScopedCarrierId()`), y tocar un vehículo ajeno es 403. Filtros opcionales: `status`, `condition` y `engineNumber` (`LIKE %term%` sobre el valor ya guardado en mayúsculas).
- La placa se normaliza a mayúsculas y es única **solo entre vehículos no desactivados** — un `inactive` la libera —, así que la unicidad no vive en un índice sino en `ensurePlateIsAvailable()`. Reactivar un vehículo revalida su placa.
- `DELETE` es una baja lógica: pasa el `status` a `inactive`; la fila sigue viva y sigue apareciendo en los listados.

### Ficha técnica y financiera (SPEC 13)

Primera spec que **amplía un dominio ya publicado**: ni tabla, ni controller, ni ruta nuevos — una migración aditiva sobre `vehicles` y seis columnas propagadas por las capas existentes.

- Columnas nuevas: `condition`, `kilometers_per_gallon`, `purchase_price`, `monthly_insurance_cost` (dinero en GTQ, `decimal`), `mileage` (entero, km) y `engine_number`. Todas nacen con `default` o `nullable` para que las filas viejas sobrevivan sin backfill; esos defaults (`1`, `used`) son relleno, no negocio: por la API los seis son obligatorios en el alta.
- `POST` es **cambio incompatible** (de 7 a 13 campos, sin periodo de gracia); el `PATCH` no lo es: los seis entran como `sometimes`.
- `condition` **no es `status`**: ejes independientes. `status` es el estado operativo, gobierna la baja lógica y la unicidad de la placa, y no se acepta en el alta (el vehículo nace `active`); `condition` es cómo se adquirió y no gobierna nada. No hay validación cruzada: `new` con 90 000 km es válido.
- `engine_number` se normaliza en `normalizeEngineNumber()` (trim + mayúsculas, vacío → `null`) y **no es único**: dos vehículos pueden compartirlo, incluso de la misma empresa.
- **`mileage` es el único campo del proyecto con autorización propia**: el `PATCH` sigue siendo alcanzable por `carrier` y `administrator`, pero solo el `administrator` puede mandar un valor **distinto** al almacenado; reenviar el mismo no es cambio y pasa con 200 para cualquier rol. La regla vive en el service —no se ve mirando las rutas— y se comprueba **antes** de subir la imagen, así que el 403 aborta la petición entera sin dejar archivos huérfanos.

## Dominio Vehicle Expenses (SPEC 14)

Gastos de mantenimiento imputados a un vehículo. Enums `App\Enums\VehicleExpenseCategory` (22 casos, `tires`…`other`) y `VehicleExpenseNature` (preventive, corrective), **ejes independientes**: no hay validación cruzada, igual que `condition` vs `status` en SPEC 13.

- **Cuelga de un vehículo pero la ruta no está anidada**: no existe `/api/vehicles/{vehicle}/expenses`. El vínculo viaja en `vehicle_id` del body y en el query param `vehicleId` del listado.
- **`vehicleId` es el único filtro obligatorio del proyecto**: `GET /api/vehicle-expenses` sin él es **422** (formato de validación de Laravel, no el sobre habitual), no un listado global. Un `vehicleId` inexistente es 404, no lista vacía. Tiene FormRequest propio para el índice (`IndexVehicleExpenseRequest`), el único del proyecto.
- **Ninguna ruta lleva `carrier.required`**: el ámbito lo resuelve el service desde el vehículo (`resolveVehicle()` / `resolveVehicleExpense()`). Un `carrier` solo alcanza los vehículos de su empresa —ajeno es **403**, no 404—; `administrator` y `manager` alcanzan todos. Leer es `carrier,administrator,manager`; escribir solo `carrier,administrator` (el `manager` recibe 403 en `store/update/destroy`); el `pilot`, 403 en los cinco.
- El `status` del vehículo no importa: uno `inactive` acepta y lista gastos igual (el mantenimiento pudo ocurrir antes de la baja).
- **`totalAmount` en la raíz del sobre**: suma de `amount` de **todos** los gastos filtrados, calculada con `sum()` sobre la consulta clonada **antes** de paginar, y sale también sin `limit` — es dato de negocio, no metadata. No confundir con `total`, que es el conteo del paginador. El controller lo inyecta a mano en el array de `data`.
- Filtros tolerantes (`category`, `nature`, `dateFrom`, `dateTo`): un valor inválido se ignora. Orden fijo `expense_date desc, id desc`.
- `vehicle_id` es **inmutable** (`UPDATABLE_FIELDS` no lo incluye) y `registered_by` sale del usuario autenticado y no se reescribe en el `update`. `DELETE` es **borrado real**: un gasto mal tecleado es basura, no historial. No hay bitácora de ediciones.
- `Vehicle` **no gana** una relación `expenses()` y `GET /api/vehicles/{vehicle}` no cambió de forma.

### Factura del gasto (SPEC 19)

Segunda spec aditiva sobre un dominio publicado (tras SPEC 13) y **primera que toca el contrato de SPEC 05**: dos columnas en `vehicle_expenses` (`is_invoiced` boolean `default false`, `invoice` string nullable con la key completa) y ningún endpoint nuevo.

- `is_invoiced` es **obligatorio en el `POST`** (cambio incompatible, sin periodo de gracia; el `default false` es relleno para las filas ya capturadas). Con `is_invoiced=true` y sin archivo → **422**; con `is_invoiced=false` un archivo enviado **se ignora en silencio** y no se sube nada.
- El archivo se guarda **tal cual llega** (`mimes:jpg,jpeg,png,pdf`, `max:3072`) en `invoices/`: sin recorte ni recompresión, `ImageProcessorServiceInterface` no interviene. Por eso el contrato de almacenamiento gana `storeUpload(UploadedFile, directory)`.
- Los dos campos son **inmutables**: el `PATCH` no los acepta y mandarlos se ignora con 200, como `vehicle_id`. Corregir un gasto mal facturado es borrarlo y recrearlo.
- **`DELETE` borra también el objeto del bucket** — única excepción a «el `DELETE` no toca el archivo»: aquí la fila desaparece de verdad.
- Filtro tolerante nuevo `isInvoiced` y tres claves nuevas en el Resource: `isInvoiced`, `invoiceUrl` (absoluta o `null`) e `invoiceType` (`jpg|png|pdf|null`, derivada de la extensión de la key, sin columna propia). El resto del contrato de SPEC 14 —roles, ámbito, orden, `totalAmount`— intacto.

## Dominio Pilots (salarios y documentos)

Primer dominio publicado **sobre una tabla pivote existente**: `carrier_pilots` gana la columna `salary` (`decimal(10,2)` nullable, mensual y en GTQ por convención — la columna no lo dice) y la bitácora `carrier_pilot_salary_histories`.

- **Tres endpoints y ninguno más**, todos con `carrier.required`: `GET /api/pilots` (listado con salario), `PATCH /api/pilots/{pilot}/salary`, `GET /api/pilots/{pilot}/salary-history`. El `apiResource` se declara `->only(['index'])`; `show/store/update/destroy` quedan deliberadamente sin generar y vincular un piloto sigue siendo `POST /api/carriers/join`.
- **`{pilot}` es el `user_id`**, no el `id` de la fila pivote, que no sale nunca de la API. `PilotResource` expone ese `user_id` como `id`.
- **`GET /api/carriers/me/pilots` (SPEC 03) no se tocó**: sigue sin `salary` y con `joinedAt` en ISO 8601. El listado de este dominio es el de administración — trae `salary`, admite filtro `carrierId` y formatea fechas como `d-m-Y h:i:s A`. Dos Resources distintos a propósito, cruzables por `id`.
- Ámbito distinto en lectura y escritura: `administrator` y `manager` leen todas las empresas y filtran por `carrierId`; a un `carrier` se le ignora ese filtro y tocar un piloto ajeno es 403. Escribir el salario es solo `administrator` y `carrier` (el `manager` recibe 403 en el PATCH), y el `pilot` recibe 403 en los tres.
- `resolvePilot()` es la guarda común del PATCH y del historial: **404 tanto si el `user_id` no existe como si existe pero no es piloto de ninguna empresa** (distinguirlos filtraría qué ids están registrados), y 403 si es de otra empresa.
- `null` en `salary` significa "sin asignar", no "gana cero"; por eso el PATCH valida `min:0.01`. El cuerpo tiene un solo campo obligatorio: a diferencia del resto de PATCH del proyecto, **vacío es 422, no un no-op**.
- **Mandar el mismo salario responde 400** y no escribe en la bitácora, para que toda fila del historial sea un cambio real. La comparación va sobre el valor formateado a dos decimales (`salaryValue()`), así que `4500`, `4500.00` y `4500.004` son el mismo salario. Bajar el salario está permitido.
- El UPDATE y la fila de bitácora corren en la **misma transacción**, con `lockForUpdate` sobre el pivote; `changed_by` sale del usuario autenticado, nunca del body. La bitácora es de solo escritura y solo lectura: `created_at` es la fecha de vigencia y no hay `reason`, `notes` ni `effective_from`. Se ordena por `id desc`, no por `created_at`, que empataría entre dos cambios del mismo segundo.
- Listado e historial paginan opt-in con `limit`, como el resto del proyecto.

### Documentos del piloto (SPEC 25)

Foto del anverso del DPI y de la licencia, exigidas **solo al piloto** y **solo en el registro**. Es la primera spec que toca `Auth` desde SPEC 10 y **no crea dominio**: sin service, sin contrato, sin provider y sin una sola ruta nueva.

- Tabla `pilot_documents`, 1:1 con el usuario (`user_id` **único**, `dpi_image`, `license_image`, `timestamps`): ninguna columna nullable —si la fila existe, existen los dos archivos—, sin `status`, sin `deleted_at` y **sin `registered_by`**, porque la crea el propio usuario que se registra. El modelo no tiene `casts()`, como `AccessoryCharacteristic`. Se llega por `User::pilotDocument()` (`HasOne`) y por ningún otro sitio.
- **`POST /api/auth/register` pasa a `multipart/form-data`** y, para `role=pilot`, de cuatro campos a seis: cambio incompatible sin periodo de gracia, como el `POST` de vehículos en SPEC 13. `dpi` y `license` son `required_if:role,pilot` —**los dos o ninguno**: mandar uno solo es 422— y un `carrier` que los mande **los ve ignorados en silencio**, con 201 y sin fila, precedente literal del archivo de un gasto con `is_invoiced=false`.
- Es **la única subida del proyecto en una ruta pública**: `register` no lleva `jwt.auth`, así que hasta SPEC 25 ningún archivo entraba al bucket sin un token detrás.
- Los archivos se guardan **tal cual llegan** con `storeUpload()` bajo `pilot-documents`: `ImageProcessorServiceInterface` no interviene, porque el recorte cuadrado a 800×800 dejaría un DPI ilegible —mismo argumento que llevó a `storeUpload()` con las facturas—. La URL resultante es pública y sin caducidad, como la imagen de un vehículo.
- **Orden a prueba de huérfanos**: los dos archivos se suben **antes** de abrir la transacción; si falla la subida del segundo se borra el primero, y si falla el commit se borran los dos con `delete()` —que nunca lanza— y se propaga el error original. El correo de confirmación sigue saliendo fuera de la transacción.
- Tres Resources ganan las mismas dos claves, resueltas a URL absoluta: `UserResource` (8 → 10) y `PilotResource` (7 → 9) como `dpiImage`/`licenseImage`, y `TripResource` (32 → 34) como **`pilotDpiImage`/`pilotLicenseImage`**, prefijadas porque ese recurso mezcla cuatro entidades. `CarrierPilotResource` y `TripListResource` quedan **intactos** a propósito.
- El eager loading va donde hace falta y no más: `PilotService` carga `user.pilotDocument` y `TripService::RELATIONS` lleva `pilot.pilotDocument`, pero `LIST_RELATIONS` carga `pilot` a secas — el listado de viajes nunca toca la tabla.
- No hay forma de corregir un documento: sin `PATCH`, sin endpoint propio (**no existe `GET /api/pilots/{pilot}/documents`**) y sin borrado. Nada se bloquea por falta de documentos y no hubo backfill, así que un `null` puede significar «no es piloto», «piloto anterior a SPEC 25» o «viaje sin piloto asignado», y el frontend no puede distinguirlos.

## Dominio Accessories (SPEC 17)

Inventario nacional de accesorios, **una fila por unidad física** (dos llantas iguales son dos registros; no hay `quantity`). Enum propio `App\Enums\AccessoryStatus` (active, inactive, under_repair) — mismos tres valores que `VehicleStatus` pero sin acoplar los dominios.

- Catálogo nacional: sin `carrier_id` ni `vehicle_id`, ninguna ruta con `carrier.required`, lectura para cualquier autenticado y escritura solo `administrator`.
- **Sin `/toggle-status`**: con tres estados un toggle no significa nada; el estado se mueve libremente por `PATCH` (sin reglas de transición) y no se acepta en el alta (nace `active`). `DELETE` es baja lógica a `inactive`, como en `Vehicle`, no la de los catálogos booleanos.
- `name` único normalizado con `normalizeName()`; `code` único **global** —un `inactive` **no** lo libera, a diferencia de la placa— normalizado con `normalizeCode()`, que **no colapsa espacios interiores**: `A 100` y `A100` son códigos distintos. Ambos con índice único y revalidados en el service para dar 400 en español.
- **`currentValue` es el primer campo calculado en lectura del proyecto**: vive solo en `AccessoryResource`, sin columna, sin job y sin caché. Depreciación **lineal** con antigüedad en fracción de días (`días / 365`, sin corrección por bisiesto) y **piso en `0.00`**: `round(max(0, price − price × annual_depreciation/100 × años), 2)`. Sale como string de dos decimales, igual que `price`. **No se puede filtrar ni ordenar por él**: la base no lo conoce.
- `annual_depreciation` es editable y admite `0` (el accesorio vale su precio para siempre). `price` (`min:0.01`) y `purchase_date` (`before_or_equal:today`) son obligatorios en el alta. Filtros tolerantes `status` y `search` (`LIKE` sobre `name` **y** `code`), orden `id ASC`, paginación opt-in.

## Dominio Accessory Characteristics (SPEC 18)

Pares nombre/valor libres colgando de un accesorio: **el primer dominio cuyo conjunto de campos no lo fija el esquema**. Sin enum, sin `status` y sin `casts()` — el primer modelo del proyecto sin ninguno.

- Repite el patrón de SPEC 14: **cuelga de un accesorio pero la ruta no está anidada** (no existe `/api/accessories/{accessory}/characteristics`); el vínculo viaja en `accessoryId` del body y en el query param **obligatorio** del listado — sin él **422**, con un id inexistente **404**, no lista vacía. Segundo FormRequest de índice del proyecto (`IndexAccessoryCharacteristicRequest`).
- Unicidad de `name` **por accesorio** (índice único `(accessory_id, name)` + `ensureNameIsAvailable($accessoryId, $name, $ignoreId)`): dos accesorios distintos pueden tener ambos «PLACA».
- **Normalización asimétrica**: `name` en MAYÚSCULAS con espacios colapsados; `value` **solo `trim`**, tal como se teclea. Todo es texto: no hay tipos, ni unidades, ni catálogo de nombres permitidos.
- `accessory_id` inmutable en el `PATCH`; `DELETE` es **borrado físico**. El `status` del accesorio no importa: uno `inactive` o `under_repair` lista y acepta características igual. Sin filtros ni `search`; orden `id ASC` y paginación opt-in.
- `AccessoryResource` **no cambia de forma**: el inventario no gana `characteristics` ni contador, y no se puede buscar accesorios por característica — el índice va siempre accesorio → características.

## Catálogos nacionales (fuel prices, products, zones, locations, departure points, freight rates, clients, shipping lines)

Ocho dominios que no pertenecen a ninguna empresa y comparten las reglas de abajo — con la salvedad de que `Client` y `ShippingLine` rompen dos: no se dan de baja con un `status` booleano sino con `SoftDeletes` real, y son los únicos sin ninguna ruta fija antes del `apiResource`.

- **Ninguna ruta lleva `carrier.required`**: son datos nacionales. La lectura queda abierta a cualquier autenticado (`jwt.auth` a secas) y toda escritura es `role:administrator`. Única excepción: `GET /api/freight-rates/{id}` también es admin — quien no administra tarifas cotiza con `/quote`.
- Las rutas fijas (`/current`, `/quote`, `/{id}/toggle-status`, `/{id}/deactivate`) van **antes** del `apiResource`, que se declara sobre `'/'` con `->parameters(['' => 'fuelPrice'])`.
- `registered_by` sale siempre del usuario autenticado, nunca del body, y **no se reescribe** en `update`.
- Los filtros de listado son tolerantes: un `status`/`locationId`/`lat,lng` inválido se **ignora** en vez de vaciar el listado (`filter_var(..., FILTER_NULL_ON_FAILURE)`).
- El nombre único (`Product`, `Zone`, `Location`, `DeparturePoint`, `Client`, `ShippingLine`, y fuera de aquí `Accessory`) se normaliza con `Model::normalizeName()` (trim + colapsar espacios + mayúsculas), compartido por FormRequest y service; el service revalida con `ensureNameIsAvailable($name, $ignoreId)` **aunque haya índice único**, para que una llamada directa dé 400 y no 500.

### Fuel Prices

- `FuelPrice` con enums `FuelType` (regular, premium, diesel, diesel_premium) y `FuelPriceStatus` (active, inactive). Historial: por cada tipo hay **como mucho un `active`**.
- `create` corre en `DB::transaction` + `lockForUpdate` sobre el vigente: lo desactiva y crea el nuevo en el mismo paso (a medias, el tipo quedaría con cero o con dos vigentes).
- Todo write pasa por `resolveActiveFuelPrice()`: una fila ya inactiva es historial de solo lectura → **400**, no 404. `update` solo toca `price`. `deactivate` deja el tipo sin vigente (ninguna inactiva asciende). `destroy` es **borrado real** — la baja lógica ya la hace `deactivate`.
- `GET /api/fuel-prices/current?fuelType=` devuelve el vigente o 404. Orden del listado: `created_at desc, id desc`.

### Products

- Catálogo plano: `name` único normalizado + `status` booleano. Filtros `status` y `search` (`LIKE %term%` sobre el nombre ya en mayúsculas, así que normalizar el término basta para ser case-insensitive). Orden por `id`.
- El `status` no sale del body en `store`: nace `true`. `toggleStatus` invierte; `destroy` es baja lógica **idempotente** (`status = false`).

### Zones

- Polígonos en PostGIS: columna `area` `geography(Polygon,4326)`, **fuera del `#[Fillable]`** (entra como expresión, sale como GeoJSON).
- `Zone::SRID`, `pairsToWkt()` y `geoJsonToPairs()` son el único sitio que conoce las dos reglas del formato: la API habla en pares `[lat, lng]` con anillo **abierto**, PostGIS en `lng lat` con anillo **cerrado**.
- Toda lectura pasa por `readQuery()`, que añade `ST_AsGeoJSON(area) as area_geojson` — leer la geometría cruda daría WKB hexadecimal ilegible desde PHP. El polígono se escribe con `DB::statement('UPDATE zones SET area = ST_GeogFromText(?) ...')`, siempre por binding.
- `whereContainsPoint()` es el único sitio con el predicado espacial (`ST_Contains(area::geometry, ...)` — `ST_Contains` no acepta `geography`, y el cast conserva el índice GiST). Su **único consumidor** es el filtro `?lat=&lng=` del listado, que no filtra por estado.
- **Desde SPEC 15 las zonas no cotizan nada**: se dibujan en el mapa y ya. `getZoneContainingPoint()` se eliminó del contrato y del service al perder su consumidor; `whereContainsPoint()` se quedó. El resto del dominio (tabla, rutas, polígono, índice GiST) sigue exactamente como lo dejó SPEC 08, y **PostGIS sigue siendo requisito** del proyecto y de la suite.
- Solape permitido. `color` por defecto `#3388FF` (el azul de Leaflet), en mayúsculas. `description` se borra mandando `null` (por eso `array_key_exists`, no `isset`).

### Locations (SPEC 15 · SPEC 21)

Destinos puntuales identificados por `google_place_id` y coordenadas. **Sustituyeron a la zona como eje de las tarifas**: primera spec que retira una capacidad publicada — cotizar por punto geográfico ya no existe.

- Tabla **plana, sin PostGIS**: `name` (único, en mayúsculas), `description` nullable, `google_place_id` (único), `latitude` `decimal(10,8)`, `longitude` `decimal(11,8)`, `status` booleano y `registered_by`. Los ocho decimales dan precisión milimétrica; nada de flotantes en una coordenada que decide un precio. Salen como **string** en el Resource, no como float.
- Forma idéntica a `Product`/`Zone`: escritura solo `administrator`, lectura para cualquier autenticado, filtros `status` y `search`, orden `id ASC`, paginación opt-in, `DELETE` como baja lógica **idempotente** y `/{location}/toggle-status` declarada antes del `apiResource`.
- **La API nunca llama a Google**: el front busca en `GET /api/places`, elige, y manda `name`, `googlePlaceId`, `latitude` y `longitude` ya resueltos. `LocationService` no conoce `PlaceServiceInterface`.
- `ensureGooglePlaceIdIsAvailable($googlePlaceId, $ignoreId)` es hermano de `ensureNameIsAvailable()`: 400 si otro destino ya ocupa ese lugar. El `google_place_id` se guarda **tal cual** (identificador opaco, sensible a mayúsculas) y **es editable**: reapuntar el destino conserva su `id` y sus tarifas. Coordenadas también editables; **no hay validación cruzada** entre `googlePlaceId` y el pin.
- `getActiveLocationById(int $id): Location` (añadido por SPEC 16) es el séptimo método del contrato: 404 si no existe, **400 si existe pero está inactivo**. Lo consume `GET /api/places/directions`.

**Tipo de destino (SPEC 21).** Columna `type` (`string` con enum `App\Enums\LocationType` — `port` | `destination`), añadida por migración aditiva: ni tabla, ni controller, ni ruta nuevos, como hizo SPEC 13 con la ficha del vehículo.

- El `default('destination')` de la columna **sí es valor de negocio**, no relleno: todos los destinos anteriores a la spec son destinos ordinarios, así que no hubo backfill. Reclasificar los puertos es un `PATCH` a mano — mientras tanto `?type=port` puede devolver lista vacía con puertos reales en el catálogo.
- **Es una etiqueta de catálogo, no una regla de negocio**: no cambia el precio, no aparece en `/quote` ni en `FreightQuoteResource`, no restringe qué tarifas se crean (`ensureLocationAndProductAreActive()` sigue mirando solo `status`) y no altera el ámbito por rol. Un puerto se cotiza como cualquier otro destino.
- **Obligatorio en el `POST`** (de cuatro campos a cinco, cambio incompatible sin periodo de gracia) y editable en el `PATCH` como `sometimes|required`, **sin ninguna restricción**: un destino con tarifas colgando puede pasar a `port` y volver, con 200 y sin bitácora.
- Filtro `type` en el listado: coincidencia exacta contra el valor del enum (`LocationType::tryFrom()`) y **tolerante** — `?type=PORT` o `?type=basura` se ignoran y devuelven el listado completo, nunca 422 ni lista vacía. No se valida en ningún FormRequest.
- El Resource pasa de diez a once claves; `type` sale con el **valor crudo del enum** en inglés, sin traducir. La unicidad de `name` y `google_place_id` sigue siendo **global**, no por tipo, y `departure_points` **no** gana `type`: esa es la primera deriva estructural entre los dos catálogos.

### Departure Points (SPEC 20)

Puntos de partida anclados a Google Places. **Primer dominio que nace como copia declarada de otro**: misma forma que `Location` —tabla plana, `name` único en mayúsculas, `google_place_id` único, coordenadas `decimal(10,8)`/`(11,8)` que salen como string, `status` booleano, seis rutas con `/{departurePoint}/toggle-status` antes del `apiResource`, filtros `status`/`search`, orden `id ASC`, baja lógica idempotente—, sin tocar `locations` ni `freight_rates` en una línea.

- La diferencia está en lo que le falta: **no tiene tarifas**, no participa en `/quote` y ninguna tabla apunta a él. Por eso su contrato **no incluye `getActiveDeparturePointById()`**: sin tarifas nadie necesita exigir que esté activo.
- Misma asimetría de duplicados que SPEC 15: `name` repetido es **422** (regla `unique` del FormRequest); `googlePlaceId` repetido es **400** desde el service, con el mensaje que **nombra al ocupante**.
- **Unicidad solo dentro de su tabla**: un mismo lugar de Google puede ser punto de partida y destino a la vez — no hay ninguna comprobación cruzada contra `locations`.

### Freight Rates

- `FreightRate` = banda **abierta** por (`location_id`, `product_id`, `fuel_type`): rige desde `fuel_min` hacia arriba hasta que exista una banda mayor. `price_per_pound` es `decimal:6`. Desde SPEC 15 el eje es el **destino**, no la zona: la migración vació la tabla, cambió `zone_id` por `location_id` y rehizo el índice compuesto; el renombrado llegó hasta los Resources (`locationId`/`locationName`) **sin periodo de gracia**.
- Usa `SoftDeletes`. `getFreightRateById()` lee `withTrashed()` a propósito: una tarifa borrada da **400 "ya fue eliminada"**, no 404, así que el segundo DELETE se distingue de un id inexistente.
- Unicidad de banda en `ensureFuelMinIsAvailable()` y **deliberadamente sin índice único**: con soft deletes, un índice bloquearía un `fuel_min` ya borrado. `fuelMinValue()` formatea a 2 decimales antes de comparar, porque si no un `30.005` que Postgres redondea a `30.01` se colaría.
- Crear o editar exige destino **y** producto activos (`ensureLocationAndProductAreActive`), incluso si el PATCH solo mueve el precio; borrar nunca se bloquea.
- `GET /api/freight-rates/quote?locationId=&productId=&fuelType=[&pounds=]`: cinco pasos, destino activo → producto activo → precio de combustible vigente (**de la BD, jamás de la petición**) → bandas del trío → `resolveBand()` toma la mayor que no supere el precio vigente (si el combustible está por debajo de todas, aplica la más barata, no es error). El orden de los pasos es contrato: cada uno falla con su **400** y su mensaje; un `locationId` inexistente lo atrapa antes el `exists:` del FormRequest con 422, así que **la cotización ya no tiene ningún 404**. `total = round(pounds * pricePerPound, 2)`, redondeando **después** del producto; sin `pounds`, `total` es `null`. Sale por `FreightQuoteResource`, que no devuelve coordenadas (quien manda el `locationId` ya las tiene).
- El listado **no pagina nunca**: devuelve `Collection` ordenada por `fuel_type, fuel_min` (la tabla de precios se lee entera), filtrable por `locationId`.
- Tras SPEC 15 el dominio **no conoce ni zonas ni PostGIS**: `FreightRateService` dejó de inyectar `ZoneServiceInterface` y se quedó sin constructor.

### Clients (SPEC 22 · SPEC 24)

Catálogo de dos campos —`code` y `name`— y el primero que se aparta del patrón de baja de los demás.

- Tabla `clients`: `id`, `code` (`varchar(15)`, único), `name` (único), `registered_by`, `timestamps` y `deleted_at`. Sin `status` y sin `casts()` en el modelo. Cinco rutas (`apiResource('/')->parameters(['' => 'client'])`) y **ninguna ruta fija antes del resource** —no hay `/toggle-status` ni `/restore`—; lectura para cualquier autenticado, escritura solo `administrator`.
- **`SoftDeletes` real, no baja lógica**: la fila desaparece del listado y del `show` (**404**), a diferencia de `Product`/`Location`/`Zone`/`DeparturePoint`, que siguen listando lo desactivado. `PATCH` o `DELETE` sobre uno borrado es **400 «El cliente ya fue eliminado»**, distinguible de un id inexistente porque `resolveWritableClient()` es el único sitio que lee `withTrashed()`. Sin `restore` ni `?trashed=true`: un borrado erróneo se arregla tocando la base.
- **La unicidad es global y un borrado no la libera**: la fila con `deleted_at` sigue ocupando su `code` y su `name` para siempre. Es el bando contrario al de `FreightRate`, que renuncia al índice único precisamente para poder reutilizar un `fuel_min` borrado. Por eso los mensajes dicen «que puede haber sido eliminado»: el alta choca con una fila invisible en todos los endpoints.
- **Los duplicados son 400 desde el service, nunca 422** (bando de `Accessory`, no la asimetría 422/400 de `Location`), y **ningún FormRequest lleva regla `unique`**: la de Laravel no ve las filas borradas y dejaría pasar un valor ocupado hasta reventar contra el índice con un 500. Si chocan los dos campos, gana el mensaje del código.
- **Normalización asimétrica entre sus dos campos**: `normalizeName()` colapsa espacios interiores; `normalizeCode()` solo hace trim + mayúsculas, y un `code` con cualquier espacio es **422** (`regex:/^\S+$/u`) — se rechaza, no se arregla.
- `ClientResource` con **siete claves**, incluida `deletedAt`: `null` en `index`, `show`, `store` y `update`, y **con fecha solo en la respuesta del `DELETE`**, porque `destroy()` devuelve el modelo ya borrado. Filtro tolerante `search` (`LIKE` sobre `code` **y** `name`, agrupado), orden `id ASC`, paginación opt-in.
- **SPEC 24 le añadió una guarda al `destroy`**: 400 «No se puede eliminar el cliente porque tiene viajes asociados», contando **también los viajes borrados** (`trips()->withTrashed()->exists()`) — si no, borrar el viaje y luego el cliente dejaría la FK de un viaje soft-deleted apuntando a una fila soft-deleted. Es la única modificación que otra spec ha hecho a este dominio.

### Shipping Lines (SPEC 23 · SPEC 24)

**Gemelo reducido de `Client`** y **primer dominio del proyecto con un solo campo de negocio**: `name`. Todo lo demás se copia deliberadamente —`SoftDeletes`, reparto de roles, cinco rutas sin ninguna fija, `withTrashed()` interno, 400 en el segundo `DELETE`, unicidad global que el borrado no libera, duplicado a 400 desde el service, `search`, orden `id ASC`, paginación opt-in y la guarda de viajes de SPEC 24—, sin `code`: desaparecen `normalizeCode()`, el `regex` de espacios, el segundo índice único y el segundo `ensure…IsAvailable()`. `ShippingLineResource` tiene **seis claves**, las de `Client` menos `code`.

## Dominio Places (Google Places)

Primer dominio **sin tabla, sin modelo y sin migración** — un proxy de lectura — y el primero que sale por su cuenta a una API de terceros. Tres rutas con `jwt.auth` a secas, sin `role:` ni `carrier.required`: `GET /api/places?search=` (texto de 3 a 200 caracteres), `GET /api/places/{place}` y `GET /api/places/directions` (SPEC 16), declaradas con el mismo `apiResource('/')->parameters(['' => 'place'])->only(['index', 'show'])` de los catálogos — con `/directions` **antes** del resource, o el comodín `{place}` la captura y busca un lugar llamado "directions".

- Contrato por capacidad, como en almacenamiento: `PlaceServiceInterface` (`searchPlaces`, `getPlaceById`, `getDirections`) → `GooglePlacesService`, bindeado en `PlaceProvider`. El nombre del proveedor no aparece fuera de `app/Services/Place/`.
- Credencial en `services.google_places.key` (`GOOGLE_PLACES_API_KEY`), enviada en la cabecera `X-Goog-Api-Key` y **nunca en la query string**, que acabaría en los logs de cada proxy intermedio. Field masks siempre explícitas (`*` factura en el tramo más caro), `pageSize` 10, `languageCode=es`, `regionCode=GT` (sesga, no excluye), timeout de 10 s y **sin reintentos**: cada llamada se paga.
- Error nuevo `App\Errors\ServiceUnavailableError` (503). Cualquier fallo del proveedor —timeout, DNS, credencial rechazada, cuerpo con forma inesperada— sale con **un único mensaje genérico**, para no filtrar el estado de la cuenta. Una lista parcialmente válida no se filtra ni se devuelve corta: invalida la llamada entera.
- Búsqueda sin resultados es lista vacía, no error. En el detalle, id inexistente e id malformado (404 y 400 del proveedor) son ambos `NotFoundError`; el `location` anidado se aplana a `latitude`/`longitude`, y un 200 sin coordenadas es 503, no coordenadas nulas.
- Su consumidor es el front: buscar dirección → elegir → dar de alta el destino con `POST /api/locations` (`googlePlaceId` + coordenadas) → cotizar con `GET /api/freight-rates/quote?locationId=`. Este dominio no cotiza nada ni persiste nada.

### Ruta por carretera (SPEC 16)

`GET /api/places/directions?locationId=&lat=&lng=` — **segunda llamada saliente del proyecto**, a `computeRoutes` de la Routes API de Google: misma credencial que Places pero **se factura aparte**. No persiste nada y no cotiza nada; el origen es un par de coordenadas sueltas, no una entidad del sistema.

- Constantes propias en `GooglePlacesService`: field mask `routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline`, `travelMode: DRIVE`, `routingPreference: TRAFFIC_UNAWARE` (misma respuesta a cualquier hora), `polylineQuality: OVERVIEW`, `units: METRIC`, `computeAlternativeRoutes: false`. Timeout de 10 s y sin reintentos, como el resto del dominio.
- `App\Services\Place\PolylineDecoder::decode()` traduce la polilínea codificada a pares `[lat, lng]`. Es el algoritmo que se prueba **sin red**, como la geometría de zonas se prueba sin PostGIS.
- El controller recibe **los dos contratos por parámetro de método** (`PlaceServiceInterface` y `LocationServiceInterface`), resuelve el destino con `getActiveLocationById()` y pasa sus coordenadas al proveedor.
- **Orden de fallo, cada paso con su mensaje**: `locationId` inexistente → 422 (`exists:` del FormRequest); destino inactivo → 400; Google sin rutas (200 con `{}` o `routes: []`) → **404**, porque el proveedor funcionó; cualquier otro fallo → 503 genérico. `duration` llega como cadena `"6300s"` (convención `protobuf.Duration`): se parsea quitando la `s`, y cualquier otra forma es 503.
- `DirectionsResource` con seis claves: `locationId`, `locationName`, `distanceKilometers` (metros/1000, 2 decimales), `durationHours` (segundos/3600), `polyline` y `points`. Las dos primeras las pone el Resource: el contrato no sabe que existe un destino registrado.

## Dominio Trips (SPEC 24)

El viaje de exportación que enlaza cliente, naviera, punto de partida y puerto de destino. Es la spec con **más dependencias del proyecto** —consume ocho dominios ya publicados— y la tabla con **más claves foráneas**: ocho, ninguna con `cascade`, y **ninguna es `carrier_id`**.

- 19 columnas de negocio + `timestamps` + `deleted_at`; `polyline` y `observations` son `TEXT`. Enum `App\Enums\TripStatus` con **tres** casos (`pending`, `in_route`, `finished`) y **sin `cancelled`**: un viaje que no se hará se borra. El `status` nace en `pending`, no se acepta en el `POST` y sale con el valor crudo del enum en inglés.
- **El `PATCH` deja de ser el único camino de escritura**: tres rutas fijas de acción, cada una con su rol y su efecto, declaradas antes del `apiResource`.

  | Ruta | Middleware | Efecto |
  |---|---|---|
  | `PATCH /{trip}/assignment` | `role:carrier` + `carrier.required` | Fija `pilot_id`, `vehicle_id` y `assigned_by`; **no toca `status`** |
  | `PATCH /{trip}/start` | `role:pilot` (el asignado) | `start_date = now()`, `status = in_route` |
  | `PATCH /{trip}/finish` | `role:pilot` (el asignado) | `end_date = now()`, `status = finished` |

  Las dos últimas no llevan FormRequest ni aceptan cuerpo: la fecha es el `now()` del servidor.
- **`GET /trips/current`** es la cuarta ruta fija y la única sin `{trip}`: `role:pilot`, sin parámetros ni cuerpo, devuelve el viaje `in_route` del piloto autenticado por `TripListResource` —las 15 claves del listado, sin `polyline` ni `points`— o **`data: null` con 200**, nunca 404: no conducir es el estado ordinario de un piloto, al revés que en `/fuel-prices/current`, donde la ausencia sí es 404. Lee el **`status`, no `start_date`**, así que un viaje devuelto a `pending` conservando su arranque no sale; es **la misma condición** que abre el `POST /{trip}/positions`. Con dos `in_route` a la vez —el dominio no valida solape— gana el de `start_date` más reciente.
- **Ámbito adquirido, no heredado** — el primero del proyecto. Un viaje nace de nadie: `administrator` y `manager` los ven todos; el `pilot` **solo** aquellos donde `pilot_id` es él, sin ver la bolsa; cualquier otro ve la **bolsa libre** (`pending` con `pilot_id` y `vehicle_id` nulos) **más** lo que asignó su empresa. `assigned_by` guarda el **id del usuario**, pero el filtro compara la **empresa** de ese usuario (`User::currentCarrier()`): así el viaje no queda huérfano de vista si su asignador se da de baja, y reasignarlo puede hacerlo cualquiera de esa empresa, no solo la persona exacta. El ámbito se aplica **antes** que los filtros.
- **Un viaje borrado es 404 en `GET /{trip}` y 400 en las cinco rutas de escritura**: `getTripById()` no lee `withTrashed()` y `resolveWritableTrip()` sí («El viaje ya fue eliminado»). Cuarto dominio con `SoftDeletes`, tras `FreightRate`, `Client` y `ShippingLine`, y sin `restore`.
- Validaciones cruzadas al escribir, cada una con su **400** y su mensaje, repartidas entre `ensureCatalogsAreUsable()` y `ensureCrewIsAssignable()`: el destino debe ser de tipo **`port`** (SPEC 21) y estar activo, el punto de partida activo, cliente y naviera **no borrados** —pasan el `exists:` del FormRequest, que lee la tabla en crudo—, el piloto debe ser un `pilot` vinculado a una empresa, el vehículo `active` (`inactive` y `under_repair` se rechazan) y **ambos de la misma empresa**. Las fechas se validan en el alta (`ship_date >= recolection_date` y las dos en el futuro); el `PATCH` **pierde el `after:now`**.
- `assign()` es la única acción con **`lockForUpdate`**, y toda la comprobación corre dentro de la transacción: un viaje libre lo toma cualquier empresa; uno ya tomado, solo la que lo tomó (**403**); y solo mientras siga `pending` (**400**).
- Normalización asimétrica, con el precedente de SPEC 18: `order` y `container` a **MAYÚSCULAS** con espacios colapsados (`Trip::normalizeReference()`, compartida por ambos); `destination`, `transport` y `observations` **solo `trim`**. Ninguno es único: dos viajes pueden compartir orden y contenedor.
- **`polyline` obligatoria** en el alta y en el `PATCH` general, resuelta por el frontend con `GET /api/places/directions`: **la API nunca llama a Google**, igual que `Location` con su `googlePlaceId`. Si el `PATCH` cambia el destino, la polilínea guardada queda obsoleta y la API no dice nada.
- **Dos Resources**, a diferencia del resto del proyecto: `TripResource` (**34 claves**, ids planos con su nombre al lado y `points` decodificados por el `PolylineDecoder` de SPEC 16) en los siete endpoints restantes, y `TripListResource` (**15 claves**) solo en el listado — sin ids de relación, sin `polyline` ni `points`, sin imágenes y sin las tres marcas de tiempo, para que un `limit=100` no decodifique cien polilíneas. El detalle carga ocho relaciones (con `pilot.pilotDocument`); el listado, seis (sin `client` ni `assignedBy`).
- Filtros tolerantes `status`, `clientId`, `shippingLineId`, `locationId`, `pilotId`, `vehicleId` y `search` (`LIKE` sobre `order` y `container`), más `dateFrom`/`dateTo` en **`Y-m-d` estricto** y por día completo sobre `recolection_date`. Orden fijo `recolection_date desc, id desc` y paginación opt-in **`[1, 100]`**, la única del proyecto sin piso de 10.
- Lo que el `PATCH` general **no** acepta: `pilot_id`, `vehicle_id`, `assigned_by` ni `registered_by` — se ignoran en silencio con 200. Un administrador **no puede asignar por ninguna vía**, y una vez asignados, piloto y vehículo **no vuelven a `null`**: se pueden cambiar mientras el viaje siga `pending`, pero no regresa a la bolsa.
- **El hueco declarado: no hay máquina de estados.** El `PATCH` del administrador acepta cualquiera de los tres `status` sin comprobar el orden, así que puede llevar un viaje de `finished` a `pending` dejando `start_date` y `end_date` puestos. Tampoco hay bitácora, ni cancelación, ni validación de solape de piloto o vehículo.

### Seguimiento en vivo (SPEC 26)

**Reabre lo que SPEC 24 cerró** —aquella dejó el tiempo real explícitamente fuera de alcance— y rompe tres cosas de golpe: la primera dependencia de infraestructura nueva desde el arranque, la primera salida del proyecto que no es una petición HTTP (la API **empuja** datos) y la primera ruta anidada.

- Tabla `trip_positions`: `trip_id`, `pilot_id`, `latitude` `decimal(10,8)`, `longitude` `decimal(11,8)`, `recorded_at` y `timestamps`. **Ninguna columna nullable y ningún índice único** —dos puntos idénticos son legítimos: un camión parado sigue reportando—, un índice compuesto `(trip_id, recorded_at)` que sirve a las dos únicas consultas del dominio, y FK **sin `cascade`**. `recorded_at` lo pone el servidor con `now()`, nunca el dispositivo. No hay `registered_by`: aquí el autor es `pilot_id`.
- Dos rutas anidadas: `POST /api/trips/{trip}/positions` con `role:pilot`, y `GET /api/trips/{trip}/positions` con `jwt.auth` a secas — **el veto al piloto en la lectura lo aplica el service, no el middleware**. `TripPositionService` inyecta `TripServiceInterface` **por constructor** para no duplicar la matriz de ámbito de SPEC 24 (precedente: el `FreightRateService` que inyectaba `ZoneServiceInterface` hasta SPEC 15).
- **Cuatro guardas del `POST`, y su orden es contrato**: viaje inexistente → **404**; borrado → **400**; quien llama no es su `pilot_id` → **403**; no está `in_route` → **400**. De ahí que un viaje **borrado y ajeno responda 400, no 403**.
- **Piso de 15 segundos**: si el último punto del viaje tiene menos de 15 s, la petición responde **200 sin guardar y sin emitir**, devolviendo el punto ya existente; **201 solo cuando se escribió**. Silencio deliberado, con el precedente del archivo ignorado de SPEC 19, y es el único freno: no hay rate limiting ni 429.
- Evento `App\Events\Trip\TripPositionUpdated` con **`ShouldBroadcastNow`** —sale dentro de la petición del piloto, porque el proyecto no declara ningún worker—, sobre `PrivateChannel('trips.{tripId}')`, alias de broadcast `.trip.position.updated` y payload de **seis** claves (`tripId`, `latitude`, `longitude`, `recordedAt`, `pilotId`, `pilotName`), que **no coincide** con las cinco del `TripPositionResource` (`id`, `latitude`, `longitude`, `recordedAt`, `pilotId`).
- Autorización del canal en `routes/channels.php`, con dos reglas en este orden: el rol **no puede ser `pilot`** —ni siquiera el asignado— y el usuario debe alcanzar el viaje según el ámbito de SPEC 24, resuelto reusando `TripServiceInterface::getTripById()` en `try/catch`: si lanza, el callback devuelve `false`. **El piloto emite y nada más**: queda fuera del canal **y** fuera del `GET`.
- **Si Reverb está caído, el punto se guarda igual**: el `event()` va en `try/catch` con `Log::error` y el `POST` responde 201.
- Orden `recorded_at asc, id asc` —**invertido respecto a todos los demás listados del proyecto**—, sin ningún filtro y con paginación opt-in `[10, 100]`. Nada se borra jamás: no hay `DELETE`, ni `PATCH`, ni purga por antigüedad, y la baja lógica del viaje **no toca** el rastro. `Trip` **no gana** una relación `positions()`, igual que `Vehicle` no ganó `expenses()`, y ni `TripResource` ni `TripListResource` cambian de forma.

## Almacenamiento de archivos

- Dos contratos en `app/Interfaces/Storage/`, con sus reglas de sustitución escritas en el PHPDoc (qué lanza, qué acepta `null`, qué garantiza la salida), implementados en `app/Services/Storage/` y bindeados por `StorageProvider`:
  - `FileStorageServiceInterface` → `S3FileStorageService`: `store(bytes, directory, extension)` / `storeUpload(UploadedFile, directory)` / `delete(?key)` / `url(?key)`. `storeUpload()` (SPEC 19) persiste el archivo **tal cual llega**, sin pasar por el procesador de imágenes: una factura recortada a un cuadrado es ilegible. Trabaja contra `Storage::disk()` **por defecto**, nunca contra `'s3'` escrito a mano (por eso el `Storage::fake()` de los tests lo intercepta). Sube con ACL `public-read` explícita; sin ella el objeto queda privado y la URL permanente da 403. Traduce tanto el `false` de retorno como cualquier `Throwable` a `BadRequestError`; `delete()` nunca lanza.
  - `ImageProcessorServiceInterface` → `ImageProcessorService`: `normalizeSquare(UploadedFile)` devuelve `array{contents, extension}`. Recorte cuadrado centrado con `cover()` a **800×800** (Intervention Image, driver GD), recomprimido conservando el formato de entrada (jpg calidad 80 o png). `SIDE` y `JPEG_QUALITY` son constantes de clase, no configuración.
- Ningún archivo fuera de `app/Services/Storage/` menciona `Storage::`, el nombre del disco ni `Intervention\`.
- Los dos contratos se inyectan **por constructor** en los services de dominio (la regla de inyectar por parámetro es solo del controller), que aportan su propio prefijo con una constante propia: `IMAGE_DIRECTORY` (`carriers`, `vehicles`), `INVOICE_DIRECTORY` (`invoices`) y `PILOT_DOCUMENT_DIRECTORY` (`pilot-documents`, SPEC 25). `AuthService` inyecta `FileStorageServiceInterface` junto al `AuthEmailsInterface` que ya tenía, y con él **`POST /api/auth/register` es la única subida del proyecto en una ruta pública**.
- La columna del archivo (`image`, `invoice`) guarda la **key completa** (`carriers/{uuid}.png`), no la URL: cambiar de proveedor no obliga a migrar datos. El Resource la resuelve a URL pública con `app(FileStorageServiceInterface::class)->url($this->image)` — localización de servicio consciente, porque un `JsonResource` se instancia con `new`.
- Ciclo de vida: procesar → subir → persistir. En `update` con imagen nueva, el archivo anterior se borra **después** de guardar la fila. El `DELETE` no toca el archivo en ningún dominio, **salvo el gasto de vehículo** (SPEC 19), donde la fila se borra de verdad y arrastra el objeto del bucket.
- Validación: `image` es `mimes:jpg,jpeg,png` + `max:3072` (3 MB, en kilobytes) en los cuatro FormRequests; la factura del gasto añade `pdf` con el mismo tope, y los documentos del piloto añaden la regla `image` **además** de `mimes`, para descartar un PDF renombrado. Requiere `upload_max_filesize`/`post_max_size` ≥ 4M en cada entorno; si PHP corta antes, el error que ve el usuario es un `required` confuso.

## Documentación OpenAPI

- Anotada con atributos PHP `OpenApi\Attributes as OA` sobre Controller / FormRequest / Resource. Los schemas base (`Info`, `bearerAuth`, `ApiError`, `ValidationError`) viven en `app/Http/Controllers/Controller.php`.
- Regenerar: `php artisan l5-swagger:generate` → `storage/api-docs/api-docs.json`. UI en `/api/documentation`.

## Tests

- Pest 5, **PostgreSQL con PostGIS** (base `legumexapps_transportes_testing`, fijada en `phpunit.xml`), `RefreshDatabase`, `Mail::fake()`, `fakeDefaultDisk()` y `Http::preventStrayRequests()` aplicados globalmente desde `tests/Pest.php`: ningún test manda correo ni sale a la red. `preventStrayRequests()` va **solo, sin un `Http::fake()` global** que lo acompañe: uno global sin argumentos taparía cualquier petición no prevista, que es justo lo que se quiere ver fallar — cada test declara su propio `Http::fake([...])`.
- **La suite no corre sin Postgres levantado.** Desde SPEC 08 las zonas usan `geography(Polygon,4326)` y `ST_Contains`, que SQLite no tiene. La extensión se habilita **por base de datos** (`CREATE EXTENSION IF NOT EXISTS postgis;` dentro de la base de tests), no por servidor; ver README.
- Helpers globales en `tests/Pest.php`: `seedAuthCode()` (planta un código conocido, porque el service solo guarda el hash), `resetAuthState()` (limpia guards y singletons de JWT entre peticiones del mismo test) y `fakeDefaultDisk()` (sustituye el disco por defecto por un fake **con `url`**, porque uno pelado devolvería rutas relativas y la API promete URLs absolutas).
- Dobles de los contratos sustituibles en `tests/Doubles/` (`InMemoryFileStorageService`, `StaticImageProcessorService`, `InMemoryPlaceService`): se bindean en el contenedor para probar sustituibilidad y los caminos de error sin decodificar imágenes de verdad ni llamar a Google.
- Helpers locales por archivo de test (ver `tests/Feature/CarrierTest.php`): `userWithRole()`, `asUser()` (llama a `resetAuthState()` y adjunta el token) y un `<recurso>Endpoints()` que alimenta los datasets de middleware.
- Cada dominio lleva Feature test (HTTP, roles y validación) + Unit test del service. Lo que no es service tiene su propio Unit test: `ZoneGeometryTest` (WKT ↔ GeoJSON), `ZoneResourceTest`, `FreightRateModelTest`, `AccessoryResourceTest` (el `currentValue` derivado), `PolylineDecoderTest` (la polilínea, sin red), `TripPositionUpdatedTest` (canal, alias y las seis claves del payload) y `TripChannelTest`. El proveedor externo se prueba en `GooglePlacesServiceTest` con `Http::fake()`.
- **La suite no levanta Reverb**: `phpunit.xml` mantiene `BROADCAST_CONNECTION=null` y `QUEUE_CONNECTION=sync`. Por eso `TripChannelTest` saca el callback del canal con `ReflectionProperty` sobre `channels` del broadcaster en vez de llamar a `POST /api/broadcasting/auth`: con el `NullBroadcaster` esa ruta autorizaría a cualquiera.
- Ejecutar: `php artisan test --compact` (o `--filter=`).

## Flujo de trabajo

- Specs en `specs/NN-slug.md`; `/spec-impl` crea la rama `spec-NN-slug` automáticamente (`specs/.spec-config.yml`).
- La última entrega de `/spec-impl` es el resumen de integración para el frontend en `references/<dominio>-api.md`, con `references/zones-api.md` como plantilla. Lo recuerdan los hooks de `.claude/settings.json` (`.claude/hooks/spec-impl-reference*.sh`): uno inyecta el contrato al lanzar el comando y el otro bloquea una vez si la spec ya quedó `Implementado` y el documento no existe. `references/` está gitignoreado.
- Scaffolding de un CRUD completo: skill `new-feature` (genera todas las capas y las cablea). Después dispara los agentes `feature-tests` y `endpoint-docs`.
- Tras tocar PHP: `vendor/bin/pint --dirty --format agent`.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v5
- phpunit/phpunit (PHPUNIT) - v13
- tailwindcss (TAILWINDCSS) - v4

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
