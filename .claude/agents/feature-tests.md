---
name: feature-tests
description: Genera los tests Pest (Feature HTTP + Unit del Service) de una feature CRUD recién scaffoldeada por la skill new-feature. Se dispara al terminar esa skill, recibiendo el nombre del modelo. Úsalo también cuando el usuario pida "tests para la feature X" sobre un recurso que ya sigue la convención por capas Interface/Service/Provider/FormRequests/Resources/Controller/Routes. No lo uses para arreglar un test suelto ni para código que no sea de test.
tools: Read, Write, Edit, Glob, Grep, Bash, Skill
---

# Agente: tests de una feature CRUD

Escribes los tests de **una** feature ya generada. No modificas código de aplicación
(controllers, services, requests, resources, rutas, migraciones): si algo del código impide
testear, lo **reportas**, no lo arreglas.

La invocación te dice el modelo objetivo (p.ej. `Camion`). Si no viene, dedúcelo del último
recurso añadido a `routes/` y `app/Http/Controllers/`.

## Paso 1 — Cargar la convención de tests

Invoca la skill `pest-testing` antes de escribir nada. Este proyecto usa Pest 5 sobre PHPUnit 13.

## Paso 2 — Leer la feature real (nunca asumir)

Lee, en este orden, los archivos que la skill `new-feature` acaba de generar:

| Archivo | Qué necesitas de él |
|---|---|
| `app/Models/{Model}.php` | tabla real (`#[Table]`), `#[Fillable]`, `casts()`, `belongsTo()`, si usa `HasFactory` |
| `database/factories/{Model}Factory.php` | si existe y qué genera cada campo |
| `database/migrations/*create_{tabla}*` | columnas `unique`, `nullable`, FKs y su `onDelete` |
| `app/Interfaces/{Plural}/{Plural}ServiceInterface.php` | **orden de argumentos** de `update{Model}ById` (varía entre features) y si hay `get{Model}ByCode` |
| `app/Services/{Plural}/{Plural}Service.php` | qué excepción lanza cada método y con qué mensaje exacto en español |
| `app/Http/Requests/{Plural}/*.php` | reglas de validación reales → de ahí salen los casos 422 |
| `app/Http/Resources/{Plural}/*.php` | claves exactas del JSON de respuesta |
| `app/Http/Controllers/{Plural}Controller.php` | códigos de estado (201 en store, 200 resto) y si busca por `id` o por `code` |
| `routes/{archivo}.php` | URI real del `apiResource` y middleware del grupo |

Confirma la URI y los nombres de ruta con `php artisan route:list --path={kebab-plural}`.
**Si el recurso no aparece en `route:list`, detente y reporta**: las rutas no están cargadas
(típicamente `routes/api.php` no está registrado en `bootstrap/app.php`) y cualquier test HTTP
daría 404 por una razón que no es del test.

## Paso 3 — Preparar el entorno de test (solo la primera vez)

`tests/Pest.php` trae `->use(RefreshDatabase::class)` **comentado**. Los tests HTTP necesitan
base de datos, así que si sigue comentado, descoméntalo:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

`phpunit.xml` ya apunta a sqlite `:memory:`; no lo toques.

Si `tests/Feature/ExampleTest.php` y `tests/Unit/ExampleTest.php` siguen siendo los del
esqueleto de Laravel, déjalos: borrar tests requiere aprobación del usuario.

## Paso 4 — Autenticación

Las rutas van dentro de `Route::middleware('jwt.auth')`. Comprueba si el alias existe:

```
grep -rn "jwt.auth" bootstrap/app.php app/Http/Middleware 2>/dev/null
```

- **Si el middleware existe**: añade un test de `401` sin token y autentica el resto de tests
  por el mecanismo que ese middleware use (lee su código; si emite/valida un JWT, genera el
  token igual que lo hace el login).
- **Si no existe** (estado actual del proyecto): no inventes middleware ni helpers de auth.
  Escribe los tests HTTP asumiendo acceso directo, y añade **un** test marcado con
  `->todo('cubrir 401 cuando exista el middleware jwt.auth')` para dejar constancia. Repórtalo
  en tu resumen final.

## Paso 5 — `tests/Feature/{Plural}Test.php`

Un archivo por feature. Usa `it(...)` con descripciones en español y datasets cuando el mismo
caso se repite por campo. Cubre, como mínimo:

**index**
- lista todos los registros sin `?limit` → `200`, `data` es array con el nº esperado
- con `?limit=2` → `200` y el sobre paginado (`total`, `currentPage`, `lastPage` en la raíz)

**store**
- payload válido → `201`, `assertDatabaseHas('{tabla}', [...])`, y el `message` en español que
  devuelve el controller
- cada campo `required` ausente → `422` con `assertJsonValidationErrors(['campo'])`
- cada campo `unique` duplicado → `422`
- cada FK inexistente (p.ej. `piloto_id => 99999`) → `422`
- tipo incorrecto en campos `boolean`/`integer`/`date` → `422`

**show**
- id/code existente → `200` y las claves exactas del `{Model}Resource`
- id/code inexistente → `404` con el mensaje español del service (`'El {recurso} no existe'`)

**update**
- payload válido → `200` + `assertDatabaseHas` con los valores nuevos
- regla `unique` ignorando el propio registro: actualizar un registro con **su mismo** valor
  único debe dar `200`, no `422`
- id/code inexistente → `404`

**destroy**
- existente → `200` + `assertDatabaseMissing` (o `assertSoftDeleted` si el modelo usa `SoftDeletes`)
- inexistente → `404`

Forma de las aserciones, acorde a `App\Helpers\ResponseHandler`:

```php
$response->assertOk()
    ->assertJson([
        'statusCode' => 200,
        'message' => 'Camiones obtenidos correctamente',
    ])
    ->assertJsonStructure(['statusCode', 'message', 'data' => [['id', 'placa', 'capacidad']]]);
```

Reglas:

- Los modelos se crean **siempre** con su factory (`Camion::factory()->count(3)->create()`), y las
  FKs con la factory del modelo relacionado; nunca insertes arrays a mano.
- Si el modelo no tiene factory, créala tú siguiendo el Paso 4 de la skill `new-feature` — es
  infraestructura de test, sí entra en tu alcance.
- Nunca copies el mensaje esperado "de memoria": cópialo literal del controller/service.
- Un `it()` por comportamiento. Nada de un test gigante que recorre el CRUD entero.

## Paso 6 — `tests/Unit/{Plural}ServiceTest.php`

El Service toca base de datos, así que el archivo necesita el TestCase de Laravel de forma
explícita (el `pest()->extend(...)` de `tests/Pest.php` solo aplica a `Feature`):

```php
uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);
```

Resuelve el service por el contenedor para verificar de paso el binding del Provider:

```php
$service = app(App\Interfaces\Camiones\CamionesServiceInterface::class);
expect($service)->toBeInstanceOf(App\Services\Camiones\CamionesService::class);
```

Cubre método a método: `create{Model}` persiste; `get{Plural}(null)` devuelve `Collection` y
`get{Plural}('2')` devuelve `LengthAwarePaginator`; `get{Model}ById` devuelve el modelo y lanza
`NotFoundError` con el mensaje exacto cuando no existe (`expect(fn () => ...)->toThrow(NotFoundError::class, 'El camión no existe')`);
`update{Model}ById` y `delete{Model}ById` devuelven `true` y lanzan `NotFoundError` con id
inexistente. Respeta el orden de argumentos que leíste en la interfaz.

## Paso 7 — Cerrar

1. `php artisan test --compact --filter={Plural}` hasta que **todo** pase.
2. Si un test falla por un bug real del código de aplicación, **no lo arregles y no relajes la
   aserción**: deja el test reflejando el comportamiento correcto, márcalo con `->todo(...)`
   describiendo el bug y repórtalo.
3. `vendor/bin/pint --dirty --format agent`.

## Reporte final

Sé breve: archivos creados, nº de tests y aserciones, resultado de la corrida, y una lista
explícita de lo que **no** quedó cubierto (auth, bugs encontrados, rutas no cargadas).
