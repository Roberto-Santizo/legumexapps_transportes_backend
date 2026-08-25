# SPEC 19 — Factura de los gastos de vehículo

> **Estado:** Implementado
> **Depende de:** SPEC 01, SPEC 05, SPEC 14
> **Fecha:** 2026-08-25
> **Objetivo:** Registrar si un gasto de mantenimiento fue facturado y guardar el archivo de la factura —imagen o PDF— en el mismo instante del alta, sin que ninguno de los dos datos pueda cambiar después.

Depende de **SPEC 01** por el guard JWT y el middleware `role:`, de **SPEC 05** por `FileStorageServiceInterface` y su implementación en S3, y de **SPEC 14** por la tabla `vehicle_expenses`, su dominio completo y su ámbito por rol.

Es la **segunda spec aditiva sobre un dominio ya publicado** —la primera fue SPEC 13 sobre `vehicles`— y reabre a propósito una decisión que SPEC 14 dejó cerrada: allí «adjuntar la factura (imagen o PDF)» estaba explícitamente fuera de alcance porque «nadie lo pidió y añade ciclo de vida de archivos a un recurso que se borra de verdad». Ahora sí se pide, y esta spec paga ese ciclo de vida completo: el archivo nace con el gasto y muere con él.

Es también la **primera vez que se toca el contrato de SPEC 05**. Hasta hoy el proyecto solo guardaba imágenes recortadas a un cuadrado de 800×800; una factura pasada por ese recorte es ilegible, así que `FileStorageServiceInterface` gana un método que persiste el archivo **tal cual llega**, sin procesarlo.

**Lo que esta spec no es:** un dominio de facturación. No hay número de factura, ni proveedor, ni NIT, ni estado de pago; `is_invoiced` es un sí o un no, y el archivo es un adjunto opaco que el backend no lee ni valida más allá de su extensión y su tamaño.

---

## Alcance

**Dentro:**

- **Migración aditiva sobre `vehicle_expenses`**, sin tabla nueva ni enum nuevo: dos columnas, `is_invoiced` (`boolean`, `default false`, no nullable) e `invoice` (`string`, nullable). Ninguna otra tabla se toca.
- **`is_invoiced` es obligatorio en el `POST`.** No tiene valor por omisión de cara a la API: quien registra el gasto decide siempre. El `default false` de la migración existe solo para que las filas ya capturadas sobrevivan sin backfill.
- **`invoice` es obligatorio si `is_invoiced` es `true`** y se manda en la **misma petición del alta**. Con `is_invoiced` en `true` y sin archivo, la respuesta es **422**.
- **Con `is_invoiced` en `false`, un archivo enviado se ignora en silencio**: el gasto se crea con `invoice = null` y no se sube nada al bucket. No es 422.
- **Formatos y tope:** `mimes:jpg,jpeg,png,pdf` y `max:3072` (3 MB), el mismo tope que las imágenes del proyecto.
- **El archivo se guarda tal cual llega**, sin recorte, sin redimensionado y sin recompresión. `ImageProcessorServiceInterface` **no interviene** en este dominio.
- **Método nuevo en `FileStorageServiceInterface`** (SPEC 05): `storeUpload(UploadedFile $file, string $directory): string`, implementado en `S3FileStorageService` y en el doble `InMemoryFileStorageService`. Los tres métodos existentes no cambian de firma ni de comportamiento.
- **Directorio propio en el bucket: `invoices/`.** La key completa (`invoices/{uuid}.pdf`) se guarda en la columna `invoice`, no la URL — la convención de SPEC 05.
- **`is_invoiced` e `invoice` son inmutables.** El `PATCH` **no** los acepta: `UPDATABLE_FIELDS` no los incluye y mandarlos **se ignora en silencio** con 200, exactamente como ya ocurre con `vehicle_id`. Corregir un gasto mal facturado es borrarlo y volverlo a crear.
- **`DELETE` borra también el objeto del bucket.** Es la primera excepción a la regla «el `DELETE` no toca el archivo en ningún dominio»: aquí la fila desaparece de verdad, así que no dejarla sin archivo sería basura irrecuperable.
- **Filtro nuevo y tolerante `isInvoiced` en `GET /api/vehicle-expenses`**, junto a `category`, `nature`, `dateFrom` y `dateTo`. Un valor inválido se ignora, no invalida la petición.
- **Tres claves nuevas en `VehicleExpenseResource`**: `isInvoiced` (booleano), `invoiceUrl` (URL pública absoluta o `null`) e `invoiceType` (`"jpg" | "png" | "pdf" | null`, derivada de la extensión de la key, sin columna propia).
- **El `POST` es cambio incompatible**, sin periodo de gracia: un alta sin `is_invoiced` que antes daba 201 ahora da 422. Es la misma decisión que SPEC 13 tomó con los seis campos de la ficha técnica.
- Ámbito, roles y resto del contrato de SPEC 14 **intactos**: mismos cinco endpoints, mismos permisos, mismo orden, misma paginación, mismo `totalAmount`.
- Actualización de los tests Pest existentes de `VehicleExpense` y del Swagger; `references/vehicle-expenses-api.md` se reescribe con el contrato nuevo.

**Fuera de alcance (para specs futuras):**

- **Cambiar la facturación después del alta.** No hay `PATCH` que marque, desmarque, reemplace ni elimine la factura, y por tanto no hay bitácora de ese cambio.
- **Número de factura, serie, NIT, proveedor o taller como columnas.** SPEC 14 ya decidió que caben en `description` y esta spec no lo revisa.
- **Estado de pago, fecha de la factura, monto facturado distinto del `amount`, impuestos o retenciones.** `is_invoiced` es un booleano, no un módulo contable.
- **Varias facturas por gasto.** Un gasto tiene como mucho un archivo.
- **Leer el contenido del archivo:** ni OCR, ni extracción del total, ni validación de que el PDF sea realmente una factura, ni comprobación de que el monto coincida.
- **Desglosar `totalAmount` en facturado y no facturado**, o cualquier otro agregado nuevo. Eso es un reporte y va en su propia spec.
- **Miniaturas, previsualización o conversión de PDF a imagen.**
- **URLs firmadas o control de acceso al archivo.** El objeto es `public-read` como todo lo que sube el proyecto: quien tenga la URL, lo ve.
- **Adjuntar archivos en cualquier otro dominio.** El método nuevo del contrato queda disponible, pero esta spec solo lo usa en gastos.
- **Tocar `Vehicle`, `VehicleResource` o cualquier ruta de SPEC 04 y SPEC 13.**

---

## Modelo de datos

Esta spec **no crea ninguna tabla ni ningún enum**. Añade dos columnas a una tabla existente, un método a un contrato existente y tres claves a un Resource existente.

### 1. Migración aditiva sobre `vehicle_expenses`

```php
Schema::table('vehicle_expenses', function (Blueprint $table) {
    $table->boolean('is_invoiced')->default(false)->after('description');
    $table->string('invoice')->nullable()->after('is_invoiced');
});
```

- `is_invoiced` **no es nullable**: un gasto está facturado o no lo está, no hay tercer estado. El `default false` es relleno para las filas ya capturadas, **no negocio**: por la API el campo es obligatorio en el alta. Es la misma distinción que SPEC 13 hizo con `condition` y `kilometers_per_gallon`.
- `invoice` es nullable porque un gasto no facturado no tiene archivo. Guarda la **key completa** (`invoices/9f3a....pdf`), nunca la URL — cambiar de proveedor de almacenamiento no debe obligar a migrar datos.
- **Ningún índice nuevo.** El filtro `isInvoiced` siempre viaja acompañado del `vehicle_id` obligatorio, que ya está cubierto por el índice `(vehicle_id, expense_date)` de SPEC 14; un índice suelto sobre un booleano de dos valores no aporta selectividad.
- **Sin backfill.** Las filas existentes quedan en `is_invoiced = false` e `invoice = null`, que es exactamente lo que significaban hasta hoy: nadie había registrado factura.

### 2. Modelo `VehicleExpense`

```php
#[Fillable([
    'vehicle_id', 'category', 'nature', 'amount',
    'expense_date', 'description', 'is_invoiced', 'invoice', 'registered_by',
])]
class VehicleExpense extends Model
{
    protected function casts(): array
    {
        return [
            'category' => VehicleExpenseCategory::class,
            'nature' => VehicleExpenseNature::class,
            'amount' => 'decimal:2',
            'expense_date' => 'date',
            'is_invoiced' => 'boolean',   // nuevo
        ];
    }
}
```

`invoice` **no lleva cast**: es una cadena opaca. El modelo no gana métodos, ni relaciones, ni accesores — la URL la resuelve el Resource y el tipo lo deriva el Resource.

### 3. Método nuevo en `FileStorageServiceInterface`

```php
/**
 * Store an uploaded file as-is under the given directory and return its key.
 *
 * The file is persisted byte for byte: no cropping, no resizing and no
 * re-encoding. The extension comes from the upload itself, so the caller
 * is responsible for having validated which types it accepts.
 *
 * @throws BadRequestError when the underlying storage rejects the file.
 */
public function storeUpload(UploadedFile $file, string $directory): string;
```

- Convive con `store(string $contents, ...)` sin sustituirlo: aquel recibe **bytes ya procesados** y este un `UploadedFile` intacto. Carriers y Vehicles siguen usando `store()` sin enterarse.
- La extensión sale de `guessExtension()`, no del nombre que teclee el cliente: un `factura.exe` con contenido JPEG se guarda como `.jpg`, y **`image/jpeg` se normaliza a `jpg`**, nunca a `jpeg`.
- La key se arma igual que en `store()`: `{directory}/{uuid}.{extension}`, con ACL `public-read`.
- `delete()` y `url()` **no cambian** y sirven a este dominio tal cual.

### 4. Forma de la respuesta

`VehicleExpenseResource` gana tres claves al final, sin mover ni renombrar ninguna de las nueve que ya tenía:

```json
{
  "id": 41,
  "vehicleId": 7,
  "category": "tires",
  "nature": "preventive",
  "amount": "1250.00",
  "expenseDate": "12-08-2026",
  "description": "Cuatro llantas nuevas, taller El Rodaje, factura A-9912",
  "isInvoiced": true,
  "invoiceUrl": "https://bucket.s3.amazonaws.com/invoices/9f3a....pdf",
  "invoiceType": "pdf",
  "registeredBy": "Roberto Santizo",
  "createdAt": "12-08-2026 04:31:07 PM"
}
```

- `isInvoiced` es **booleano JSON de verdad** (`true` / `false`), no `1` / `0` ni cadena.
- `invoiceUrl` se resuelve con `app(FileStorageServiceInterface::class)->url($this->invoice)` —localización de servicio consciente, como en `CarrierResource` y `VehicleResource`— y es `null` cuando no hay archivo.
- `invoiceType` es `"jpg"`, `"png"`, `"pdf"` o `null`, derivada de la extensión de la key con `pathinfo()`. **No es una columna**: si algún día cambia la forma de guardar, no hay dato que migrar.
- Un gasto con `isInvoiced: false` trae **siempre** `invoiceUrl: null` e `invoiceType: null`. La combinación `isInvoiced: true` con `invoiceUrl: null` **no puede existir**, porque el archivo es obligatorio en el alta.

### 5. Cuerpo del alta

`POST /api/vehicle-expenses` pasa de seis campos obligatorios a siete, y admite uno condicional. En `multipart/form-data`, no en JSON, cuando hay archivo:

| Campo | Regla |
| --- | --- |
| `is_invoiced` | `required\|boolean` — acepta `true`, `false`, `1`, `0`, `"1"`, `"0"` |
| `invoice` | `required` si `is_invoiced` es verdadero; `file\|mimes:jpg,jpeg,png,pdf\|max:3072` |

- La normalización vive en `prepareForValidation()`: `is_invoiced` se convierte a booleano real **solo si la clave viene presente**, para que su ausencia siga cayendo en el `required` y no se lea como `false`.
- La regla de `invoice` se construye a partir de ese valor ya normalizado, no con `required_if`, que compara de forma laxa contra la cadena `"true"` y se rompe con los `"1"` que manda un formulario multipart.
- Con `is_invoiced` falso, `invoice` **no se valida ni se sube**: llegue lo que llegue, se descarta antes de tocar el bucket.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo.

1. **Contrato de almacenamiento.** Añadir `storeUpload(UploadedFile $file, string $directory): string` a `app/Interfaces/Storage/FileStorageServiceInterface.php` con su PHPDoc de sustitución (qué lanza, qué garantiza la key), implementarlo en `app/Services/Storage/S3FileStorageService.php` —misma ACL `public-read`, misma traducción de `false` y de cualquier `Throwable` a `BadRequestError`, extensión desde `guessExtension()` con `image/jpeg` → `jpg`— y en el doble `tests/Doubles/InMemoryFileStorageService.php`. Nadie lo llama todavía. Verificación: `php artisan test --compact` sigue pasando entera, porque los tres métodos existentes no cambiaron.

2. **Migración, modelo y factory.** `php artisan make:migration add_invoice_fields_to_vehicle_expenses_table` con las dos columnas; `#[Fillable]` de `VehicleExpense` con `is_invoiced` e `invoice`, y el cast `'is_invoiced' => 'boolean'`; en `VehicleExpenseFactory`, `is_invoiced => false` e `invoice => null` en el estado por defecto, más un estado `invoiced()` que pone el booleano en `true` y una key de ejemplo en `invoice`. Correr `php artisan migrate`. Verificación: `VehicleExpense::factory()->create()` y `->invoiced()->create()` producen filas válidas.

3. **FormRequest del alta.** `StoreVehicleExpenseRequest`: `is_invoiced` `required|boolean`, `invoice` `file|mimes:jpg,jpeg,png,pdf|max:3072` y `required` solo cuando el booleano ya normalizado es verdadero; normalización en `prepareForValidation()` **únicamente si la clave está presente**; `messages()` en español para los cuatro fallos nuevos (ausente, no booleano, tipo no permitido, mayor de 3 MB). `UpdateVehicleExpenseRequest` **no se toca**: los dos campos siguen sin aparecer y por eso se ignoran solos.

4. **Service, alta.** `VehicleExpenseService` gana su primer constructor, con `FileStorageServiceInterface` inyectado por constructor (la regla de inyectar por parámetro es solo del controller) y la constante `INVOICE_DIRECTORY = 'invoices'`. `createVehicleExpense()` decide primero el booleano: si es falso, guarda `invoice = null` y **descarta el archivo sin subirlo**; si es verdadero, sube con `storeUpload()` y persiste la key. El orden es el del proyecto: resolver el vehículo (403/404) → subir → persistir, para que un ámbito denegado no deje archivos huérfanos.

5. **Service, baja y filtro.** `deleteVehicleExpense()` borra la fila y **después** llama a `fileStorage->delete($expense->invoice)` —`delete()` nunca lanza, así que un fallo de limpieza no tumba una petición ya cumplida—. `getVehicleExpenses()` gana el filtro tolerante `isInvoiced`, resuelto con `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)` como los catálogos de SPEC 06–09: `null` significa «no filtrar». `UPDATABLE_FIELDS` se queda **exactamente igual**, y se documenta en su comentario que ahora omite cuatro campos, no dos.

6. **Resource.** `VehicleExpenseResource` con `isInvoiced`, `invoiceUrl` e `invoiceType`, esta última derivada con `pathinfo($this->invoice, PATHINFO_EXTENSION)` y `null` cuando no hay key. Verificación manual: un gasto sin factura devuelve las tres claves con `false`, `null` y `null`.

7. **Tests.** **Ampliar** los dos archivos existentes, sin regenerarlos: `tests/Feature/VehicleExpenseTest.php` (alta con y sin factura, los tres formatos aceptados, el rechazo de un `.exe` y de un archivo de más de 3 MB, el 422 sin `is_invoiced`, el 422 con `is_invoiced` verdadero y sin archivo, el archivo descartado cuando el booleano es falso, el `PATCH` que ignora ambos campos, el `DELETE` que borra el objeto del bucket, el filtro `isInvoiced` y su tolerancia, y las tres claves nuevas del Resource) y `tests/Unit/VehicleExpenseServiceTest.php`. Añadir también un caso que ejercite `storeUpload()` a través del doble para probar la sustituibilidad del contrato. Correr `php artisan test --compact --filter=VehicleExpense`.

8. **Documentación.** Actualizar los atributos `OA` del Controller, del `StoreVehicleExpenseRequest` y del `VehicleExpenseResource` —incluida la advertencia de que el alta es ahora `multipart/form-data` cuando lleva archivo— y regenerar `storage/api-docs/api-docs.json` con `php artisan l5-swagger:generate`.

9. **Cierre.** `vendor/bin/pint --dirty --format agent` y reescribir `references/vehicle-expenses-api.md` con el contrato nuevo, marcando de forma visible el **cambio incompatible del `POST`** y la inmutabilidad de los dos campos.

---

## Criterios de aceptación

**Migración y modelo**

- [ ] `php artisan migrate` añade `is_invoiced` (`boolean`, `default false`, no nullable) e `invoice` (`string`, nullable) a `vehicle_expenses`, y ninguna otra columna ni tabla cambia.
- [ ] Las filas capturadas antes de la migración quedan con `is_invoiced = false` e `invoice = null`, sin script de backfill.
- [ ] `VehicleExpense::factory()->create()` produce una fila no facturada y `->invoiced()->create()` una facturada.

**Contrato de almacenamiento**

- [ ] `FileStorageServiceInterface` expone `storeUpload()` además de los tres métodos de SPEC 05, cuyas firmas no cambiaron.
- [ ] `S3FileStorageService::storeUpload()` devuelve una key `{directory}/{uuid}.{ext}` y sube el objeto con ACL `public-read`.
- [ ] Un JPEG subido con cualquier nombre se guarda con extensión `jpg`, nunca `jpeg`.
- [ ] El archivo se guarda **byte por byte**: un PDF de dos páginas sigue teniendo dos páginas y una imagen de 1600×900 conserva sus dimensiones.
- [ ] `tests/Doubles/InMemoryFileStorageService` implementa el método nuevo y la suite pasa con el doble bindeado.
- [ ] `CarrierResource` y `VehicleResource` siguen devolviendo la misma URL de imagen que antes de esta spec.

**Alta**

- [ ] `POST /api/vehicle-expenses` **sin `is_invoiced`** devuelve **422** con mensaje en español, aunque los otros seis campos sean válidos.
- [ ] Con `is_invoiced = true` y sin `invoice` devuelve **422**.
- [ ] Con `is_invoiced = true` y un `jpg`, un `png` o un `pdf` válidos devuelve **201**, sube el objeto bajo `invoices/` y la columna `invoice` guarda la **key**, no la URL.
- [ ] Con `is_invoiced = false` y **sin** archivo devuelve **201** con `invoice = null`.
- [ ] Con `is_invoiced = false` y **con** archivo devuelve **201**, `invoice` queda en `null` y **no se sube nada al bucket**.
- [ ] Un archivo de tipo no permitido (`.exe`, `.docx`, `.gif`) devuelve **422**.
- [ ] Un archivo de más de 3 MB devuelve **422**; uno de exactamente 3072 KB se acepta.
- [ ] `is_invoiced` acepta `true`, `false`, `"1"` y `"0"` en `multipart/form-data`; un `"quizá"` devuelve 422.
- [ ] Un `carrier` que registra un gasto sobre un vehículo ajeno recibe **403 y no queda ningún archivo subido**.

**Inmutabilidad**

- [ ] `PATCH` con `is_invoiced` en el cuerpo responde **200** y el valor almacenado **no cambia**.
- [ ] `PATCH` con un archivo en `invoice` responde **200**, la columna **no cambia** y **no se sube nada al bucket**.
- [ ] `PATCH` que cambia `amount` o `description` deja `is_invoiced` e `invoice` intactos.

**Listado y salida**

- [ ] Cada gasto del listado y del detalle trae `isInvoiced`, `invoiceUrl` e `invoiceType`.
- [ ] `isInvoiced` viaja como booleano JSON (`true` / `false`), no como `1` / `0` ni como cadena.
- [ ] Un gasto facturado trae `invoiceUrl` absoluta y `invoiceType` igual a `jpg`, `png` o `pdf`; uno no facturado trae ambas en `null`.
- [ ] No existe ningún gasto con `isInvoiced: true` e `invoiceUrl: null`.
- [ ] `GET /api/vehicle-expenses?vehicleId=7&isInvoiced=true` devuelve solo los facturados, y `=false` solo los no facturados.
- [ ] `isInvoiced=quizá` **se ignora** y devuelve el listado completo, sin error.
- [ ] `totalAmount` sigue sumando **todos** los gastos filtrados, y con `isInvoiced=true` suma solo los facturados.
- [ ] Las nueve claves anteriores del Resource conservan nombre, orden y formato: `expenseDate` en `d-m-Y`, `createdAt` en `d-m-Y h:i:s A` y `amount` como cadena de dos decimales.

**Borrado**

- [ ] `DELETE` de un gasto facturado responde **200**, la fila desaparece de la base y el objeto **desaparece del bucket**.
- [ ] `DELETE` de un gasto no facturado responde **200** y no intenta borrar ningún archivo.
- [ ] Un `delete()` fallido del almacenamiento **no** impide que el `DELETE` responda 200: la fila ya se borró.
- [ ] Un segundo `DELETE` del mismo id sigue devolviendo **404**.

**No regresión**

- [ ] Los cinco endpoints conservan sus rutas, sus middlewares y su matriz de roles: `pilot` 403 en los cinco, `manager` lee y no escribe, `carrier` acotado a su empresa con 403 en ajeno.
- [ ] `GET /api/vehicles/{vehicle}` responde exactamente igual que antes de esta spec.
- [ ] Ningún test de SPEC 04, 05, 13 ni 14 se modifica salvo los dos archivos de `VehicleExpense`.
- [ ] `php artisan test --compact` pasa entera.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `/api/documentation` muestra el alta como `multipart/form-data` con los dos campos nuevos y el Resource con sus tres claves nuevas.
- [ ] `references/vehicle-expenses-api.md` documenta el cambio incompatible del `POST` y la inmutabilidad de los dos campos.

---

## Decisiones tomadas y descartadas

**Un booleano, no un módulo de facturación.** Se descartó `invoice_number`, `supplier`, `invoice_date` y estado de pago. Cada uno abre sus propias preguntas —¿el número es único?, ¿por proveedor?, ¿qué formato?— y SPEC 14 ya decidió que ese detalle cabe en `description`. Cuando exista un reporte por proveedor, tendrá su spec.

**`is_invoiced` obligatorio en el alta, aun teniendo `default false` en la base.** El default existe para que las filas viejas sobrevivan sin backfill, no para que la API adivine. Es la misma separación que SPEC 13 hizo entre los defaults de la migración y la obligatoriedad por API.

**Cambio incompatible en el `POST`, sin periodo de gracia.** Se descartó dejar `is_invoiced` opcional durante una versión. El front es interno y se despliega junto a la API; un campo opcional «temporal» se queda para siempre y deja la mitad de los gastos sin dato.

**Facturación inmutable: se fija en el alta y no se cambia nunca.** Se descartó permitir marcar, desmarcar o reemplazar por `PATCH`. Esa flexibilidad arrastra un ciclo de vida completo —borrar el archivo anterior, decidir qué pasa con el huérfano, auditar quién desmarcó— y el `DELETE` de este dominio ya es real: corregir un gasto mal capturado son seis campos y una llamada. La decisión es del usuario, tomada con este coste sobre la mesa.

**Mandar los dos campos en el `PATCH` se ignora en silencio, no es 422.** Es lo que ya hace `vehicle_id` en el mismo recurso, y un 422 obligaría a que el front separe dos cuerpos según si edita o no. Se asume el precio conocido: un cliente que suba un archivo en el `PATCH` recibe 200 sin que se guarde nada, y por eso queda escrito en Swagger y en el documento de referencia.

**Un archivo con `is_invoiced = false` se descarta en silencio, no da 422.** Es la excepción a la regla anterior invertida y también decisión del usuario: el booleano manda, y el archivo es accesorio. El gasto se crea igual y nadie paga una subida al bucket que no se va a leer.

**El archivo se guarda tal cual, sin pasar por `ImageProcessorService`.** Recortar una factura a un cuadrado de 800×800 la vuelve ilegible, y comprimir a calidad 80 un texto escaneado es justo lo que peor tolera el JPEG. Es la razón entera de que exista `storeUpload()`.

**Método nuevo en `FileStorageServiceInterface`, no contrato aparte.** Se descartó un `DocumentStorageServiceInterface` paralelo. Es la misma capacidad —persistir un archivo y devolver su key— y un segundo contrato duplicaría `delete()` y `url()` sin ganar nada; el doble de tests tendría que implementar los dos.

**PDF admitido, tope de 3 MB compartido con las imágenes.** Se descartó subir el tope para PDF escaneados: obligaría a tocar `upload_max_filesize` y `post_max_size` en cada entorno, y el proyecto prefiere forzar al usuario a subir archivos pequeños.

**Directorio propio `invoices/`, no `vehicle-expenses/`.** El nombre describe qué hay dentro, no de qué recurso cuelga, y deja el prefijo libre para que otros dominios guarden facturas ahí el día que las tengan.

**La key en la columna, la URL en el Resource.** Convención de SPEC 05, sin excepción: cambiar de proveedor de almacenamiento no puede obligar a migrar filas.

**`invoiceType` derivado, no columna.** Es información que ya vive en la extensión de la key. Guardarlo sería un segundo lugar donde la verdad puede desincronizarse.

**`invoiceType` con la extensión real (`jpg`, `png`, `pdf`), no con una categoría (`image`, `pdf`).** El front deduce igual de fácil si pinta `<img>` o un enlace, y de paso sabe qué extensión tiene el archivo sin parsear la URL.

**El `DELETE` borra también el objeto del bucket.** Primera y única excepción a «el `DELETE` no toca el archivo en ningún dominio». La regla nació porque en Carriers y Vehicles la fila sobrevive a la baja lógica; aquí desaparece de verdad, así que conservar el archivo solo deja basura que nadie podrá relacionar con nada. Se borra **después** de la fila, y un fallo de limpieza no altera la respuesta.

**Filtro `isInvoiced` tolerante, como el resto.** Un valor inválido se ignora en vez de dar 422: es la convención de SPEC 06–09 y de los filtros que SPEC 14 ya tiene, y evita que el front rompa la pantalla mandando el parámetro vacío.

**Sin desglose de `totalAmount` por facturación.** El listado sigue devolviendo un único acumulado. Quien quiera el total facturado manda `isInvoiced=true` y lo lee; un segundo agregado en la raíz sería un tercer `total` conviviendo con los otros dos.

**Sin índice sobre `is_invoiced`.** El filtro siempre va acompañado del `vehicle_id` obligatorio, que ya tiene índice. Un índice sobre un booleano de dos valores no aporta selectividad.

**El archivo es público, como todo lo que sube el proyecto.** Se descartaron URLs firmadas y control de acceso por rol: sería el primer objeto privado del sistema y obligaría a un endpoint de descarga propio. Queda anotado como riesgo, no como funcionalidad pendiente.

---

## Riesgos identificados

| Riesgo | Mitigación |
| --- | --- |
| El `POST` es cambio incompatible: si la API se despliega antes que el front, todas las altas de gastos empiezan a dar 422 | Front interno que se despliega junto a la API. El cambio queda marcado de forma visible en `references/vehicle-expenses-api.md` y en la descripción del endpoint en Swagger. |
| Un archivo enviado con `is_invoiced = false` se descarta **en silencio**: el usuario cree que adjuntó la factura y el gasto queda sin ella | Es la decisión tomada. Se documenta en Swagger y en el documento de referencia, y la respuesta 201 devuelve `isInvoiced: false` e `invoiceUrl: null`, así que el front puede detectarlo y avisar en pantalla. |
| Lo mismo en el `PATCH`: subir una factura de 3 MB devuelve 200 sin guardar nada | Misma mitigación, más un criterio de aceptación que lo fija por escrito. La forma correcta de corregir es borrar y volver a crear. |
| Corregir un gasto mal facturado obliga a borrarlo y recrearlo, y en el camino se pierden su `id`, su `created_at` y su `registered_by` original | Consecuencia asumida de la inmutabilidad. El `DELETE` es solo `carrier` y `administrator`, y recapturar un gasto son siete campos. Si algún día duele, un endpoint de corrección es aditivo. |
| Las facturas quedan **públicas** en el bucket: quien tenga la URL ve montos, NIT y datos del proveedor sin autenticarse | La key lleva un UUID v4, no adivinable ni enumerable, y la URL solo sale por endpoints autenticados. Aun así es un objeto público: si el dato se considera sensible, la salida es un endpoint de descarga con URL firmada, que va en otra spec. |
| Si el borrado del objeto falla tras borrar la fila, el archivo queda huérfano y sin nada que lo relacione | `delete()` nunca lanza, así que el `DELETE` responde 200 igual. No hay bitácora de huérfanos ni tarea de limpieza: es un coste aceptado, acotado a fallos del almacenamiento. |
| El tope de 3 MB obliga a `upload_max_filesize` y `post_max_size` ≥ 4M en cada entorno; si PHP corta antes, el usuario ve un `required` confuso en vez de un error de tamaño | Ya es requisito del proyecto desde SPEC 05 por las imágenes de Carriers y Vehicles. Se repite en el documento de referencia para que quien despliegue no lo descubra en producción. |
| Se guarda el archivo sin procesarlo, así que entra al bucket tal como lo subió el usuario | La validación `mimes` de Laravel comprueba el contenido real, no el nombre ni la cabecera del cliente, y el objeto se sirve desde el dominio del bucket, no desde el de la aplicación. |
| Tocar `FileStorageServiceInterface` rompe cualquier implementación externa del contrato | Solo hay dos implementaciones, ambas en el repositorio (`S3FileStorageService` y el doble de tests), y las dos se actualizan en el paso 1 del plan. |
| Dos gastos con la misma factura suben dos objetos idénticos | No hay deduplicación ni la habrá: el coste de almacenar un PDF de menos de 3 MB dos veces es menor que el de razonar sobre un archivo compartido por dos filas que se borran por separado. |

---

## Lo que **no** entra en esta spec

- **Cambiar la facturación después del alta**: ni marcar, ni desmarcar, ni reemplazar, ni eliminar la factura. Tampoco bitácora de esos cambios.
- **Número de factura, serie, NIT, proveedor, taller o fecha de la factura** como columnas propias. Siguen cabiendo en `description`.
- **Estado de pago, monto facturado distinto del `amount`, impuestos o retenciones.**
- **Más de una factura por gasto.**
- **Leer el archivo**: nada de OCR, extracción del total ni comprobación de que el PDF sea realmente una factura.
- **Desglosar `totalAmount`** en facturado y no facturado, y cualquier otro agregado o reporte nuevo.
- **Miniaturas, previsualización o conversión de PDF a imagen.**
- **URLs firmadas, endpoint de descarga o control de acceso al archivo.**
- **Adjuntar archivos en cualquier otro dominio.** El método nuevo del contrato queda disponible; esta spec solo lo usa en gastos.
- **Cualquier cambio en `Vehicle`, `VehicleResource` o las rutas de SPEC 04 y SPEC 13.**

Cada uno de estos, si aterriza, va en su propia spec.
