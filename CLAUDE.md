# Legumex Transportes — Backend

API REST interna de transportes (Laravel 13 + PHP 8.5). Sin frontend propio: solo expone JSON bajo `/api`. Autenticación con JWT (`php-open-source-saver/jwt-auth`) y documentación OpenAPI con `darkaonline/l5-swagger`.

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

En un `apiResource`, las rutas fijas (`/join`, `/me`, `/me/pilots`, `/current`, `/quote`, `/{pilot}/salary`) se declaran **antes** del resource: si no, las captura el comodín `{carrier}`.

Dominios ya implementados: `Auth`, `Carrier`, `Vehicle`, `FuelPrice`, `Product`, `Zone`, `FreightRate`, `Pilot` (SPEC 01–11).

## Respuestas y errores

- Todo pasa por `App\Helpers\ResponseHandler`: sobre `{ statusCode, message, data }`. `success($data, $message, $statusCode)` resuelve `JsonResource` automáticamente y aplana metadata de paginación.
- Los errores de negocio son subclases de `App\Errors\ApiException` (`BadRequestError`, `UnauthorizedError`, `ForbiddenError`, `NotFoundError`, `NotAcceptable`); el controller los captura y `ResponseHandler::error()` mapea el status. Cualquier otro `Throwable` cae a 500.
- `bootstrap/app.php` renderiza JSON para `api/*` y traduce el `UnauthorizedHttpException` del middleware `jwt.auth` al mismo sobre.
- Mensajes de cara al usuario en español; nombres de código y PHPDoc en inglés.

## Paginación

- Opt-in por query param `limit`: sin `limit` (o no numérico) el service devuelve una `Collection` completa; con `limit` numérico pagina, acotado a `[10, 100]`. Cada service repite `resolvePerPage()` con sus constantes `MIN_PER_PAGE`/`MAX_PER_PAGE`. Excepción: `FreightRate` no pagina.
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

## Dominio Carriers

- `Carrier` pertenece a un `User` con rol `carrier` (`owner`); los `pilot` se vinculan por la pivote `carrier_pilots` (`Carrier::pilots()` / `User::pilotCarrier()`). `User::currentCarrier()` es la única fuente de verdad de "¿tiene empresa?" (dueño o piloto).
- Un `carrier` solo puede registrar **una** empresa; el service genera un `code` único de 6 caracteres `[A-Z0-9]` y el piloto se une con `POST /api/carriers/join` mandando ese código (se normaliza a mayúsculas).
- `destroy` **no borra nada**: solo resuelve la empresa para responder 404 si no existe.

## Dominio Vehicles

- `Vehicle` pertenece a un `Carrier`. Enums `App\Enums\VehicleType` (truck, van, trailer, pickup) y `VehicleStatus` (active, inactive, under_repair).
- Ámbito: el `administrator` ve todos y puede filtrar por `carrierId`; cualquier otro rol queda acotado a su propia empresa (`resolveScopedCarrierId()`), y tocar un vehículo ajeno es 403. Filtro opcional `status`.
- La placa se normaliza a mayúsculas y es única **solo entre vehículos no desactivados** — un `inactive` la libera —, así que la unicidad no vive en un índice sino en `ensurePlateIsAvailable()`. Reactivar un vehículo revalida su placa.
- `DELETE` es una baja lógica: pasa el `status` a `inactive`; la fila sigue viva y sigue apareciendo en los listados.

## Dominio Pilots (salarios)

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

## Catálogos nacionales (fuel prices, products, zones, freight rates)

Cuatro dominios que no pertenecen a ninguna empresa y comparten reglas:

- **Ninguna ruta lleva `carrier.required`**: son datos nacionales. La lectura queda abierta a cualquier autenticado (`jwt.auth` a secas) y toda escritura es `role:administrator`. Única excepción: `GET /api/freight-rates/{id}` también es admin — quien no administra tarifas cotiza con `/quote`.
- Las rutas fijas (`/current`, `/quote`, `/{id}/toggle-status`, `/{id}/deactivate`) van **antes** del `apiResource`, que se declara sobre `'/'` con `->parameters(['' => 'fuelPrice'])`.
- `registered_by` sale siempre del usuario autenticado, nunca del body, y **no se reescribe** en `update`.
- Los filtros de listado son tolerantes: un `status`/`zoneId`/`lat,lng` inválido se **ignora** en vez de vaciar el listado (`filter_var(..., FILTER_NULL_ON_FAILURE)`).
- El nombre único (`Product`, `Zone`) se normaliza con `Model::normalizeName()` (trim + colapsar espacios + mayúsculas), compartido por FormRequest y service; el service revalida con `ensureNameIsAvailable($name, $ignoreId)` **aunque haya índice único**, para que una llamada directa dé 400 y no 500.

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
- `whereContainsPoint()` es el único sitio con el predicado espacial (`ST_Contains(area::geometry, ...)` — `ST_Contains` no acepta `geography`, y el cast conserva el índice GiST). Lo usan el filtro `?lat=&lng=` del listado y `getZoneContainingPoint()`, del que cuelgan las tarifas.
- Solape permitido: `getZoneContainingPoint()` gana el `id` más bajo y **solo mira zonas activas**; el filtro del listado no filtra por estado. `color` por defecto `#3388FF` (el azul de Leaflet), en mayúsculas. `description` se borra mandando `null` (por eso `array_key_exists`, no `isset`).

### Freight Rates

- `FreightRate` = banda **abierta** por (`zone_id`, `product_id`, `fuel_type`): rige desde `fuel_min` hacia arriba hasta que exista una banda mayor. `price_per_pound` es `decimal:6`.
- Usa `SoftDeletes`. `getFreightRateById()` lee `withTrashed()` a propósito: una tarifa borrada da **400 "ya fue eliminada"**, no 404, así que el segundo DELETE se distingue de un id inexistente.
- Unicidad de banda en `ensureFuelMinIsAvailable()` y **deliberadamente sin índice único**: con soft deletes, un índice bloquearía un `fuel_min` ya borrado. `fuelMinValue()` formatea a 2 decimales antes de comparar, porque si no un `30.005` que Postgres redondea a `30.01` se colaría.
- Crear o editar exige zona **y** producto activos (`ensureZoneAndProductAreActive`), incluso si el PATCH solo mueve el precio; borrar nunca se bloquea.
- `GET /api/freight-rates/quote?lat=&lng=&productId=&fuelType=[&pounds=]`: resuelve zona por punto → producto activo → precio de combustible vigente (**de la BD, jamás de la petición**) → bandas del trío → `resolveBand()` toma la mayor que no supere el precio vigente (si el combustible está por debajo de todas, aplica la más barata, no es error). El orden de los pasos es contrato: cada uno falla con su mensaje. `total = round(pounds * pricePerPound, 2)`, redondeando **después** del producto; sin `pounds`, `total` es `null`. Sale por `FreightQuoteResource`.
- El listado **no pagina nunca**: devuelve `Collection` ordenada por `fuel_type, fuel_min` (la tabla de precios se lee entera).
- El PostGIS no se toca aquí: `ZoneServiceInterface` se inyecta **por constructor** en `FreightRateService`, como los contratos de almacenamiento.

## Almacenamiento de archivos

- Dos contratos en `app/Interfaces/Storage/`, con sus reglas de sustitución escritas en el PHPDoc (qué lanza, qué acepta `null`, qué garantiza la salida), implementados en `app/Services/Storage/` y bindeados por `StorageProvider`:
  - `FileStorageServiceInterface` → `S3FileStorageService`: `store(bytes, directory, extension)` / `delete(?key)` / `url(?key)`. Trabaja contra `Storage::disk()` **por defecto**, nunca contra `'s3'` escrito a mano (por eso el `Storage::fake()` de los tests lo intercepta). Sube con ACL `public-read` explícita; sin ella el objeto queda privado y la URL permanente da 403. Traduce tanto el `false` de retorno como cualquier `Throwable` a `BadRequestError`; `delete()` nunca lanza.
  - `ImageProcessorServiceInterface` → `ImageProcessorService`: `normalizeSquare(UploadedFile)` devuelve `array{contents, extension}`. Recorte cuadrado centrado con `cover()` a **800×800** (Intervention Image, driver GD), recomprimido conservando el formato de entrada (jpg calidad 80 o png). `SIDE` y `JPEG_QUALITY` son constantes de clase, no configuración.
- Ningún archivo fuera de `app/Services/Storage/` menciona `Storage::`, el nombre del disco ni `Intervention\`.
- Los dos contratos se inyectan **por constructor** en los services de dominio (la regla de inyectar por parámetro es solo del controller), que aportan su propio prefijo con la constante `IMAGE_DIRECTORY` (`carriers`, `vehicles`).
- La columna `image` guarda la **key completa** (`carriers/{uuid}.png`), no la URL: cambiar de proveedor no obliga a migrar datos. El Resource la resuelve a URL pública con `app(FileStorageServiceInterface::class)->url($this->image)` — localización de servicio consciente, porque un `JsonResource` se instancia con `new`.
- Ciclo de vida: procesar → subir → persistir. En `update` con imagen nueva, el archivo anterior se borra **después** de guardar la fila. El `DELETE` no toca el archivo en ningún dominio.
- Validación: `image` es `mimes:jpg,jpeg,png` + `max:3072` (3 MB, en kilobytes) en los cuatro FormRequests. Requiere `upload_max_filesize`/`post_max_size` ≥ 4M en cada entorno; si PHP corta antes, el error que ve el usuario es un `required` confuso.

## Documentación OpenAPI

- Anotada con atributos PHP `OpenApi\Attributes as OA` sobre Controller / FormRequest / Resource. Los schemas base (`Info`, `bearerAuth`, `ApiError`, `ValidationError`) viven en `app/Http/Controllers/Controller.php`.
- Regenerar: `php artisan l5-swagger:generate` → `storage/api-docs/api-docs.json`. UI en `/api/documentation`.

## Tests

- Pest 5, **PostgreSQL con PostGIS** (base `legumexapps_transportes_testing`, fijada en `phpunit.xml`), `RefreshDatabase`, `Mail::fake()` y `fakeDefaultDisk()` aplicados globalmente desde `tests/Pest.php`: ningún test manda correo ni sale a la red.
- **La suite no corre sin Postgres levantado.** Desde SPEC 08 las zonas usan `geography(Polygon,4326)` y `ST_Contains`, que SQLite no tiene. La extensión se habilita **por base de datos** (`CREATE EXTENSION IF NOT EXISTS postgis;` dentro de la base de tests), no por servidor; ver README.
- Helpers globales en `tests/Pest.php`: `seedAuthCode()` (planta un código conocido, porque el service solo guarda el hash), `resetAuthState()` (limpia guards y singletons de JWT entre peticiones del mismo test) y `fakeDefaultDisk()` (sustituye el disco por defecto por un fake **con `url`**, porque uno pelado devolvería rutas relativas y la API promete URLs absolutas).
- Dobles de los contratos de almacenamiento en `tests/Doubles/` (`InMemoryFileStorageService`, `StaticImageProcessorService`): se bindean en el contenedor para probar sustituibilidad y los caminos de error sin decodificar imágenes de verdad.
- Helpers locales por archivo de test (ver `tests/Feature/CarrierTest.php`): `userWithRole()`, `asUser()` (llama a `resetAuthState()` y adjunta el token) y un `<recurso>Endpoints()` que alimenta los datasets de middleware.
- Cada dominio lleva Feature test (HTTP, roles y validación) + Unit test del service. Lo que no es service tiene su propio Unit test: `ZoneGeometryTest` (WKT ↔ GeoJSON), `ZoneResourceTest`, `FreightRateModelTest`.
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
