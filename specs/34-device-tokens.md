# SPEC 34 — Tokens de dispositivo para notificaciones push

> **Estado:** Aprobado
> **Depende de:** SPEC 01
> **Fecha:** 2026-09-23
> **Objetivo:** Crear la tabla `user_device_tokens` y dos endpoints, `POST /api/device-tokens` y `DELETE /api/device-tokens/{token}`, para que cualquier usuario autenticado registre y retire los tokens FCM (Android/iOS) de sus dispositivos, sin enviar todavía ninguna notificación.

Es el **primer dominio del proyecto que guarda datos para una capacidad que aún no existe**: los tokens se acumulan aquí y la spec del envío, cuando llegue, solo tendrá que leerlos. Por eso esta spec no añade dependencias de Composer, ni credenciales de Firebase, ni ninguna llamada saliente.

Solo depende de SPEC 01, por `jwt.auth` y `User`.

---

## Alcance

**Dentro:**

- **Dominio `DeviceToken`** con las capas del proyecto: migración `user_device_tokens`, modelo `UserDeviceToken` con factory, enum `App\Enums\DevicePlatform` (`android` | `ios`), `DeviceTokenServiceInterface` (`app/Interfaces/DeviceToken/`), `DeviceTokenService` (`app/Services/DeviceToken/`), `DeviceTokenProvider` registrado en `bootstrap/providers.php`, `StoreDeviceTokenRequest` (`app/Http/Requests/DeviceToken/`), `DeviceTokenResource` (`app/Http/Resources/DeviceToken/`), `DeviceTokenController` y `routes/device-tokens.php`, incluido desde `routes/api.php`.
- **Dos rutas**, con `jwt.auth` a secas: sin `role:` ni `carrier.required`, así que valen para los siete roles.
  - `POST /api/device-tokens` con cuerpo `{ token, platform }`.
  - `DELETE /api/device-tokens/{token}`, donde `{token}` es el token FCM y no el id de la fila.
- **`POST` idempotente con reasignación.** Hay tres casos, según quién tenga ya el token:
  - Nadie lo tiene: crea la fila y responde **201**.
  - Ya es del usuario autenticado: actualiza `platform` y `last_seen_at` y responde **200**.
  - Es de otro usuario: lo **reasigna** al autenticado (`user_id`, `platform`, `last_seen_at`) y responde **200**.

  El upsert corre en `DB::transaction` con `lockForUpdate` sobre la fila existente.
- **`DELETE` con borrado físico**: **404** tanto si el token no existe como si es de otro usuario, para no revelar a quién pertenece. Borrar el propio responde 200 con el recurso borrado.
- **`User` gana `deviceTokens()`** (`HasMany`) para que la spec de envío lo consuma. No se expone en `UserResource`, ni en el JWT, ni en ningún otro Resource.
- **Anotaciones OpenAPI** en Controller, FormRequest y Resource, y regeneración de `storage/api-docs/api-docs.json`.
- **Tests Pest**:
  - `DeviceTokenTest` (Feature): los tres casos del `POST`, los dos 404 del `DELETE`, la validación, que los siete roles tienen acceso y el 401 sin token.
  - `DeviceTokenServiceTest` (Unit).
- **Resumen de integración para el frontend** en `references/device-tokens-api.md`.

**Fuera de alcance (para specs futuras):**

- **Enviar notificaciones push**: ni SDK de Firebase, ni `FIREBASE_CREDENTIALS`, ni canal de `Notification`, ni decidir qué eventos notifican.
- **Limpiar tokens inválidos** a partir de las respuestas de FCM (`UNREGISTERED`, `INVALID_ARGUMENT`).
- **Purgar por antigüedad** usando `last_seen_at`: ni comando programado ni job.
- **Listar tokens**: no hay `GET /api/device-tokens` ni `/me`.
- **Límite de tokens por usuario.**
- **Web Push (VAPID) y APNs directo**: solo tokens FCM de Android e iOS.
- **Borrar los tokens al desactivar o borrar el usuario**: el proyecto no tiene endpoint de usuarios.
- **Un endpoint `/logout`**: retirar el token al cerrar sesión es responsabilidad del móvil, que llama al `DELETE`.
- **Preferencias de notificación** por usuario o por tipo de evento.

---

## Modelo de datos

### Tabla `user_device_tokens`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | `bigIncrements` | Nunca sale en el path; sí en el Resource |
| `user_id` | `foreignId` → `users.id` | `cascadeOnDelete()` · índice |
| `token` | `string(512)` | **Único global** · se guarda tal cual, sin normalizar |
| `platform` | `string` | Enum `DevicePlatform`: `android` \| `ios` |
| `last_seen_at` | `timestamp` | No nullable · `now()` del servidor en cada `POST` |
| `created_at` / `updated_at` | `timestamps` | |

Sin `status`, sin `deleted_at` y sin `registered_by`: el autor es `user_id`.

### Enum `App\Enums\DevicePlatform`

`Android = 'android'`, `Ios = 'ios'`.

### Modelo `UserDeviceToken`

- `#[Fillable]` con `user_id`, `token`, `platform` y `last_seen_at`.
- `casts()`: `platform` → `DevicePlatform`, `last_seen_at` → `datetime`.
- `user()` es `BelongsTo`. `User` gana `deviceTokens()` como `HasMany`.

### Validación (`StoreDeviceTokenRequest`)

| Campo | Reglas |
|---|---|
| `token` | `required\|string\|max:512\|regex:/^\S+$/u` (sin espacios; un espacio es 422, no se recorta) |
| `platform` | `required` + `Rule::enum(DevicePlatform::class)` |

Mensajes en español. El `DELETE` no tiene FormRequest: si el `{token}` del path no existe, responde 404.

### `DeviceTokenResource` (cinco claves)

```json
{
  "id": 12,
  "token": "fcm-token-…",
  "platform": "android",
  "lastSeenAt": "23-09-2026 10:15:02 AM",
  "createdAt": "23-09-2026 10:15:02 AM"
}
```

- Las fechas van en formato `d-m-Y h:i:s A`, como en `TripFuelResource`.
- `platform` sale con el valor crudo del enum.
- No expone `userId`: el dueño es siempre quien hace la petición.

---

## Plan de implementación

1. **Migración, enum, modelo y factory.** Crear la migración `create_user_device_tokens_table`, `App\Enums\DevicePlatform`, el modelo `UserDeviceToken` (con `#[Fillable]`, `casts()` y `user()`) y `UserDeviceTokenFactory`, cuyo `token` es un string aleatorio de unos 160 caracteres sin espacios y cuya plataforma sale al azar. Añadir `User::deviceTokens()`. Verificación: `php artisan migrate` en local y en la base de tests.
2. **Contrato y service.** Crear `DeviceTokenServiceInterface` con dos métodos:
   - `registerToken(User $user, array $data): array{token: UserDeviceToken, created: bool}`
   - `deleteToken(User $user, string $token): UserDeviceToken`

   Implementar `DeviceTokenService` con `#[Override]`: el upsert en `DB::transaction` + `lockForUpdate`, con los tres casos de la sección de alcance, y el `deleteToken` que lanza `NotFoundError` («El token de dispositivo no existe») si el token no existe o es ajeno. Registrar `DeviceTokenProvider` en `bootstrap/providers.php`.
3. **Capa HTTP.** Crear `StoreDeviceTokenRequest`, `DeviceTokenResource`, `DeviceTokenController` (`store` y `destroy`, con el service por parámetro de método, `try/catch` y `ResponseHandler`; el `store` responde 201 o 200 según `created`) y `routes/device-tokens.php` con el prefijo `device-tokens`, nombres de ruta propios y `jwt.auth`, incluido desde `routes/api.php`. Verificación: `php artisan route:list --path=device-tokens` muestra dos rutas.
4. **Tests.** `tests/Feature/DeviceTokenTest.php` y `tests/Unit/DeviceTokenServiceTest.php`. Correr `php artisan test --compact --filter=DeviceToken`.
5. **OpenAPI.** Añadir los atributos a Controller, FormRequest y Resource, y correr `php artisan l5-swagger:generate`.
6. **Documentación.** Añadir la sección `DeviceToken` en `CLAUDE.md` (dominios implementados), escribir `references/device-tokens-api.md` y marcar la spec como `Implementado`. Correr `vendor/bin/pint --dirty --format agent`.

---

## Criterios de aceptación

**Esquema**

- [ ] Existe la tabla `user_device_tokens` con las columnas del modelo de datos, índice único en `token` y FK `user_id` con `cascadeOnDelete`.
- [ ] `User::deviceTokens()` devuelve los tokens del usuario.

**`POST /api/device-tokens`**

- [ ] Con un token nuevo responde **201**, crea una fila con el `user_id` del autenticado y fija `last_seen_at`.
- [ ] Con un token que ya es del autenticado responde **200**. No crea una segunda fila, actualiza `platform` y avanza `last_seen_at`.
- [ ] Con un token de otro usuario responde **200** y la fila pasa al autenticado: sigue habiendo una sola fila con ese token.
- [ ] Un `user_id` en el body se ignora: el dueño es siempre el autenticado.
- [ ] Sin `token`, con `token` de más de 512 caracteres o con espacios, sin `platform`, o con `platform` distinto de `android`/`ios`, responde **422** con mensajes en español.
- [ ] Responde 201/200 para los siete roles, `pilot` y `carrier` sin empresa incluidos.

**`DELETE /api/device-tokens/{token}`**

- [ ] Borrar un token propio responde **200** y la fila desaparece físicamente.
- [ ] Un token inexistente responde **404** con el mensaje «El token de dispositivo no existe».
- [ ] Un token de otro usuario responde **404** con el mismo mensaje, y la fila sigue intacta.

**Transversales**

- [ ] Sin JWT, las dos rutas responden **401** con el sobre del proyecto.
- [ ] `DeviceTokenResource` expone exactamente cinco claves: `id`, `token`, `platform`, `lastSeenAt` y `createdAt`.
- [ ] `UserResource` y los claims del JWT no cambian de forma.
- [ ] `composer.json` no gana dependencias y no aparece ninguna variable de entorno nueva.
- [ ] `php artisan test --compact` pasa completo.
- [ ] `storage/api-docs/api-docs.json` incluye las dos rutas.
- [ ] Existe `references/device-tokens-api.md`.

---

## Decisiones

- **Sí:** tabla hija 1:N (`user_device_tokens`) y no pivote. Un token pertenece a un solo usuario y un usuario tiene varios dispositivos.
- **No:** un solo token por usuario, en una columna de `users`. Perdería el segundo dispositivo.
- **Sí:** solo FCM (Android e iOS). Un único formato de string para ambas plataformas.
- **No:** Web Push, APNs directo ni Expo. Formatos distintos que no se usan hoy.
- **Sí:** guardar sin enviar. El envío abre sus propias decisiones (SDK, credenciales, eventos, cola, limpieza de tokens inválidos) y merece su spec.
- **Sí:** `token` único global con **reasignación** en el `POST`. Si el teléfono cambia de cuenta, el usuario anterior deja de recibir sus notificaciones.
- **No:** responder 400 ante un token ajeno. Dejaría al usuario nuevo sin push y al anterior recibiendo las del teléfono ajeno.
- **Sí:** el `POST` es idempotente, 201 al crear y 200 al refrescar o reasignar. Mismo criterio que el piso de 15 s de SPEC 26: 201 solo cuando nace una fila.
- **Sí:** `DELETE` por token en el path, no por id. El móvil conoce su token, no el id de la fila.
- **Sí:** 404 tanto para inexistente como para ajeno. Distinguirlos revelaría que el token está registrado en otra cuenta; es el precedente de `resolvePilot()` en SPEC 11.
- **Sí:** borrado físico. Un token retirado no es historial.
- **Sí:** `jwt.auth` a secas, sin `role:` ni `carrier.required`. Cualquier rol puede llegar a recibir notificaciones, y tener empresa no tiene que ver con tener un teléfono.
- **Sí:** columna `platform` obligatoria. FCM arma el payload distinto por plataforma y la spec del envío la necesitará.
- **Sí:** `last_seen_at` no nullable y refrescado en cada `POST`. Sirve de base para una purga futura sin tener que migrar después.
- **No:** límite de tokens por usuario. Los tokens muertos los limpiará la spec del envío con la respuesta de FCM.
- **Sí:** `cascadeOnDelete()` en `user_id`, la primera FK con cascade del proyecto. Un token sin usuario no tiene ningún valor, a diferencia de un viaje o un gasto.
- **Sí:** `string(512)`. FCM no documenta un máximo; 512 deja margen y permite un índice único normal.
- **No:** `text`. Sin necesidad real y con un índice único más caro.
- **Sí:** el token se guarda tal cual y un espacio es 422. Es un identificador opaco y sensible a mayúsculas, como `google_place_id`; se rechaza, no se arregla.
- **No:** `GET` de los tokens propios. El móvil no lo necesita.
- **No:** endpoint `/logout`. Retirar el token es un `DELETE` explícito del móvil.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El móvil no llama al `DELETE` al cerrar sesión, y el token sigue ligado al usuario anterior | Si otro usuario entra en ese teléfono, el `POST` lo reasigna. Si nadie vuelve a entrar, lo limpiará la spec del envío cuando FCM devuelva `UNREGISTERED`. |
| Acumulación de tokens muertos (app desinstalada, token rotado por FCM) | `last_seen_at` deja preparada una purga futura; la limpieza real llega con la spec del envío. |
| Un usuario malicioso manda el token de otro y se lo "roba" por reasignación | El token FCM solo lo conoce el dispositivo que lo generó, así que robarlo exige tener ya ese dispositivo o interceptar su tráfico. Se acepta el riesgo, como lo acepta cualquier backend FCM. |
| Un token FCM futuro más largo que 512 caracteres | Sería un 422 claro, no un 500, y se corrige con una migración que amplíe la columna. |
| Tokens con caracteres especiales en el path del `DELETE` | Los tokens FCM usan `[A-Za-z0-9:_-]`, que es seguro en una URL. El móvil debe codificar el token con `encodeURIComponent` de todos modos, y se documenta en `references/`. |

---

## Lo que **no** está en esta spec

- Envío de notificaciones push (SDK de Firebase, credenciales, eventos que notifican).
- Limpieza de tokens inválidos según la respuesta de FCM.
- Purga por antigüedad con `last_seen_at`.
- Listado de tokens (`GET /api/device-tokens`, `/me`).
- Límite de tokens por usuario.
- Web Push, APNs directo o Expo.
- Endpoint `/logout`.
- Preferencias de notificación.

Cada una de esas, si llega, va en su propia spec.
