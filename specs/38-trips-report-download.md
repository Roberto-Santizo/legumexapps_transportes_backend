# SPEC 38 — Reporte de viajes descargable

> **Estado:** Implementado
> **Depende de:** SPEC 24, SPEC 30, SPEC 32, SPEC 37
> **Fecha:** 2026-09-25
> **Objetivo:** Exponer `GET /api/reports/trips?dateFrom=&dateTo=`, que devuelve en binario un `.xlsx` con los viajes cuya `recolection_date` cae en el rango, respetando el ámbito de `GET /api/trips` y añadiendo las columnas de productos terminados solo para los roles que los leen.

---

## Alcance

**Dentro:**

- **Ruta nueva `GET /api/reports/trips`**, en `routes/reports.php` (nuevo, incluido desde `routes/api.php`).
  - Middleware `jwt.auth` + `role:` con `UserRole::allExcept(UserRole::Pilot)`: el `pilot` recibe 403.
  - Sin `carrier.required`, igual que `GET /api/trips`: el ámbito del `carrier` lo resuelve `getTrips()`.
- **`Report` gana su primera capa HTTP**: `ReportController` y `ExportTripsReportRequest`. Sin Resource, porque la respuesta no es JSON.
- **Validación (`ExportTripsReportRequest`)**:
  - `dateFrom` y `dateTo` son `required|date_format:Y-m-d`; `dateTo` es además `after_or_equal:dateFrom`. Sin ellos, o con formato inválido, 422.
  - Sin rango máximo.
  - El filtro es por día completo sobre `recolection_date`, como en `GET /api/trips`.
- **Filtros opcionales y tolerantes**, los mismos de `GET /api/trips`: `status`, `clientId`, `shippingLineId`, `locationId`, `pilotId`, `vehicleId` y `search`. No se validan en el FormRequest y `limit` se descarta.
- **Método nuevo en `ReportServiceInterface`: `downloadTrips(User $user, array $filters)`.**
  - Llama a `TripServiceInterface::getTrips()` sin `limit`, así que el ámbito por rol y los filtros son exactamente los del listado.
  - Devuelve el nombre del archivo y sus bytes.
  - No sube nada al bucket.
- **Tope de filas**: con más de `ReportService::MAX_ROWS` (5000) viajes en el resultado, responde **400** «El reporte excede 5000 viajes; acota el rango de fechas» antes de escribir el archivo.
- **Columnas base, las ven todos los roles autorizados** (22): Id, Orden, Estado (en español), Cliente, Naviera, Punto de partida, Puerto, Destino final, Transporte, Contenedor, Fecha recolección, Fecha embarque, Inicio, Fin, Km estimados, Horas estimadas, Km reales, Horas reales, Observaciones, Piloto, Placa, Registrado por.
- **Columnas de productos**, solo para `administrator`, `manager`, `export` y `shipment` (2):
  - «Productos», con el texto `CODE × N cajas; CODE × N cajas`.
  - «Total de cajas».
  - Los productos se cargan con eager loading solo cuando el rol los ve; el SKU borrado sigue saliendo (`withTrashed()`).
- **Respuesta exitosa**:
  - `200` con el `.xlsx` en binario.
  - `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
  - `Content-Disposition: attachment; filename="viajes-{dateFrom}_{dateTo}.xlsx"`.
  - Los errores (401, 403, 422, 400) salen por el sobre JSON de `ResponseHandler`.
- **Rango sin viajes**: `200` con un archivo que solo trae la fila de encabezados. No es un error.
- OpenAPI y regeneración de `api-docs.json`, tests Feature y Unit, `references/reports-api.md` y actualización de `CLAUDE.md`.

**Fuera de alcance (para specs futuras):**

- Cambios a la tool `export_trips` del asistente y a `ReportService::exportTrips()`: adoptar la matriz por rol va en otra spec.
- Galones, viáticos, costo del viaje (SPEC 33) y cualquier columna de dinero.
- Rango máximo de fechas.
- Otros reportes descargables, como gastos de vehículo por endpoint.
- Formatos distintos de `.xlsx` (CSV, PDF).
- Generación asíncrona, cola, guardar el archivo en el bucket o historial de reportes.

---

## Modelo de datos

Esta spec **no crea tablas, columnas, migraciones, modelos, enums ni factories**. Lee `trips` (SPEC 24, 30, 32) y `trip_finished_products` (SPEC 37) tal como están.

### Contrato

`ReportServiceInterface` gana un tercer método. Los dos que ya tiene no cambian.

```php
/**
 * @param  array{dateFrom: string, dateTo: string, status?: string|null, clientId?: string|null,
 *               shippingLineId?: string|null, locationId?: string|null, pilotId?: string|null,
 *               vehicleId?: string|null, search?: string|null}  $filters
 * @return array{fileName: string, contents: string}
 *
 * @throws BadRequestError when the result exceeds MAX_ROWS or the spreadsheet cannot be written
 */
public function downloadTrips(User $user, array $filters): array;
```

- `fileName` sigue el patrón `viajes-{dateFrom}_{dateTo}.xlsx`.
- `contents` son los bytes que devuelve `SpreadsheetWriterInterface::write()`.
- El método **no toca `FileStorageServiceInterface`**.

### Constantes nuevas en `ReportService`

```php
/** 22 columnas, las ve todo rol autorizado. */
private const array TRIP_REPORT_HEADERS = [
    'Id', 'Orden', 'Estado', 'Cliente', 'Naviera', 'Punto de partida', 'Puerto',
    'Destino final', 'Transporte', 'Contenedor', 'Fecha recolección', 'Fecha embarque',
    'Inicio', 'Fin', 'Km estimados', 'Horas estimadas', 'Km reales', 'Horas reales',
    'Observaciones', 'Piloto', 'Placa', 'Registrado por',
];

/** Se añaden al final solo para PRODUCT_ROLES. */
private const array TRIP_PRODUCT_HEADERS = ['Productos', 'Total de cajas'];

/** @var list<UserRole> */
private const array PRODUCT_ROLES = [
    UserRole::Administrator, UserRole::Manager, UserRole::Export, UserRole::Shipment,
];
```

### Fila del reporte

La fila se arma **desde el modelo `Trip`**, no desde `TripListResource`. Ese Resource no trae `clientName`, `destination` ni `transport`, y cambiarle la forma rompería el listado.

| Columna | Origen | Formato en la celda |
|---|---|---|
| Id | `id` | número |
| Orden | `order` | texto |
| Estado | `status` | `Pendiente` / `En ruta` / `Finalizado` (`STATUS_LABELS`, ya existe) |
| Cliente | `client->name` | texto |
| Naviera | `shippingLine->name` | texto |
| Punto de partida | `departurePoint->name` | texto |
| Puerto | `location->name` | texto |
| Destino final | `destination` | texto |
| Transporte | `transport` | texto |
| Contenedor | `container` | texto |
| Fecha recolección / Fecha embarque / Inicio / Fin | `recolection_date`, `ship_date`, `start_date`, `end_date` | `d-m-Y h:i:s A`, vacía si `null` |
| Km estimados / Horas estimadas / Km reales / Horas reales | `estimated_*`, `traveled_*` | número (`float`), vacía si `null` |
| Observaciones | `observations` | texto |
| Piloto | `pilot->name` | texto, vacía si no hay |
| Placa | `vehicle->plate` | texto, vacía si no hay |
| Registrado por | `registeredBy->name` | texto |
| Productos *(solo PRODUCT_ROLES)* | `finishedProducts[].finishedProduct->code` + `boxes` | `CODE × N cajas; CODE × N cajas`, ordenado por `id` de la línea; vacía si no hay líneas |
| Total de cajas *(solo PRODUCT_ROLES)* | Σ `finishedProducts[].boxes` | número; `0` si no hay líneas |

### Carga de relaciones

`getTrips()` ya trae `LIST_RELATIONS`. Sobre la colección resultante, `ReportService` completa lo que falta con **una consulta por relación**, nunca por viaje:

- `client` (con `withTrashed()` si la relación de `Trip` ya lo hace; si no, tal cual) para todos los roles.
- `finishedProducts.finishedProduct` solo para `PRODUCT_ROLES`. `finishedProduct()` ya lee `withTrashed()`.

### Respuesta HTTP

```
HTTP/1.1 200 OK
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="viajes-2026-09-01_2026-09-30.xlsx"
```

Sin sobre JSON, sin `Cache-Control` especial. Los errores sí van por el sobre `{ statusCode, message, data }`.

---

## Plan de implementación

1. **Contrato.** Añadir `downloadTrips(User $user, array $filters): array` a `app/Interfaces/Report/ReportServiceInterface.php`, con el PHPDoc del modelo de datos. En `ReportService`, un esqueleto con `#[Override]` que lanza `BadRequestError`. La suite sigue verde porque nadie lo llama aún.
2. **Columnas base en `ReportService::downloadTrips()`.**
   - Quitar `limit` con `withoutLimit()` y llamar a `getTrips()`.
   - Si `count() > $this->maxRows`, lanzar `BadRequestError` con «El reporte excede 5000 viajes; acota el rango de fechas».
   - Cargar `client` sobre la colección.
   - Mapear las 22 columnas desde el modelo con `TRIP_REPORT_HEADERS`.
   - Escribir con `SpreadsheetWriterInterface` y devolver `{ fileName, contents }`.
   - Unit test en `tests/Unit/ReportServiceTest.php`: encabezados, una fila con cada formato, rango vacío que solo trae encabezados y el 400 con `maxRows` pequeño.
3. **Columnas de productos.**
   - Constantes `TRIP_PRODUCT_HEADERS` y `PRODUCT_ROLES`.
   - Solo para esos roles: `load('finishedProducts.finishedProduct')` y las dos celdas extra.
   - Unit test: los cuatro roles traen 24 columnas; `carrier` y `user`, 22. El texto `CODE × N cajas; …` ordenado por línea, «Total de cajas», un SKU borrado que sigue saliendo y un viaje sin líneas que da celda vacía y `0`.
4. **Capa HTTP.**
   - `app/Http/Requests/Report/ExportTripsReportRequest.php`: `dateFrom`/`dateTo` con `messages()` en español.
   - `app/Http/Controllers/ReportController.php`:
     - Método `trips(ExportTripsReportRequest, ReportServiceInterface)`.
     - `try/catch` → `ResponseHandler::error()`; en éxito, `response($contents, 200, [...])` con `Content-Type` y `Content-Disposition`.
   - `routes/reports.php`: prefijo `reports`, `name('reports.')`, `GET /trips` con `jwt.auth` + `role:` `allExcept(Pilot)`. Incluirlo desde `routes/api.php`.
5. **Tests Feature** en `tests/Feature/ReportTest.php`:
   - 401 sin token y 403 al `pilot`.
   - 422 sin `dateFrom`, sin `dateTo`, con formato inválido y con `dateTo < dateFrom`.
   - 200 con las cabeceras exactas; el binario se lee con el `Reader` de OpenSpout.
   - Solo viajes del rango (bordes inclusivos).
   - Ámbito: el `carrier` no ve viajes de otra empresa; `user` y `shipment` ven todos.
   - Matriz de columnas para los seis roles.
   - Un filtro opcional aplicado (`status`) y un filtro inválido ignorado.
   - 400 por exceso, con el `maxRows` rebindeado bajo en el contenedor.
   - Número fijo de consultas con `DB::getQueryLog()`: no crece con N viajes ni con N líneas de productos.
6. **OpenAPI.**
   - Atributos en `ReportController` y `ExportTripsReportRequest`.
   - Respuesta 200 con `content: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, formato `binary`, más 400/401/403/422.
   - `php artisan l5-swagger:generate`.
7. **Documentación.**
   - `CLAUDE.md`: `Report` deja de ser «sin ninguna capa HTTP»; nueva sección de la ruta y de la matriz por rol.
   - `references/reports-api.md` con `references/zones-api.md` como plantilla.
   - `vendor/bin/pint --dirty --format agent`.

---

## Criterios de aceptación

**Ruta y acceso**

- [x] `php artisan route:list --path=api/reports` muestra una sola ruta: `GET api/reports/trips`, con nombre `reports.trips`.
- [x] Sin token, la ruta responde 401 con el sobre JSON.
- [x] Un `pilot` recibe 403 con el sobre JSON.
- [x] `administrator`, `manager`, `carrier`, `export`, `user` y `shipment` reciben 200.

**Validación**

- [x] Sin `dateFrom` o sin `dateTo`, 422.
- [x] `dateFrom=2026-13-01` o `dateFrom=01-09-2026`, 422.
- [x] `dateTo` anterior a `dateFrom`, 422.
- [x] `dateFrom` igual a `dateTo`, 200.
- [x] `?status=basura` se ignora y responde 200 con los viajes del rango sin filtrar por estado.
- [x] `?limit=5` se ignora: el archivo trae todos los viajes del rango.

**Respuesta**

- [x] La respuesta 200 lleva `Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.
- [x] La respuesta 200 lleva `Content-Disposition: attachment; filename="viajes-{dateFrom}_{dateTo}.xlsx"` con las fechas de la petición.
- [x] El cuerpo se abre con el `Reader` de OpenSpout y la primera fila son los encabezados en el orden del modelo de datos.
- [x] Tras la petición, el disco falso no contiene ningún archivo bajo `reports/`.
- [x] Un rango sin viajes responde 200 con un archivo que solo trae la fila de encabezados.

**Contenido**

- [x] Un viaje con `recolection_date` el día `dateFrom` a las 00:00:00 aparece.
- [x] Un viaje con `recolection_date` el día `dateTo` a las 23:59:59 aparece.
- [x] Un viaje del día anterior a `dateFrom` no aparece, y uno del día siguiente a `dateTo` tampoco.
- [x] Los viajes borrados no aparecen.
- [x] El orden de las filas es `recolection_date desc, id desc`.
- [x] El estado sale como `Pendiente`, `En ruta` o `Finalizado`.
- [x] Las fechas salen en `d-m-Y h:i:s A`.
- [x] Km y horas son celdas numéricas; un `null` es una celda vacía.
- [x] Un viaje en la bolsa trae vacías las celdas de Piloto y Placa.

**Ámbito**

- [x] Un `carrier` no recibe viajes asignados por otra empresa y sí los de la bolsa libre.
- [x] `user` y `shipment` reciben viajes de todas las empresas.

**Matriz de columnas**

- [x] `administrator`, `manager`, `export` y `shipment` reciben 24 columnas, con «Productos» y «Total de cajas» al final.
- [x] `carrier` y `user` reciben 22 columnas, sin ninguna de las dos de productos.
- [x] Un viaje con dos líneas de productos muestra `CODE1 × 120 cajas; CODE2 × 40 cajas` en el orden de `id` de la línea, y `160` en Total de cajas.
- [x] Un producto terminado borrado después sigue saliendo con su `code`.
- [x] Un viaje sin líneas muestra la celda de Productos vacía y `0` en Total de cajas.

**Tope de filas y rendimiento**

- [x] Con `maxRows = 2` y tres viajes en el rango, responde 400 con «El reporte excede 5000 viajes; acota el rango de fechas».
  - El mensaje usa la constante `MAX_ROWS`, no el valor inyectado.
  - Después del 400, `SpreadsheetWriterInterface` no se invocó.
- [x] El número de consultas registradas por `DB::getQueryLog()` es el mismo con 1 y con 10 viajes, y con 1 y con 5 líneas de productos por viaje.

**Lo que no debe cambiar**

- [x] `ReportService::exportTrips()` y la tool `export_trips` no cambian: sus tests existentes pasan sin tocarse.
- [x] `TripListResource` no cambia: sus tests existentes pasan sin tocarse.

**Entregables**

- [x] `api-docs.json` regenerado contiene `/api/reports/trips` con la respuesta 200 binaria y los códigos 400/401/403/422.
- [ ] `php artisan test --compact` pasa completo. **Nota:** 3751/3752; la única falla (`TripTimeoutTest` › «no toca ninguna parada al iniciar el viaje») es previa a esta rama: hace `/start` sin carga de combustible confirmada (guarda de SPEC 27).

---

## Decisiones tomadas y descartadas

**Entrega del archivo**

- **Sí:** el `.xlsx` sale en binario en la respuesta, con `Content-Disposition: attachment`. Es una descarga real; el front solo hace `blob()`.
- **No:** subirlo a `reports/{uuid}.xlsx` y devolver la URL, como la tool. Dejaría archivos públicos, permanentes y sin purga por cada descarga. La tool lo hace solo porque el SDK de IA únicamente admite strings como resultado.

**Ruta y dominio**

- **Sí:** `GET /api/reports/trips` en `routes/reports.php`. `Report` ya es el dominio de las exportaciones y deja sitio para otros reportes.
- **No:** `GET /api/trips/export` como ruta fija en `routes/trips.php`. Sumaría una ruta más a un archivo que ya declara diecisiete y dispersaría las exportaciones por dominio.

**Fechas y filtros**

- **Sí:** `dateFrom` y `dateTo` obligatorios, `Y-m-d`, sobre `recolection_date`. Es la columna por la que ya filtran y ordenan `GET /api/trips` y el tablero.
- **No:** un rango máximo de días. Por decisión del usuario, de momento no lo hay; el freno real es `MAX_ROWS`.
- **Sí:** los demás filtros de `GET /api/trips`, opcionales y tolerantes. Salen gratis al llamar a `getTrips()`.

**Tope de filas**

- **Sí:** 400 con más de 5000 viajes, **antes** de escribir el archivo. Un archivo cortado sin aviso visible es peor que un error claro.
- **No:** truncar con una cabecera `X-Report-Truncated`. El navegador descarga igual y nadie lee la cabecera.
- **Sí:** reusar `ReportService::MAX_ROWS`, sin constante propia. Un solo tope para los dos caminos de exportación.

**Matriz por rol**

- **Sí:** columnas base para todo rol autorizado, y productos solo para `administrator`, `manager`, `export` y `shipment`. Es decisión del usuario.
  - `carrier` y `user` quedan fuera de productos aunque `GET /api/trip-finished-products` sí les responda. El reporte tiene su propia matriz, no hereda la de cada endpoint.
- **No:** galones, viáticos ni costo. El costo exigiría un cálculo por lote en `TripCost`, porque `getTripCost()` hace unas 6 consultas por viaje. Quien lo necesite va al detalle o pregunta al asistente.
- **Sí:** el `pilot` recibe 403 desde el middleware, no desde el service.

**Construcción de la fila**

- **Sí:** armar la fila desde el modelo `Trip`. `TripListResource` no trae cliente, destino final ni transporte, y ampliarlo cambiaría el contrato del listado.
- **No:** `TripResource`. Decodifica polilíneas y carga ocho relaciones por viaje; es pagar de más para un Excel.
- **Sí:** completar relaciones con `load()` sobre la colección, una consulta por relación. `getTrips()` no cambia su firma ni sus `LIST_RELATIONS`.

**Qué no se toca**

- **Sí:** método nuevo `downloadTrips()` en lugar de modificar `exportTrips()`. La tool conserva su contrato y sus tests; alinear la tool con la matriz por rol va en otra spec, por decisión del usuario.
- **Sí:** un rango sin viajes da 200 con solo encabezados, no 404. Un periodo sin actividad es un resultado legítimo.

---

## Riesgos identificados

| Riesgo | Mitigación |
|---|---|
| Un rango amplio carga hasta 5000 viajes con sus relaciones antes del `count()` que decide el 400. El 400 llega tarde y caro, porque `getTrips()` no ofrece un conteo previo. | Se acepta de momento. Si pesa en producción, se añade un conteo al contrato de `Trip` en otra spec. Como no hay rango máximo, el usuario puede provocarlo. |
| Memoria y tiempo de la petición con 5000 filas: el writer arma todo en memoria y devuelve bytes. | OpenSpout escribe en flujo sobre un `tempnam()`. 5000 filas × 24 columnas es del orden de 1 MB. Si hace falta más, el siguiente paso es cola o `StreamedResponse`, fuera de esta spec. |
| Un middleware o el manejo de errores de `bootstrap/app.php` fuerza JSON sobre `api/*` y rompe la respuesta binaria. | El test Feature valida las cabeceras exactas y abre el cuerpo con el `Reader` de OpenSpout. Si falla, se ve en la suite. |
| La matriz de columnas por rol vive en el service y no se ve desde las rutas, igual que la regla de `mileage` en SPEC 13. | Constante `PRODUCT_ROLES` con nombre explícito, documentada en `CLAUDE.md` y en el Swagger. El test recorre los seis roles. |
| La tool `export_trips` y el endpoint divergen: la tool no distingue roles y trae otras 17 columnas. | Queda declarado como deuda en «Fuera de alcance». La próxima spec alinea la tool con la matriz. |

---

## Lo que **no** está en esta spec

- Cambios a la tool `export_trips` del asistente y a `ReportService::exportTrips()`.
- Galones, viáticos, costo del viaje y cualquier columna de dinero.
- Rango máximo de fechas.
- Otros reportes descargables (gastos de vehículo, etc.).
- Formatos distintos de `.xlsx`.
- Generación asíncrona, cola, archivo en el bucket o historial de reportes.

Cada uno de esos, si llega, va en su propia spec.
