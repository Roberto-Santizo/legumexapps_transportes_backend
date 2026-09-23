# SPEC 35 — Notificaciones push de viajes vía FCM

> **Estado:** Implementado
> **Depende de:** SPEC 24, SPEC 34
> **Fecha:** 2026-09-23
> **Objetivo:** Enviar notificaciones push por Firebase Cloud Messaging a los tokens de `user_device_tokens` cuando un viaje se asigna (al piloto asignado), se inicia o se finaliza (a todos los usuarios no piloto que alcanzan el viaje), después de la respuesta HTTP y borrando los tokens que FCM rechaza.

Es la **segunda mitad de SPEC 34**. Aquella guardó los tokens sin enviar nada; esta los lee con `User::deviceTokens()` y abre la **tercera llamada saliente** del proyecto, tras Google Places y Routes. Es también la **primera tarea diferida** del proyecto: el envío corre con `dispatchAfterResponse()`, sin worker, por la misma razón que `TripPositionUpdated` usa `ShouldBroadcastNow`.

No crea rutas ni tablas. Engancha tres acciones ya publicadas de SPEC 24 (`/assignment`, `/start` y `/finish`) sin cambiar su contrato HTTP.

Depende de SPEC 24 por las tres acciones y el ámbito, y de SPEC 34 por los tokens. No depende de SPEC 27, aunque `/assignment` cree la carga: el push no mira combustible.

---

## Alcance

**Dentro:**

- **Dependencia `kreait/laravel-firebase`**, instalada por el usuario (no por el agente). Credencial en `FIREBASE_CREDENTIALS`, la ruta al JSON de la service account, añadida a `.env.example`.
- **Contrato de envío por capacidad**, con la misma estructura que Storage:
  - `PushNotificationServiceInterface` en `app/Interfaces/PushNotification/`.
  - `FcmPushNotificationService` en `app/Services/PushNotification/`.
  - `PushNotificationProvider` registrado en `bootstrap/providers.php`.

  `FcmPushNotificationService` es el **único archivo que menciona `Kreait\`**, regla espejo de `Intervention\` y `OpenSpout\`. Un solo método envía un mensaje (título, cuerpo, `data`) a una lista de tokens, en lotes multicast de **500**, y **devuelve los tokens que FCM rechazó** (`UNREGISTERED`, `INVALID_ARGUMENT`).
- **Dominio `TripNotification`**, que decide a quién se envía y qué se dice:
  - `TripNotificationServiceInterface` y `TripNotificationService`, registrados con `TripNotificationProvider`.
  - Enum `App\Enums\TripNotificationType`: `trip.assigned`, `trip.started`, `trip.finished`.

  El service resuelve los destinatarios, arma el texto, envía por el contrato de arriba y **borra de `user_device_tokens` los tokens rechazados**. Lee el modelo `Trip` directamente y **no inyecta `TripServiceInterface`**, para no cerrar un ciclo en el contenedor, con el precedente de `closeOpenTimeout()`.
- **Job `App\Jobs\SendTripNotification`** (`tripId` + tipo), despachado con `dispatchAfterResponse()`. Estrena `app/Jobs/`. No necesita worker ni depende de `QUEUE_CONNECTION`.
- **Tres enganches en `TripService`**, cada uno **después** de que la transacción haga commit:

  | Acción | Tipo | Destinatarios |
  |---|---|---|
  | `assign()` (`PATCH /{trip}/assignment`) | `trip.assigned` | El piloto asignado, en **cada** asignación exitosa, aunque se repita el mismo piloto |
  | `start()` (`PATCH /{trip}/start`) | `trip.started` | Todo usuario con rol distinto de `pilot`, **salvo** los `carrier` que no son dueños de la empresa que tomó el viaje |
  | `finish()` (`PATCH /{trip}/finish`) | `trip.finished` | Los mismos que `trip.started` |

- **Textos en español**:
  - «Nuevo viaje asignado» / «Orden {order} · {destino}».
  - «Viaje iniciado» / «Orden {order} · {piloto} en ruta a {destino}».
  - «Viaje finalizado» / «Orden {order} · {piloto} llegó a {destino}».

  El bloque `data` es `{ type, tripId }`, con los dos valores como string.
- **Degradado silencioso.** Si falta la credencial, si FCM falla o si cualquier otra cosa lanza durante el envío, se registra con `Log::error` y no se reintenta. La acción del viaje ya respondió, así que el cliente nunca ve un 5xx por el push.
- **Tests:**
  - Un doble `tests/Doubles/InMemoryPushNotificationService` **bindeado globalmente** en `tests/Pest.php`. Kreait usa su propio Guzzle, y `Http::preventStrayRequests()` no lo intercepta.
  - `TripNotificationTest` (Feature): destinatarios por evento y por rol, reasignación, el `PATCH` del administrador sin envío y la limpieza de tokens.
  - `TripNotificationServiceTest` (Unit).
  - `FcmPushNotificationServiceTest` (Unit): `Messaging` de kreait mockeado, los lotes de 500 y la extracción de tokens inválidos.
- **Resumen de integración para el móvil** en `references/trip-notifications-api.md`.

**Fuera de alcance (para futuras specs):**

- **Otros eventos:**
  - Carga o viático pendiente de confirmar → piloto.
  - Viaje nuevo en la bolsa → transportistas.
  - Confirmaciones del piloto → empresa.
- **El `PATCH` general del administrador**, aunque mueva el `status`: no notifica.
- **Aviso al piloto desasignado** cuando se reasigna el viaje.
- **Historial o bandeja de notificaciones**: sin tabla y sin endpoint de lectura.
- **Cola con worker, reintentos y backoff.**
- **Preferencias por usuario** (silenciar eventos).
- **Purga por `last_seen_at`**, límite de tokens por usuario, Web Push y APNs directo.
- **Endpoint de prueba** de tipo «envíame un push».
- **Traducción** a otros idiomas.
- **Notificar vía websocket**: Reverb sigue solo con las posiciones.

---

## Modelo de datos

La spec no crea tablas, columnas ni migraciones. Reutiliza `user_device_tokens` (SPEC 34), `users` (SPEC 01), `carriers` (SPEC 03) y `trips` (SPEC 24).

### Enum `App\Enums\TripNotificationType`

```php
enum TripNotificationType: string
{
    case Assigned = 'trip.assigned';
    case Started = 'trip.started';
    case Finished = 'trip.finished';
}
```

### Contrato `PushNotificationServiceInterface`

```php
/**
 * @param  list<string>  $tokens
 * @param  array<string, string>  $data
 * @return list<string>  tokens FCM rechazados (UNREGISTERED / INVALID_ARGUMENT)
 */
public function send(array $tokens, string $title, string $body, array $data): array;
```

- Una lista vacía no llama a FCM y devuelve `[]`.
- Parte los tokens en lotes de `FcmPushNotificationService::BATCH_SIZE = 500`.
- Si un fallo afecta a todo el lote o a toda la llamada (credencial, red), lanza. Es el llamador quien lo registra.
- Los fallos por token que no son `UNREGISTERED` ni `INVALID_ARGUMENT` (por ejemplo cuota o un error interno) no se devuelven, y el token no se borra.

### Contrato `TripNotificationServiceInterface`

```php
public function notify(int $tripId, TripNotificationType $type): void;
```

- Si el viaje no existe, está borrado o no tiene destinatarios con token, termina sin hacer nada.
- Borra con un solo `DELETE ... WHERE token IN (...)` los tokens que devuelve `send()`.
- Captura cualquier `Throwable` con `Log::error`. Nunca lanza.

### Job `App\Jobs\SendTripNotification`

```php
public function __construct(public int $tripId, public TripNotificationType $type) {}
// handle(TripNotificationServiceInterface $service): $service->notify(...)
```

Se despacha con `SendTripNotification::dispatchAfterResponse($trip->id, $type)`. El job lleva solo el id, y el service recarga el viaje, para leer el estado ya comprometido.

### Resolución de destinatarios

- `trip.assigned`: los tokens de `trips.pilot_id`.
- `trip.started` y `trip.finished`: los tokens de los usuarios que cumplen `role <> 'pilot'` **y** una de estas dos condiciones:
  - su rol no es `carrier`;
  - es `carrier` y es dueño (`carriers.user_id`) de la empresa de `assigned_by`, resuelta con `User::currentCarrier()`.

  Todo sale en una sola consulta de tokens con `JOIN users`. Un `carrier` sin empresa queda fuera.

### Mensaje

| Tipo | `title` | `body` | `data` |
|---|---|---|---|
| `trip.assigned` | Nuevo viaje asignado | Orden {order} · {location.name} | `{ "type": "trip.assigned", "tripId": "42" }` |
| `trip.started` | Viaje iniciado | Orden {order} · {pilot.name} en ruta a {location.name} | `{ "type": "trip.started", "tripId": "42" }` |
| `trip.finished` | Viaje finalizado | Orden {order} · {pilot.name} llegó a {location.name} | `{ "type": "trip.finished", "tripId": "42" }` |

### Configuración

- La de kreait: `config/firebase.php`, publicado con `vendor:publish`, que lee `FIREBASE_CREDENTIALS`.
- `.env.example` gana `FIREBASE_CREDENTIALS=`.
- `phpunit.xml` no la define, porque la suite usa el doble.

---

## Plan de implementación

1. **Dependencia y configuración.** El usuario corre `composer require kreait/laravel-firebase`; el agente solo verifica que exista en `vendor/`. Después, `php artisan vendor:publish --provider="Kreait\Laravel\Firebase\ServiceProvider" --tag=config` y añadir `FIREBASE_CREDENTIALS=` a `.env.example`. Verificación: `php artisan config:show firebase` resuelve sin error.
2. **Contrato de envío.** Crear:
   - `PushNotificationServiceInterface`.
   - `FcmPushNotificationService`, con `#[Override]`. Inyecta `Kreait\Firebase\Contract\Messaging` por constructor. Usa `CloudMessage` + `sendMulticast` en lotes de 500 y lee los fallos `UNREGISTERED`/`INVALID_ARGUMENT` del `MulticastSendReport`.
   - `PushNotificationProvider`, registrado en `bootstrap/providers.php`.

   Añadir `tests/Doubles/InMemoryPushNotificationService` (registra los envíos, con una lista configurable de tokens a rechazar) y bindearlo globalmente en `tests/Pest.php`. Tests: `FcmPushNotificationServiceTest`.
3. **Dominio `TripNotification`.** Crear:
   - Enum `TripNotificationType`.
   - `TripNotificationServiceInterface`.
   - `TripNotificationService`: resolución de destinatarios, textos, envío, borrado de tokens rechazados y `try/catch` con `Log::error`.
   - `TripNotificationProvider`, registrado.

   Todavía sin enganchar a nada. Tests: `TripNotificationServiceTest` (destinatarios por tipo y rol, limpieza, viaje borrado o inexistente, fallo del proveedor que no lanza).
4. **Job.** Crear `App\Jobs\SendTripNotification` con `php artisan make:job`, que delega en `TripNotificationServiceInterface::notify()`. Test unitario: `handle()` invoca al service.
5. **Enganches en `TripService`.** En `assign()`, `start()` y `finish()`, despachar `SendTripNotification::dispatchAfterResponse()` **fuera y después** de la transacción. El `update()` general no despacha nada. Tests: `TripNotificationTest` (Feature, por HTTP con el doble):
   - `/assignment` notifica al piloto;
   - reasignar vuelve a notificar;
   - `/start` y `/finish` notifican a los no-piloto dentro del ámbito;
   - el `PATCH` del administrador no notifica;
   - una acción que falla con 4xx no notifica.

   Además, correr `TripTest` completo para confirmar que el contrato HTTP no cambió.
6. **Documentación.**
   - Añadir la sección `TripNotification` en `CLAUDE.md` (dominios implementados, tercera llamada saliente, `FIREBASE_CREDENTIALS`, el doble global).
   - Escribir `references/trip-notifications-api.md` con los tres tipos, el payload `data` y los textos, para el móvil.
   - Marcar la spec como `Implementado` y correr `vendor/bin/pint --dirty --format agent`.

No hay paso de OpenAPI: la spec no crea ni cambia ningún endpoint.

---

## Criterios de aceptación

**Infraestructura**

- [ ] `composer.json` incluye `kreait/laravel-firebase`, existe `config/firebase.php` y `.env.example` tiene `FIREBASE_CREDENTIALS=`.
- [ ] Ningún archivo fuera de `app/Services/PushNotification/` menciona `Kreait\`.
- [ ] `PushNotificationServiceInterface` está bindeado a `FcmPushNotificationService`, y `TripNotificationServiceInterface` a `TripNotificationService`.
- [ ] En la suite, el contenedor resuelve `PushNotificationServiceInterface` al doble en memoria sin que ningún test lo pida.

**Envío (`FcmPushNotificationService`)**

- [ ] 1 200 tokens producen exactamente 3 llamadas multicast (500 + 500 + 200).
- [ ] Una lista vacía no llama a `Messaging` y devuelve `[]`.
- [ ] Devuelve solo los tokens con fallo `UNREGISTERED` o `INVALID_ARGUMENT`; un fallo de otro tipo no aparece en el resultado.

**Destinatarios**

- [ ] `PATCH /{trip}/assignment` exitoso envía `trip.assigned` solo a los tokens del piloto asignado.
- [ ] Reasignar el viaje vuelve a enviar `trip.assigned`, al piloto nuevo o al mismo si no cambió. El piloto anterior no recibe nada.
- [ ] `PATCH /{trip}/start` exitoso envía `trip.started` a los tokens de `administrator`, `manager`, `export`, `user`, `shipment` y del dueño de la empresa que tomó el viaje.
- [ ] Ningún `pilot` recibe `trip.started` ni `trip.finished`, incluido el asignado.
- [ ] Un `carrier` de otra empresa y un `carrier` sin empresa no reciben `trip.started` ni `trip.finished`.
- [ ] `PATCH /{trip}/finish` exitoso envía `trip.finished` a los mismos destinatarios que `trip.started`.
- [ ] Un usuario con dos tokens recibe el envío en los dos.

**Sin envío**

- [ ] El `PATCH /api/trips/{trip}` del administrador no envía nada, aunque cambie el `status`.
- [ ] Una acción que responde 4xx (400, 403, 404, 422) no envía nada.
- [ ] Si ningún destinatario tiene token, no se llama al contrato de envío.

**Contenido**

- [ ] El título, el cuerpo y `data` de cada tipo coinciden con la tabla del modelo de datos.
- [ ] Los valores de `data` son strings (`"tripId": "42"`).

**Limpieza y degradado**

- [ ] Los tokens que devuelve `send()` desaparecen de `user_device_tokens`; los demás quedan intactos.
- [ ] Si el contrato de envío lanza, la acción del viaje responde igual (200), se escribe un `Log::error` y no se borra ningún token.
- [ ] Sin `FIREBASE_CREDENTIALS`, `/assignment`, `/start` y `/finish` responden igual que antes de esta spec.

**Transversales**

- [ ] El contrato HTTP de `/assignment`, `/start` y `/finish` (cuerpo, status y Resource) no cambia, y `TripTest` pasa sin modificaciones.
- [ ] No hay migraciones nuevas ni rutas nuevas (`php artisan route:list` da el mismo conteo).
- [ ] `php artisan test --compact` pasa completo.
- [ ] Existe `references/trip-notifications-api.md`.

---

## Decisiones

- **Sí:** `kreait/laravel-firebase` detrás de un contrato propio. Resuelve el OAuth de la service account y el multicast, y el contrato por capacidad permite sustituirlo por un doble, como Storage.
- **No:** `laravel-notification-channels/fcm`. Acopla el envío al sistema de `Notification` de Laravel, que el proyecto no usa, y usa kreait por debajo de todos modos.
- **No:** HTTP v1 a mano con `Http::` + `google/auth`. Obliga a reimplementar la firma del token OAuth y el troceo en lotes.
- **Sí:** `dispatchAfterResponse()`. No suma la latencia de FCM a las acciones del viaje y no exige un proceso nuevo.
- **No:** envío síncrono dentro de la petición. Sumaría la latencia de FCM a cada `/start` y `/finish`, justo los que más destinatarios tienen.
- **No:** cola con worker. Sería un segundo proceso permanente, junto a Reverb, solo para esto; si el volumen lo exige, llegará en otra spec.
- **Sí:** el job lleva solo `tripId` y el tipo, y el service recarga el viaje. Así lee el estado ya comprometido y no serializa modelos.
- **Sí:** despachar después del commit. Si la transacción de `/assignment` se revierte, no sale un aviso de algo que no ocurrió.
- **Sí:** `TripNotificationService` lee `Trip` directamente, sin `TripServiceInterface`. `TripService` depende del job y el job del service; el contrato inverso cerraría un ciclo, con el precedente de `closeOpenTimeout()`.
- **Sí:** asignación al piloto asignado, e inicio y fin a todos los no-piloto. Es lo que pidió el usuario: el piloto actúa y los demás siguen el viaje.
- **Sí:** excluir a los `carrier` de otras empresas. Respeta el ámbito de SPEC 24, que les responde 403 en `GET /trips/{id}`, y no filtra actividad de la competencia.
- **Sí:** notificar en cada `/assignment`, aunque se repita el piloto. Pudo cambiar el vehículo, y es más simple que comparar el estado anterior.
- **No:** avisar al piloto desasignado. Queda fuera por alcance.
- **No:** notificar desde el `PATCH` general del administrador. Es una vía de corrección sin máquina de estados (el hueco de SPEC 24), no una transición real.
- **Sí:** borrar los tokens `UNREGISTERED`/`INVALID_ARGUMENT`. Es lo que SPEC 34 dejó para esta spec; otros fallos, como la cuota, no significan que el token esté muerto.
- **Sí:** degradado silencioso con `Log::error` y sin reintento. El push es accesorio; misma línea que Reverb caído en SPEC 26.
- **No:** tabla de historial o bandeja de notificaciones. No hay pantalla que la consuma.
- **Sí:** entregar por dispositivo, no por usuario. Un usuario con dos teléfonos recibe el aviso en los dos.
- **Sí:** `data` con `{ type, tripId }` como strings. FCM exige strings en `data`, y al móvil le basta para abrir el viaje.
- **Sí:** el doble de envío se bindea globalmente en `tests/Pest.php`. Kreait no pasa por el facade `Http`, así que `preventStrayRequests()` no protege la suite.
- **Sí:** estrenar `app/Jobs/`. Es la carpeta estándar de Laravel y la crea `make:job`.
- **No:** tocar OpenAPI. No cambia ningún endpoint.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Un test sin el doble dispara un envío real a FCM, porque kreait usa su propio Guzzle y `preventStrayRequests()` no lo cubre | El doble se bindea globalmente en `tests/Pest.php`, y `phpunit.xml` no define `FIREBASE_CREDENTIALS`, así que un envío real fallaría antes de salir. |
| `dispatchAfterResponse()` corre en el mismo proceso PHP. Con miles de tokens, el proceso de PHP-FPM sigue ocupado tras responder | Cada lote de 500 es una sola llamada HTTP. Si el volumen crece, se pasa a cola con worker en otra spec: el job ya existe y solo cambia cómo se despacha. |
| Credencial caducada o revocada: los envíos fallan en silencio | `Log::error` en cada fallo. Monitorizarlo queda fuera. |
| Un fallo transitorio de FCM pierde la notificación | Se acepta: sin reintentos por diseño. El estado del viaje se sigue consultando por la API. |
| La lista de destinatarios crece con cada usuario no-piloto, y `/start` y `/finish` notifican a todos | Una sola consulta de tokens con `JOIN users`. El troceo en 500 evita el tope de FCM. |
| Tokens borrados por error si FCM clasifica mal un fallo | Solo se borran `UNREGISTERED` e `INVALID_ARGUMENT`, y el móvil vuelve a registrar su token con `POST /api/device-tokens` al abrir la app. |
| El servidor de producción no tiene el JSON de la service account en la ruta de `FIREBASE_CREDENTIALS` | La API funciona entera sin push (degradado silencioso), y el requisito queda documentado en `CLAUDE.md` y en `references/`. |

---

## Lo que **no** está en esta spec

- Otros eventos: cargas o viáticos pendientes, bolsa de viajes, confirmaciones del piloto.
- Notificar desde el `PATCH` general del administrador.
- Aviso al piloto desasignado.
- Historial o bandeja de notificaciones.
- Cola con worker, reintentos y backoff.
- Preferencias de notificación por usuario.
- Purga por `last_seen_at`, límite de tokens por usuario, Web Push y APNs directo.
- Endpoint de prueba de envío.
- Traducción a otros idiomas.
- Notificaciones por websocket.

Cada una de esas, si llega, va en su propia spec.
