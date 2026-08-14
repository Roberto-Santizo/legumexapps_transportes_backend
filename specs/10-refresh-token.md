# SPEC 10 — Refresh token de 14 días en login y check-status

> **Estado:** Aprobado
> **Depende de:** SPEC 01
> **Fecha:** 2026-08-14
> **Objetivo:** Emitir en `login` y en `check-status` un segundo JWT llamado `refreshToken`, idéntico al normal salvo por una vigencia de 14 días y por el claim `tokenType`, para que una sesión siga viva indefinidamente mientras el usuario vuelva antes de que caduque.

Depende de **SPEC 01** en todo: el guard `api`, el `JWTSubject` del `User`, `AuthService`, `AuthController` y `routes/auth.php` salen de ahí. Esta spec **revierte parcialmente una decisión de SPEC 01** —«TTL de 60 minutos, sin refresh tokens»— y no toca ninguna otra.

No hay endpoint nuevo, tabla nueva ni migración. `check-status` acepta hoy los dos tokens sin cambio alguno: ambos llevan la misma firma y el mismo guard, así que `jwt.auth` no distingue entre ellos. El trabajo real es emitir el segundo token, ensancharle el `exp` y marcar los dos con `tokenType`.

---

## Alcance

**Dentro:**

- Clave nueva `'refresh_token_ttl' => (int) env('JWT_REFRESH_TOKEN_TTL', 20160)` en `config/jwt.php`, y `JWT_REFRESH_TOKEN_TTL=20160` en `.env.example`, junto a `JWT_TTL`. 20160 minutos son 14 días.
- **`JWT_TTL` se queda en 60.** Ningún token existente cambia de vigencia, ningún test ni descripción de Swagger que hoy afirme 60 minutos deja de ser cierto.
- **`jwt.refresh_ttl` del paquete no se toca.** Vale 20160 y coincide por casualidad; solo la usa `refresh()`, método que este código no llama.
- `login()` y `checkStatus()` de `AuthService` pasan a devolver `array{user: User, token: string, refreshToken: string}`. El PHPDoc de los dos métodos de `AuthServiceInterface` se actualiza.
- Método privado nuevo `issueTokens(User $user): array{token: string, refreshToken: string}` en `AuthService`: **único sitio del proyecto** que conoce `claims()` y `factory()->setTTL()`. Lo llaman `login()` y `checkStatus()`.
- Claim `tokenType` en **ambos** tokens, con los valores `access` y `refresh`, puesto **inline** en la emisión. No entra en `User::getJWTCustomClaims()`: ese método no recibe argumentos y no puede variar el claim por token.
- `issueTokens()` **restaura el TTL del `Factory`** a `config('jwt.ttl')` después de emitir el refresh. El TTL y los custom claims del paquete son estado de la petición, no del token.
- Respuesta de `POST /api/auth/login` y `GET /api/auth/check-status`: `data: { user, token, refreshToken }`. Los dos siguen respondiendo 200 y conservan sus mensajes actuales.
- `check-status` emite un **par nuevo** en cada llamada: el refresh que llegó se sustituye por otro de 14 días completos. La sesión se prorroga sola mientras el usuario vuelva dentro de la ventana.
- El `refreshToken` es un JWT plenamente válido: sirve como `Authorization: Bearer` en **cualquier** ruta protegida, no solo en `check-status`. Es la decisión tomada, y se documenta como tal en Swagger.
- Swagger de los dos endpoints: propiedad `refreshToken`, las dos vigencias y la advertencia anterior.
- Tests añadidos a `tests/Feature/AuthTest.php` y `tests/Unit/AuthServiceTest.php` existentes. No se crean archivos de test nuevos.

**Fuera de alcance (para specs futuras):**

- **Endpoint `POST /api/auth/refresh` dedicado.** No hace falta: `check-status` acepta el refresh token porque `jwt.auth` no distingue entre los dos.
- **`logout`, blacklist y revocación.** Un `refreshToken` filtrado es válido 14 días y nada puede cortarlo antes.
- **Restringir el `refreshToken` a renovar.** Hoy `tokenType` es informativo; ningún middleware lo lee. Limitarlo a `check-status` es una spec propia y el claim ya deja el terreno listo.
- **Persistir el refresh** en una tabla, rotación con detección de reuso y listado de sesiones activas por dispositivo.
- **Cambiar `JWT_TTL`.** Se evaluó subirlo a 1 día y se descartó para no arrastrar tests, Swagger y SPEC 01.
- **`refreshToken` en `register`, `confirm-account` y `reset-password`.** Esos tres siguen sin emitir token de ninguna clase.
- **Rate limiting** de `check-status`, que con esta spec pasa a ser el endpoint que sostiene la sesión.
- **Cookies `httpOnly`** o cualquier instrucción sobre dónde guarda el cliente los dos tokens.
- **2FA y login social.**

---

## Modelo de datos

Esta spec **no introduce ninguna tabla, migración, modelo, enum ni Resource**. Lo único que cambia de forma es el payload del JWT y el `data` de dos respuestas.

### 1. Configuración

```php
// config/jwt.php — junto a 'ttl'
'refresh_token_ttl' => (int) env('JWT_REFRESH_TOKEN_TTL', 20160),
```

```dotenv
# .env.example
JWT_TTL=60
JWT_REFRESH_TOKEN_TTL=20160
```

El default del `env()` es el mismo 20160: un entorno que no declare la variable emite refresh tokens de 14 días, no de cero minutos.

### 2. Payload de los dos tokens

| Claim | `token` | `refreshToken` |
|---|---|---|
| `sub`, `iat`, `jti`, `nbf` | los escribe el paquete | los escribe el paquete |
| `id`, `name`, `email`, `role`, `carrierId`, `carrierName`, `carrierCode` | de `User::getJWTCustomClaims()` | **idénticos** |
| `exp` | `iat + 3600` (60 min) | `iat + 1209600` (14 días) |
| `tokenType` | `'access'` | `'refresh'` |

Los dos se firman con el mismo `JWT_SECRET` y los valida el mismo guard `api`. La **única** diferencia funcional es el `exp`; `tokenType` no lo lee nadie todavía.

### 3. Emisión

```php
private function issueTokens(User $user): array
{
    $token = auth('api')->claims(['tokenType' => 'access'])->login($user);

    auth('api')->factory()->setTTL(config('jwt.refresh_token_ttl'));
    $refreshToken = auth('api')->claims(['tokenType' => 'refresh'])->login($user);
    auth('api')->factory()->setTTL(config('jwt.ttl'));

    return ['token' => $token, 'refreshToken' => $refreshToken];
}
```

Tres reglas que el orden de esas líneas codifica:

- El **access va primero**, con el TTL de config intacto.
- El `setTTL()` final **no es decorativo**: `Factory` es un singleton de la petición. Sin esa línea, cualquier token emitido más tarde en la misma petición saldría con 14 días.
- Los dos `claims()` son **explícitos**. `JWT::$customClaims` tampoco se limpia entre emisiones: si el access no declarase su `tokenType`, heredaría el `'refresh'` de una emisión anterior en la misma petición.

`claims()` gana el merge de `JWT::getClaimsArray()` —`sub` → `getJWTCustomClaims()` → inline—, así que el claim entra sin tocar el modelo `User`.

### 4. Contrato del service

```php
// AuthServiceInterface — cambian dos PHPDoc, ninguna firma
/** @return array{user: User, token: string, refreshToken: string} */
public function login(array $data): array;

/** @return array{user: User, token: string, refreshToken: string} */
public function checkStatus(): array;
```

`checkStatus()` sustituye su `auth('api')->login($user)` actual por `issueTokens($user)`; el comentario que explica por qué no se usa `refresh()` sigue siendo válido y se conserva.

### 5. Forma de las respuestas

| Endpoint | `data` |
|---|---|
| `POST /api/auth/login` | `{ user: UserResource, token, refreshToken }` |
| `GET /api/auth/check-status` | `{ user: UserResource, token, refreshToken }` |
| Los otros cuatro | **sin cambios** |

`UserResource` no se toca. Las claves nuevas se montan en `AuthController`, junto a `token`, como se monta hoy.

---

## Plan de implementación

Cada paso deja el sistema arrancable y la suite en verde.

### Paso 1 — Configuración

`'refresh_token_ttl' => (int) env('JWT_REFRESH_TOKEN_TTL', 20160)` en `config/jwt.php`, junto a `'ttl'`, y `JWT_REFRESH_TOKEN_TTL=20160` en `.env.example` bajo `JWT_TTL`. *Verificación:* `php artisan config:show jwt.refresh_token_ttl` devuelve `20160` y `php artisan config:show jwt.ttl` sigue devolviendo `60`.

### Paso 2 — Contrato

Actualizar el `@return` de `login()` y `checkStatus()` en `AuthServiceInterface` a `array{user: User, token: string, refreshToken: string}`. Ninguna firma cambia. *Verificación:* la suite sigue verde; el PHPDoc queda por delante de la implementación, no al revés.

### Paso 3 — `issueTokens()` y `login()`

Método privado `issueTokens()` en `AuthService` con las cuatro líneas de la sección 3 del modelo de datos, y `login()` devolviendo `['user' => $user, ...$this->issueTokens($user)]` en lugar del `$token` de `attempt()`.

`attempt()` sigue siendo quien valida las credenciales y quien decide el 401; su token se descarta y los dos definitivos salen de `issueTokens()`. Emitir tres tokens en un login es el costo de que los dos que viajan lleven `tokenType`.

*Verificación:* un test unitario decodifica los dos tokens de `login()` y comprueba `exp - iat` = 3600 y 1209600, y `tokenType` = `access` y `refresh`.

### Paso 4 — `checkStatus()`

Sustituir `'token' => auth('api')->login($user)` por `...$this->issueTokens($user)`. Se conserva el comentario que explica por qué no se usa `refresh()`. *Verificación:* llamar a `check-status` mandando el `refreshToken` en el header responde 200 y devuelve un par nuevo.

### Paso 5 — Controller

`AuthController::login()` y `AuthController::checkStatus()` añaden `'refreshToken' => $result['refreshToken']` al array que pasan a `ResponseHandler::success()`. Nada más cambia: ni los mensajes, ni los códigos, ni el `try/catch`. *Verificación:* `data` trae exactamente `user`, `token` y `refreshToken` en los dos endpoints.

### Paso 6 — Formato

`vendor/bin/pint --dirty --format agent`.

### Paso 7 — Tests

**No** se dispara el agente `feature-tests`: ese agente genera la suite de un CRUD recién scaffoldeado, y aquí hay que **añadir** a `tests/Feature/AuthTest.php` y `tests/Unit/AuthServiceTest.php`, que ya existen y ya cubren los seis endpoints. Se usa la skill `test-endpoint` sobre `auth.login` y sobre `auth.check-status`, que es la que sabe apilar casos en un archivo existente.

Los casos nuevos son los de la sección de criterios de aceptación. El test de SPEC 01 que asserta `exp - iat === 60 * 60` sobre el token de login **debe seguir pasando sin tocarlo**: es el canario de que `JWT_TTL` no se movió.

### Paso 8 — Documentación

Skill `document-endpoint` sobre los mismos dos endpoints —tampoco el agente `endpoint-docs`, por la misma razón— y `php artisan l5-swagger:generate`. Las descripciones deben decir las dos vigencias, que `check-status` admite cualquiera de los dos tokens y que el `refreshToken` sirve como token normal en toda la API.

---

## Criterios de aceptación

**Configuración**

- [ ] `php artisan config:show jwt.refresh_token_ttl` devuelve `20160`.
- [ ] `php artisan config:show jwt.ttl` sigue devolviendo `60`.
- [ ] `.env.example` declara `JWT_REFRESH_TOKEN_TTL=20160`.
- [ ] Con la variable ausente del `.env`, el refresh token sigue saliendo de 14 días.

**Login**

- [ ] `POST /api/auth/login` con credenciales válidas devuelve un `data` con exactamente tres claves: `user`, `token` y `refreshToken`.
- [ ] `token` y `refreshToken` son cadenas distintas entre sí.
- [ ] El payload de `token` tiene `exp - iat === 3600` y `tokenType === 'access'`.
- [ ] El payload de `refreshToken` tiene `exp - iat === 1209600` y `tokenType === 'refresh'`.
- [ ] Los dos payloads traen los mismos `id`, `name`, `email`, `role`, `carrierId`, `carrierName` y `carrierCode`.
- [ ] Con contraseña incorrecta sigue devolviendo 401 y con cuenta sin confirmar sigue devolviendo 403, en ambos casos sin `refreshToken`.

**Check-status**

- [ ] `GET /api/auth/check-status` con un `token` normal devuelve `user`, `token` y `refreshToken`.
- [ ] Llamado con el `refreshToken` en el header responde **200** y devuelve también las tres claves.
- [ ] El `token` devuelto es distinto del enviado y el `refreshToken` devuelto es distinto del anterior.
- [ ] El `refreshToken` devuelto vuelve a tener 14 días completos, no el remanente del que llegó.
- [ ] Sin header `Authorization` sigue devolviendo 401 en JSON.
- [ ] Con una cuenta sin confirmar sigue devolviendo 403.

**El refresh token es un token corriente**

- [ ] El `refreshToken` autentica una ruta protegida cualquiera de otro dominio — p. ej. `GET /api/products` — con 200.
- [ ] El middleware `role:administrator` se comporta igual con `token` que con `refreshToken`.
- [ ] Ningún middleware ni servicio del proyecto lee el claim `tokenType`: `grep -r "tokenType" app/` solo lo encuentra en `AuthService`.

**Sesión indefinida**

- [ ] Encadenar `check-status` usando cada vez el `refreshToken` de la respuesta anterior funciona indefinidamente, sin tope de renovaciones.
- [ ] Un `refreshToken` cuyo `exp` ya pasó devuelve 401 en `check-status`.

**No regresión de SPEC 01**

- [ ] El test de SPEC 01 que asserta `exp - iat === 60 * 60` sobre el token de login pasa **sin haber sido modificado**.
- [ ] `register`, `confirm-account`, `forgot-password` y `reset-password` devuelven exactamente el mismo `data` que antes de esta spec.
- [ ] `UserResource` no cambió.
- [ ] No hay migraciones nuevas: `php artisan migrate:status` lista las mismas de antes.
- [ ] `User::getJWTCustomClaims()` no menciona `tokenType`.

**Emisión**

- [ ] Tras un `login`, un token emitido más adelante **en la misma petición** sale con 60 minutos, no con 14 días (el TTL del `Factory` quedó restaurado).
- [ ] `issueTokens()` es el único método del proyecto que llama a `claims()` o a `factory()->setTTL()`.

**Cierre**

- [ ] `php artisan test --compact` pasa la suite entera, incluidas las nueve specs anteriores.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `/api/documentation` muestra `refreshToken` en `login` y en `check-status`, con las dos vigencias y la advertencia de que el refresh sirve como token normal.

---

## Decisiones tomadas y descartadas

### El refresh token es un JWT propio, no un token opaco en tabla

**Descartado:** token aleatorio persistido y hasheado en una tabla `refresh_tokens`, al estilo de los códigos de 6 dígitos de SPEC 01.

Era la opción con revocación real: borrar la fila corta la sesión al instante, y de paso permite listar sesiones activas por dispositivo. Se rechazó porque cuesta migración, tabla, limpieza de expirados y una consulta a base en cada renovación, a cambio de una capacidad —revocar— que este proyecto no ha pedido y que hoy tampoco tiene para el token de 60 minutos.

Con JWT no hay nada que persistir: la firma y el `exp` son la validación entera.

### No hay endpoint `/refresh`

`check-status` ya hace exactamente eso: recibe un token, comprueba la sesión y devuelve uno nuevo. Como los dos tokens llevan la misma firma y el mismo guard, `jwt.auth` acepta el refresh sin una línea de código nueva.

Un `/refresh` aparte habría duplicado la lógica de `check-status` para no añadir nada, y habría reabierto una puerta que SPEC 01 cerró con motivo.

### El refresh token sirve como token normal en toda la API

**Descartado:** un middleware que rechace `tokenType: refresh` fuera de `check-status`.

Es la decisión más cara de la spec y se toma con los ojos abiertos: si el token de 14 días vale en cualquier ruta, **el TTL de 60 minutos deja de ser un límite de nada**. Quien robe un `refreshToken` opera catorce días, sin `logout` y sin blacklist que lo corte.

Se acepta porque simplifica el cliente hasta lo trivial —un solo tipo de credencial, un solo header— y porque la revocación tampoco existía antes de esta spec. El claim `tokenType` queda puesto precisamente para que restringirlo, el día que se quiera, sea un middleware y no una reemisión de todos los tokens vivos.

### `tokenType` en los dos tokens, explícito

**Descartado:** marcar solo el refresh y dejar el access sin claim.

Habría bastado para distinguirlos, pero deja la ausencia del claim significando dos cosas —«es un access» y «es un token viejo de antes de esta spec»—. Además, `JWT::$customClaims` no se limpia entre emisiones dentro de una misma petición: si el access no declarase el suyo, heredaría el `'refresh'` de la emisión anterior. Declararlo en ambos convierte un bug latente en imposible.

**Descartado también:** meter `tokenType` en `User::getJWTCustomClaims()`. Ese método no recibe argumentos: devolvería el mismo valor para los dos tokens. El claim tiene que ir inline, en la emisión.

### 14 días, y el token normal se queda en 60 minutos

**Descartado:** 30 días para el refresh, que era el planteamiento inicial.

**Descartado:** subir `JWT_TTL` a 1 día.

Lo segundo es lo relevante: `JWT_TTL` es global, así que pasarlo a 1440 habría alargado todos los tokens del sistema y obligado a tocar un test de SPEC 01, dos descripciones de Swagger y un criterio de aceptación ya marcado. La ganancia era cosmética. 60 minutos se queda, y el test que lo asserta se convierte en el canario de que nadie lo mueva por accidente.

Los 14 días salen de la misma conversación: son la mitad de exposición que 30 con la misma comodidad práctica para el usuario.

### Variable propia, no `jwt.refresh_ttl`

`jwt.refresh_ttl` del paquete vale hoy 20160 —el mismo número— pero significa otra cosa: la ventana máxima en la que `refresh()` acepta reemitir un token desde su `iat` original. Este código no llama a `refresh()` en ningún sitio.

Reutilizarla habría ahorrado una clave y atado nuestra vigencia a un valor que alguien puede tocar cualquier día por un motivo que no tiene nada que ver. La clave nueva se llama `refresh_token_ttl` y vive junto a `ttl`.

### `check-status` renueva la ventana completa

**Descartado:** que el `refreshToken` conserve el `exp` del login original, de modo que a los 14 días haya que volver a autenticarse sí o sí.

Se eligió la renovación completa: cada `check-status` devuelve 14 días nuevos y una sesión activa vive **indefinidamente**. El techo absoluto desaparece — el `refresh_ttl` del paquete no lo pone, porque no usamos `refresh()`. Es exactamente lo pedido, y está anotado como riesgo.

### SPEC 01 no se edita

Su criterio «un `exp` a 60 minutos» sigue siendo cierto después de esta spec, así que ni siquiera hay contradicción que resolver. Y aunque la hubiera: una spec implementada es el registro de lo que se decidió entonces, no un documento vivo. Esta la supera en el punto «sin refresh tokens» y lo dice en su primera línea.

### Tests y Swagger con skills, no con agentes

Las nueve specs anteriores delegan en `feature-tests` y `endpoint-docs`, que generan la suite y la documentación de un CRUD recién scaffoldeado. Aquí no hay CRUD nuevo: hay dos endpoints que ya están testeados y documentados desde SPEC 01, y a los que solo se les añade una clave. `test-endpoint` y `document-endpoint` son las herramientas que saben apilar sobre un archivo existente en vez de generarlo.

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| Un `refreshToken` filtrado vale **14 días en toda la API**, no solo en `check-status`, y no hay `logout`, blacklist ni tabla que lo corte antes de su `exp`. | Ninguna dentro de esta spec: es la consecuencia asumida de la tercera decisión. El claim `tokenType` deja el terreno preparado para que restringirlo sea un middleware nuevo y no una reemisión de todos los tokens vivos. |
| La sesión **no tiene techo absoluto**. Encadenando `check-status` una vez cada trece días, una credencial comprometida sobrevive para siempre. `jwt.refresh_ttl`, que sería el tope natural, no aplica porque este código no llama a `refresh()`. | Es el comportamiento pedido explícitamente. El tope, si algún día se quiere, es un claim `sessionStartedAt` comparado contra un máximo — y eso ya es otra spec. |
| Los claims `role`, `carrierId`, `carrierName` y `carrierCode` de un `refreshToken` quedan **congelados hasta 14 días**, frente a los 60 minutos de SPEC 01. Un piloto que se une a una empresa hoy puede seguir presentando un token que dice que no tiene ninguna. | La regla de SPEC 01 sigue vigente y ahora vale por catorce veces más: **el backend nunca autoriza contra el claim**. `EnsureUserHasCarrier` consulta `$user->currentCarrier()` en base, y `role:` resuelve el usuario con `auth('api')->user()`. El claim es informativo para el front. |
| El TTL y los custom claims del paquete son **estado de la petición**. Cualquier emisión de token futura fuera de `issueTokens()` heredaría 14 días y `tokenType: refresh` si se olvida restaurarlos. | La restauración está en el método, hay un criterio de aceptación que la comprueba y otro que exige que `issueTokens()` sea el único sitio que toca `claims()` y `setTTL()`. |
| Cambiar `JWT_REFRESH_TOKEN_TTL` **no acorta los tokens ya emitidos**. Bajarlo a un día tras un incidente no invalida nada: los de 14 días siguen vivos hasta su `exp`. | Inherente a JWT sin persistencia. La única palanca real es rotar `JWT_SECRET`, que invalida absolutamente todas las sesiones de golpe. Queda documentado como el procedimiento de emergencia, aunque no se automatiza aquí. |
| `login()` emite **tres** tokens: el de `attempt()`, que se descarta, más los dos definitivos. El descartado sigue siendo criptográficamente válido 60 minutos. | No sale del servidor en ninguna respuesta ni log, así que nadie puede presentarlo. Es ruido conceptual, no una fuga; se anota porque quien lea `login()` va a preguntárselo. |
| El cliente guarda dos credenciales y nada le impide usar solo el `refreshToken` y no renovar nunca el access. | Funciona, y es precisamente lo que la tercera decisión permite. Swagger dice cuál es el uso previsto de cada uno; el sistema no lo impone. |

---

## Lo que **no** entra en esta spec

Repetición deliberada de lo ya dicho en el alcance:

- Endpoint `POST /api/auth/refresh` dedicado.
- `logout`, blacklist, revocación y listado de sesiones activas.
- Restringir el `refreshToken` a `check-status` leyendo `tokenType` desde un middleware.
- Persistir refresh tokens, rotarlos con detección de reuso o atarlos a un dispositivo.
- Cambiar `JWT_TTL`, que se queda en 60 minutos.
- Tope absoluto de duración de una sesión.
- `refreshToken` en `register`, `confirm-account` y `reset-password`.
- Rate limiting de `check-status`.
- Decidir dónde guarda el cliente los dos tokens (cookies `httpOnly`, storage, memoria).
- 2FA y login social.

Cada uno de ellos, si entra, va en su propia spec.
