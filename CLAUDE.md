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

En un `apiResource`, las rutas fijas (`/join`, `/me`, `/me/pilots`) se declaran **antes** del resource: si no, las captura el comodín `{carrier}`.

## Respuestas y errores

- Todo pasa por `App\Helpers\ResponseHandler`: sobre `{ statusCode, message, data }`. `success($data, $message, $statusCode)` resuelve `JsonResource` automáticamente y aplana metadata de paginación.
- Los errores de negocio son subclases de `App\Errors\ApiException` (`BadRequestError`, `UnauthorizedError`, `ForbiddenError`, `NotFoundError`, `NotAcceptable`); el controller los captura y `ResponseHandler::error()` mapea el status. Cualquier otro `Throwable` cae a 500.
- `bootstrap/app.php` renderiza JSON para `api/*` y traduce el `UnauthorizedHttpException` del middleware `jwt.auth` al mismo sobre.
- Mensajes de cara al usuario en español; nombres de código y PHPDoc en inglés.

## Paginación

- Opt-in por query param `limit`: sin `limit` (o no numérico) el service devuelve una `Collection` completa; con `limit` numérico pagina, acotado a `[10, 100]`.
- El listado paginado se envuelve en `App\Http\Resources\PaginatedResource` (`new PaginatedResource($paginator, CarrierResource::class)`), y `ResponseHandler` funde `total/currentPage/lastPage` en la raíz del sobre, no bajo `meta`.

## Autenticación y autorización

- Guard `api` de JWT; alias de middleware `jwt.auth`. `JWT_TTL=60` minutos.
- `User` implementa `JWTSubject` y añade claims `id/name/email/role` + `carrierId/carrierName/carrierCode` (null si no tiene empresa; **informativos para el front, nunca fuente de verdad para autorizar**). Roles en `App\Enums\UserRole` (administrator, carrier, pilot, manager) — solo `pilot` y `carrier` pueden autoregistrarse.
- Flujo: register (sin token, cuenta sin confirmar) → confirm-account → login (emite token) → check-status (único endpoint que renueva token; no hay `/refresh` ni `/logout`).
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

- Pest 5, SQLite en memoria, `RefreshDatabase`, `Mail::fake()` y `fakeDefaultDisk()` aplicados globalmente desde `tests/Pest.php`: ningún test manda correo ni sale a la red.
- Helpers globales en `tests/Pest.php`: `seedAuthCode()` (planta un código conocido, porque el service solo guarda el hash), `resetAuthState()` (limpia guards y singletons de JWT entre peticiones del mismo test) y `fakeDefaultDisk()` (sustituye el disco por defecto por un fake **con `url`**, porque uno pelado devolvería rutas relativas y la API promete URLs absolutas).
- Dobles de los contratos de almacenamiento en `tests/Doubles/` (`InMemoryFileStorageService`, `StaticImageProcessorService`): se bindean en el contenedor para probar sustituibilidad y los caminos de error sin decodificar imágenes de verdad.
- Helpers locales por archivo de test (ver `tests/Feature/CarrierTest.php`): `userWithRole()`, `asUser()` (llama a `resetAuthState()` y adjunta el token) y un `<recurso>Endpoints()` que alimenta los datasets de middleware.
- Cada dominio lleva Feature test (HTTP, roles y validación) + Unit test del service.
- Ejecutar: `php artisan test --compact` (o `--filter=`).

## Flujo de trabajo

- Specs en `specs/NN-slug.md`; `/spec-impl` crea la rama `spec-NN-slug` automáticamente (`specs/.spec-config.yml`).
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
