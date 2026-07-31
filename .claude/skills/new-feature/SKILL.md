---
name: new-feature
description: "Use this skill to scaffold a full CRUD API feature in this Laravel backend, either starting from an existing model in app/Models or creating a brand-new model from scratch by asking the user for its name, fields, field types and foreign keys. Triggers when the user asks to 'create a feature for <Model>', 'add CRUD for <Model>', 'expose <Model> via API', 'crear modelo X con CRUD', 'nueva feature desde cero', or asks to finish a half-built resource (model + migration exist but no controller/service/routes yet). Generates the model, migration and factory when missing, then — in the project's own layered convention — the Interface, Service, Provider, FormRequests, Resources, Controller, and route file, wiring them into bootstrap/providers.php and routes/api.php. Do not use for plain Eloquent tweaks, unrelated bug fixes, or frontend work."
metadata:
  author: project
---

# Model → Feature Scaffold

This backend does **not** use plain Laravel controllers. Every resource is built as a small
layered stack: `Model → Interface → Service → Provider → FormRequests → Resources → Controller → Routes`.
Before generating anything, study the 2-3 existing features that are structurally closest to the
target model (same relation shape) and mirror their exact conventions — this codebase is **not**
perfectly uniform between features (see "Known inconsistencies" below), so copy the closest sibling
rather than assuming one universal rule.

## Step 0 — Determine the target model

If the user didn't name the model, **ask for it**. It must be singular `StudlyCase`, in the domain's
language (e.g. `Camion`, `Piloto`, `Ruta`).

Then `ls app/Models` to check whether it already exists.

### If the model already exists

Read `app/Models/{Model}.php` and note:

- **Fillable fields** — declared via `#[Fillable([...])]` attribute above the class (Laravel 13 style),
  not a `protected $fillable` property.
- **Relations** — `belongsTo()` methods reveal foreign keys (e.g. `weekly_plan_id`, `line_sku_id`).
- **`use HasFactory;`** — present only on some models. Only create a factory if the model uses it
  (or add the trait if the user wants one and it's missing).
- **A unique business identifier** — some resources are looked up in the URL by a `code` column
  instead of the numeric `id` (e.g. `PackingMaterial`, `Sku`, `Line`). Check the migration for a
  `->unique()` string column named `code` to decide whether the service needs a `getXByCode()` method.

Also confirm a migration exists for the table (`database/migrations/*create_{table}*`). With the model
and migration in place (common when a feature was started but left unfinished), **skip straight to
Step 5**.

### If the model does not exist

Continue to Step 1 to collect its schema.

## Step 1 — Collect the schema

Ask the user for **all** the fields in a single message, showing them this mini-spec:

```
<campo>:<tipo> [nullable] [unique] [index] [default=<valor>]
<campo>_id:fk-><tabla> [nullable] [cascade|restrict|nullOnDelete]
```

Example of what they'd reply:

```
placa:string unique
capacidad:decimal(8,2)
activo:boolean default=true
piloto_id:fk->pilotos nullable
```

### Type reference

| spec           | migration (Blueprint)                         | `casts()` entry |
|----------------|-----------------------------------------------|-----------------|
| `string`       | `->string('x')`                               | —               |
| `string(50)`   | `->string('x', 50)`                           | —               |
| `text`         | `->text('x')`                                 | —               |
| `integer`      | `->integer('x')`                              | `'integer'`     |
| `decimal(8,2)` | `->decimal('x', 8, 2)`                        | `'decimal:2'`   |
| `float`        | `->float('x')`                                | `'float'`       |
| `boolean`      | `->boolean('x')`                              | `'boolean'`     |
| `date`         | `->date('x')`                                 | `'date'`        |
| `datetime`     | `->dateTime('x')`                             | `'datetime'`    |
| `time`         | `->time('x')`                                 | —               |
| `json`         | `->json('x')`                                 | `'array'`       |
| `enum(a,b,c)`  | `->enum('x', ['a','b','c'])`                  | —               |
| `fk-><tabla>`  | `->foreignId('x_id')->constrained('<tabla>')` | —               |

### Foreign key rules

- If the user writes `piloto_id:fk` **without** a table, ask explicitly which table it points to — never
  guess.
- Verify the referenced table actually exists in `database/migrations/`. If it doesn't, say so and ask
  whether that migration should be created first — the migration timestamp order matters, the referenced
  table must be created before this one.
- Only omit the `constrained()` argument when the table name exactly matches the English plural of the
  column minus `_id`. With Spanish names this often breaks (`camion_id` infers `camions`, not `camiones`),
  so **always pass the table explicitly** — it is never wrong.
- Chain order matters: `->nullable()` goes **before** `->constrained()`, and the delete behaviour last:
  `$table->foreignId('piloto_id')->nullable()->constrained('pilotos')->nullOnDelete();`
  Options are `->cascadeOnDelete()`, `->restrictOnDelete()` (Laravel's default), `->nullOnDelete()`.

### Confirm before generating

Print the parsed schema as a table and **wait for the user to confirm** before touching any file:

```
| Campo      | Tipo         | Null | Extra      |
|------------|--------------|------|------------|
| placa      | string       | no   | unique     |
| capacidad  | decimal(8,2) | no   |            |
| activo     | boolean      | no   | default=1  |
| piloto_id  | foreignId    | sí   | -> pilotos |
```

Carry this confirmed spec forward — Steps 3, 4, 8 and 9 all derive from it, so there's no need to
re-read the migration later.

## Step 2 — Create the model (and its migration + factory)

```
php artisan make:model {Model} -m -f --no-interaction
```

`-m` creates the migration, `-f` the factory.

**Table name gotcha.** Laravel's pluralizer is English-only, so Spanish model names frequently produce a
wrong table (`Camion` → `camions`, `Mes` → `mes`). Right after generating:

1. Check the real name: `php artisan tinker --execute '(new App\Models\{Model})->getTable();'`
2. If it's wrong, pin it with the Laravel 13 `#[Table]` attribute **and** rename the generated migration
   file to `..._create_{tabla_correcta}_table.php`, updating its `Schema::create('{tabla_correcta}', ...)`.

Then edit the model, following the style of `app/Models/User.php` — Laravel 13 uses **attributes**, not
`protected $fillable` properties:

```php
<?php

namespace App\Models;

use Database\Factories\CamionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('camiones')]
#[Fillable(['placa', 'capacidad', 'activo', 'piloto_id'])]
class Camion extends Model
{
    /** @use HasFactory<CamionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'capacidad' => 'decimal:2',
        ];
    }

    public function piloto(): BelongsTo
    {
        return $this->belongsTo(Piloto::class);
    }
}
```

- `#[Fillable([...])]` lists every field from the spec (never `id` or timestamps).
- `casts()` only when at least one field has a cast in the type table above — otherwise omit the method
  entirely.
- One `belongsTo()` per foreign key, return-typed `BelongsTo`. The method name is the column without
  `_id`, in camelCase.
- `#[Table]` only when the pluralizer got it wrong.
- Other Laravel 13 attributes available if they apply: `#[Hidden]`, `#[Appends]`, `#[RouteKey]` (the last
  one is handy when the resource is looked up by `code` instead of `id` — see Step 10).

## Step 3 — Fill the migration

Laravel 13 migrations are anonymous classes:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camiones', function (Blueprint $table) {
            $table->id();
            $table->string('placa')->unique();
            $table->decimal('capacidad', 8, 2);
            $table->boolean('activo')->default(true);
            $table->foreignId('piloto_id')->nullable()->constrained('pilotos')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('camiones');
    }
};
```

- Always the anonymous class form, never a named migration class.
- `$table->id()` first, `$table->timestamps()` last, the user's fields in the order they gave them.
- `constrained()` already creates the index — don't add a redundant `->index()` on foreign keys.
- `down()` is always `Schema::dropIfExists`.
- If the user asked for soft deletes: `$table->softDeletes()` plus the `SoftDeletes` trait on the model.

## Step 4 — Factory

Already generated by `-f` in Step 2, so just fill `definition()`. One value per spec field, matching its
declared type, and `{Related}::factory()` for every foreign key:

```php
public function definition(): array
{
    return [
        'placa' => fake()->bothify('P-####'),
        'capacidad' => fake()->randomFloat(2, 1, 100),
        'activo' => fake()->boolean(),
        'piloto_id' => Piloto::factory(),
    ];
}
```

Common generators by type: `string` → `fake()->word()`, `text` → `fake()->paragraph()`, `integer` →
`fake()->numberBetween(1, 100)`, `decimal` → `fake()->randomFloat(2, 1, 100)`, `boolean` →
`fake()->boolean()`, `date` → `fake()->date()`, `datetime` → `fake()->dateTime()`, `enum` →
`fake()->randomElement([...])`.

If the model doesn't use `HasFactory`, delete the generated factory instead.

## Step 5 — Interface

`app/Interfaces/{Plural}/{Plural}ServiceInterface.php`:

```php
<?php

namespace App\Interfaces\{Plural};

interface {Plural}ServiceInterface
{
    public function create{Model}(array $data);

    public function get{Plural}(?string $limit);

    public function get{Model}ById(string $id);

    // Only if the model has a unique `code` column looked up from the URL:
    // public function get{Model}ByCode(string $code);

    public function update{Model}ById(string $id, array $data);

    public function delete{Model}ById(string $id);
}
```

Check the closest sibling interface for **argument order on the update method** — this codebase has
both `updateXById(string $id, array $data)` (e.g. `PackingMaterialsServiceInterface`,
`ClientsServiceInterface`) and `updateXById(array $data, string $id)` (e.g. `PositionsServiceInterface`,
`WeeklyPlanEmployeesServiceInterface`). Pick whichever the nearest analogous feature uses, and keep the
Service and Controller calls consistent with the interface you write.

## Step 6 — Service

`app/Services/{Plural}/{Plural}Service.php`, implementing the interface, with `#[Override]` on every
method (import `use Override;`):

```php
<?php

namespace App\Services\{Plural};

use App\Errors\NotFoundError;
use App\Interfaces\{Plural}\{Plural}ServiceInterface;
use App\Models\{Model};
use Override;

class {Plural}Service implements {Plural}ServiceInterface
{
    #[Override]
    public function create{Model}(array $data)
    {
        return {Model}::create($data);
    }

    #[Override]
    public function get{Plural}(?string $limit)
    {
        $query = {Model}::query();

        if ($limit) {
            return $query->paginate($limit);
        }

        return $query->get();
    }

    #[Override]
    public function get{Model}ById(string $id)
    {
        ${model} = {Model}::find($id);
        if (! ${model}) {
            throw new NotFoundError('El {nombre en español} no existe');
        }

        return ${model};
    }

    #[Override]
    public function update{Model}ById(string $id, array $data)
    {
        ${model} = $this->get{Model}ById($id);
        ${model}->update($data);

        return true;
    }

    #[Override]
    public function delete{Model}ById(string $id)
    {
        ${model} = $this->get{Model}ById($id);
        ${model}->delete();

        return true;
    }
}
```

If the model has a business `code` lookup, add `get{Model}ByCode(string $code)` using
`{Model}::where('code', '=', $code)->first()`, and have `show`/`update`/`destroy` in the controller call
that instead of the by-id lookup (see `PackingMaterialsService`).

Error messages are always **Spanish**, phrased "El/La {recurso} no existe". Use `NotFoundError` (404)
for missing records and `BadRequestError` (400) from `App\Errors` for validation-style failures inside
the service (e.g. bulk import row errors) — both extend `App\Errors\ApiException`.

## Step 7 — Provider

`app/Providers/{Plural}/{Plural}Provider.php`:

```php
<?php

namespace App\Providers\{Plural};

use App\Interfaces\{Plural}\{Plural}ServiceInterface;
use App\Services\{Plural}\{Plural}Service;
use Illuminate\Support\ServiceProvider;

class {Plural}Provider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind({Plural}ServiceInterface::class, {Plural}Service::class);
    }

    public function boot(): void
    {
        //
    }
}
```

Register it in `bootstrap/providers.php`: add the `use` import and append `{Plural}Provider::class,` to
the returned array (keep the list roughly alphabetical, matching current order).

## Step 8 — FormRequests

`app/Http/Requests/{Plural}/Create{Model}Request.php` and `Update{Model}Request.php`. Both have
`authorize(): bool` (return `true` unless the closest sibling restricts by role — see
`CreatePositionRequest` which blocks non-`admin` users; ask the user if unsure whether this resource
needs that), `rules(): array`, and `messages(): array` with **Spanish** messages for every rule.

Derive the rules from the **schema confirmed in Step 1** (`string`/`integer`/`numeric`/`boolean`/`date`),
not by re-reading the migration:

- Foreign key fields: `['required', 'integer', 'exists:{table},id']` — `{table}` is the table the user
  declared in the `fk->` spec.
- A unique `code` field on create: `['required', 'string', 'unique:{table},code']`.
- On update, the same field uses `Rule::unique('{table}', 'code')->ignore($this->route('{singular_snake_uri_param}'), 'code')`
  — the route param name is the singular snake_case of the `apiResource` URI segment (e.g. URI
  `/packing-materials` → route param `packing_material`).
- Booleans: `['nullable', 'boolean']` (or `['boolean']` if not optional — match sibling).
- Fields marked `nullable` in the spec use `nullable` instead of `required`.

## Step 9 — Resources

`app/Http/Resources/{Plural}/{Model}Resource.php` — plain `toArray()` mapping the fields from the Step 1
spec (never dump raw `$this->getAttributes()`; list fields explicitly, casting booleans with
`? true : false` if stored as tinyint).

`app/Http/Resources/{Plural}/Paginated{Plural}Resource.php` — wraps a paginator:

```php
public function toArray(Request $request): array
{
    $items = {Model}Resource::collection($this->items());

    return [
        'data' => $items,
        'total' => $this->total(),
        'currentPage' => $this->currentPage(),
        'lastPage' => $this->lastPage(),
    ];
}
```

## Step 10 — Controller

`app/Http/Controllers/{Plural}Controller.php`. Every action wraps its body in `try { ... } catch
(\Throwable $th) { return ResponseHandler::error($th); }`. Success responses use
`ResponseHandler::success($data, '{Mensaje en español}', {statusCode})` — `201` for `store`, `200`
otherwise. `index` chooses between the paginated resource and the plain collection based on
`$request->query('limit')`, exactly like `PackingMaterialsController::index`. Inject
`{Plural}ServiceInterface` (not the concrete service) as a controller-method parameter — this project
uses method injection, not constructor injection.

## Step 11 — Routes

Decide whether this resource belongs inside an **existing** domain route file (e.g. `routes/skus.php`
already groups `SkusController`, `LineSkusController`, and `SkuPackingMaterialsController` together) or
needs its own new file. If new, create `routes/{lowercasenospaces}.php`:

```php
<?php

use App\Http\Controllers\{Plural}Controller;
use Illuminate\Support\Facades\Route;

Route::middleware('jwt.auth')->group(function () {
    Route::apiResource('/{kebab-plural}', {Plural}Controller::class);
});
```

Then add `require __DIR__.'/{lowercasenospaces}.php';` to `routes/api.php` (append at the bottom,
matching existing require order). If adding to an existing file instead, just append the
`Route::apiResource(...)` line inside the existing `jwt.auth` group.

## Step 12 — Finish

1. Run `vendor/bin/pint --dirty --format agent` to fix formatting on every file you touched.
2. Sanity-check with `php artisan route:list --path={kebab-plural}`.
3. If a migration was created, verify it with `php artisan migrate --pretend` before running it.
4. Do **not** write tests or Swagger annotations yourself — Step 13 delegates both.

## Step 13 — Hand off to the follow-up agents

The user has **standing approval** for this hand-off: as soon as the scaffold above is complete,
launch both agents with the `Agent` tool, in the **same message** so they run in parallel (they touch
disjoint files — one writes only under `tests/`, the other only adds attributes to already-generated
classes):

- `subagent_type: "feature-tests"` — Pest Feature (HTTP) + Unit (Service) tests.
- `subagent_type: "endpoint-docs"` — Swagger/OpenAPI attributes + `l5-swagger:generate`.

Give each the same short brief: the model name, its plural, the route URI, and the route file you
created. Example prompt: `Feature recién generada: modelo Camion, plural Camiones, ruta
/api/camiones en routes/camiones.php. Documenta/testea esa feature.`

Skip the hand-off only if the user explicitly said they don't want tests or docs. When they run in
the background, tell the user both are running and finish your own summary — don't wait idle.

When both report back, relay in one paragraph: tests created + result of the run, endpoints
documented, and anything either agent flagged as uncovered or inconsistent.

## Known inconsistencies to watch for (don't "fix" them, just match locally)

- `update{Model}ById` argument order (`$id, $data` vs `$data, $id`) differs between existing features.
- Lookup key for `show`/`update`/`destroy` is sometimes the numeric `id`, sometimes a business `code`
  column — depends on the model.
- Most route files are named after a single resource, but a few (`routes/skus.php`) intentionally group
  several related resources under one domain file.
- Laravel's pluralizer is English-only while the domain is in Spanish — always confirm `getTable()` after
  creating a model and pin it with `#[Table]` when it doesn't match.
