# Legumex Transportes — Backend

API REST interna de transportes (Laravel 13 + PHP 8.5). Sin frontend propio: solo expone JSON bajo `/api`. Autenticación con JWT (`php-open-source-saver/jwt-auth`) y documentación OpenAPI con `darkaonline/l5-swagger`.

## Arquitectura por capas

Cada recurso se implementa con la misma cadena de archivos, agrupados en subcarpeta por dominio (`Auth/`, etc.):

| Capa | Ubicación | Responsabilidad |
|---|---|---|
| Rutas | `routes/<recurso>.php`, incluido desde `routes/api.php` | Prefijo + `name()` propios; middleware `jwt.auth` en lo protegido |
| Controller | `app/Http/Controllers/` | `try/catch` → `ResponseHandler`; sin lógica de negocio; atributos `OpenApi\Attributes` |
| FormRequest | `app/Http/Requests/<Dominio>/` | Validación + `messages()` en español; schema OA del body |
| Resource | `app/Http/Resources/<Dominio>/` | Salida en **camelCase** (`emailVerifiedAt`); schema OA del modelo |
| Interface | `app/Interfaces/<Dominio>/` | Contrato del service, con PHPDoc de array shapes |
| Service | `app/Services/<Dominio>/` | Lógica de negocio; lanza errores de `App\Errors`; `#[Override]` en cada método |
| Provider | `app/Providers/<Dominio>/` | `bind(Interface::class, Service::class)`, registrado en `bootstrap/providers.php` |

El service se inyecta **por parámetro del método del controller** (`public function login(LoginRequest $request, AuthServiceInterface $authService)`), no por constructor.

## Respuestas y errores

- Todo pasa por `App\Helpers\ResponseHandler`: sobre `{ statusCode, message, data }`. `success($data, $message, $statusCode)` resuelve `JsonResource` automáticamente y aplana metadata de paginación.
- Los errores de negocio son subclases de `App\Errors\ApiException` (`BadRequestError`, `UnauthorizedError`, `ForbiddenError`, `NotFoundError`, `NotAcceptable`); el controller los captura y `ResponseHandler::error()` mapea el status. Cualquier otro `Throwable` cae a 500.
- `bootstrap/app.php` renderiza JSON para `api/*` y traduce el `UnauthorizedHttpException` del middleware `jwt.auth` al mismo sobre.
- Mensajes de cara al usuario en español; nombres de código y PHPDoc en inglés.

## Autenticación

- Guard `api` de JWT; alias de middleware `jwt.auth`. `JWT_TTL=60` minutos.
- `User` implementa `JWTSubject` y añade claims `id/name/email/role`; roles en `App\Enums\UserRole` (administrator, carrier, pilot, manager) — solo `pilot` y `carrier` pueden autoregistrarse.
- Flujo: register (sin token, cuenta sin confirmar) → confirm-account → login (emite token) → check-status (único endpoint que renueva token; no hay `/refresh` ni `/logout`).
- Códigos de 6 dígitos hasheados con vigencia de 1 h en `account_confirmation_tokens` y `password_reset_tokens`.

## Documentación OpenAPI

- Anotada con atributos PHP `OpenApi\Attributes as OA` sobre Controller / FormRequest / Resource. Los schemas base (`Info`, `bearerAuth`, `ApiError`, `ValidationError`) viven en `app/Http/Controllers/Controller.php`.
- Regenerar: `php artisan l5-swagger:generate` → `storage/api-docs/api-docs.json`. UI en `/api/documentation`.

## Tests

- Pest 5, SQLite en memoria, `RefreshDatabase` aplicado globalmente desde `tests/Pest.php`.
- Helpers globales en `tests/Pest.php`: `seedAuthCode()` (planta un código conocido, porque el service solo guarda el hash) y `resetAuthState()` (limpia guards y singletons de JWT entre peticiones del mismo test).
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
