---
name: test-endpoint
description: "Use this skill to write Pest tests for ONE specific API endpoint chosen by the user, instead of a whole CRUD feature. Ask the user which endpoint (method + URI, or route name, or 'el store de Camion') when they didn't say, list the candidates from route:list, and then generate only that endpoint's Feature test cases — appending to the existing test file when there is one. Triggers on 'testea el endpoint X', 'tests para POST /api/camiones', 'necesito tests de un endpoint', 'cubre con tests el show de X'. Do not use to test a whole freshly scaffolded feature (that is the feature-tests agent), nor to fix an unrelated failing test."
metadata:
  author: project
---

# Tests de **un** endpoint

Cubres con Pest **un solo** endpoint, el que el usuario elija. Todo lo que quede fuera de ese
endpoint no se toca. No modificas código de aplicación (controller, service, request, resource,
rutas, migraciones): si algo impide testear, lo **reportas**.

Para cubrir los 5 endpoints de una feature recién scaffoldeada existe el agente `feature-tests`;
esta skill es para el caso de uno suelto.

## Paso 0 — Elegir el endpoint

Si el usuario ya nombró el endpoint sin ambigüedad (`POST /api/camiones`, `camiones.store`,
"el show de Camion"), salta a la resolución.

Si no, **no adivines**. Lista los candidatos reales y pregúntale cuál:

```
php artisan route:list --except-vendor --json
```

Preséntale una tabla corta (método, URI, nombre, acción del controller) y pídele que elija uno.
Si dice algo como "los de camiones" (varios), pídele que concrete uno — esta skill trabaja de a
un endpoint; si de verdad quiere todos, propónle el agente `feature-tests`.

### Resolver la elección

Traduce lo que eligió a: **método HTTP + URI + `{Plural}Controller@{accion}`**, confirmándolo
contra `php artisan route:list --path={fragmento}`. Si el endpoint **no aparece** en `route:list`,
detente y repórtalo: las rutas no están cargadas y cualquier test daría 404 por un motivo ajeno
al test.

Repite en una línea lo que vas a testear y sigue: `Voy a testear POST /api/camiones →
CamionesController@store`.

## Paso 1 — Cargar la convención de tests

Invoca la skill `pest-testing` antes de escribir nada. Este proyecto usa Pest 5 sobre PHPUnit 13.

## Paso 2 — Leer solo lo que ese endpoint toca

| Archivo | Qué necesitas |
|---|---|
| `app/Http/Controllers/{Plural}Controller.php` | **solo** el método de la acción elegida: código de estado, `message` literal, si busca por `id` o por `code` |
| el FormRequest que ese método inyecta | reglas reales → de ahí salen los casos `422` (si la acción no valida, no hay casos 422) |
| el Resource que devuelve | claves exactas del JSON |
| `app/Services/{Plural}/{Plural}Service.php` | qué excepción y qué mensaje exacto lanza el método que usa esa acción |
| `app/Models/{Model}.php` + su factory | cómo construir los datos; `SoftDeletes` si aplica |
| la migración de la tabla | `unique`, `nullable`, FKs (para los casos negativos) |
| el archivo de rutas del recurso | middleware del grupo |

No leas ni documentes las otras acciones del controller.

## Paso 3 — Entorno de test (solo la primera vez)

Si `tests/Pest.php` todavía tiene `->use(RefreshDatabase::class)` comentado, descoméntalo:

```php
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

`phpunit.xml` ya usa sqlite `:memory:`; no lo toques. No borres los `ExampleTest` del esqueleto.

## Paso 4 — Autenticación

Comprueba si el alias del middleware del grupo existe:

```
grep -rn "jwt.auth" bootstrap/app.php app/Http/Middleware 2>/dev/null
```

- **Existe**: añade el caso `401` sin token y autentica el resto igual que lo hace el login.
- **No existe**: no inventes middleware ni helpers de auth. Escribe los casos asumiendo acceso
  directo y deja **uno** marcado `->todo('cubrir 401 cuando exista el middleware jwt.auth')`.
  Menciónalo al cerrar.

## Paso 5 — Escribir los casos

Archivo: `tests/Feature/{Plural}Test.php`.

- **Si ya existe**, *append*: añade únicamente los `it()` del endpoint elegido, respetando el
  estilo del archivo. Nunca lo reescribas ni reordenes lo que ya había. Si un caso equivalente
  ya está cubierto, dilo y no lo dupliques.
- **Si no existe**, créalo con solo estos casos.

Casos mínimos según la acción elegida:

| Acción | Casos |
|---|---|
| `index` | `200` sin `?limit` (colección completa); `200` con `?limit=2` y el sobre paginado (`total`, `currentPage`, `lastPage` en la raíz) |
| `store` | `201` + `assertDatabaseHas`; un `422` por cada campo `required` ausente; `422` por `unique` duplicado; `422` por FK inexistente; `422` por tipo inválido en `boolean`/`integer`/`date` |
| `show` | `200` con las claves exactas del Resource; `404` con el mensaje español del service |
| `update` | `200` + `assertDatabaseHas` con los valores nuevos; `200` al reenviar su **propio** valor `unique` (la regla debe ignorarse a sí misma); `404` con id/code inexistente |
| `destroy` | `200` + `assertDatabaseMissing` (o `assertSoftDeleted`); `404` inexistente |

Forma de las aserciones, acorde a `App\Helpers\ResponseHandler`:

```php
$response->assertCreated()
    ->assertJson([
        'statusCode' => 201,
        'message' => 'Camión creado correctamente',
    ])
    ->assertJsonStructure(['statusCode', 'message', 'data' => ['id', 'placa']]);
```

Reglas:

- Los modelos se crean **siempre** con factory (`Camion::factory()->create()`), las FKs con la
  factory del relacionado; nada de inserts a mano. Si el modelo no tiene factory, créala — es
  infraestructura de test y sí entra en tu alcance.
- Los `message` esperados se copian **literales** del controller; nunca de memoria.
- Un `it()` por comportamiento, descripción en español. Usa datasets cuando el mismo caso se
  repite campo por campo.

## Paso 6 — Cerrar

1. `php artisan test --compact --filter={Plural}` hasta que todo pase (incluido lo que ya existía
   en el archivo).
2. Si falla por un bug real del código de aplicación, **no lo arregles ni relajes la aserción**:
   deja el test con el comportamiento correcto, márcalo `->todo(...)` describiendo el bug y
   repórtalo.
3. `vendor/bin/pint --dirty --format agent`.
4. Resumen breve: endpoint cubierto, casos añadidos, resultado de la corrida, y qué quedó fuera
   (auth, bugs, casos ya existentes que no duplicaste).
