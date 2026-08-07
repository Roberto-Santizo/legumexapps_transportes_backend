# SPEC 05 — Almacenamiento de archivos

> **Estado:** Implementado
> **Depende de:** SPEC 03, SPEC 04
> **Fecha:** 2026-08-05
> **Objetivo:** Introducir un contrato `FileStorageServiceInterface` sustituible por cualquier proveedor, implementarlo sobre un bucket público de AWS S3, y saldar con él la deuda de las imágenes de `Carrier` y `Vehicle`, que hoy se validan y se descartan. Toda imagen que entre se normaliza antes de subirse —recorte cuadrado centrado, mismo lado en píxeles y recompresión— y el body queda acotado a 3 MB.

---

## Alcance

**Dentro:**

- Contrato `App\Interfaces\Storage\FileStorageServiceInterface` con las operaciones de subir, borrar y resolver URL de un archivo. Sube **bytes**, no un `UploadedFile`: lo que se persiste ya no es lo que llegó por HTTP.
- Implementación `App\Services\Storage\S3FileStorageService`, que por dentro trabaja contra `Storage::disk(config('filesystems.default'))`.
- Contrato `App\Interfaces\Storage\ImageProcessorServiceInterface` e implementación `App\Services\Storage\ImageProcessorService`: **recorte cuadrado centrado + reescalado a un lado fijo + recompresión**. Todas las imágenes almacenadas acaban con las mismas dimensiones.
- `App\Providers\Storage\StorageProvider` con los dos `bind` (`FileStorageServiceInterface` → `S3FileStorageService`, `ImageProcessorServiceInterface` → `ImageProcessorService`), registrado en `bootstrap/providers.php`.
- **Límite de tamaño en la validación:** `max:3072` (3 MB en kilobytes) en el campo `image` de los cuatro FormRequests de `Carrier` y `Vehicle`, con su mensaje en español. El exceso es un 422 legible, no un fallo de infraestructura.
- Variables `AWS_*` y `FILESYSTEM_DISK` documentadas en `.env.example`.
- **Prefijo por dominio**: los archivos se suben a `carriers/{uuid}.{ext}` y `vehicles/{uuid}.{ext}`. El prefijo lo decide cada service de dominio, no el de almacenamiento.
- La columna `image` de `carriers` y de `vehicles` pasa a guardar **la key completa** (`carriers/9f3a....png`). No cambia el tipo ni se añade ninguna columna.
- `CarrierResource` y `VehicleResource` siguen exponiendo el campo `image`, pero resuelto a **URL pública permanente** desde la key.
- Retrofit de `CarrierService` y `VehicleService`: desaparece `buildImageName()` en los dos, y en su lugar se inyectan los dos contratos (procesado + almacenamiento).
- **Subida antes de persistir.** Si S3 falla, no se crea ni se actualiza la fila y la respuesta es 400 con `BadRequestError`.
- **`update` con imagen nueva borra el objeto anterior** de S3.
- **`DELETE` no borra el archivo** en ninguno de los dos dominios: en `carriers` es no-op y en `vehicles` solo pasa a `inactive`, así que la fila sigue viva y el listado la sigue mostrando.
- **Actualización de los tests existentes de SPEC 03 y 04.** Hoy afirman que no se escribe ningún archivo; ahora deben afirmar lo contrario con `Storage::fake()`. Es la única spec del proyecto que modifica tests ya aprobados.
- Reescritura de las descripciones OpenAPI de `image` en los cuatro FormRequests y los dos Resources, que hoy advierten explícitamente de que el archivo se descarta. Pasan a documentar el límite de 3 MB y el recorte cuadrado.

**Requisito previo (fuera de esta spec):**

- `league/flysystem-aws-s3-v3` instalado y las variables `AWS_*` con valores reales en el `.env`. Lo hace el usuario antes de empezar la implementación.
- `intervention/image` (^3) instalado. Usa el driver **GD**, que ya está compilado en el PHP del proyecto; no hace falta Imagick.
- `upload_max_filesize` y `post_max_size` del PHP de cada entorno **≥ 4M**. Si PHP corta antes, el request llega vacío y la validación devuelve un `required` confuso en vez del `max` correcto. Es configuración de infraestructura, no de código.
- El bucket creado, público y con su política de acceso resuelta, y con las **ACLs habilitadas** (*Object Ownership* en «Bucket owner preferred», no en «Bucket owner enforced») y *Block public ACLs* desactivado, porque cada objeto se sube con ACL `public-read`. Es tarea de operaciones.

**Fuera de alcance (para specs futuras):**

- **Endpoints nuevos.** La subida sigue embebida en los `store`/`update` que ya existen; no hay `POST /api/files` ni cambia ninguna ruta.
- **Una segunda implementación del contrato** (local, GCS, MinIO). La interfaz queda lista para recibirla; escribirla hoy sería código muerto.
- **Migrar las filas ya existentes.** Sus `uuid.ext` no resuelven a nada y se borran a mano fuera del código.
- **URLs firmadas y buckets privados.** Se eligió bucket público y URL permanente.
- **Miniaturas y variantes por tamaño.** Se guarda **una sola** versión normalizada por registro, no un juego de tamaños.
- **Conversión de formato.** Un `.png` sigue saliendo `.png` y un `.jpg`, `.jpg`; no se pasa nada a WebP ni se unifica el formato. Lo que se unifica son las dimensiones.
- **Conservar el original sin recortar.** La imagen que llegó no se guarda en ningún sitio: se procesa en memoria y se sube solo el resultado.
- **Recorte elegido por el usuario** (encuadre manual, foco, coordenadas en el body). El recorte es siempre centrado.
- **Escaneo antivirus** o inspección del contenido más allá del `mimes` que ya valida el FormRequest.
- **Subida asíncrona por colas.** La petición HTTP espera a que S3 responda.
- **Varios archivos por registro** (galerías, documentos del vehículo). Sigue siendo una imagen por fila.
- **Subida directa del front a S3** con URL prefirmada.
- **CDN o CloudFront** delante del bucket.
- **Más cambios en las reglas de validación de los que pide esta spec.** `image` sigue siendo obligatorio en el alta, opcional en la edición y limitado a `jpg`, `jpeg` y `png`; lo único que se añade es `max:3072`. No entran `dimensions:`, ni relación de aspecto mínima, ni un `max` distinto por dominio.
- **Provisionar la infraestructura**: crear el bucket, la política IAM, el CORS y el acceso público.
- **Limpieza de archivos huérfanos** acumulados por fallos parciales.

---

## Modelo de datos

Esta spec **no crea tablas ni columnas**. Cambia el *significado* de dos columnas que ya existen e introduce cinco clases nuevas.

### 1. El contrato de almacenamiento

```php
// app/Interfaces/Storage/FileStorageServiceInterface.php
interface FileStorageServiceInterface
{
    /**
     * Store raw file contents under the given directory and return its key.
     *
     * @throws BadRequestError when the underlying storage rejects the contents.
     */
    public function store(string $contents, string $directory, string $extension): string;

    /**
     * Delete a stored file by key. Returns false when the key is null or the file is missing.
     */
    public function delete(?string $key): bool;

    /**
     * Resolve the publicly reachable URL of a stored file. Null key yields null.
     */
    public function url(?string $key): ?string;
}
```

Tres métodos y ninguno más. `exists()` y `download()` se dejan fuera hasta que algo los necesite.

`store()` recibe **bytes**, no un `UploadedFile`: cuando llega al almacenamiento la imagen ya pasó por el procesador y no existe como archivo subido. Un contrato de almacenamiento tampoco tiene por qué saber que sus datos vienen de un formulario HTTP.

**El contrato es la parte que hace el trabajo de Liskov, así que sus reglas son tan vinculantes como las firmas.** Cualquier implementación futura debe cumplirlas o dejará de ser sustituible:

- `store()` recibe un directorio **sin** barra inicial ni final y una extensión **sin** punto, y devuelve la key completa. Nunca devuelve `null`; si no puede guardar, lanza `BadRequestError`.
- `delete()` **no lanza** cuando la key es `null` o el archivo no existe. Devuelve `false` y sigue. Un borrado fallido nunca puede tumbar una petición.
- `url()` acepta `null` y devuelve `null`. Es el único método que puede depender del proveedor en su formato de salida.

### 2. La implementación del almacenamiento

```php
// app/Services/Storage/S3FileStorageService.php
final class S3FileStorageService implements FileStorageServiceInterface
{
    #[Override]
    public function store(string $contents, string $directory, string $extension): string
    {
        $key = $directory.'/'.Str::uuid().'.'.$extension;
        // Storage::put($key, $contents, ['ACL' => 'public-read']) → false si falla;
        // los discos llevan 'throw' => false
        // Cualquier Throwable (región inválida, DNS, credenciales) se captura y
        // se traduce a BadRequestError('No se pudo almacenar la imagen')
    }

    #[Override]
    public function delete(?string $key): bool;

    #[Override]
    public function url(?string $key): ?string;   // Storage::url($key)
}
```

Trabaja siempre contra el disco por defecto (`Storage::disk()`), nunca contra `'s3'` escrito a mano. El disco lo decide `FILESYSTEM_DISK`, y por eso los tests pueden interceptarlo con `Storage::fake()` sin tocar la clase.

Los discos de `config/filesystems.php` llevan `'throw' => false`, así que un fallo llega como un `false` de retorno, no como excepción. La clase comprueba las dos vías.

Cada subida marca el objeto como de **acceso público** con la ACL `public-read`, en una constante de la clase. No es opcional: sin ella Flysystem cae en su `determineAcl()`, cuyo valor por defecto es `private`, y la URL permanente que promete `url()` devolvería un 403. La ACL pasada en las opciones del `put()` tiene prioridad sobre el `visibility` del disco (`$options['params']['ACL'] ?? $this->determineAcl($config)` en `AwsS3V3Adapter::upload()`), así que es el único sitio donde se decide.

### 3. El contrato de procesado

```php
// app/Interfaces/Storage/ImageProcessorServiceInterface.php
interface ImageProcessorServiceInterface
{
    /**
     * Crop the image to a centered square, resize it to the canonical side and re-encode it.
     *
     * @return array{contents: string, extension: string} Processed bytes and the extension they were encoded as.
     *
     * @throws BadRequestError when the file cannot be decoded as an image.
     */
    public function normalizeSquare(UploadedFile $file): array;
}
```

Un solo método. Devuelve un array shape, no un `UploadedFile` ni un archivo temporal: el resultado vive en memoria y va directo al `store()` del almacenamiento.

Reglas de sustitución, igual de vinculantes que las del otro contrato:

- La salida es **siempre** cuadrada y del lado canónico, sea cual sea la entrada. Ninguna implementación puede devolver el original "porque ya era pequeño".
- `extension` es una de `jpg`, `jpeg` o `png` y **coincide con el formato real** de `contents`. Es lo que decide la extensión de la key, así que mentir aquí produce archivos que el navegador no pinta.
- Nunca devuelve `null` ni cadena vacía; si no puede decodificar, lanza `BadRequestError`.

### 4. La implementación del procesado

```php
// app/Services/Storage/ImageProcessorService.php
final class ImageProcessorService implements ImageProcessorServiceInterface
{
    private const SIDE = 800;        // píxeles, lado del cuadrado final
    private const JPEG_QUALITY = 80; // 0-100

    #[Override]
    public function normalizeSquare(UploadedFile $file): array
    {
        // Intervention\Image con driver GD:
        //   ->read($file->getRealPath())
        //   ->cover(self::SIDE, self::SIDE)   // recorte centrado + reescalado, sin deformar
        //   ->toJpeg(self::JPEG_QUALITY) | ->toPng()  según la extensión de entrada
        // Cualquier Throwable → BadRequestError('No se pudo procesar la imagen')
    }
}
```

Las tres decisiones que fijan el resultado:

| | Valor | Por qué |
|---|---|---|
| Lado | **800 × 800 px** | Cubre de sobra el uso real —logo de empresa y foto de camión en un listado o una ficha— y en pantallas retina sigue sin verse blando. Un archivo de 800² recomprimido pesa decenas de KB frente a los megas del original. |
| Recorte | `cover()`, centrado | Reescala al lado mayor y recorta el sobrante por los bordes. Nunca deforma ni añade bandas: la imagen llena el cuadrado. |
| Formato | El de entrada | JPEG con calidad 80; PNG recomprimido. Pasar un PNG a JPEG le pondría fondo negro a los logos con transparencia. |

`SIDE` y `JPEG_QUALITY` son **constantes de la clase**, no configuración. No hay ningún caso de uso que quiera dos tamaños distintos, y una variable de entorno más es una variable más que puede estar mal puesta en producción.

Una imagen que ya sea cuadrada y de 800 px pasa igualmente por el pipeline: se recomprime. Es el precio de tener una sola ruta de código en vez de dos.

### 5. Provider y configuración

```php
// app/Providers/Storage/StorageProvider.php
$this->app->bind(FileStorageServiceInterface::class, S3FileStorageService::class);
$this->app->bind(ImageProcessorServiceInterface::class, ImageProcessorService::class);
```

Registrado en `bootstrap/providers.php`, como los tres providers que ya existen.

En `.env.example`:

```dotenv
FILESYSTEM_DISK=s3

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_URL=
```

`AWS_URL` es opcional: si se deja vacío, Flysystem construye la URL desde bucket y región.

### 6. Las columnas `image`

| | Antes | Ahora |
|---|---|---|
| `carriers.image` | `9f3a....png` | `carriers/9f3a....png` |
| `vehicles.image` | `9f3a....png` | `vehicles/9f3a....png` |

Mismo tipo, misma nulabilidad, **sin migración**. La key es autosuficiente: basta para borrar el objeto y para construir su URL.

El prefijo vive en cada service de dominio, no en el de almacenamiento:

```php
private const IMAGE_DIRECTORY = 'carriers';   // 'vehicles' en VehicleService
```

### 7. Los Resources

```php
'image' => $this->fileStorage()->url($this->image),
```

`CarrierResource` y `VehicleResource` resuelven el contrato desde el contenedor con `app(FileStorageServiceInterface::class)`, porque un `JsonResource` se instancia con `new` y no admite inyección por constructor. Con `image` en `null` el campo sale `null`, no una URL rota.

### 8. Consumo desde los services de dominio

`CarrierService` y `VehicleService` reciben los dos contratos **por constructor**, no por parámetro de método:

```php
public function __construct(
    private readonly ImageProcessorServiceInterface $imageProcessor,
    private readonly FileStorageServiceInterface $fileStorage,
) {}
```

La regla de inyectar por parámetro es del *controller*; entre services el contenedor resuelve el constructor sin ayuda.

El par procesar → subir es siempre el mismo y vive en un helper privado de cada service:

```php
private function storeImage(UploadedFile $file): string
{
    $image = $this->imageProcessor->normalizeSquare($file);

    return $this->fileStorage->store($image['contents'], self::IMAGE_DIRECTORY, $image['extension']);
}
```

Y el flujo en cada operación:

| Operación | Comportamiento |
|---|---|
| `create` | Procesar y `store()` **antes** del `create()`. Si cualquiera de los dos falla, no hay fila. |
| `update` con `image` | Procesar, `store()` el nuevo, persistir, y `delete()` el anterior **después** de que la fila quede guardada. |
| `update` sin `image` | Ni se procesa ni se toca la key. |
| `destroy` | El archivo se queda. La fila sigue existiendo en los dos dominios. |

El borrado del anterior va **después** de persistir a propósito: si se borrase primero y la escritura fallara, la fila quedaría apuntando a un objeto que ya no existe.

El procesado va **antes** que la subida, no después: si la imagen no se puede decodificar, el 400 se devuelve sin haber escrito nada en el bucket.

### 9. La validación del tamaño

Los cuatro FormRequests añaden una regla y un mensaje:

```php
// StoreCarrierRequest / StoreVehicleRequest
'image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:3072'],

// UpdateCarrierRequest / UpdateVehicleRequest
'image' => ['sometimes', 'required', 'file', 'mimes:jpg,jpeg,png', 'max:3072'],
```

```php
'image.max' => 'La imagen no puede pesar más de 3 MB',
```

`max` en una regla de archivo se mide en **kilobytes**, así que 3 MB son `3072`. Va después de `mimes` para que un `.pdf` de 10 MB falle por tipo, que es el motivo más útil de los dos.

Es un límite de validación, no de infraestructura: para que dé 422 en vez de reventar antes, el `upload_max_filesize` y el `post_max_size` de PHP tienen que ser **mayores** que 3 MB en todos los entornos (4M es un valor razonable). Eso queda fuera del código y está listado como requisito previo.

---

## Plan de implementación

> **Requisito previo (fuera del plan):** `league/flysystem-aws-s3-v3` e `intervention/image` instalados y las variables `AWS_*` con valores reales en el `.env`. Lo hace el usuario antes de empezar; ningún paso lo asume hasta el 2.

Nueve pasos. Cada uno deja la suite en verde y es commiteable por sí solo.

1. **Contratos.** `app/Interfaces/Storage/FileStorageServiceInterface.php` e `ImageProcessorServiceInterface.php`, cada uno con el PHPDoc que fija sus reglas de sustitución (qué lanza, qué acepta `null`, qué devuelve, qué garantiza de la salida). Sin implementación todavía. *Verificación:* la suite sigue verde; las interfaces no las usa nadie aún.

2. **Implementación del almacenamiento y red de seguridad de los tests.** Tres cosas que van juntas porque sin las dos últimas la primera queda sin probar:
   - `app/Services/Storage/S3FileStorageService.php` con `#[Override]` en los tres métodos, `store()` construyendo `{directory}/{uuid}.{ext}` a partir de la extensión recibida y traduciendo tanto el `false` de retorno como cualquier `Throwable` a `BadRequestError`.
   - `Storage::fake()` global en `tests/Pest.php`, junto al `Mail::fake()` que ya está. Es lo que garantiza que **ningún test toque la red**, ni ahora ni en las specs futuras.
   - `tests/Unit/FileStorageServiceTest.php`: la key lleva el prefijo, el archivo aparece en el disco falso con los bytes que se le pasaron, `delete()` de una key inexistente devuelve `false` sin lanzar, `url(null)` devuelve `null`.

   *Verificación:* `php artisan test --compact --filter=FileStorage` pasa.

3. **Implementación del procesado.** `app/Services/Storage/ImageProcessorService.php` con las constantes `SIDE`/`JPEG_QUALITY`, el `cover()` centrado y la codificación según el formato de entrada, más `tests/Unit/ImageProcessorServiceTest.php`. Los tests parten de imágenes reales generadas en el momento con `UploadedFile::fake()->image('x.jpg', 1600, 900)`, que produce un archivo decodificable de verdad, y leen las dimensiones del resultado con `getimagesizefromstring()`. *Verificación:* `php artisan test --compact --filter=ImageProcessor` pasa.

4. **Provider y configuración.** `app/Providers/Storage/StorageProvider.php` con los dos `bind`, registrado en `bootstrap/providers.php`. Añadir a `.env.example` el bloque `FILESYSTEM_DISK=s3` y las cinco `AWS_*`, para que quede documentado qué necesita el proyecto. *Verificación:* `php artisan tinker --execute 'var_dump(app(App\Interfaces\Storage\FileStorageServiceInterface::class)::class, app(App\Interfaces\Storage\ImageProcessorServiceInterface::class)::class);'` imprime las dos implementaciones.

5. **Límite de 3 MB.** `max:3072` y su mensaje en los cuatro FormRequests, con un caso de test por dominio usando `UploadedFile::fake()->create('grande.jpg', 4096, 'image/jpeg')`: 422, el error cuelga de `image`, y no se escribe nada en el disco. Va antes del retrofit porque no depende de él y deja cerrada la puerta de entrada. *Verificación:* `php artisan test --compact --filter='Carrier|Vehicle'` pasa.

6. **Retrofit de `Carrier`.** El service, el resource y sus tests en el mismo paso: separarlos dejaría un commit con el service guardando keys y el resource devolviéndolas crudas.
   - `CarrierService`: constructor con los dos contratos, constante `IMAGE_DIRECTORY = 'carriers'`, fuera `buildImageName()`, helper privado `storeImage()` antes del `create()`, y en `update` el borrado del anterior después de persistir.
   - `CarrierResource`: `image` resuelto a URL.
   - `tests/Feature/CarrierTest.php`: invertir las aserciones que hoy afirman que no se escribe nada, y añadir las de key con prefijo, URL en la respuesta, dimensiones cuadradas del archivo subido y borrado del archivo anterior al reemplazar la imagen.

   *Verificación:* `php artisan test --compact --filter=Carrier` pasa.

7. **Retrofit de `Vehicle`.** Idéntico al paso 6 con `IMAGE_DIRECTORY = 'vehicles'`, más la aserción de que el `DELETE` **no** borra el archivo. *Verificación:* `php artisan test --compact --filter=Vehicle` pasa.

8. **Formato.** `vendor/bin/pint --dirty --format agent`.

9. **Documentación OpenAPI.** Reescribir a mano las seis descripciones de `image` que hoy advierten de que el archivo se descarta — cuatro FormRequests y dos Resources — para que digan lo que ahora pasa de verdad: se almacena, se recorta a un cuadrado de 800 px y no puede pasar de 3 MB. Regenerar con `php artisan l5-swagger:generate`. *Verificación:* `storage/api-docs/api-docs.json` no contiene ya la cadena "se valida y se descarta" y sí menciona el límite de 3 MB.

---

## Criterios de aceptación

**Contrato y contenedor**

- [x] `app(FileStorageServiceInterface::class)` resuelve a `S3FileStorageService`.
- [x] `app(ImageProcessorServiceInterface::class)` resuelve a `ImageProcessorService`.
- [x] Los tres métodos de `S3FileStorageService` y el de `ImageProcessorService` llevan `#[Override]`.
- [x] Ningún archivo fuera de `app/Services/Storage/` menciona `Storage::` ni el nombre del disco `'s3'`. Todo el acceso al almacenamiento pasa por el contrato.
- [x] Ningún archivo fuera de `app/Services/Storage/ImageProcessorService.php` menciona `Intervention\`. Los services de dominio no saben con qué se recorta.
- [x] No hay migraciones nuevas: `php artisan migrate:fresh` produce el mismo esquema que antes de la spec.

**`S3FileStorageService`**

- [x] `store($contents, 'carriers', 'png')` devuelve una key con el formato `carriers/{uuid}.png` y el archivo aparece en el disco con exactamente esos bytes.
- [x] `store()` llama al disco con la opción `ACL` en `public-read`. Ninguna subida se hace sin ella.
- [x] La extensión de la key es la que se le pasó: `'jpg'` produce una key `.jpg` y `'png'` una `.png`.
- [x] La key **no** contiene el nombre original del archivo: subir `mi-foto-personal.png` no deja rastro de esa cadena.
- [x] Dos subidas del mismo contenido producen dos keys distintas.
- [x] `delete()` de una key existente la borra y devuelve `true`.
- [x] `delete(null)` devuelve `false` sin lanzar.
- [x] `delete('carriers/no-existe.png')` devuelve `false` sin lanzar.
- [x] `url(null)` devuelve `null`.
- [x] `url('carriers/x.png')` devuelve una URL absoluta que termina en `carriers/x.png`.

**`ImageProcessorService`**

- [x] Una imagen apaisada de 1600×900 sale de 800×800.
- [x] Una imagen vertical de 600×1200 sale de 800×800.
- [x] Una imagen más pequeña que el lado canónico, 200×200, sale igualmente de 800×800.
- [x] Una imagen que ya era 800×800 sale de 800×800 y sigue siendo decodificable.
- [x] El recorte no deforma: en una imagen apaisada con un patrón conocido, lo que se pierde son los bordes laterales, no la escala.
- [x] Un `.jpg` de entrada devuelve `extension` `jpg` y bytes JPEG; un `.png` devuelve `png` y bytes PNG.
- [x] El `extension` devuelto coincide con el formato real detectado en `contents` por `getimagesizefromstring()`.
- [x] Una foto grande de 4000×3000 sale pesando menos que el original.
- [x] Un archivo que no es una imagen decodificable lanza `BadRequestError`, no un `Throwable` crudo.
- [x] El archivo original **no** se modifica en disco: el procesado ocurre en memoria.

**Sustituibilidad (el principio de Liskov)**

- [x] Existe en `tests/` un doble que implementa `FileStorageServiceInterface` sin usar S3.
- [x] Existe en `tests/` un doble que implementa `ImageProcessorServiceInterface` sin decodificar nada, y la suite de dominio pasa entera con él bindeado.
- [x] Con ese doble bindeado en el contenedor, **toda** la suite de `Carrier` y `Vehicle` pasa sin modificar ni un test de dominio.
- [x] Con un doble cuyo `store()` lanza `BadRequestError`, `POST /api/carriers` devuelve 400 y **no** se crea ninguna fila.
- [x] Con ese mismo doble, `POST /api/vehicles` devuelve 400 y no se crea ninguna fila.
- [x] Con un doble cuyo `normalizeSquare()` lanza `BadRequestError`, `POST /api/carriers` devuelve 400, no se crea fila y **no se escribe nada en el disco**: el procesado va antes que la subida.

**`Carrier`**

- [x] `POST /api/carriers` devuelve 201 y la columna `image` guarda una key que empieza por `carriers/`.
- [x] El archivo existe en el disco después del alta.
- [x] El archivo del disco mide **800×800**, aunque se haya subido una imagen apaisada.
- [x] El campo `image` de la respuesta es una URL absoluta, no la key cruda.
- [x] `PATCH` con imagen nueva persiste una key distinta, el archivo nuevo existe y **el anterior ya no está** en el disco.
- [x] `PATCH` sin `image` deja la key intacta y el archivo anterior sigue existiendo.
- [x] `DELETE /api/carriers/{id}` deja el archivo en el disco.
- [x] Enviar un `.pdf` sigue devolviendo 422 y no sube nada al disco.
- [x] Un archivo de **4 MB** devuelve 422 con el error colgando de `image` y no sube nada al disco, tanto en `POST` como en `PATCH`.
- [x] Un archivo de **3 MB justos** se acepta: `max:3072` es inclusivo.

**`Vehicle`**

- [x] `POST /api/vehicles` devuelve 201 y la columna `image` guarda una key que empieza por `vehicles/`.
- [x] El archivo del disco mide **800×800**.
- [x] Un archivo de **4 MB** devuelve 422 con el error colgando de `image` y no sube nada al disco, tanto en `POST` como en `PATCH`.
- [x] El campo `image` de la respuesta es una URL absoluta.
- [x] Un vehículo con `image` en `null` devuelve `image: null` en la respuesta, no una URL rota.
- [x] `PATCH` con imagen nueva borra el archivo anterior del disco.
- [x] `DELETE /api/vehicles/{id}` pasa el vehículo a `inactive` y **el archivo sigue en el disco**.
- [x] Un `carrier` que sube una imagen a un vehículo ajeno recibe 403 y **no** se sube ningún archivo.

**No regresión**

- [x] `tests/Pest.php` aplica `Storage::fake()` globalmente.
- [x] La suite completa corre sin acceso a la red: desconectado de internet, `php artisan test --compact` pasa igual.
- [x] Los tests de SPEC 01 y SPEC 02 pasan sin modificarse.
- [x] Los tests de SPEC 03 y 04 que se modificaron son **solo** los relativos a `image`; el resto queda intacto.

**Calidad**

- [x] `php artisan test --compact` pasa en verde.
- [x] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [x] `php artisan l5-swagger:generate` regenera sin errores.
- [x] `storage/api-docs/api-docs.json` no contiene la cadena "se valida y se descarta" y sí documenta el límite de 3 MB y el recorte cuadrado.

El bloque de sustituibilidad es el único que prueba de verdad el principio pedido. `Storage::fake()` sustituye el **disco**, que es la abstracción de Laravel; el doble en `tests/` sustituye la **implementación del contrato**, que es la propia. Que la suite de dominio pase entera con el doble, sin tocar un solo test, es la demostración operativa de Liskov. Ese doble vive en `tests/`, así que no contradice el "una segunda implementación queda fuera de alcance": eso hablaba de una implementación de producción.

---

## Decisiones

**Arquitectura del contrato**

- **Sí:** interfaz propia `FileStorageServiceInterface` por encima de los discos de Laravel. Es lo que hace la sustitución explícita y verificable, y encaja en la cadena de capas Interface/Service/Provider que ya usa todo el proyecto.
- **No:** usar `Storage::disk()` directo en los services de dominio. Ya sería sustituible por configuración, pero la sustitución quedaría implícita, sin contrato que un doble pueda cumplir ni test que lo demuestre.
- **No:** hablar con `aws/aws-sdk-php` sin Flysystem. Más control del que este dominio necesita, mucho más código, y perderías `Storage::fake()` en los tests.
- **Sí:** solo tres métodos (`store`, `delete`, `url`). `exists()` y `download()` no los pide nadie hoy; un contrato pequeño es más fácil de cumplir para la siguiente implementación.
- **Sí:** las reglas de sustitución van escritas en el PHPDoc de la interfaz. Qué lanza, qué acepta `null` y qué devuelve es tan parte del contrato como las firmas, y es lo único que impide que la próxima implementación lo rompa sin darse cuenta.
- **Sí:** `S3FileStorageService` trabaja contra el disco por defecto, no contra `'s3'` escrito a mano. Un nombre de disco fijo en el código haría imposible el `Storage::fake()` de los tests.
- **Sí:** el contrato se inyecta por **constructor** en los services de dominio. La regla de inyectar por parámetro es del controller; entre services el contenedor resuelve solo.
- **Sí:** `store()` recibe **bytes** (`string $contents`) en vez de un `UploadedFile`. Después del procesado ya no hay archivo subido que pasar, y el almacenamiento no tiene por qué conocer una clase de HTTP.
- **No:** dejar `store(UploadedFile)` y añadir un `storeContents()` al lado. Serían dos puertas al mismo sitio y una de ellas quedaría muerta: no hay ni un solo caso en el proyecto que suba algo sin procesarlo antes.

**Procesado de imagen**

- **Sí:** un contrato aparte, `ImageProcessorServiceInterface`. Recortar y almacenar son dos responsabilidades distintas y cambian por motivos distintos: el día que se cambie de proveedor de almacenamiento, el recorte no se toca.
- **No:** meter el recorte dentro de `S3FileStorageService::store()`. Ataría cada implementación futura del almacenamiento a reimplementar también el procesado, que es justo lo que rompe la sustituibilidad que persigue la spec.
- **No:** un helper estático o un trait compartido entre los dos services de dominio. No se puede sustituir por un doble en los tests, y los tests de `Carrier` y `Vehicle` acabarían decodificando imágenes de verdad en cada caso.
- **Sí:** `intervention/image` con driver **GD**. GD ya está compilado en el PHP del proyecto; Imagick obligaría a instalar una extensión en cada entorno para no ganar nada en este caso de uso.
- **No:** llamar a las funciones `imagecreatefromjpeg`/`imagecopyresampled` de GD a mano. Es el mismo resultado con cuarenta líneas más de aritmética de coordenadas y sin gestión de errores.
- **Sí:** `cover()` centrado, un solo lado (**800 px**) para todo. Es lo que garantiza que el front pueda maquetar con una caja fija sin verse nunca una imagen deformada ni con bandas.
- **No:** `contain()` con relleno. Metería franjas de color en logos y fotos, y habría que decidir de qué color.
- **No:** un lado distinto para `Carrier` (logo) y para `Vehicle` (foto). Dos números que mantener y dos comportamientos que explicar, para un ahorro de bytes que a este volumen no se nota.
- **Sí:** `SIDE` y `JPEG_QUALITY` como constantes de clase. Nadie pide hoy configurarlas por entorno, y una variable de entorno más es una variable más que se puede quedar sin poner en producción.
- **Sí:** conservar el formato de entrada. Convertir PNG a JPEG le pondría fondo negro a cualquier logo con transparencia, que es exactamente el caso de uso de `Carrier`.
- **No:** convertir todo a WebP. Pesaría menos, pero es un cambio del contrato de salida —extensión, `mimes` de entrada, compatibilidad del consumidor— que merece su propia spec.
- **Sí:** el original se descarta. Guardar las dos versiones dobla el coste del bucket para un original que ningún endpoint expone.
- **Sí:** procesar **antes** de subir. Un archivo ilegible se corta con un 400 sin haber tocado el bucket.

**Límite de tamaño**

- **Sí:** `max:3072` en los cuatro FormRequests. Cierra el agujero que la versión anterior de esta spec dejaba anotado como riesgo conocido, y lo hace en la capa que devuelve un 422 legible.
- **Sí:** 3 MB y el mismo número en los dos dominios. Una foto de móvil típica entra de sobra; un número por dominio serían dos reglas que explicar al front.
- **No:** confiar solo en el `upload_max_filesize` de PHP. Cuando salta, el request llega sin el campo y el usuario recibe un "la imagen es obligatoria" que no dice nada del tamaño real del problema.
- **Sí:** aun así, exigir que el `upload_max_filesize` de PHP sea **mayor** que el límite de validación. Si fuera igual o menor, PHP cortaría primero y la regla `max` no llegaría a evaluarse nunca.
- **No:** validar tamaño después de procesar. La imagen recortada siempre pesa poco: el límite existe para no aceptar el archivo de entrada, no el resultado.

**Almacenamiento y nombres**

- **Sí:** la columna `image` guarda la key completa con prefijo (`carriers/{uuid}.png`). Es autosuficiente: basta para borrar el objeto y para construir su URL, sin que nadie tenga que recordar en qué carpeta vivía.
- **No:** guardar la URL completa en base. Te ataría al proveedor dentro de los datos, que es exactamente lo contrario de lo que persigue esta spec: cambiar de proveedor obligaría a una migración de datos.
- **No:** guardar solo el nombre y reconstruir el prefijo en cada operación. Es la misma información repartida en dos sitios, y basta que un service olvide el prefijo para que el borrado apunte a la nada.
- **Sí:** el prefijo lo decide el service de dominio, no el de almacenamiento. `S3FileStorageService` no tiene por qué saber que existen los carriers.
- **Sí:** se mantiene el `{uuid}.{ext}` que ya usaba `buildImageName()`. Evita colisiones y no filtra el nombre original del archivo del usuario, que puede contener datos personales.
- **Sí:** bucket público y URL permanente. El front pinta un `src` directo y el navegador cachea.
- **Sí:** ACL `public-read` **explícita en cada subida**, pasada en las opciones del `put()`. Sin ella el objeto sube como `private` —es el valor por defecto de Flysystem— y la URL permanente devuelve 403.
- **No:** confiar solo en la política del bucket. La policy es tarea de operaciones y vive fuera del repositorio; si algún día se restringe, el código dejaría de cumplir lo que promete `url()` sin que nada en la aplicación avise.
- **No:** poner `'visibility' => 'public'` en el disco `s3` de `config/filesystems.php`. La ACL de las opciones del `put()` gana sobre él, así que quedaría como configuración muerta y como una segunda fuente de verdad para el mismo dato.
- **No:** bucket privado con URLs firmadas temporales. Más seguro, pero para fotos de camiones y logos de empresa el coste —URLs que caducan, imposibles de cachear, un `temporaryUrl` por fila en cada listado— no compra nada.

**Ciclo de vida del archivo**

- **Sí:** subir **antes** de persistir. Si S3 falla, no queda una fila apuntando a un archivo inexistente. El caso contrario —un archivo huérfano sin fila— es basura barata; una fila con imagen rota la ve el usuario.
- **Sí:** el `update` con imagen nueva borra la anterior. La key es única por registro y nadie más la referencia, así que dejarla solo hace crecer el bucket.
- **Sí:** el borrado del archivo anterior ocurre **después** de que la fila quede guardada. Al revés, un fallo de escritura dejaría la fila apuntando a un objeto ya borrado.
- **Sí:** el `DELETE` **no** toca el archivo, en los dos dominios. En `carriers` es un no-op y en `vehicles` solo cambia el `status` a `inactive`: la fila sigue viva y sigue apareciendo en los listados, así que su imagen tiene que seguir resolviendo.
- **No:** borrado en cascada de archivos cuando algún día exista el borrado real de filas. Esa decisión pertenece a la spec que implemente ese borrado, no a esta.

**Errores**

- **Sí:** un fallo de subida se traduce a `BadRequestError` (400). Reutiliza el mecanismo de errores que ya existe y el front no necesita un caso nuevo.
- **No:** una clase `App\Errors\StorageError` propia. No habilitaría ningún comportamiento distinto; sería una clase más para el mismo 400.
- **Sí:** `S3FileStorageService` captura tanto el `false` de retorno como cualquier `Throwable`. Los discos llevan `'throw' => false`, así que un fallo de escritura vuelve como `false`, pero una región inválida o un problema de DNS sí lanzan.
- **Sí:** `delete()` nunca lanza. Un borrado de limpieza que falle no puede tumbar una petición que ya hizo su trabajo.

**Presentación**

- **Sí:** el campo se sigue llamando `image` y ahora vale una URL. El front no cambia de nombre de campo, solo deja de recibir un valor inútil.
- **No:** añadir un `imageUrl` junto a `image`. Dos campos para el mismo dato, y uno de ellos —la key— no le sirve de nada al consumidor.
- **Sí:** la URL se resuelve en el Resource con `app(FileStorageServiceInterface::class)`. Es localización de servicio y no gusta, pero un `JsonResource` se instancia con `new` y no admite inyección por constructor.
- **No:** llamar a `Storage::url()` directamente en el Resource. Sería más corto y metería el conocimiento del disco en la capa de presentación, rompiendo la abstracción que esta spec construye.

**Tests**

- **Sí:** `Storage::fake()` global en `tests/Pest.php`, junto al `Mail::fake()` que ya estaba. Es lo único que garantiza que ningún test —ni los de las specs futuras— intente salir a la red.
- **Sí:** un doble del contrato en `tests/`, y un criterio de aceptación que exige que la suite de dominio pase entera con él. Es la demostración operativa de la sustituibilidad; sin ella, el principio de Liskov se queda en una intención.
- **Sí:** modificar los tests de SPEC 03 y 04. Afirman que no se escribe ningún archivo, y esa afirmación es justo la que esta spec deroga. Se tocan solo los relativos a `image`.

**Alcance y proceso**

- **Sí:** la subida se queda embebida en los `store`/`update` existentes. Ni una ruta nueva, ni un cambio en el contrato HTTP más allá del valor de `image`.
- **No:** un endpoint `POST /api/files` que devuelva la URL para mandarla luego en el body. Obligaría al front a dos peticiones y abriría la puerta a archivos subidos que nunca se asocian a nada.
- **No:** subida directa del front a S3 con URL prefirmada. Es la opción que más escala, y hoy no hay ni volumen ni archivos grandes que la justifiquen.
- **No:** escribir ya una segunda implementación de producción (local, GCS, MinIO). Sería código muerto; la interfaz queda lista para recibirla el día que haga falta.
- **No:** migrar las filas existentes con `uuid.ext` sin prefijo. No resuelven a ningún archivo porque ese archivo nunca existió; se borran a mano fuera del código.
- **Sí:** instalar `league/flysystem-aws-s3-v3` e `intervention/image` como requisito previo, fuera del plan. Decisión del usuario.
- **No:** delegar el paso de documentación al agente `endpoint-docs`. Está pensado para features recién scaffoldeadas; aquí solo cambian seis cadenas en atributos que ya existen.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| **El bucket es público y las URLs son permanentes.** Cualquiera con el enlace ve la imagen para siempre, aunque el registro se desactive o el carrier deje de operar. No hay forma de revocar el acceso salvo borrando el objeto. | Decisión explícita y consciente. El contenido es logos de empresa y fotos de vehículos, no documentos personales. El `{uuid}` hace la key impredecible, así que no se puede enumerar el bucket adivinando nombres. Si algún día entran documentos sensibles —seguros, licencias—, esa spec deberá cambiar a bucket privado con URLs firmadas. |
| **Las ACLs pueden estar deshabilitadas en el bucket.** Desde abril de 2023 los buckets nuevos de AWS nacen con *Object Ownership* en «Bucket owner enforced», que ignora las ACLs y responde `AccessControlListNotSupported` a cualquier `put` que mande una. Con ese ajuste, ninguna imagen se sube. | El fallo es ruidoso y temprano, no silencioso: sale como 400 con «No se pudo almacenar la imagen» en la primera alta real, no como un objeto subido que luego no se ve. La configuración correcta —ACLs habilitadas y *Block public ACLs* desactivado— está listada como requisito previo del bucket. Ningún test lo cubre, porque `Storage::fake()` usa el disco local y no valida ACLs. |
| **El recorte es centrado y ciego.** Un logo con el texto pegado a un lado o una foto de camión encuadrada a la izquierda pierden justo lo que importaba, y el usuario no ve el resultado hasta después de subir. | Aceptado: es el coste de no pedir coordenadas de encuadre al front. El caso real —logos cuadrados o casi, fotos de vehículo con el camión centrado— aguanta bien un `cover()`. Si aparecen quejas, el siguiente paso es un recorte elegido por el usuario, y eso es una spec con cambios en el body y en el front. |
| **El original no se guarda y el recorte es irreversible.** Subida la imagen, los píxeles de los bordes y la calidad perdida en la recompresión no se recuperan: la única salida es volver a subir el archivo. | Consciente. Guardar el original doblaría el coste del bucket por una versión que ningún endpoint expone. El usuario conserva su archivo; lo que se pierde es una copia derivada. |
| **Subir la calidad o el lado más adelante no re-procesa lo ya subido.** Cambiar `SIDE` a 1200 deja el bucket con una mezcla de imágenes de 800 y de 1200, y el front no puede distinguirlas. | El front maqueta con caja fija y `object-fit`, así que la mezcla se ve bien igualmente. Re-procesar el histórico sería un comando artisan puntual, no un cambio de esta spec. |
| **El procesado ocurre en memoria y dentro de la petición.** Una imagen de 3 MB muy grande en píxeles (una 8000×6000) se descomprime a bastante más que 3 MB de RAM en GD, y se suma al tiempo de respuesta junto con la subida a S3. | El `max:3072` acota el archivo, que es la única cota práctica: GD reserva en función de los píxeles, no de los bytes del JPEG. Si aparecen `memory_limit` en producción, la salida es una regla `dimensions:max_width,max_height` en el FormRequest, un cambio de una línea. La subida asíncrona por colas sigue fuera de alcance. |
| **El límite de 3 MB se comprueba en dos sitios que pueden desincronizarse.** La regla `max:3072` vive en el código y el `upload_max_filesize` en la configuración de PHP de cada entorno. Si alguien deja PHP en 2M, la validación nunca llega a ejecutarse y el usuario recibe un `required` que no explica nada. | Está listado como requisito previo con un valor concreto (≥ 4M) y es lo primero que hay que mirar si aparece un `required` inexplicable con un archivo presente. Ningún test lo puede cubrir: `UploadedFile::fake()` no pasa por el parser de PHP. |
| **Provisionar el bucket queda fuera de alcance.** La suite puede estar entera en verde con `Storage::fake()` y la aplicación fallar en el primer alta real por credenciales, región, permisos o CORS. Verde no significa funciona. | El fallo llega como 400 con mensaje claro, no como 500 silencioso. Antes de dar la spec por terminada hay que hacer un alta real contra el bucket de verdad; ningún test puede cubrir eso. |
| **El `Storage::fake()` global enmascara la configuración real.** Ningún test volverá a detectar que `FILESYSTEM_DISK` está mal puesto o que faltan las `AWS_*`. | Es el precio de tener tests deterministas y sin red, y se paga a sabiendas. La verificación de la configuración real es manual y va en el paso previo a desplegar. |
| **Un `save()` que falle después de la subida deja un archivo huérfano** en el bucket, sin ninguna fila que lo referencie. Nadie lo va a borrar nunca. | Es el lado barato del trade-off: se eligió huérfano antes que fila con imagen rota. La limpieza de huérfanos está fuera de alcance y, si el volumen lo justifica, se resuelve con una regla de ciclo de vida en el propio S3, sin tocar código. |
| **Si la autorización se comprueba después de subir**, un `carrier` que intente tocar un vehículo ajeno recibirá su 403 correcto pero habrá dejado un archivo en el bucket. Un atacante podría llenarlo a base de peticiones que sabe que van a fallar. | El orden es obligatorio y está fijado por un criterio de aceptación: ámbito y unicidad de placa se validan **antes** de llamar a `store()`. Si ese criterio se rompe, el bucket queda expuesto a escritura por cualquier usuario autenticado. |
| **El borrado del archivo anterior en el `update` es irreversible.** No hay versionado, ni papelera, ni confirmación: reemplazar una imagen por error pierde la original para siempre. | Aceptado. El versionado de objetos es una opción del bucket que se puede activar desde la consola de AWS sin tocar el código, y esa es la vía si algún día importa. |
| **Cambiar de proveedor no migra los archivos ya subidos.** El contrato hace que el *código* sea sustituible, pero las keys guardadas seguirán apuntando a objetos que solo existen en el bucket viejo. | La sustituibilidad que promete esta spec es la del código, no la de los datos. Cualquier cambio real de proveedor necesita además una copia del bucket, y eso es una tarea de operaciones que merece su propia spec. |
| **El `app()` dentro de los Resources esconde una dependencia** que no aparece en ninguna firma. Quien lea `CarrierResource` no ve que necesita el contenedor hasta que un test falla con un binding sin resolver. | El `Storage::fake()` global y el binding en el provider hacen que esté siempre resuelto, tanto en tests como en runtime. Está documentado en el modelo de datos como mal menor consciente. |
| **Se modifican tests de specs ya aprobadas.** Al invertir aserciones existentes es fácil perder cobertura por el camino sin que nada avise: un test que antes comprobaba algo y ahora comprueba lo contrario puede dejar de comprobar un tercer detalle. | Los criterios de aceptación exigen que solo se toquen los tests relativos a `image` y que el resto quede intacto. La revisión del diff de `CarrierTest` y `VehicleTest` es obligatoria antes de cerrar. |

---

## Lo que **no** entra en esta spec

- Endpoints nuevos de subida: la subida sigue embebida en los `store`/`update` existentes.
- Una segunda implementación de producción del contrato (local, GCS, MinIO).
- Migrar o limpiar las filas existentes con `uuid.ext` sin prefijo.
- Buckets privados y URLs firmadas temporales.
- Miniaturas y variantes por tamaño: se guarda una sola versión normalizada por registro.
- Conversión de formato: el formato de entrada se conserva y nada pasa a WebP.
- Conservar el original sin recortar.
- Recorte elegido por el usuario: siempre es centrado.
- Escaneo antivirus del contenido subido.
- Subida asíncrona por colas.
- Varios archivos por registro: galerías y documentos del vehículo.
- Subida directa del front a S3 con URL prefirmada.
- CDN o CloudFront delante del bucket.
- Cualquier cambio a las reglas de los FormRequests que no sea el `max:3072`: ni `dimensions:`, ni relación de aspecto, ni límites por dominio.
- Ajustar el `upload_max_filesize` y el `post_max_size` de PHP en los entornos: es configuración de infraestructura.
- Provisionar el bucket, la política IAM, el CORS y el acceso público.
- Limpieza de archivos huérfanos.

Cada uno de esos, si entra, va en su propia spec.
