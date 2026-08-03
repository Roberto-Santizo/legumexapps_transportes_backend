# SPEC 02 — Envío de correos de autenticación

> **Estado:** Aprobado
> **Depende de:** SPEC 01
> **Fecha:** 2026-08-03
> **Objetivo:** Enviar por correo los códigos de confirmación y reseteo que SPEC 01 ya persiste, a través de un `AuthEmails` singleton que delega en los mailers nativos de Laravel (log, SMTP/Mailtrap o Resend según `.env`).

---

## Alcance

**Dentro:**

- Instalación de `resend/resend-laravel` y registro del mailer `resend` en `config/mail.php`.
- Variables `RESEND_API_KEY`, `MAIL_FROM_ADDRESS` y `MAIL_FROM_NAME` en `.env.example`, con `MAIL_MAILER=log` como valor por defecto de desarrollo.
- Contrato `App\Interfaces\Auth\AuthEmailsInterface` con tres métodos.
- Implementación `App\Mail\AuthEmails`, registrada como **singleton** en el `AuthProvider` existente.
- Tres Mailables en `app/Mail/`: `AccountConfirmationMail`, `PasswordResetMail`, `WelcomeMail`.
- Tres plantillas Markdown en `resources/views/emails/auth/`, con textos en español.
- Captura de cualquier `Throwable` del proveedor dentro de `AuthEmails`, con `Log::error` y sin propagar.
- Refactor de `AuthService`: recibe `AuthEmailsInterface` por constructor, extrae el código en claro fuera de la transacción y dispara el envío tras el commit en `register`, `confirmAccount` y `forgotPassword`.
- `Mail::fake()` global en el `beforeEach` de `tests/Pest.php`.
- Tests nuevos: Feature (que cada endpoint encola el Mailable correcto al destinatario correcto) y Unit (que un fallo del proveedor no rompe el flujo).

**Fuera de alcance (para specs futuras):**

- **Endpoint `resend-code`.** Sigue pendiente desde SPEC 01; esta spec no añade rutas.
- **Colas.** El envío es síncrono dentro del request. `ShouldQueue`, worker y tabla `jobs` van en su propia spec.
- **Reintentos ante fallo del proveedor.** Un correo que falla se loguea y se pierde.
- **Webhooks de Resend** (bounces, quejas, entregas) y tracking de aperturas.
- **Correos ajenos a autenticación** (viajes, reportes, notificaciones operativas).
- **Branding propio de las plantillas.** Se usan los componentes Markdown que trae Laravel sin publicar ni retocar sus vistas.
- **Traducción de los correos.** Español fijo, sin `lang/`.
- **Verificación del dominio en Resend.** Es tarea de infraestructura, no de código.
- **Documentación Swagger.** El contrato HTTP de los endpoints no cambia, así que no hay nada que regenerar.

---

## Modelo de datos

**Esta spec no crea ni modifica ninguna tabla.** No hay migraciones. Los códigos siguen viviendo en `account_confirmation_tokens` y `password_reset_tokens` tal como los dejó SPEC 01. Lo único nuevo son un contrato, tres objetos de correo y dos entradas de configuración.

### 1. Contrato `App\Interfaces\Auth\AuthEmailsInterface`

```php
interface AuthEmailsInterface
{
    public function sendAccountConfirmation(User $user, string $code): void;

    public function sendPasswordReset(User $user, string $code): void;

    public function sendWelcome(User $user): void;
}
```

Los tres devuelven `void` y **ninguno lanza**: un fallo del proveedor se loguea y se traga dentro de la implementación. El `$code` que reciben es el código **en claro**, el mismo que `AuthService::storeCode()` ya devuelve y hasta ahora se descartaba.

### 2. Mailables

| Clase | Asunto | Datos que recibe | Vista |
|---|---|---|---|
| `AccountConfirmationMail` | Confirma tu cuenta | `User $user`, `string $code` | `emails.auth.account-confirmation` |
| `PasswordResetMail` | Restablece tu contraseña | `User $user`, `string $code` | `emails.auth.password-reset` |
| `WelcomeMail` | Bienvenido a Legumex Transportes | `User $user` | `emails.auth.welcome` |

Los tres usan promoción de propiedades en el constructor, `Envelope` con el asunto fijo y `Content(markdown: ...)`. El remitente no se declara en el `Envelope`: sale de `config('mail.from')`.

Las plantillas de código muestran el código de 6 dígitos en un `mail::panel` y avisan de la vigencia de 1 hora. Ninguna incluye enlaces ni botones — el front pide el código a mano.

### 3. Configuración

`config/mail.php` gana un mailer:

```php
'resend' => [
    'transport' => 'resend',
],
```

`.env.example`:

```dotenv
MAIL_MAILER=log
MAIL_FROM_ADDRESS="onboarding@resend.dev"
MAIL_FROM_NAME="Legumex Transportes"
RESEND_API_KEY=
```

`MAIL_MAILER` es el único interruptor de proveedor: `log` en local, `smtp` con las credenciales de Mailtrap en desarrollo compartido, `resend` en producción. `AuthEmails` nunca nombra un mailer.

### 4. Estructura de log ante fallo

```php
Log::error('No se pudo enviar el correo de autenticación', [
    'mailable' => AccountConfirmationMail::class,
    'email' => $user->email,
    'exception' => $exception->getMessage(),
]);
```

Nunca se loguea el código en claro.

---

## Plan de implementación

Cada paso deja el sistema arrancable y es commiteable por sí solo.

1. **Instalar Resend.** `composer require resend/resend-laravel`, añadir el mailer `resend` a `config/mail.php` y las cuatro variables a `.env.example`. *Verificación:* `php artisan config:show mail.mailers.resend` devuelve el transporte.

2. **Contrato de correos.** Crear `app/Interfaces/Auth/AuthEmailsInterface.php` con los tres métodos y su PHPDoc. Sin implementación todavía; nada lo consume.

3. **Mailable de confirmación.** `php artisan make:mail AccountConfirmationMail --markdown=emails.auth.account-confirmation`, con `User` y `string $code` promovidos, asunto *Confirma tu cuenta* y la plantilla con el código en un `mail::panel`. *Verificación:* `php artisan tinker --execute '(new App\Mail\AccountConfirmationMail(App\Models\User::factory()->make(), "123456"))->render();'` no lanza.

4. **Mailable de reseteo.** Igual que el anterior, `PasswordResetMail` con asunto *Restablece tu contraseña*.

5. **Mailable de bienvenida.** `WelcomeMail`, solo recibe `User`, asunto *Bienvenido a Legumex Transportes*, sin código.

6. **Implementación `AuthEmails`.** `app/Mail/AuthEmails.php` implementando la interfaz, con `#[Override]` en cada método. Cada uno hace `Mail::to($user->email)->send(...)` dentro de un `try/catch (\Throwable)` que loguea con `Log::error` y no propaga.

7. **Registro del singleton.** En `app/Providers/Auth/AuthProvider.php`, `$this->app->singleton(AuthEmailsInterface::class, AuthEmails::class);` junto al bind existente. *Verificación:* `php artisan tinker --execute 'var_dump(app(App\Interfaces\Auth\AuthEmailsInterface::class) === app(App\Interfaces\Auth\AuthEmailsInterface::class));'` imprime `true`.

8. **`Mail::fake()` global.** Añadirlo al `beforeEach` de `tests/Pest.php`, junto a `RefreshDatabase`. *Verificación:* `php artisan test --compact` sigue en verde antes de tocar `AuthService`.

9. **`AuthService` recibe el contrato.** Añadir el constructor con `private AuthEmailsInterface $authEmails` promovido. Todavía no se usa. *Verificación:* la suite sigue verde, el contenedor resuelve el service.

10. **Enganchar `register`.** `DB::transaction()` pasa a devolver el código en claro; tras el commit, `sendAccountConfirmation($user, $code)`. El método sigue devolviendo el `User`. *Verificación:* un test que registra y comprueba `Mail::assertSent(AccountConfirmationMail::class)`.

11. **Enganchar `forgotPassword`.** Mismo patrón: se guarda el código, se cierra la transacción y se envía `sendPasswordReset`. El retorno temprano cuando el email no existe se mantiene intacto: sin usuario no hay correo.

12. **Enganchar `confirmAccount`.** Tras la transacción que marca `email_verified_at` y borra la fila, `sendWelcome($user)`.

13. **Formato.** `vendor/bin/pint --dirty --format agent`.

14. **Tests.** Delegar al agente `feature-tests`: Feature sobre `register`, `forgot-password` y `confirm-account` verificando el Mailable y el destinatario; Unit de `AuthService` con un doble de `AuthEmailsInterface` que lanza, comprobando que el flujo no se rompe.

---

## Criterios de aceptación

**Configuración**

- [ ] `php artisan config:show mail.mailers.resend` devuelve el mailer con `transport => resend`.
- [ ] `.env.example` contiene `RESEND_API_KEY`, `MAIL_FROM_ADDRESS="onboarding@resend.dev"`, `MAIL_FROM_NAME="Legumex Transportes"` y `MAIL_MAILER=log`.
- [ ] Cambiar `MAIL_MAILER` entre `log`, `smtp` y `resend` no requiere tocar ningún archivo de `app/`.

**Singleton**

- [ ] `app(AuthEmailsInterface::class)` resuelve a una instancia de `App\Mail\AuthEmails`.
- [ ] Dos resoluciones consecutivas de `AuthEmailsInterface` devuelven **la misma instancia**.
- [ ] `AuthService` recibe el contrato por constructor; ningún método de `AuthService` usa la facade `Mail` directamente.

**Correos enviados**

- [ ] `POST /api/auth/register` con datos válidos envía exactamente un `AccountConfirmationMail` al email registrado.
- [ ] El cuerpo renderizado de ese correo contiene el código de 6 dígitos que valida el `confirm-account` siguiente.
- [ ] `POST /api/auth/confirm-account` con código correcto envía exactamente un `WelcomeMail`; con código incorrecto no envía ninguno.
- [ ] `POST /api/auth/forgot-password` con un email registrado envía exactamente un `PasswordResetMail` a ese email.
- [ ] `POST /api/auth/forgot-password` con un email **no** registrado sigue devolviendo 200 y no envía ningún correo.
- [ ] `POST /api/auth/login` no envía ningún correo.

**Tolerancia a fallos**

- [ ] Con un `AuthEmailsInterface` que lanza en `sendAccountConfirmation`, `register` sigue devolviendo 201 y el usuario y su código quedan persistidos.
- [ ] Ese fallo deja un `Log::error` cuyo contexto incluye el email y **no** incluye el código en claro.
- [ ] Ningún endpoint de auth devuelve 500 por un fallo del proveedor de correo.

**Orden respecto a la transacción**

- [ ] Si el `save()` del usuario falla en `register`, no se envía ningún correo.

**Calidad**

- [ ] `php artisan test --compact` pasa en verde, incluidos todos los tests de SPEC 01 sin modificarlos.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] Las tres plantillas renderizan sin error con `->render()`.

---

## Decisiones

**Proveedor y transporte**

- **Sí:** los mailers nativos de Laravel (`config/mail.php` + facade `Mail`). Mailtrap y cualquier SMTP entran con el driver `smtp` sin código nuevo; Resend con su paquete oficial. Cambiar de proveedor es cambiar `MAIL_MAILER`.
- **No:** abstracción HTTP propia con un `ResendDriver` y un `MailtrapDriver` pegando a cada API REST. Reimplementa lo que el framework ya trae y hay que mantener cada driver a mano.
- **Sí:** instalar `resend/resend-laravel` ya, aunque en local se use `log`. Deja el proyecto listo para producción sin una segunda spec de una línea.
- **Sí:** `MAIL_MAILER=log` como valor por defecto en `.env.example`. Un `git clone` no manda correos ni necesita credenciales.
- **Sí:** remitente desde `config('mail.from')`. Es el mecanismo estándar y evita duplicar la configuración dentro de `AuthEmails`.
- **No:** fijar el `from` en el `Envelope` de cada Mailable — tres sitios que mantener sincronizados.

**Forma del singleton**

- **Sí:** `AuthEmailsInterface` en `app/Interfaces/Auth/` e implementación en `app/Mail/AuthEmails.php`. La interfaz sigue la convención por capas del proyecto; la implementación vive junto a los Mailables que instancia.
- **Sí:** `singleton()` y no `bind()`. La clase no tiene estado por petición: una instancia por request es suficiente y es lo que pidió el requisito.
- **Sí:** bind dentro del `AuthProvider` existente. Hoy hay un solo dominio de correos; un `MailProvider` propio sería un provider con un único registro.
- **No:** un `MailService` genérico con `AuthEmails` encima. La indirección extra no compra nada mientras solo existan correos de autenticación.
- **Sí:** inyección por constructor en `AuthService`. Es la excepción consciente a la convención de inyectar por método: eso aplica a los controllers, y `AuthEmails` es una dependencia de los tres métodos del service.

**Comportamiento**

- **Sí:** envío síncrono. Sin tabla `jobs`, sin worker y sin supervisor. El coste es ~300-800 ms en tres endpoints que no son de alta frecuencia.
- **No:** `ShouldQueue`. Añade infraestructura que hoy nadie más necesita; entra cuando haya un segundo caso de uso que la justifique.
- **No:** `dispatchAfterResponse()`. Gana latencia pero pierde el correo sin rastro si el proceso muere, y complica los tests.
- **Sí:** fallo suave. El código ya está en base; que el correo no salga no debe invalidar el registro ni delatar nada en `forgot-password`.
- **Sí:** el `try/catch` vive **dentro** de `AuthEmails`, no en `AuthService`. Un solo lugar donde puede olvidarse, y el service queda legible.
- **No:** meter el envío dentro de `DB::transaction()`. Un commit fallido después del envío dejaría al usuario con un código que no existe.
- **Sí:** el código en claro viaja del `storeCode()` al Mailable por parámetro y nunca se loguea. Persistido solo el hash, igual que en SPEC 01.

**Contenido**

- **Sí:** Markdown Mailables de Laravel. Responsive, con versión texto plano automática y cero HTML de correo que mantener.
- **No:** publicar y retocar los componentes de vendor para meter branding. Es trabajo de diseño, no de esta spec.
- **Sí:** correos sin enlaces ni botones. El flujo de SPEC 01 es de código de 6 dígitos que el usuario teclea; un enlace implicaría endpoints que no existen.
- **Sí:** correo de bienvenida tras `confirm-account`. Cierra el flujo de alta y no toca el contrato del endpoint.
- **No:** endpoint `resend-code`. Sigue pendiente desde SPEC 01 y trae ruta, FormRequest, método de servicio y rate limiting propio.

**Proceso**

- **Sí:** `Mail::fake()` global en `tests/Pest.php`. Los tests de SPEC 01 pasan sin tocarse y ningún test futuro manda correo por descuido.
- **Sí:** tests delegados al agente `feature-tests`, como en el resto del proyecto.
- **No:** regenerar Swagger. Ningún request ni response cambia de forma.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| `onboarding@resend.dev` es el remitente compartido de pruebas de Resend: solo entrega a la dirección dueña de la cuenta. A cualquier otro destinatario la API responde 403 y el correo se pierde en un log. | Sirve para dev y para validar la integración. Producción exige dominio propio verificado en Resend y cambiar `MAIL_FROM_ADDRESS`. Queda anotado como requisito de despliegue, fuera del código. |
| Fallo suave: si el proveedor cae, el usuario se registra pero nunca recibe su código, y sin `resend-code` no tiene forma de pedir otro. Queda bloqueado hasta que un administrador intervenga. | Es el argumento principal para que `resend-code` sea la siguiente spec. Mientras tanto, el `Log::error` deja rastro del email afectado. |
| Envío síncrono: una latencia alta o un timeout del proveedor se traslada íntegro al tiempo de respuesta de `register`, `confirm-account` y `forgot-password`. | Tres endpoints de baja frecuencia. Si el tiempo de respuesta se vuelve un problema, la spec de colas es el remedio y no cambia el contrato de `AuthEmails`. |
| `forgot-password` con envío síncrono se vuelve un oráculo por tiempo: con email registrado tarda lo que tarda el correo, sin él responde al instante. La diferencia es medible. | Riesgo aceptado. Cerrarlo bien exige encolar o meter un retardo artificial, y ambas cosas son de otra spec. El endpoint sigue devolviendo 200 idéntico en los dos casos. |
| El código de 6 dígitos viaja en claro por correo, un canal que el backend no controla. | Es inherente al flujo elegido en SPEC 01. Acotado por la vigencia de 1 hora y por el borrado de la fila al consumirse. |
| `RESEND_API_KEY` filtrada permite mandar correo en nombre del dominio verificado. | Vive solo en `.env`, nunca en el repositorio; `.env.example` la deja vacía. Rotable desde el panel de Resend sin desplegar. |
| El `Mail::fake()` global oculta un fallo real de configuración de correo: la suite puede estar verde con un `config/mail.php` roto. | Los criterios de aceptación incluyen `config:show mail.mailers.resend` y el `->render()` de las tres plantillas, que sí tocan configuración y vistas reales. |
| Una dependencia nueva (`resend/resend-laravel`) que hoy solo se usa en producción. | Paquete oficial del proveedor, mantenido, sin dependencias transitivas pesadas. Si se descarta Resend, se desinstala y solo desaparece una entrada de `config/mail.php`. |

---

## Lo que **no** entra en esta spec

- Endpoint `resend-code` para reenviar el código de confirmación.
- Colas: `ShouldQueue`, worker y tabla `jobs`.
- Reintentos automáticos ante fallo del proveedor.
- Webhooks de Resend (bounces, quejas, entregas) y tracking de aperturas.
- Correos ajenos a autenticación.
- Branding propio de las plantillas de correo.
- Traducción de los correos a otros idiomas.
- Verificación del dominio en Resend (requisito de despliegue, no de código).
- Regeneración de la documentación Swagger.

Cada uno de esos, si entra, va en su propia spec.
