# SPEC 03 — Transportistas y vinculación de pilotos

> **Estado:** Aprobado
> **Depende de:** SPEC 01
> **Fecha:** 2026-08-04
> **Objetivo:** Permitir que un usuario con rol `carrier` cree su empresa transportista y que los pilotos se vinculen a ella con un código de 6 caracteres, bloqueando el resto de la API a todo usuario autenticado sin transportista salvo `administrator` y `manager`.

---

## Alcance

**Dentro:**

- Migración `carriers`: `id`, `user_id` (FK a `users`, único), `name`, `image` (nullable), `code` (único, 6 caracteres), `active` (booleano, default `true`), `timestamps`.
- Migración `carrier_pilots`: `id`, `carrier_id` (FK), `user_id` (FK, **único**), `timestamps`. El índice único es lo que garantiza un piloto en una sola empresa.
- Modelo `Carrier` con factory, y relaciones `owner()` y `pilots()`.
- Relación `carrier()` en `User`, **sin añadirle columnas** a la tabla `users`.
- Tres claims nuevos en el JWT: `carrierId`, `carrierName` y `carrierCode`, en `null` cuando el usuario no está vinculado.
- Middleware `carrier.required`: bloquea con 403 a todo usuario autenticado sin transportista, salvo `administrator` y `manager`. Resuelve la relación **contra la base de datos**, nunca contra el claim.
- Middleware `role`: filtro grueso por rol, aplicado en `routes/carriers.php`.
- **`App\Http\Resources\PaginatedResource`**, clase genérica y reutilizable en todo el proyecto: recibe un `LengthAwarePaginator` y la clase del resource hijo, y devuelve `{ data, total, currentPage, lastPage }`. Es la convención de paginación para todos los recursos, no solo los de esta spec.
- **Paginación opcional por `limit`**: sin `limit` en la query se devuelven todos los registros con `Resource::collection()`; con `limit` numérico se pagina, acotado al rango 10–100; con `limit` no numérico se devuelven todos los registros sin error.
- Cadena de capas completa para el dominio `Carrier`: `CarrierServiceInterface`, `CarrierService`, `CarrierProvider`, FormRequests (`StoreCarrierRequest`, `UpdateCarrierRequest`, `JoinCarrierRequest`), Resources (`CarrierResource`, `CarrierPilotResource`) y `CarrierController`.
- `routes/carriers.php` con el `apiResource` y el bloque de funcionalidades, incluido desde `routes/api.php`.
- Ocho endpoints, todos sobre el mismo controller: `index`, `store`, `show`, `update`, `destroy`, `join`, `me` y `me/pilots`.
- Generación del código: 6 caracteres alfanuméricos en mayúsculas, verificado como único antes de persistir.
- Imagen: el `store` recibe y valida el archivo (`jpg`, `jpeg`, `png`), **no lo almacena**, y guarda en `image` un UUID con la extensión original.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **Subida real de la imagen** a la nube o a disco. Esta spec solo genera y guarda el identificador.
- **Semántica de `active`.** El campo se persiste y se expone, pero no bloquea nada ni impide unirse a una empresa inactiva.
- **Desvincular pilotos:** ni salida voluntaria ni expulsión por parte del carrier.
- **Rotar o regenerar el código** de una empresa, e invitaciones nominales con expiración.
- **Borrado real de empresas.** `destroy` existe, responde y no modifica nada.
- **Búsquedas, filtros y ordenación** en `index` y `me/pilots`. Solo paginación.
- **Retroadaptar endpoints existentes** al nuevo `PaginatedResource`. Hoy no hay ningún listado en el proyecto, así que no hay nada que migrar.
- **El rol `manager`:** queda exento del bloqueo, pero no puede llamar a ningún endpoint de carriers.
- **Varias empresas por carrier** y **pilotos en varias empresas**. La cardinalidad es 1 a 1 y 1 a N por diseño.
- **Asignación manual de pilotos** por parte de un administrator.
- **Correos** al crear la empresa o al unirse un piloto.
- **Renovar el token** en `store` o `join`. El front llama a `check-status`.

---

## Modelo de datos

### 1. Tabla `carriers`

```php
Schema::create('carriers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->string('image')->nullable();
    $table->string('code', 6)->unique();
    $table->boolean('active')->default(true);
    $table->timestamps();
});
```

`user_id` es único: un usuario `carrier` tiene como máximo una empresa.

### 2. Tabla `carrier_pilots`

```php
Schema::create('carrier_pilots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
    $table->timestamps();
});
```

El único sobre `user_id` es la garantía de "un piloto en una sola empresa". `created_at` es la fecha de unión que expone `me/pilots`.

### 3. Modelos

```php
#[Fillable(['user_id', 'name', 'image', 'code', 'active'])]
class Carrier extends Model
{
    public function owner(): BelongsTo;                 // User dueño (rol carrier)
    public function pilots(): BelongsToMany;            // Users vía carrier_pilots, ->withTimestamps()
}

#[Fillable(['carrier_id', 'user_id'])]
class CarrierPilot extends Model                        // pivote con id y timestamps propios
{
    public function carrier(): BelongsTo;
    public function user(): BelongsTo;
}
```

En `User` (sin columnas nuevas):

```php
public function carrier(): HasOne;              // la empresa que posee, vía carriers.user_id
public function pilotCarrier(): HasOneThrough;  // la empresa a la que se unió, vía carrier_pilots
public function currentCarrier(): ?Carrier;     // helper: dueño o membresía, lo que aplique
```

`currentCarrier()` es el único punto donde se resuelve "¿este usuario tiene transportista?". Lo consumen los claims del JWT y el middleware.

### 4. Claims del JWT

`getJWTCustomClaims()` pasa a devolver:

```php
[
    'id' => 1, 'name' => '...', 'email' => '...', 'role' => 'pilot',
    'carrierId' => 3,
    'carrierName' => 'Transportes del Norte',
    'carrierCode' => 'A7K2QX',
]
```

Los tres van en `null` cuando `currentCarrier()` devuelve `null`: carrier sin empresa creada, piloto sin unir, y siempre para `administrator` y `manager`.

### 5. Resources

```php
// CarrierResource
['id', 'name', 'image', 'code', 'active']

// CarrierPilotResource
['id', 'name', 'email', 'joinedAt']   // joinedAt sale de carrier_pilots.created_at

// PaginatedResource — genérico, reutilizable en todo el proyecto
new PaginatedResource($paginator, CarrierResource::class);
// toArray => ['data' => ..., 'total' => ..., 'currentPage' => ..., 'lastPage' => ...]
```

Tras el aplanado de `ResponseHandler`, un listado paginado sale como `{ statusCode, message, data: [...], total, currentPage, lastPage }`; uno sin paginar, como `{ statusCode, message, data: [...] }`.

### 6. Generación de valores

- **`code`**: 6 caracteres de `ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789`, regenerado hasta que no exista en `carriers`.
- **`image`**: `Str::uuid()` más la extensión real del archivo recibido (`4f1c…-9a.png`). El archivo se valida y se descarta.
- **`limit`**: si no llega o no es numérico, no se pagina; si llega numérico, se acota a `[10, 100]`.

### 7. Contrato HTTP

| Método y ruta | Acción | Rol | `carrier.required` |
|---|---|---|---|
| `GET /api/carriers` | `index` | administrator | sí |
| `POST /api/carriers` | `store` | carrier | no |
| `GET /api/carriers/{carrier}` | `show` | administrator | sí |
| `PUT\|PATCH /api/carriers/{carrier}` | `update` | carrier, administrator | sí |
| `DELETE /api/carriers/{carrier}` | `destroy` | administrator | sí |
| `POST /api/carriers/join` | `join` | pilot | no |
| `GET /api/carriers/me` | `me` | carrier | sí |
| `GET /api/carriers/me/pilots` | `pilots` | carrier | sí |

`administrator` y `manager` atraviesan `carrier.required` siempre; para ellos la columna "sí" no cambia nada.

---

## Plan de implementación

Cada paso deja el sistema arrancable y es commiteable por sí solo.

1. **Tabla y modelo `Carrier`.** `php artisan make:model Carrier -mf`. Migración con las seis columnas, modelo con `#[Fillable]`, cast de `active` a `boolean` y factory que genera `name`, `code` único y `active = true`. *Verificación:* `php artisan migrate` corre limpio y `Carrier::factory()->create()` persiste.

2. **Tabla y modelo `CarrierPilot`.** `php artisan make:model CarrierPilot -m`. Migración con el único sobre `user_id`. *Verificación:* insertar dos filas con el mismo `user_id` falla por constraint.

3. **Relaciones.** `Carrier::owner()` y `Carrier::pilots()`; `CarrierPilot::carrier()` y `CarrierPilot::user()`; en `User`, `carrier()`, `pilotCarrier()` y el helper `currentCarrier()`. *Verificación:* `php artisan tinker --execute 'Carrier::factory()->create()->owner;'` devuelve el usuario.

4. **Claims del JWT.** `User::getJWTCustomClaims()` añade `carrierId`, `carrierName` y `carrierCode` desde `currentCarrier()`, con `null` cuando no hay. *Verificación:* la suite de SPEC 01 sigue verde y un login devuelve el token con los tres claims.

5. **`PaginatedResource` genérico.** `app/Http/Resources/PaginatedResource.php`, extiende `ResourceCollection`, recibe paginador y clase del resource hijo, y devuelve `data`, `total`, `currentPage` y `lastPage`. Nadie lo consume todavía.

6. **Resources del dominio.** `CarrierResource` (`id`, `name`, `image`, `code`, `active`) y `CarrierPilotResource` (`id`, `name`, `email`, `joinedAt` desde el pivote), ambos en camelCase.

7. **Middleware `role`.** `php artisan make:middleware EnsureUserHasRole`. Recibe roles por parámetro, compara contra `$request->user()->role` y devuelve `ResponseHandler::error(new ForbiddenError(...))` si no coincide. Alias `role` en `bootstrap/app.php`. *Verificación:* aplicado a una ruta de prueba, un rol distinto recibe 403 con el sobre estándar.

8. **Middleware `carrier.required`.** `php artisan make:middleware EnsureUserHasCarrier`. Deja pasar a `administrator` y `manager`; para el resto exige `currentCarrier() !== null` o devuelve 403 con *"Debes estar vinculado a un transportista para acceder a este recurso"*. Alias `carrier.required` en `bootstrap/app.php`.

9. **Contrato del service.** `app/Interfaces/Carrier/CarrierServiceInterface.php` con los ocho métodos y su PHPDoc de array shapes. Sin implementación.

10. **Service, lecturas.** `app/Services/Carrier/CarrierService.php` con `getCarriers(?string $limit)`, `getCarrierById(int $id)`, `getMyCarrier(User $user)` y `getMyPilots(User $user, ?string $limit)`. `getCarriers` y `getMyPilots` aplican el acotado a `[10, 100]` y devuelven paginador o colección. `getCarrierById` lanza `NotFoundError` si no existe.

11. **Service, escrituras.** `createCarrier()` (genera código único e `image`, lanza `BadRequestError` si el usuario ya tiene empresa), `updateCarrier()` (lanza `ForbiddenError` si el `carrier` autenticado no es el dueño), `deleteCarrier()` (no modifica nada, solo responde) y `joinCarrier()` (normaliza el código a mayúsculas, `NotFoundError` si no existe, `BadRequestError` si el piloto ya pertenece a una empresa). *Verificación:* `php artisan test --compact` sigue verde.

12. **Provider.** `app/Providers/Carrier/CarrierProvider.php` con `bind(CarrierServiceInterface::class, CarrierService::class)`, registrado en `bootstrap/providers.php`. *Verificación:* `php artisan tinker --execute 'app(App\Interfaces\Carrier\CarrierServiceInterface::class);'` resuelve.

13. **FormRequests.** `StoreCarrierRequest` (`name` requerido, `image` requerida y `mimes:jpg,jpeg,png`), `UpdateCarrierRequest` (`name` e `image` opcionales, `active` booleano opcional) y `JoinCarrierRequest` (`code` requerido, 6 caracteres). Mensajes en español.

14. **Controller.** `CarrierController` con los ocho métodos, cada uno con `try/catch` hacia `ResponseHandler` y el service inyectado por parámetro. Los listados eligen envoltorio con `$limit ? new PaginatedResource(...) : Resource::collection(...)`.

15. **Rutas.** `routes/carriers.php` con `jwt.auth` en todo el grupo, `/join`, `/me` y `/me/pilots` declaradas **antes** del `apiResource` para que `me` no caiga en el comodín `{carrier}`, y los middlewares `role` y `carrier.required` por ruta según la tabla del contrato. Incluido desde `routes/api.php`. *Verificación:* `php artisan route:list --path=carriers` muestra las ocho con sus middlewares.

16. **Formato.** `vendor/bin/pint --dirty --format agent`.

17. **Tests.** Delegar al agente `feature-tests`.

18. **Documentación.** Delegar al agente `endpoint-docs` y regenerar `storage/api-docs/api-docs.json`.

---

## Criterios de aceptación

**Base de datos y modelos**

- [ ] `php artisan migrate:fresh` crea `carriers` y `carrier_pilots` sin errores.
- [ ] Insertar dos `carriers` con el mismo `user_id` falla por constraint único.
- [ ] Insertar dos filas de `carrier_pilots` con el mismo `user_id` falla por constraint único.
- [ ] `Carrier::factory()->create()->owner` devuelve un `User`, y `$carrier->pilots` una colección de `User`.
- [ ] `$user->currentCarrier()` devuelve la empresa propia si el usuario es el dueño, la empresa vinculada si es piloto, y `null` para `administrator` y `manager`.
- [ ] La tabla `users` no gana ninguna columna nueva.

**Claims del JWT**

- [ ] El token de un `carrier` con empresa incluye `carrierId`, `carrierName` y `carrierCode` con los valores de su empresa.
- [ ] El token de un `carrier` sin empresa y el de un `pilot` sin unir traen los tres claims en `null`.
- [ ] El token de un `administrator` y el de un `manager` traen los tres claims en `null`.
- [ ] Tras crear la empresa, `GET /api/auth/check-status` devuelve un token nuevo con los tres claims ya poblados.

**Middlewares**

- [ ] Un `carrier` sin empresa que llama a `GET /api/carriers/me` recibe 403 con el sobre `{ statusCode, message, data: null }`.
- [ ] Un `administrator` sin empresa atraviesa `carrier.required` y recibe 200 en `GET /api/carriers`.
- [ ] `GET /api/auth/check-status` **no** está bloqueado por `carrier.required`.
- [ ] Un `pilot` que llama a `POST /api/carriers` recibe 403 por el middleware `role`.
- [ ] Un `manager` recibe 403 en los ocho endpoints de carriers.
- [ ] Una petición sin token a cualquier endpoint de carriers recibe 401.

**`POST /api/carriers`**

- [ ] Un `carrier` confirmado y sin empresa recibe 201 y la empresa queda persistida con `active = true`.
- [ ] La respuesta incluye un `code` de exactamente 6 caracteres alfanuméricos en mayúsculas.
- [ ] Dos empresas creadas seguidas nunca comparten el mismo `code`.
- [ ] La columna `image` guarda un UUID con la extensión del archivo enviado, y **no** aparece ningún archivo nuevo en `storage/`.
- [ ] Enviar un `.pdf` como `image` devuelve 422.
- [ ] Un `carrier` que ya tiene empresa recibe 400 y no se crea una segunda fila.
- [ ] Dos empresas pueden llamarse igual: el `name` repetido no es error.

**`POST /api/carriers/join`**

- [ ] Un `pilot` sin empresa que envía un código válido recibe 200 con un mensaje de éxito y queda una fila en `carrier_pilots`.
- [ ] El código enviado en minúsculas (`a7k2qx`) vincula igual que en mayúsculas.
- [ ] Un código inexistente devuelve 404 y no crea ninguna fila.
- [ ] Un `pilot` que ya pertenece a una empresa recibe 400 y su vínculo original no cambia.
- [ ] Un usuario sin cuenta confirmada no llega a `join`: el login de SPEC 01 no le emite token.

**Lecturas**

- [ ] `GET /api/carriers/me` devuelve la empresa del `carrier` autenticado, incluido su `code`.
- [ ] `GET /api/carriers/me/pilots` devuelve solo los pilotos de esa empresa, con `id`, `name`, `email` y `joinedAt`.
- [ ] Un piloto de otra empresa no aparece en ese listado.
- [ ] `GET /api/carriers/{id}` con un id inexistente devuelve 404.

**Paginación**

- [ ] `GET /api/carriers` sin `limit` devuelve todos los registros y la respuesta **no** trae `total`, `currentPage` ni `lastPage`.
- [ ] `GET /api/carriers?limit=10` devuelve como máximo 10 registros y la respuesta trae `data`, `total`, `currentPage` y `lastPage` al nivel raíz del sobre.
- [ ] `GET /api/carriers?limit=abc` devuelve todos los registros sin error.
- [ ] `GET /api/carriers?limit=3` pagina de 10 en 10.
- [ ] `GET /api/carriers?limit=500` pagina de 100 en 100.
- [ ] Las cinco reglas anteriores valen igual para `GET /api/carriers/me/pilots`.

**Escrituras restantes**

- [ ] `PATCH /api/carriers/{id}` del propio dueño actualiza `name`, `image` y `active`.
- [ ] Un `carrier` que intenta actualizar la empresa de otro recibe 403.
- [ ] Un `administrator` puede actualizar cualquier empresa.
- [ ] `DELETE /api/carriers/{id}` devuelve 200 y la fila **sigue existiendo** en base de datos.

**Calidad**

- [ ] `php artisan test --compact` pasa en verde, incluidos todos los tests de SPEC 01 y SPEC 02 sin modificarlos.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `php artisan route:list --path=carriers` muestra las ocho rutas con sus middlewares.
- [ ] `php artisan l5-swagger:generate` regenera sin errores y los ocho endpoints aparecen en `/api/documentation`.

---

## Decisiones

**Modelo de datos**

- **Sí:** `carriers.user_id` único para el dueño y tabla pivote `carrier_pilots` para los pilotos. Mantiene `users` intacta y el único sobre `user_id` en el pivote impone la cardinalidad en base y no en código.
- **No:** una columna `users.carrier_id` que llevaran dueño y pilotos por igual. Era la opción más simple de consultar, pero obligaba a migrar la tabla de usuarios.
- **Sí:** modelo `CarrierPilot` propio para el pivote. La tabla tiene `id` y `timestamps`, y `joinedAt` sale de ahí.
- **Sí:** helper `currentCarrier()` en `User`. Dueño y piloto llegan a la empresa por caminos distintos; concentrar la resolución en un método evita que middleware, claims y service la reimplementen cada uno a su manera.
- **Sí:** el código vive en una columna de `carriers`, en claro y permanente. El caso de uso es que el carrier lo comparta con sus pilotos, no que sea un secreto rotatorio.
- **No:** tabla de invitaciones con expiración y un solo uso. Es un mecanismo distinto, con su propio ciclo de vida; entra en su spec si hace falta.
- **Sí:** 6 caracteres alfanuméricos en mayúsculas. Se dicta por teléfono sin ambigüedad y da ~2.100 millones de combinaciones.

**Autorización**

- **Sí:** middleware `role` para el filtro grueso. La regla queda visible junto a la ruta y el service no se llena de comprobaciones de rol.
- **Sí:** las reglas que necesitan base de datos ("ya tienes empresa", "no eres el dueño", "ya perteneces a una") viven en el service y lanzan errores de `App\Errors`. Es la convención del proyecto.
- **No:** Policies de Laravel. El proyecto no usa ninguna; introducirlas por un dominio rompe el patrón sin ganar nada.
- **Sí:** `carrier.required` consulta la base de datos, no el claim del token. Un carrier que acaba de crear su empresa seguiría bloqueado hasta 60 minutos si mirásemos el claim.
- **Sí:** los claims se mantienen igualmente, como información para el front. Son un espejo del estado, nunca la fuente de verdad de una autorización.
- **Sí:** `administrator` y `manager` exentos del bloqueo. Ninguno de los dos tiene forma de vincularse a una empresa, así que la regla los dejaría fuera del sistema para siempre.
- **Sí:** los middlewares devuelven `ResponseHandler::error(new ForbiddenError(...))` directamente. Una excepción lanzada en un middleware no la captura el `try/catch` del controller.

**Paginación**

- **Sí:** `PaginatedResource` genérico y único para todo el proyecto, parametrizado con la clase del resource hijo. Una clase paginada por dominio sería el mismo `toArray` copiado N veces.
- **Sí:** la paginación la dispara la **presencia** de `limit`. Sin `limit`, colección completa; con `limit`, sobre paginado. Un solo parámetro decide las dos formas de respuesta.
- **Sí:** `limit` no numérico devuelve todos los registros en vez de 422. Un parámetro de presentación mal escrito no debe tumbar una lectura.
- **Sí:** acotado a `[10, 100]` en el service. Protege la base de un `limit=999999` y evita páginas de un solo registro.
- **Sí:** el `if` del envoltorio vive en el controller. Es una decisión de presentación, no de negocio.

**Comportamiento**

- **Sí:** la imagen se recibe, se valida y se descarta, guardando solo el identificador. Deja el contrato HTTP y la columna definitivos, para que la spec de la nube solo tenga que añadir el guardado real.
- **Sí:** `active` nace en `true` y no se puede enviar en `store`. Nadie crea una empresa desactivada.
- **No:** que `active = false` bloquee a los pilotos. Queda fuera a propósito: hoy es un dato informativo.
- **Sí:** `destroy` existe, responde y no borra. Completa el `apiResource` sin dejar pilotos huérfanos ni empresas fantasma.
- **Sí:** `join` devuelve solo un mensaje. El front tiene `check-status` para leer el estado nuevo.
- **Sí:** `store` y `join` **no** devuelven token nuevo. El front llama a `check-status` inmediatamente después, y así el token se emite en un único lugar del sistema.
- **No:** desvincular pilotos en esta spec. Abre preguntas propias (¿quién puede?, ¿queda historial?) que merecen su documento.

**Proceso**

- **Sí:** las funcionalidades (`join`, `me`, `me/pilots`) van en el mismo `CarrierController` que el `apiResource`, en su propio bloque de rutas.
- **Sí:** `/join`, `/me` y `/me/pilots` declaradas antes del `apiResource`. Si no, `me` entra por el comodín `{carrier}` y devuelve 404.
- **Sí:** tests con el agente `feature-tests` y Swagger con `endpoint-docs`, como en el resto del proyecto.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Un piloto que teclea mal el código y acierta el de otra empresa queda vinculado al transportista equivocado, y esta spec no tiene forma de deshacerlo: ni salida ni expulsión. | Solo se arregla borrando la fila de `carrier_pilots` a mano en base. Es el argumento principal para que la desvinculación sea la siguiente spec. |
| El código es un secreto compartido: cualquiera que lo consiga se une sin aprobación del carrier, y no caduca nunca. | Riesgo aceptado. El objetivo de hoy es saber qué pilotos están con qué transportista, no controlar el acceso. Aprobación e invitaciones nominales van en su propia spec. |
| `carrier.required` añade una consulta por petición a toda ruta protegida futura. | La consulta va por clave foránea indexada y devuelve una fila. Si llega a pesar, se cachea `currentCarrier()` por petición sin cambiar el contrato del middleware. |
| Olvidar `carrier.required` en una ruta protegida futura abre un agujero silencioso: el endpoint queda accesible a usuarios sin transportista y nadie se entera. | Queda documentado como obligatorio para toda ruta protegida. Convertirlo en middleware global del grupo `jwt.auth`, con lista de excepciones, es la evolución natural si el olvido llega a ocurrir. |
| El token guarda `carrierId` pero la autorización mira la base: los dos pueden discrepar durante hasta 60 minutos. Un front que decida qué pintar solo por el claim mostrará estado viejo. | El middleware nunca lee el claim, así que la discrepancia no es explotable. `check-status` es el mecanismo de refresco y el front lo llama tras `store` y `join`. |
| La imagen se recibe, se valida y se descarta. Un front que suba un archivo creerá que quedó guardado y verá una `image` que no resuelve a nada. | Es una decisión explícita de alcance. Debe quedar anotado en la documentación Swagger del endpoint para que el consumidor no se confíe. |
| `/me` y `/me/pilots` conviven con el comodín `{carrier}` del `apiResource`. Si se declaran después, `me` entra por el comodín y devuelve 404. | El orden está fijado en el plan y hay un criterio de aceptación que lo cubre. |
| Colisión al generar el `code`. | Se regenera hasta encontrar uno libre, y el índice único de la columna es la red de seguridad definitiva. |
| El borrado en cascada desde `users` arrastra la empresa y sus vínculos: si algún día se borra un usuario `carrier`, sus pilotos quedan sueltos y por tanto bloqueados de la API. | Hoy no existe ningún endpoint que borre usuarios. Cuando exista, tendrá que decidir qué pasa con la empresa, y esa decisión va en su spec. |
| `limit=1` devuelve páginas de 10 por el acotado inferior. Un front que pida una sola fila recibirá diez. | Documentado en Swagger y cubierto por un criterio de aceptación. El mínimo protege de barridos página a página. |

---

## Lo que **no** entra en esta spec

- Subida real de la imagen a la nube o a disco.
- Que `active = false` bloquee a los pilotos o impida unirse.
- Desvincular pilotos: ni salida voluntaria ni expulsión.
- Rotar o regenerar el código de una empresa.
- Invitaciones nominales con expiración y aprobación del carrier.
- Borrado real de empresas.
- Búsquedas, filtros y ordenación en los listados.
- Retroadaptar endpoints existentes al nuevo `PaginatedResource`.
- Endpoints de carriers para el rol `manager`.
- Varias empresas por carrier o pilotos en varias empresas.
- Asignación manual de pilotos por parte de un administrator.
- Correos al crear la empresa o al unirse un piloto.
- Renovar el token en `store` o `join`.

Cada uno de esos, si entra, va en su propia spec.
