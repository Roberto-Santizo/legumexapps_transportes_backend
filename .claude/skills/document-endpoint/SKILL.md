---
name: document-endpoint
description: "Use this skill to document ONE specific API endpoint chosen by the user in Swagger/OpenAPI, instead of a whole CRUD feature. Ask the user which endpoint (method + URI, or route name, or 'el store de Camion') when they didn't say, list the candidates from route:list, then add the OpenApi\\Attributes for just that action on the Controller — plus the request/resource schemas it needs — and regenerate storage/api-docs/api-docs.json. Triggers on 'documenta el endpoint X', 'swagger para POST /api/camiones', 'agrega este endpoint a la documentación', 'falta documentar el show de X'. Do not use to document a whole freshly scaffolded feature (that is the endpoint-docs agent), nor for prose docs or README."
metadata:
  author: project
---

# Documentación Swagger de **un** endpoint

Documentas con `darkaonline/l5-swagger` (swagger-php 6) y **atributos PHP** —nunca docblocks
`@OA\...`— **un solo** endpoint, el que el usuario elija. No cambias comportamiento: solo añades
atributos. Si al documentar descubres un bug o una incoherencia, lo reportas.

Para documentar los 5 endpoints de una feature recién scaffoldeada existe el agente
`endpoint-docs`; esta skill es para el caso de uno suelto.

## Paso 0 — Elegir el endpoint

Si el usuario ya lo nombró sin ambigüedad (`POST /api/camiones`, `camiones.store`, "el show de
Camion"), salta a la resolución.

Si no, **no adivines**. Lista los candidatos reales y pregúntale cuál:

```
php artisan route:list --except-vendor --json
```

Muéstrale una tabla corta (método, URI, nombre, acción del controller) y, si te resulta útil,
marca cuáles ya tienen atributos `OA\` y cuáles no. Si pide varios, pídele que concrete uno; si
de verdad quiere la feature entera, propónle el agente `endpoint-docs`.

### Resolver la elección

Traduce lo elegido a **método HTTP + path real + `{Plural}Controller@{accion}`**, confirmándolo
con `php artisan route:list --path={fragmento}`. Documenta el path **tal cual sale ahí**, incluido
el prefijo `api/` y el nombre del parámetro (`{camion}`), no como crees que debería ser.

Repite en una línea lo que vas a documentar y sigue.

## Contexto ya montado (no lo rehagas)

- `app/Http/Controllers/Controller.php` (clase base) ya tiene `#[OA\Info]`, `#[OA\Server]`, el
  security scheme **`bearerAuth`** y los schemas **`ApiError`** y **`ValidationError`**. Léelo y
  reutilízalos por `ref`; nunca los redefinas.
- Config en `config/l5-swagger.php`: escanea `app/`, UI en `/api/documentation`, JSON en
  `storage/api-docs/api-docs.json`.
- Sobre de `App\Helpers\ResponseHandler::success()`: `{ statusCode, message, data }`. Cuando el
  `data` recibido es un array con clave `data` y más claves (el recurso paginado), esas claves
  extra se **funden en la raíz**: `{ statusCode, message, data: [...], total, currentPage, lastPage }`.
- Errores (`ResponseHandler::error()`): `{ statusCode, message, data: null }` → `ApiError`.

## Paso 1 — Leer solo lo que ese endpoint toca

| Archivo | Qué necesitas |
|---|---|
| `app/Http/Controllers/{Plural}Controller.php` | **solo** el método elegido: código de estado, `message` literal, si busca por `id` o por `code` |
| el archivo de rutas del recurso | middleware del grupo (`jwt.auth` ⇒ `security` + `401`) |
| el FormRequest que inyecta (si lo hay) | campos, `required`/`nullable`, tipos, `unique`, `exists` |
| el Resource que devuelve | claves y tipos exactos |
| `app/Models/{Model}.php` | casts, para tipar bien `boolean`/`decimal`/`date` |

No documentes las otras acciones del controller.

## Paso 2 — Schemas que falten (y solo esos)

Antes de crear un schema, comprueba si ya existe: `grep -rn "schema: '{Nombre}'" app/`.

- **Schema del recurso** — sobre la clase `{Model}Resource`, si aún no lo tiene:

  ```php
  use OpenApi\Attributes as OA;

  #[OA\Schema(
      schema: 'Camion',
      title: 'Camión',
      properties: [
          new OA\Property(property: 'id', type: 'integer', example: 1),
          new OA\Property(property: 'placa', type: 'string', example: 'P-1234'),
          new OA\Property(property: 'activo', type: 'boolean', example: true),
          new OA\Property(property: 'piloto_id', type: 'integer', nullable: true, example: 3),
      ],
      type: 'object',
  )]
  ```

  Una propiedad por clave que el `toArray()` devuelve **de verdad**, en el mismo orden, con
  `nullable: true` donde la migración lo permita.

- **Schema paginado** — solo si documentas un `index`: `Paginated{Plural}` sobre
  `Paginated{Plural}Resource`, con `data` como array de `#/components/schemas/{Model}` más
  `total`, `currentPage`, `lastPage`.

- **Schema de request** — solo si la acción recibe body (`store`/`update`): sobre su FormRequest,
  con exactamente los campos de sus `rules()`; `required` refleja las reglas `required` reales y
  los `nullable` van con `nullable: true`.

## Paso 3 — Atributo del endpoint

Sobre el método del controller, el verbo correspondiente. Si el grupo de rutas lleva `jwt.auth`,
va `security: [['bearerAuth' => []]]` y el `401`. `tags` es el plural del recurso.

```php
#[OA\Post(
    path: '/api/camiones',
    operationId: 'storeCamion',
    summary: 'Crear un camión',
    description: 'Registra un camión nuevo.',
    security: [['bearerAuth' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(ref: '#/components/schemas/CreateCamionRequest'),
    ),
    tags: ['Camiones'],
    responses: [
        new OA\Response(
            response: 201,
            description: 'Camión creado correctamente',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                    new OA\Property(property: 'message', type: 'string', example: 'Camión creado correctamente'),
                    new OA\Property(property: 'data', ref: '#/components/schemas/Camion'),
                ],
                type: 'object',
            ),
        ),
        new OA\Response(response: 422, description: 'Datos inválidos', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        new OA\Response(response: 401, description: 'Token ausente o inválido', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
    ],
)]
public function store(CreateCamionRequest $request, CamionesServiceInterface $service) { ... }
```

Respuestas mínimas según la acción:

| Acción | Verbo / path | Respuestas |
|---|---|---|
| `index` | `GET /api/{recurso}` | `200` (documenta el parámetro `limit` en `query` y el sobre paginado), `401` |
| `store` | `POST /api/{recurso}` | `201`, `422`, `401` |
| `show` | `GET /api/{recurso}/{param}` | `200`, `404`, `401` |
| `update` | `PUT /api/{recurso}/{param}` | `200`, `422`, `404`, `401` |
| `destroy` | `DELETE /api/{recurso}/{param}` | `200`, `404`, `401` |

Reglas:

- `operationId` único en **toda** la API: `{accion}{Plural}` / `{accion}{Model}`. Verifícalo con
  `grep -rn "operationId" app/` antes de escribirlo.
- `summary` y `description` en **español**; los `message` de ejemplo se copian **literales** del
  controller, no se inventan.
- El parámetro de path se llama como en `route:list` y se describe según lo que el service usa de
  verdad: `id` numérico o `code` de negocio.
- Nunca dupliques `ApiError` / `ValidationError`: siempre por `ref`.
- Si el endpoint ya tenía atributos `OA\`, **actualízalos** en sitio en vez de añadir un segundo
  bloque.
- Si el `jwt.auth` del grupo aún no existe como middleware registrado, documenta igualmente
  `security` y el `401` (es el contrato previsto) y menciónalo al cerrar.

## Paso 4 — Cerrar

1. `php artisan l5-swagger:generate` — sin warnings de swagger-php. Los fallos típicos son `$ref`
   a un schema inexistente y `operationId` duplicado; arréglalos.
2. Comprueba que el endpoint quedó en el JSON:
   `grep -c "{operationId}" storage/api-docs/api-docs.json`
3. `vendor/bin/pint --dirty --format agent`.
4. Resumen breve: endpoint documentado, schemas creados o reutilizados, resultado de
   `l5-swagger:generate`, URL de la UI (`/api/documentation`) y cualquier incoherencia detectada.
