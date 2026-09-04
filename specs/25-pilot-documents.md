# SPEC 25 — Documentos del piloto en el registro

> **Estado:** Aprobado
> **Depende de:** SPEC 01, SPEC 05, SPEC 11, SPEC 19, SPEC 24
> **Fecha:** 2026-09-04
> **Objetivo:** Exigir al piloto, y solo al piloto, la foto del anverso de su DPI y de su licencia durante el registro, guardándolas en la tabla nueva `pilot_documents` y exponiendo sus URLs en `UserResource`, `PilotResource` y `TripResource`.

Es la **primera spec que toca el dominio `Auth`** desde SPEC 10, y la primera que hace subir un archivo en una ruta **pública**: `POST /api/auth/register` no lleva `jwt.auth`, así que hasta hoy ningún archivo entraba al bucket sin un token detrás.

Rompe dos cosas de golpe, y conviene tenerlas delante:

1. **`POST /api/auth/register` deja de ser JSON.** Pasa a `multipart/form-data` y, para `role=pilot`, de cuatro campos a seis. Es un **cambio incompatible sin periodo de gracia**, como lo fueron el `POST` de vehículos en SPEC 13 y el `type` de destinos en SPEC 21.
2. **Un campo obligatorio que depende de otro campo del mismo cuerpo.** `dpi` y `license` son `required_if:role,pilot`: es la primera validación condicional por rol del proyecto. Un `carrier` que los mande los ve **ignorados en silencio**, exactamente como el archivo de un gasto con `is_invoiced=false` en SPEC 19.

**Lo que esta spec no es:** un módulo de expediente del piloto. No hay número de DPI, ni número de licencia, ni tipo de licencia, ni vencimiento, ni verificación por parte del administrador, ni reemplazo posterior. Son dos fotos que se suben una vez, en el alta, y se leen.

---

## Alcance

**Dentro:**

- **Tabla nueva `pilot_documents`** con `id`, `user_id` (**único**, FK a `users`), `dpi_image`, `license_image` y `timestamps`. **Relación 1:1 con el usuario**: si la fila existe, existen los dos archivos. No lleva `status`, no lleva `deleted_at` y no lleva `registered_by` — la fila la crea el propio usuario que se registra.
- **Modelo `PilotDocument` con su factory**, sin enum, sin `status` y **sin `casts()`** — segundo modelo del proyecto sin ninguno, tras `AccessoryCharacteristic` (SPEC 18).
- **Relación nueva `User::pilotDocument()`** (`HasOne`). Es la única forma de llegar a los documentos: no hay service, no hay contrato, no hay provider y **no hay dominio nuevo**. Esta spec no crea ninguna carpeta bajo `app/Services/`.
- **`POST /api/auth/register` pasa a `multipart/form-data`** y gana dos campos de archivo, `dpi` y `license`:
  - `required_if:role,pilot`, `image`, `mimes:jpg,jpeg,png`, `max:3072` (3 MB, en kilobytes), con sus mensajes en español.
  - **Los dos o ninguno**: registrarse como `pilot` mandando solo uno es **422**, con el formato de validación de Laravel, no el sobre habitual.
  - Un `carrier` que los mande **los ve ignorados en silencio**: no se sube nada, no se crea fila y la respuesta es 201 como siempre. Precedente literal: el archivo de un gasto con `is_invoiced=false` en SPEC 19.
- **Los archivos se guardan tal cual llegan** con `storeUpload()` (SPEC 19) bajo el directorio **`pilot-documents`**, declarado como constante propia en `AuthService`. **`ImageProcessorServiceInterface` no interviene**: el recorte cuadrado a 800×800 dejaría un DPI ilegible, mismo argumento que llevó a `storeUpload()` con las facturas.
- **`AuthService` gana `FileStorageServiceInterface` por constructor**, junto al `AuthEmailsInterface` que ya inyecta. La regla de inyectar por parámetro sigue siendo solo del controller.
- **Orden de operaciones a prueba de huérfanos**: se suben los dos archivos **antes** de abrir la transacción; si el commit falla, se borran los dos objetos del bucket con `delete()` —que nunca lanza— y se propaga el error original. El correo de confirmación sigue saliendo **fuera** de la transacción, como hoy.
- **Las columnas guardan la key completa** (`pilot-documents/{uuid}.jpg`), nunca la URL, como `image` en vehículos e `invoice` en gastos.
- **Tres Resources ganan las mismas dos claves**, `dpiImage` y `licenseImage`, resueltas a **URL absoluta** con `app(FileStorageServiceInterface::class)->url(...)` y `null` cuando no hay fila:
  - **`UserResource`**, de 8 a 10 claves. Salen por tanto en `register`, en `login` y en `check-status`: el piloto ve siempre sus propios documentos.
  - **`PilotResource`** (SPEC 11), de 7 a 9 claves, para que la administración los vea en `GET /api/pilots`.
  - **`TripResource`** (SPEC 24), de 33 a 35 claves, con los nombres **`pilotDpiImage`** y **`pilotLicenseImage`** —prefijados como `pilotName` y `pilotId`— junto al `vehicleImage` que ya existe. Son `null` mientras el viaje no tenga piloto asignado.
- **La URL del objeto es pública**, igual que la de la imagen de un vehículo: quien tenga el enlace lo abre sin token. No hay URL firmada ni caducidad.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.
- Resumen de integración para el frontend en `references/pilot-documents-api.md`.

**Fuera de alcance (para specs futuras):**

- **Reemplazar o corregir un documento.** No hay `PATCH`, no hay endpoint nuevo y ninguna ruta existente acepta estos archivos. Una foto mal subida se arregla **fuera de la aplicación**, tocando la base y el bucket. Es la consecuencia aceptada de atar la subida al alta, y está decidido a propósito.
- **Ninguna ruta nueva.** Esta spec no añade ni un `Route::`, y ningún middleware cambia en ninguna ruta existente. En particular **no hay `GET /api/pilots/{pilot}/documents`**: las URLs viajan dentro de los tres Resources y no hay una cuarta forma de pedirlas.
- **El reverso del DPI y el de la licencia.** Solo el anverso de cada uno. Si hacen falta, es otra spec y son dos columnas más sobre esta misma tabla.
- **Cualquier dato que no sea la foto.** Nada de número de DPI, número de licencia, tipo de licencia (A, B, C), fecha de emisión ni **fecha de vencimiento**. La API no sabe si una licencia está caducada.
- **Verificación o aprobación.** Nadie revisa las fotos desde la aplicación: no hay `verified_at`, no hay estado «pendiente de revisión» y el administrador no aprueba nada. El flujo de confirmación de cuenta (SPEC 01) **no cambia**: el código de 6 dígitos sigue siendo lo único que activa la cuenta.
- **Bloquear nada por falta de documentos.** Un piloto sin fila hace login, se une a una empresa con `POST /api/carriers/join`, recibe salario y arranca y cierra viajes con normalidad. **No hay backfill** y los pilotos anteriores a la spec no se ven afectados en nada.
- **`CarrierPilotResource` (`GET /api/carriers/me/pilots`, SPEC 03) y `TripListResource` (SPEC 24) quedan intactos.** Siguen exactamente con las claves que tienen hoy, por el mismo motivo por el que SPEC 11 no tocó el primero: son recursos distintos a propósito.
- **PDF.** Solo `jpg`, `jpeg` y `png`. La factura del gasto acepta PDF; una foto de DPI, no.
- **Procesar la imagen.** Sin recorte, sin redimensionado, sin recompresión y sin marca de agua. Lo que sube el piloto es lo que queda en el bucket.
- **Documentos para los demás roles.** El `carrier`, el `administrator` y el `manager` no suben nada, ni ahora ni con otro nombre de campo.
- **Borrar los archivos.** No existe baja de usuario en el proyecto, así que nada borra estos objetos del bucket. La excepción del `DELETE` de SPEC 19 no se extiende aquí.
- **Filtrar o buscar por documentos.** No hay `?hasDocuments=` en `GET /api/pilots` ni en ningún otro listado.
- **Bitácora.** No se guarda quién subió qué ni cuándo cambió; los `timestamps` de la fila son todo el rastro que hay.

---

## Modelo de datos

Esta spec **no toca ninguna tabla existente**: `users` no gana ni una columna. Crea una tabla, un modelo y una factory, y **ningún enum**.

### 1. Tabla `pilot_documents`

```php
Schema::create('pilot_documents', function (Blueprint $table) {
    $table->id();
    /**
     * Único: un piloto tiene como mucho una fila de documentos. Sin cascade, como en
     * el resto del proyecto: no existe baja de usuario, y si algún día alguien borra
     * uno a mano, la restricción debe fallar ruidosamente en vez de llevarse la fila
     * por delante y dejar dos objetos huérfanos en el bucket.
     */
    $table->foreignId('user_id')->unique()->constrained('users');
    /** La key completa, «pilot-documents/{uuid}.jpg», nunca la URL. */
    $table->string('dpi_image');
    /** Ídem. Las dos son NOT NULL: si hay fila, hay los dos archivos. */
    $table->string('license_image');
    $table->timestamps();
});
```

- **Ninguna columna es nullable.** La fila entera es opcional —un piloto anterior a la spec no la tiene—, pero **no hay estados a medias**: no existe una fila con DPI y sin licencia.
- **No hay `registered_by`.** Es la primera tabla del proyecto que no lo lleva teniendo autor: el autor es el propio `user_id`, y en el momento del alta el usuario ni siquiera está autenticado.
- **No hay `status`, no hay `deleted_at` y no hay `verified_at`.** Los documentos existen o no existen.
- **Ningún índice extra.** La única consulta es por `user_id`, que ya lleva el índice único.

### 2. Modelo `PilotDocument`

```php
#[Fillable(['user_id', 'dpi_image', 'license_image'])]
class PilotDocument extends Model
{
    /** @use HasFactory<PilotDocumentFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- **Cuarto modelo del proyecto sin `casts()`**, tras `AccessoryCharacteristic`, `Client` y `ShippingLine`: aquí todo es texto.
- **Sin `normalizeName()` ni ninguna otra normalización**: no hay ni un campo de texto de negocio que normalizar.
- **Sin `SoftDeletes`.**

### 3. Relación nueva en `User`

```php
/**
 * The DPI and license photos this user uploaded when registering as a pilot.
 *
 * @return HasOne<PilotDocument, $this>
 */
public function pilotDocument(): HasOne
{
    return $this->hasOne(PilotDocument::class);
}
```

- Es **el único cambio en `User`**: ni `#[Fillable]`, ni `casts()`, ni `getJWTCustomClaims()` se tocan. **Los documentos no viajan en el token**, que ya lleva siete claims y no necesita dos URLs firmando cada petición.

### 4. Entrada — `RegisterRequest`

Dos reglas nuevas, con el resto intacto:

```php
'dpi' => ['required_if:role,'.UserRole::Pilot->value, 'image', 'mimes:jpg,jpeg,png', 'max:3072'],
'license' => ['required_if:role,'.UserRole::Pilot->value, 'image', 'mimes:jpg,jpeg,png', 'max:3072'],
```

- **`required_if` es lo único que ata los archivos al rol.** No hay validación cruzada de ningún otro tipo: no se comprueba que la foto sea legible, ni que sea un documento, ni que las dos sean distintas.
- Mensajes en español, en la línea de los que ya tiene el FormRequest: «La foto del DPI es obligatoria para los pilotos», «La licencia debe ser una imagen», «La foto del DPI no puede superar los 3 MB».
- **La regla `image` va además de `mimes`** a propósito: `mimes` mira la extensión real del contenido, `image` descarta de entrada un PDF renombrado.

### 5. Salida — las dos claves nuevas

La misma pareja en tres Resources, siempre URL absoluta o `null`:

```json
{
  "dpiImage": "https://bucket.s3.amazonaws.com/pilot-documents/9f3a....jpg",
  "licenseImage": "https://bucket.s3.amazonaws.com/pilot-documents/1c07....png"
}
```

| Resource | Claves | Nombres | `null` cuando |
|---|---|---|---|
| `UserResource` | 8 → 10 | `dpiImage`, `licenseImage` | El usuario no es piloto, o es un piloto anterior a la spec |
| `PilotResource` (SPEC 11) | 7 → 9 | `dpiImage`, `licenseImage` | El piloto es anterior a la spec |
| `TripResource` (SPEC 24) | 33 → 35 | **`pilotDpiImage`**, **`pilotLicenseImage`** | El viaje no tiene piloto asignado, o el piloto es anterior a la spec |

- Los tres resuelven la key con `app(FileStorageServiceInterface::class)->url(...)`, la misma localización de servicio consciente que ya usan `VehicleResource` y el `vehicleImage` de `TripResource`: un `JsonResource` se instancia con `new`.
- **En `TripResource` van prefijadas** porque el recurso mezcla piloto, vehículo, cliente y naviera; un `dpiImage` suelto ahí no diría de quién es.
- **`url(null)` devuelve `null`** por contrato, así que la ausencia de fila no necesita un `?:` en el Resource, solo el operador nullsafe sobre la relación.

### 6. Factory

`PilotDocumentFactory` genera las dos keys como cadenas (`pilot-documents/{uuid}.jpg`), **sin tocar el disco**: los tests que quieran el archivo de verdad usan el `fakeDefaultDisk()` global de `tests/Pest.php`.

---

## Plan de implementación

Nueve pasos. Cada uno deja la aplicación arrancable y la suite en verde.

1. **Migración `create_pilot_documents_table`.** Crear la tabla tal como está en el modelo de datos, con el índice único sobre `user_id` y las dos columnas NOT NULL. Verificación manual: `php artisan migrate` y `php artisan migrate:rollback` corren limpios.

2. **Modelo `PilotDocument` y `PilotDocumentFactory`.** `#[Fillable]` con los tres campos, relación `user()`, sin `casts()` y sin `SoftDeletes`. La factory genera las dos keys como cadenas, sin tocar el disco.

3. **Relación `User::pilotDocument()`.** Un `HasOne` y nada más. No se toca `#[Fillable]`, ni `casts()`, ni `getJWTCustomClaims()`, ni `currentCarrier()`.

4. **`RegisterRequest`: reglas, mensajes y schema.** Añadir `dpi` y `license` con `required_if:role,pilot`, `image`, `mimes:jpg,jpeg,png` y `max:3072`, sus mensajes en español, y cambiar el schema OA del body a `multipart/form-data` con las dos propiedades `type: 'string', format: 'binary'`. Después de este paso el endpoint ya **rechaza** un alta de piloto sin fotos, aunque todavía no las guarde.

5. **`AuthServiceInterface`: actualizar el array shape de `register()`.** El PHPDoc pasa a declarar `dpi` y `license` como `UploadedFile|null` opcionales, y a documentar en prosa las dos reglas que el tipo no expresa: que solo se persisten cuando el rol es `pilot`, y que un fallo de la transacción limpia los objetos ya subidos.

6. **`AuthService`: inyectar `FileStorageServiceInterface` y declarar `PILOT_DOCUMENT_DIRECTORY`.** Constructor con la segunda dependencia y la constante `'pilot-documents'`. Sin cambiar todavía `register()`: paso puramente estructural.

7. **`AuthService::register()`: subir, persistir y limpiar.** Método privado `storePilotDocuments(array $data): ?array` que devuelve `null` si el rol no es `pilot` —ahí es donde los archivos de un `carrier` se ignoran en silencio— y las dos keys si lo es. Se llama **antes** de `DB::transaction`; dentro de la transacción, tras `$user->save()`, se crea la fila de `pilot_documents`; si algo lanza, se borran las dos keys con `delete()` y se propaga el error original. El correo sigue saliendo fuera de la transacción.

8. **Las dos claves en los tres Resources.** `UserResource` y `PilotResource` ganan `dpiImage` y `licenseImage`; `TripResource` gana `pilotDpiImage` y `pilotLicenseImage`. En los tres, la key se resuelve con `app(FileStorageServiceInterface::class)->url(...)` sobre la relación con nullsafe. Actualizar los schemas OA de los tres.

9. **Eager loading para no meter N+1.** `PilotService` carga `user.pilotDocument` allí donde ya carga `user`, y `TripService` añade `pilot.pilotDocument` a las relaciones que ya carga para el detalle. **`TripListResource` no lo necesita** y el listado de viajes no carga la relación.

Al terminar los nueve pasos: `vendor/bin/pint --dirty --format agent`, después el agente `feature-tests` para los tests Pest, después el agente `endpoint-docs` para regenerar `storage/api-docs/api-docs.json`, y por último `references/pilot-documents-api.md`.

---

## Criterios de aceptación

**Registro de piloto**

- [ ] `POST /api/auth/register` con `role=pilot`, `dpi` y `license` responde **201**, crea el usuario, crea una fila en `pilot_documents` y deja dos objetos bajo `pilot-documents/` en el disco por defecto.
- [ ] La misma petición sin `dpi` responde **422** y **no crea el usuario**.
- [ ] La misma petición sin `license` responde **422** y **no crea el usuario**.
- [ ] Con `dpi` de más de 3 MB responde **422**; con un PDF renombrado a `.jpg`, también.
- [ ] El usuario creado nace con `email_verified_at` en `null` y recibe su código de confirmación, igual que antes de esta spec.
- [ ] Las dos keys guardadas empiezan por `pilot-documents/` y **no** son URLs.
- [ ] El archivo se guarda **byte por byte**: el tamaño y las dimensiones del objeto subido coinciden con los del archivo original, sin recorte a 800×800.

**Registro de transportista**

- [ ] `POST /api/auth/register` con `role=carrier` y **sin** archivos responde **201**, exactamente como antes de esta spec.
- [ ] `POST /api/auth/register` con `role=carrier` **mandando** `dpi` y `license` responde **201**, **no crea fila** en `pilot_documents` y **no sube ningún objeto** al disco.

**Limpieza ante fallo**

- [ ] Si la transacción del registro falla después de haber subido los archivos, el disco queda **sin ningún objeto** bajo `pilot-documents/` y la respuesta es un error, no un 201.

**Lectura**

- [ ] La respuesta 201 del registro de un piloto trae `dpiImage` y `licenseImage` como URLs **absolutas**.
- [ ] `POST /api/auth/login` y `GET /api/auth/check-status` de ese piloto traen las mismas dos claves con las mismas URLs.
- [ ] `UserResource` de un usuario sin fila —un `carrier`, o un piloto anterior a la spec— trae las dos claves en **`null`**, no ausentes.
- [ ] `GET /api/pilots` trae `dpiImage` y `licenseImage` en cada elemento, y el listado de N pilotos ejecuta un número de consultas **independiente de N**.
- [ ] `GET /api/trips/{trip}` de un viaje con piloto asignado trae `pilotDpiImage` y `pilotLicenseImage` con las URLs de ese piloto.
- [ ] `GET /api/trips/{trip}` de un viaje `pending` sin asignar trae esas dos claves en **`null`**.

**Lo que no cambia**

- [ ] `GET /api/carriers/me/pilots` devuelve **exactamente las mismas claves** que antes de esta spec.
- [ ] `GET /api/trips` (listado) devuelve **exactamente las 15 claves** de `TripListResource`, sin las dos nuevas.
- [ ] El token JWT emitido en el login lleva **los mismos siete claims** que antes: ninguna URL de documento entra en el payload.
- [ ] Un piloto sin fila en `pilot_documents` hace login, se une a una empresa con `POST /api/carriers/join`, recibe un salario y arranca y cierra un viaje sin recibir ningún error nuevo.
- [ ] `php artisan route:list --path=api` devuelve **el mismo número de rutas** que antes de esta spec.

**Cierre**

- [ ] `php artisan test --compact` pasa entera.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `php artisan l5-swagger:generate` corre sin errores y el body de `/api/auth/register` aparece en la UI como `multipart/form-data` con `dpi` y `license`.
- [ ] Existe `references/pilot-documents-api.md`.

---

## Decisiones

**Estructura**

- **Sí:** tabla propia `pilot_documents`, 1:1 con `users`. Mantiene `users` —la tabla más consultada del proyecto— sin dos columnas que solo importan a un rol, y deja sitio para el reverso del DPI o el vencimiento sin volver a tocar `users`.
- **No:** dos columnas en `users` (`dpi_image`, `license_image`), como hizo SPEC 13 con la ficha del vehículo. Habría sido menos código, pero carga a los cuatro roles con columnas que solo usa uno.
- **Sí:** una fila por piloto con las dos columnas. Son exactamente dos documentos fijos.
- **No:** una fila por documento con un enum `type` (`dpi`|`license`). El enum permite estados imposibles —dos filas `dpi`, ninguna `license`— y obliga a validar en el service lo que aquí garantiza el esquema.
- **Sí:** las dos columnas NOT NULL. Si hay fila, hay los dos archivos; no existe el alta a medias.

**Subida**

- **Sí:** `storeUpload()`, el archivo tal cual llega. Un DPI recortado a un cuadrado de 800×800 es ilegible, que es el mismo argumento por el que SPEC 19 sacó las facturas del procesador.
- **No:** `ImageProcessorServiceInterface::normalizeSquare()`. El ahorro de bytes no compensa perder el documento.
- **Sí:** solo `jpg`, `jpeg` y `png`, máximo 3 MB. Es el límite que ya rige en los cuatro FormRequests con imagen del proyecto, y el `upload_max_filesize` de los entornos ya está dimensionado para él.
- **No:** aceptar PDF, como sí hace la factura del gasto. Se pidió una **foto**; admitir PDF obliga a que el frontend sepa pintar dos cosas distintas donde debería haber una imagen.
- **Sí:** subir **antes** de abrir la transacción y borrar los dos objetos si el commit falla. `delete()` nunca lanza, así que la limpieza no puede enmascarar el error original.
- **No:** subir dentro de la transacción. El bucket no participa del rollback: dejaría huérfanos sin ninguna referencia que permita encontrarlos después.
- **Sí:** directorio único `pilot-documents`. Un `dpis/` y un `licenses/` separados no aportan nada: el nombre del objeto es un UUID y nadie navega el bucket por carpeta.

**Validación**

- **Sí:** `required_if:role,pilot`. Primera validación condicional por rol del proyecto, y la única forma de exigir el archivo a un rol sin partir el endpoint en dos.
- **No:** un `POST /api/auth/register-pilot` separado. Duplicaría el alta entera —correo, código, transacción— para no escribir un `required_if`.
- **Sí:** los archivos de un `carrier` se **ignoran en silencio**. Precedente literal en SPEC 19 con `is_invoiced=false`, y es lo tolerante con un frontend que manda un formulario único.
- **No:** 422 cuando un `carrier` manda archivos. Convierte en error algo que no rompe nada.
- **Sí:** cambio incompatible sin periodo de gracia. El registro de pilotos exige seis campos desde el primer despliegue, como el `POST` de vehículos en SPEC 13 y el `type` de destinos en SPEC 21.

**Lectura**

- **Sí:** las dos claves en `UserResource`, `PilotResource` y `TripResource`. Sin ellas la spec sube archivos que nadie puede consultar.
- **No:** un `GET /api/pilots/{pilot}/documents`. La URL del bucket ya es pública —igual que la de la imagen de un vehículo—, así que un endpoint nuevo solo añade superficie sin añadir acceso.
- **No:** URLs firmadas con caducidad. Rompería el patrón de los otros dos dominios con archivo y obligaría a que el frontend refrescara enlaces; si algún día la privacidad importa, cambia para los tres a la vez y es otra spec.
- **Sí:** prefijo `pilot` solo en `TripResource`. Ahí conviven cuatro entidades y un `dpiImage` suelto no diría de quién es.
- **No:** tocar `CarrierPilotResource` ni `TripListResource`. Mismo criterio con el que SPEC 11 dejó intacto el primero: son recursos distintos a propósito, y el listado de viajes es corto adrede.
- **No:** meter las URLs en los claims del JWT. El token ya lleva siete claims, se manda en cada petición y estos datos no autorizan nada.

**Alcance**

- **Sí:** subida atada al alta, sin reemplazo posterior. Es lo que se pidió y es lo que mantiene la spec en una tabla y cero rutas nuevas.
- **No:** endpoint de reemplazo. Corregir una foto mal subida se hace fuera de la aplicación; es el mismo precio que SPEC 23 aceptó al no poder restaurar una naviera borrada.
- **Sí:** sin backfill y sin bloquear nada. Los pilotos existentes siguen operando; convertir la falta de documentos en un bloqueo dejaría fuera a gente que hoy trabaja.
- **No:** verificación por parte del administrador. Añadiría estado, transiciones y una bitácora a una spec que hoy no tiene ninguno de los tres.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **El frontend sigue mandando el registro como JSON** y todos los pilotos empiezan a recibir 422. Es el riesgo real de la spec: el cambio es incompatible y la ruta es pública. | Se despliega coordinado con el frontend. El 422 trae los mensajes en español que nombran los dos campos, así que el fallo es legible desde el primer intento. |
| **`upload_max_filesize` o `post_max_size` por debajo de 4M en algún entorno.** PHP corta la petición antes de llegar a Laravel y el usuario ve un `required` confuso: parece que no mandó la foto. | Ya documentado en CLAUDE.md desde SPEC 05 y es el mismo requisito que los otros dominios con imagen. Se verifica en cada entorno antes del despliegue. |
| **Dos archivos por alta en una ruta pública, sin token ni rate limit.** Cualquiera puede llenar el bucket registrando cuentas basura. | Fuera del alcance de esta spec, pero se anota: el registro ya era abusable antes —creaba usuarios y mandaba correos—, y esta spec sube el coste por abuso de un correo a 6 MB. |
| **Documentos de identidad en un bucket con URL pública y permanente.** Quien obtenga el enlace ve el DPI de un piloto sin autenticarse. | Decisión consciente: es el mismo modelo que ya rige para la imagen del vehículo y la factura del gasto. Las keys son UUID, así que no son adivinables, pero **no son secretas**. Si la privacidad pasa a importar, cambia para los tres dominios a la vez y es otra spec. |
| **N+1 en `GET /api/pilots` y en el detalle del viaje** al resolver la relación por elemento. | El paso 9 del plan añade el eager loading y hay un criterio de aceptación que lo comprueba: el número de consultas no puede depender de N. |
| **Se sube el archivo y el commit falla**, dejando dos objetos huérfanos que nadie puede encontrar. | La limpieza con `delete()` en el `catch` y su criterio de aceptación propio. `delete()` nunca lanza, así que no puede tapar el error original. |

---

## Lo que **no** entra en esta spec

- **Reemplazar un documento después del alta.** Ni endpoint, ni ruta, ni campo en ningún `PATCH` existente.
- **El reverso del DPI y el de la licencia.** Solo el anverso de cada uno.
- **Cualquier dato que no sea la foto:** número de DPI, número de licencia, tipo de licencia, fecha de emisión y **fecha de vencimiento**. La API no sabe si una licencia está caducada.
- **Verificación o aprobación de los documentos.** Sin `verified_at`, sin estado de revisión y sin intervención del administrador.
- **Bloquear nada por falta de documentos.** Login, `POST /api/carriers/join`, salario y viajes siguen funcionando igual para un piloto sin fila.
- **Backfill de los pilotos existentes.**
- **Rutas nuevas.** Esta spec no añade ni un `Route::` y no cambia el middleware de ninguna ruta.
- **Documentos para el resto de roles.** El `carrier`, el `administrator` y el `manager` no suben nada.
- **PDF, recorte, redimensionado y marca de agua.**
- **Borrar los archivos del bucket.** Nada los borra: no existe baja de usuario.
- **URLs firmadas o con caducidad.**
- **`CarrierPilotResource` y `TripListResource`.** Intactos.
- **Filtrar o buscar por documentos**, y **bitácora** de quién subió qué.

Cada uno de ellos, si llega, va en su propia spec.
