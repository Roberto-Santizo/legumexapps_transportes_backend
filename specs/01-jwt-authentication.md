# SPEC 01 — Autenticación de usuarios con JWT

> **Estado:** Aprobado
> **Depende de:** —
> **Fecha:** 2026-07-31
> **Objetivo:** Exponer la autenticación del backend vía JWT con registro, confirmación de cuenta por código de 6 dígitos, login, validación/renovación de token y recuperación de contraseña.

---

## Alcance

**Dentro:**

- Instalación y configuración de `php-open-source-saver/jwt-auth` (publicar config, generar `JWT_SECRET`, TTL de 60 minutos).
- Infraestructura base de la API: creación de `routes/api.php`, registro del grupo `api` en `bootstrap/app.php`, alias de middleware `jwt.auth` y guard `api` con driver `jwt` en `config/auth.php`.
- Adaptación de `App\Models\User` para implementar `JWTSubject`.
- Migración de la tabla `account_confirmation_tokens` (`email`, `token`, `expiration_date`, `created_at`).
- Nuevas clases de error en `app/Errors` para la capa de autenticación (401 y 403).
- Stack por capas del recurso `Auth`: `AuthServiceInterface`, `AuthService`, `AuthProvider`, FormRequests, `UserResource`, `AuthController`, `routes/auth.php`.
- Seis endpoints bajo `/api/auth`: `register`, `confirm-account`, `login`, `check-status`, `forgot-password`, `reset-password`.
- Generación y persistencia de los códigos de 6 dígitos (confirmación y reseteo), hasheados, con expiración de 1 hora.
- Tests Pest: Feature (HTTP de los 6 endpoints) y Unit (`AuthService`).
- Documentación Swagger/OpenAPI de los 6 endpoints.

**Fuera de alcance (para specs futuras):**

- **Envío de correo.** Los códigos se generan y persisten, pero no se manda ningún `Mailable` ni `Notification`. La spec que implemente el envío consumirá los códigos que esta ya deja en base de datos.
- Reenvío del código de confirmación (`resend-code`).
- `POST /logout` e invalidación/blacklist de tokens.
- `POST /refresh` explícito: la renovación ocurre únicamente dentro de `check-status`.
- Cambio de contraseña estando autenticado (con `current_password`).
- Roles, permisos y autorización por rol. `authorize()` en los FormRequests devuelve `true`.
- Login social / OAuth, y 2FA.
- Limpieza programada de tokens expirados (job/scheduler).
- Rate limiting específico de los endpoints de auth.

---

## Modelo de datos

### 1. Enum nuevo: `App\Enums\UserRole`

```php
enum UserRole: string
{
    case Administrator = 'administrator';
    case Carrier = 'carrier';
    case Pilot = 'pilot';
    case Manager = 'manager';
}
```

Se crea `app/Enums/` (carpeta nueva). El registro público solo acepta `pilot` y `carrier`; `administrator` y `manager` se asignan por otra vía, fuera de esta spec.

### 2. Tabla `users` — se modifica la migración original

Se agrega la columna directamente en `0001_01_01_000000_create_users_table.php` (no hay datos en producción, no hace falta `ALTER`):

```php
$table->string('email')->unique();
$table->enum('role', ['administrator', 'carrier', 'pilot', 'manager']);
$table->timestamp('email_verified_at')->nullable();
```

`email_verified_at` es el marcador de cuenta confirmada:

| Valor | Significado |
|---|---|
| `null` | Cuenta registrada, sin confirmar. No puede hacer login. |
| fecha | Cuenta confirmada. Puede hacer login. |

### 3. Modelo `App\Models\User`

```php
#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function getJWTIdentifier(): mixed { /* $this->getKey() */ }

    /** @return array{id: int, name: string, email: string, role: string} */
    public function getJWTCustomClaims(): array { /* id, name, email, role->value */ }
}
```

### 4. Payload del JWT

`sub` = `users.id` (lo escribe la librería), `exp` = emisión + 60 min (`JWT_TTL=60`), y los claims personalizados `id`, `name`, `email` y `role` (string, el `value` del enum).

### 5. Tabla nueva: `account_confirmation_tokens`

```php
Schema::create('account_confirmation_tokens', function (Blueprint $table) {
    $table->string('email')->primary();
    $table->string('token');
    $table->timestamp('expiration_date');
    $table->timestamp('created_at')->nullable();
});
```

- `email` es PK: máximo un código vivo por usuario. Se escribe con `updateOrCreate`, pisando el anterior.
- `token` guarda el **hash** (`Hash::make`) del código de 6 dígitos, nunca el código en claro.
- `expiration_date` = `now()->addHour()`.

### 6. Tabla existente: `password_reset_tokens`

Se reutiliza la que ya trae Laravel y se le agrega `expiration_date` con la migración `add_expiration_date_to_password_reset_tokens_table`, para que ambos flujos compartan la misma validación de vigencia:

```php
Schema::table('password_reset_tokens', function (Blueprint $table) {
    $table->timestamp('expiration_date')->nullable()->after('token');
});
```

### 7. Forma de las respuestas

Todas pasan por `ResponseHandler::success($data, $mensaje, $code)`, sobre fijo `{ statusCode, message, data }`:

| Endpoint | `data` |
|---|---|
| `POST /api/auth/register` | `UserResource` (sin token — aún no puede loguear) |
| `POST /api/auth/confirm-account` | `null` |
| `POST /api/auth/login` | `{ user: UserResource, token: string }` |
| `GET /api/auth/check-status` | `{ user: UserResource, token: string }` (renovado) |
| `POST /api/auth/forgot-password` | `null` |
| `POST /api/auth/reset-password` | `null` |

`UserResource` expone `id`, `name`, `email`, `role`, `emailVerifiedAt`. Nunca `password`.

---

## Plan de implementación

Cada paso deja el sistema arrancable y es commiteable por sí solo.

1. **Instalar y configurar la librería JWT.** `composer require php-open-source-saver/jwt-auth`, publicar `config/jwt.php`, `php artisan jwt:secret` y fijar `JWT_TTL=60`. Añadir `JWT_SECRET` y `JWT_TTL` a `.env.example`. *Verificación:* `php artisan config:show jwt.ttl` devuelve `60`.

2. **Infraestructura base de la API.** Crear `routes/api.php` (vacío, solo cabecera), registrarlo en `bootstrap/app.php` con `api: __DIR__.'/../routes/api.php'`, declarar el alias `'jwt.auth' => Authenticate::class` en `withMiddleware`, y añadir el guard `api` con `driver => 'jwt'` en `config/auth.php`. *Verificación:* `php artisan route:list --path=api` corre sin error.

3. **Rol de usuario.** Crear `app/Enums/UserRole.php`, añadir la columna `role` a la migración original de `users`, actualizar `#[Fillable]` y `casts()` del modelo, y añadir `'role' => fake()->randomElement(...)` a `UserFactory`. *Verificación:* `php artisan migrate:fresh` y `User::factory()->create()` en un test.

4. **`User` implementa `JWTSubject`.** Añadir `getJWTIdentifier()` y `getJWTCustomClaims()` (con `id`, `name`, `email`, `role`). *Verificación:* test unitario que decodifica un token generado y comprueba los cuatro claims.

5. **Migraciones de códigos.** Crear `create_account_confirmation_tokens_table` y `add_expiration_date_to_password_reset_tokens_table`. *Verificación:* `php artisan migrate --pretend` y luego `migrate`.

6. **Errores de autenticación.** Crear `App\Errors\UnauthorizedError` (401) y `App\Errors\ForbiddenError` (403), ambos extendiendo `ApiException` igual que los existentes.

7. **Interfaz del servicio.** `app/Interfaces/Auth/AuthServiceInterface.php` con los seis métodos: `register`, `confirmAccount`, `login`, `checkStatus`, `forgotPassword`, `resetPassword`.

8. **`AuthService` — registro y confirmación.** `app/Services/Auth/AuthService.php` con `#[Override]` en cada método:
   - `register(array $data)`: crea el usuario con `email_verified_at = null`, genera código de 6 dígitos, lo guarda hasheado en `account_confirmation_tokens` con `expiration_date = now()->addHour()`, devuelve el `User`.
   - `confirmAccount(array $data)`: busca el registro por email; si no existe, está expirado o el código no coincide (`Hash::check`) lanza `BadRequestError`; si coincide, marca `email_verified_at = now()` y borra la fila.

9. **`AuthService` — login y check-status.**
   - `login(array $data)`: `auth('api')->attempt()`; credenciales malas → `UnauthorizedError` (401); credenciales buenas con `email_verified_at = null` → `ForbiddenError` (403); si todo bien devuelve `['user' => $user, 'token' => $token]`.
   - `checkStatus()`: toma el usuario del guard, devuelve `['user' => $user, 'token' => auth('api')->refresh()]`.

10. **`AuthService` — recuperación de contraseña.**
    - `forgotPassword(array $data)`: si el email existe genera código de 6 dígitos hasheado en `password_reset_tokens` con `expiration_date`. **Responde igual exista o no el email** (no revela si la cuenta está registrada).
    - `resetPassword(array $data)`: valida email + código + vigencia; si falla lanza `BadRequestError`; si pasa actualiza la contraseña y borra la fila.

11. **Provider.** `app/Providers/Auth/AuthProvider.php` con el `bind` de interfaz a servicio, registrado en `bootstrap/providers.php`. *Verificación:* `php artisan tinker --execute 'app(App\Interfaces\Auth\AuthServiceInterface::class);'` resuelve.

12. **FormRequests.** En `app/Http/Requests/Auth/`: `RegisterRequest` (`name`, `email` único, `password` confirmada mín. 8, `role` restringido a `pilot|carrier`), `ConfirmAccountRequest`, `LoginRequest`, `ForgotPasswordRequest`, `ResetPasswordRequest`. Todos con `authorize(): true` y `messages()` en español.

13. **Resource.** `app/Http/Resources/Auth/UserResource.php` con `id`, `name`, `email`, `role`, `emailVerifiedAt`.

14. **Controlador.** `app/Http/Controllers/AuthController.php`, seis acciones, cada una con `try/catch (\Throwable $th) { return ResponseHandler::error($th); }`, respuestas con `ResponseHandler::success(...)` y mensajes en español. Inyección por método de `AuthServiceInterface`. Códigos: `register` → 201, el resto → 200.

15. **Rutas.** `routes/auth.php`: `register`, `login`, `confirm-account`, `forgot-password`, `reset-password` públicas; `check-status` (GET) dentro de `Route::middleware('jwt.auth')`. Todo bajo `Route::prefix('auth')`. Añadir `require __DIR__.'/auth.php';` en `routes/api.php`. *Verificación:* `php artisan route:list --path=auth` lista los 6.

16. **Formato.** `vendor/bin/pint --dirty --format agent`.

17. **Tests.** Delegar al agente `feature-tests`: Feature (HTTP de los 6 endpoints, casos felices y de error) + Unit (`AuthService`).

18. **Documentación.** Delegar al agente `endpoint-docs`: atributos OpenAPI en `AuthController`, FormRequests y `UserResource`, más `l5-swagger:generate`.

Los pasos 17 y 18 corren en paralelo al final, como marca la convención del proyecto.

---

## Criterios de aceptación

**Infraestructura**

- [x] `php artisan route:list --path=api/auth` lista exactamente 6 rutas.
- [x] `php artisan config:show jwt.ttl` devuelve `60`.
- [x] `php artisan migrate:fresh` corre sin errores y crea la tabla `account_confirmation_tokens` y la columna `password_reset_tokens.expiration_date`.
- [x] `app(App\Interfaces\Auth\AuthServiceInterface::class)` resuelve a `App\Services\Auth\AuthService`.

**Registro**

- [x] `POST /api/auth/register` con datos válidos devuelve 201 y un `data` con `id`, `name`, `email`, `role` y `emailVerifiedAt` en `null`.
- [x] La respuesta de `register` no contiene `password` ni `token`.
- [x] Tras un registro existe exactamente una fila en `account_confirmation_tokens` para ese email, con `token` distinto del código en claro y `expiration_date` a 1 hora del `created_at`.
- [x] `POST /api/auth/register` con `role = administrator` o `role = manager` devuelve 422.
- [x] `POST /api/auth/register` con un email ya registrado devuelve 422.

**Confirmación de cuenta**

- [x] `POST /api/auth/confirm-account` con email y código correctos devuelve 200, deja `users.email_verified_at` no nulo y borra la fila de `account_confirmation_tokens`.
- [x] Con código incorrecto devuelve 400 y `email_verified_at` sigue en `null`.
- [x] Con un código cuya `expiration_date` ya pasó devuelve 400.
- [x] Un segundo `confirm-account` con el mismo código devuelve 400.

**Login**

- [x] `POST /api/auth/login` con credenciales válidas de cuenta confirmada devuelve 200 con `data.token` y `data.user`.
- [x] El token devuelto contiene los claims `id`, `name`, `email` y `role`, y un `exp` a 60 minutos.
- [x] Con contraseña incorrecta devuelve 401.
- [x] Con un email inexistente devuelve 401.
- [x] Con credenciales correctas pero cuenta sin confirmar devuelve 403.

**Check-status**

- [x] `GET /api/auth/check-status` sin header `Authorization` devuelve 401 en JSON (no HTML ni redirección).
- [x] Con token válido devuelve 200, el usuario autenticado y un token distinto al enviado.
- [x] El token devuelto por `check-status` sirve para una llamada posterior a `check-status`.
- [x] Con un token manipulado o caducado devuelve 401.

**Recuperación de contraseña**

- [ ] `POST /api/auth/forgot-password` con un email registrado devuelve 200 y crea una fila en `password_reset_tokens` con `token` hasheado y `expiration_date` a 1 hora.
- [ ] `POST /api/auth/forgot-password` con un email no registrado devuelve **también 200** y no crea ninguna fila.
- [ ] `POST /api/auth/reset-password` con email, código válido y nueva contraseña devuelve 200 y borra la fila de `password_reset_tokens`.
- [ ] Tras un reset exitoso, el login con la contraseña anterior devuelve 401 y con la nueva devuelve 200.
- [ ] `reset-password` con código incorrecto o expirado devuelve 400 y no cambia la contraseña.

**Calidad**

- [x] `php artisan test --compact` pasa en verde con los tests Feature y Unit de la feature.
- [x] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [x] Los 6 endpoints aparecen en `storage/api-docs/api-docs.json` tras `l5-swagger:generate`.
- [x] Ninguna respuesta de la feature expone el campo `password` ni el código en claro.

---

## Decisiones

**Librería y sesión**

- **Sí:** `php-open-source-saver/jwt-auth`. Fork mantenido de tymon, trae guard `jwt` y el middleware `jwt.auth` listos.
- **No:** `tymon/jwt-auth` original — mantenimiento irregular.
- **No:** `firebase/php-jwt` con guard propio — más código nuestro sin ganancia aquí.
- **No:** Laravel Sanctum/Passport — el requisito es JWT explícito.
- **Sí:** TTL de 60 minutos, sin refresh tokens.
- **No:** `logout` con blacklist y `refresh` explícito. La única renovación es `check-status`; si hace falta invalidar tokens, va en otra spec.

**Modelo de datos**

- **Sí:** `email_verified_at` como marcador de cuenta confirmada. La columna ya existe y significa exactamente eso.
- **No:** columna `status` o `active` nueva — sería un segundo estado redundante con el primero.
- **Sí:** tabla `account_confirmation_tokens` con `email` como PK. Un código vivo por usuario; `updateOrCreate` pisa el anterior.
- **No:** guardar el código en columnas de `users` — ensucia la tabla y obliga a limpiarla a mano.
- **Sí:** código de 6 dígitos guardado **hasheado**. Una fuga de la base no entrega códigos usables.
- **Sí:** `expiration_date` explícita en ambas tablas, incluida `password_reset_tokens` (tabla del framework). Una sola validación de vigencia para los dos flujos.
- **No:** calcular la vigencia desde `created_at` — funciona, pero deja la regla implícita en el código.
- **Sí:** `role` como enum PHP `App\Enums\UserRole` + columna enum, agregado a la migración original de `users`. No hay datos en producción, así que no hace falta `ALTER`.
- **No:** tabla `roles` con foreign key — cuatro roles fijos no justifican la relación.

**Endpoints y seguridad**

- **Sí:** el `register` solo acepta `pilot` y `carrier`. `administrator` y `manager` no pueden auto-asignarse desde un endpoint público.
- **Sí:** el `register` **no** devuelve token. La cuenta no puede operar hasta confirmarse.
- **Sí:** 401 para credenciales inválidas y 403 para cuenta sin confirmar. Son casos distintos y el front necesita distinguirlos para mandar al usuario a la pantalla de confirmación.
- **Sí:** `forgot-password` responde 200 exista o no el email. Evita usar el endpoint como oráculo de correos registrados.
- **Sí:** `id`, `name`, `email` y `role` como claims personalizados, además del `sub` que escribe la librería.
- **No:** meter permisos o datos de perfil en el claim — cuanto más entra, más se desactualiza el token.
- **Sí:** dos endpoints para el reseteo (`forgot-password` + `reset-password`), sin `verify-code` intermedio. El código se valida al mismo tiempo que se cambia la contraseña.

**Proceso**

- **Sí:** esta spec arrastra `routes/api.php`, el alias `jwt.auth` y el guard `api`. Es la primera del proyecto y sin eso nada corre.
- **Sí:** tests y Swagger delegados a los agentes `feature-tests` y `endpoint-docs`, como en el resto del proyecto.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Código de 6 dígitos sin rate limiting: un millón de combinaciones y una hora de vigencia lo hacen forzable por fuerza bruta, igual que `login`. | Es el motivo principal por el que la spec de rate limiting debe ir inmediatamente después de esta. Mientras tanto, la vigencia de 1 hora acota la ventana. |
| Sin envío de correo, el usuario nunca recibe su código y el flujo no cierra de cara al usuario final. | La feature queda completa a nivel API y verificable por tests, que leen el código desde la base. La spec del mailer solo consume lo que esta ya persiste. |
| Los claims `name`, `email` y `role` quedan congelados hasta 60 min: un cambio de rol no surte efecto en el token vivo. | El backend autoriza siempre contra la base, nunca contra el claim. El claim es informativo para el front. |
| Sin `logout` ni blacklist, un token robado sigue siendo válido hasta que expira. | TTL corto de 60 min. La invalidación explícita entra en la spec de `logout`. |
| `check-status` renueva el token en cada llamada, así que una sesión activa puede vivir indefinidamente. | El `refresh_ttl` de la librería (2 semanas por defecto) pone el techo absoluto. Dejarlo en su valor por defecto. |
| `role` como enum de base de datos: agregar un quinto rol obliga a un `ALTER TABLE` en MySQL. | Los cuatro roles son estables. Si el catálogo empieza a moverse, migrar a tabla `roles` en su propia spec. |
| Se modifica la migración original de `users`, ya ejecutada en los entornos locales existentes. | Requiere `php artisan migrate:fresh`. Sin datos en producción, el costo es cero, pero hay que avisarlo al equipo. |

---

## Lo que **no** entra en esta spec

- Envío de correos (el código se genera y persiste, pero no se manda).
- Reenvío del código de confirmación.
- Rate limiting de los endpoints de autenticación.
- `logout`, blacklist de tokens y `refresh` explícito.
- Cambio de contraseña con sesión iniciada.
- Roles y permisos como sistema de autorización (la columna `role` se crea, pero nada la usa para autorizar todavía).
- Login social / OAuth y 2FA.
- Limpieza programada de tokens expirados.

Cada uno de esos, si entra, va en su propia spec.
