# SPEC 06 — Catálogo de precios de combustible

> **Estado:** Aprobado
> **Depende de:** SPEC 01, SPEC 03
> **Fecha:** 2026-08-06
> **Objetivo:** Mantener un catálogo nacional de precios de combustible en GTQ por galón, con un único precio vigente por tipo que el `administrator` registra manualmente y que cualquier usuario autenticado puede consultar, quedando el resto del histórico congelado como inactivo.

Depende de **SPEC 01** por el guard JWT y el `User` al que apunta `registered_by`, y de **SPEC 03** porque de ahí sale el middleware `role`, que es lo que restringe la escritura al `administrator`. No depende de SPEC 04: un precio nacional no se relaciona con vehículos ni con empresas.

---

## Alcance

**Dentro:**

- Enum `App\Enums\FuelType` con `Regular`, `Premium`, `Diesel` y `DieselPremium` (valores en base: `regular`, `premium`, `diesel`, `diesel_premium`).
- Enum `App\Enums\FuelPriceStatus` con `Active` e `Inactive` (valores en base: `active`, `inactive`).
- Migración `fuel_prices`: `id`, `fuel_type`, `price` (`decimal(8, 2)`, GTQ por galón), `status` (default `active`), `registered_by` (FK a `users`), `timestamps`. Sin `deleted_at` y sin índice único.
- Modelo `FuelPrice` con factory, relación `registeredBy()`, y estados de factory `active()` e `inactive()`.
- Cadena de capas completa en la subcarpeta `FuelPrice/`: `FuelPriceServiceInterface`, `FuelPriceService`, `FuelPriceProvider`, `StoreFuelPriceRequest`, `UpdateFuelPriceRequest`, `CurrentFuelPriceRequest`, `FuelPriceResource` y `FuelPriceController`.
- `routes/fuel_prices.php` incluido desde `routes/api.php`, con las rutas fijas (`/current`, `/{fuelPrice}/deactivate`) declaradas **antes** del `apiResource`.
- **Un solo precio vigente por tipo.** Al registrar un precio, el `active` de ese mismo tipo —si existe— pasa a `inactive` en la misma transacción. Los cuatro tipos son independientes entre sí.
- **Lectura abierta a cualquier usuario autenticado** (`administrator`, `carrier`, `pilot`, `manager`), sin `carrier.required`: es un dato nacional, no de una empresa.
- **Escritura restringida a `administrator`** con `role:administrator` en `store`, `update`, `deactivate` y `destroy`.
- `GET /api/fuel-prices/current` con `fuelType` **obligatorio** en la query: devuelve el único precio `active` de ese tipo, o `404 NotFoundError` si el tipo no tiene vigente. Un `fuelType` ausente o fuera del enum es 422.
- **El histórico es intocable.** `update`, `deactivate` y `destroy` solo operan sobre una fila `active`; intentarlo sobre una `inactive` es `400 BadRequestError`. No existe reactivación por ninguna vía.
- `PATCH /api/fuel-prices/{fuelPrice}` acepta **únicamente `price`**. Ni `fuelType` ni `status` viajan nunca en el body.
- `PATCH /api/fuel-prices/{fuelPrice}/deactivate` pasa la fila `active` a `inactive` y deja ese tipo **sin precio vigente**, hasta que se registre uno nuevo.
- **`DELETE` es borrado real** (`delete()`, no baja lógica). La fila desaparece y el tipo queda sin vigente; ninguna fila `inactive` asciende para reemplazarla.
- `registered_by` se toma del usuario autenticado (`auth('api')->user()`), nunca del body. No cambia en el `update`: sigue apuntando a quien lo dio de alta.
- Filtros opcionales en `index`: `fuelType` y `status` (valor inválido en cualquiera se ignora, como en SPEC 04). Orden por defecto `created_at DESC, id DESC`.
- Paginación opcional por `limit` con `PaginatedResource`, acotada a `[10, 100]`, exactamente como en SPEC 03 y SPEC 04.
- `FuelPriceResource` en camelCase: `id`, `fuelType`, `price`, `status`, `registeredByName` y `createdAt`.
- Tests Pest delegados al agente `feature-tests` y documentación Swagger al agente `endpoint-docs`.

**Fuera de alcance (para specs futuras):**

- **Columna `effective_date`.** Al fijarse con `now()` y no ser editable, sería idéntica a `created_at`. La fecha de vigencia es `created_at`.
- **Reactivar un precio inactivo.** Si un precio vuelve a su valor anterior, se registra uno nuevo con el mismo monto.
- **Editar o borrar filas inactivas.** El histórico es de solo lectura una vez desplazado.
- **Precio por región o departamento.** El precio es nacional y único. Si algún día se regionaliza, es otra spec con su propia columna y su propia clave de vigencia.
- **Moneda y unidad configurables.** GTQ y galón son convenciones del dominio, no columnas; se documentan en Swagger.
- **Registro de tanqueos** (galones cargados, odómetro, estación, monto real pagado por vehículo). Depende del dominio de viajes, que todavía no existe.
- **Cálculo de costo de combustible por viaje o rendimiento km/galón.** Esta spec solo publica el precio; nadie lo consume aún.
- **Importar precios desde el boletín del MEM**, por CSV o por scraping. La captura es manual.
- **Precios programados a futuro.** Un precio entra en vigencia en el instante en que se crea.
- **Notificaciones o correos** al cambiar un precio.
- **Auditoría de cambios de `price`.** El `PATCH` sobrescribe el monto sin dejar rastro del anterior; solo se conserva quién dio de alta la fila.
- **Rango de fechas (`from`/`to`) y búsqueda por texto** en `index`.
- **Histórico de precios en un solo endpoint agrupado por tipo** (ej. serie temporal para graficar).

---

## Modelo de datos

### 1. Enums

```php
// app/Enums/FuelType.php
enum FuelType: string
{
    case Regular = 'regular';
    case Premium = 'premium';
    case Diesel = 'diesel';
    case DieselPremium = 'diesel_premium';
}

// app/Enums/FuelPriceStatus.php
enum FuelPriceStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
```

Los valores viajan en inglés en la API; la traducción al español es del front, como con `UserRole` y `VehicleType`.

### 2. Tabla `fuel_prices`

```php
Schema::create('fuel_prices', function (Blueprint $table) {
    $table->id();
    $table->string('fuel_type');
    $table->decimal('price', 8, 2);                                  // GTQ por galón
    $table->string('status')->default(FuelPriceStatus::Active->value);
    $table->foreignId('registered_by')->constrained('users');
    $table->timestamps();

    $table->index(['fuel_type', 'status']);
});
```

`price` es `decimal(8, 2)` en **quetzales por galón**. Ni la moneda ni la unidad se guardan en base: son convención del dominio y van documentadas en Swagger.

El índice compuesto `(fuel_type, status)` es la consulta caliente: `/current` y la desactivación del anterior en cada `store` buscan exactamente por ese par. **No es único**: la unicidad de «un solo activo por tipo» es una regla del service, igual que la placa en SPEC 04, porque un índice único sobre `(fuel_type, status)` haría imposible tener dos filas inactivas del mismo tipo.

`registered_by` usa `constrained('users')` sin `cascadeOnDelete`: el proyecto no borra usuarios, y si algún día lo hiciera, la FK debe frenar el borrado antes que perder la trazabilidad del precio.

### 3. Modelo

```php
#[Fillable(['fuel_type', 'price', 'status', 'registered_by'])]
class FuelPrice extends Model
{
    public function registeredBy(): BelongsTo;   // usuario administrador que lo capturó

    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'status' => FuelPriceStatus::class,
            'price' => 'decimal:2',
        ];
    }
}
```

`registeredBy()` es `belongsTo(User::class, 'registered_by')` — la FK no sigue la convención `user_id` porque el nombre describe el rol de la relación, no la tabla.

La factory nace `active` con un precio realista y un `User` administrador; añade los estados `active()` e `inactive()` para armar históricos en los tests.

**Ninguna relación inversa en `User`.** Un usuario no necesita listar los precios que capturó; añadirla sería API que nadie llama.

### 4. Resource

```php
// FuelPriceResource
[
    'id',
    'fuelType',           // 'regular' | 'premium' | 'diesel' | 'diesel_premium'
    'price',              // string con dos decimales, GTQ por galón
    'status',             // 'active' | 'inactive'
    'registeredByName',   // desde la relación registeredBy
    'createdAt',          // instante en que el precio entró en vigencia
]
```

`registeredByName` sale de `$this->registeredBy->name`; el service carga la relación con `with('registeredBy')` para no provocar N+1 en los listados.

`createdAt` es la fecha de vigencia. No hay columna `effective_date`: sería idéntica a esta.

### 5. Validación

```php
// StoreFuelPriceRequest
'fuelType' => ['required', Rule::enum(FuelType::class)],
'price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
// status NO se acepta: nace siempre en 'active'
// registeredBy NO se acepta: sale del usuario autenticado

// UpdateFuelPriceRequest
'price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
// y nada más: ni fuelType ni status

// CurrentFuelPriceRequest — valida la query, no el body
'fuelType' => ['required', Rule::enum(FuelType::class)],
```

`price` en el `PATCH` es `required`, no `sometimes`: es el único campo del body, así que un `PATCH` vacío es un error del cliente, no una operación sin efecto.

El body y la query hablan **camelCase** (`fuelType`), igual que el filtro `carrierId` de SPEC 04; el service traduce a `fuel_type` al persistir.

`CurrentFuelPriceRequest` existe para que el `required` del `fuelType` produzca un 422 con mensaje en español, en vez de un 404 confuso resuelto dentro del service.

### 6. Reglas de negocio del service

- **Un solo activo por tipo.** `create()` corre en `DB::transaction()`: desactiva el `active` de ese `fuel_type` —si existe— y después inserta la fila nueva como `active`. Sin transacción, un fallo intermedio deja el tipo con cero o con dos vigentes. La consulta del activo usa `lockForUpdate()` para que dos altas simultáneas del mismo tipo no dejen dos filas vigentes.
- **`registered_by`** se toma del `User` autenticado que recibe el service por parámetro, nunca del body. El `update` no lo reescribe.
- **Solo se opera sobre lo activo.** `update`, `deactivate` y `destroy` comparten una guarda: si la fila no existe es `NotFoundError` (404); si existe pero está `inactive`, es `BadRequestError` (400) con «Solo se puede modificar el precio vigente».
- **`update`** cambia únicamente `price`. No toca `status`, ni `fuel_type`, ni `registered_by`.
- **`deactivate`** pasa la fila a `inactive` y devuelve el recurso actualizado. Ese tipo queda sin vigente hasta el próximo `store`. **Ninguna fila inactiva asciende.**
- **`destroy`** ejecuta un `delete()` real. La fila desaparece, ese tipo queda sin vigente y ninguna fila inactiva asciende. Devuelve el recurso ya borrado en memoria, para que la respuesta diga qué se eliminó.
- **`getCurrent`** busca la única fila `active` del `fuel_type` pedido. Si no hay, lanza `NotFoundError` con «No existe un precio vigente para el combustible indicado».
- **Filtros de `index`:** `fuelType` y `status` solo se aplican si son valores válidos de su enum; cualquier otro valor se ignora sin error, como en SPEC 04. Orden fijo `created_at DESC, id DESC`.
- **`limit`:** idéntico a SPEC 03 y SPEC 04 — ausente o no numérico devuelve la colección completa; numérico pagina, acotado a `[10, 100]`.

### 7. Contrato HTTP

| Método y ruta | Acción | Rol |
|---|---|---|
| `GET /api/fuel-prices` | `index` | cualquier autenticado |
| `GET /api/fuel-prices/current?fuelType=` | `current` | cualquier autenticado |
| `POST /api/fuel-prices` | `store` | administrator |
| `GET /api/fuel-prices/{fuelPrice}` | `show` | cualquier autenticado |
| `PATCH /api/fuel-prices/{fuelPrice}` | `update` | administrator |
| `PATCH /api/fuel-prices/{fuelPrice}/deactivate` | `deactivate` | administrator |
| `DELETE /api/fuel-prices/{fuelPrice}` | `destroy` | administrator |

Query params de `index`: `fuelType`, `status` y `limit`. Ninguna ruta lleva `carrier.required`.

`/current` y `/{fuelPrice}/deactivate` se declaran **antes** del `apiResource`, siguiendo la convención del proyecto. El archivo replica la forma de `routes/vehicles.php`: `apiResource('/', ...)->parameters(['' => 'fuelPrice'])` con `middlewareFor` por acción.

---

## Plan de implementación

Cada paso deja el sistema arrancable y es commiteable por sí solo.

1. **Enums.** `app/Enums/FuelType.php` y `app/Enums/FuelPriceStatus.php` con los valores del modelo de datos. *Verificación:* `php artisan tinker --execute 'echo App\Enums\FuelType::DieselPremium->value;'` imprime `diesel_premium`.

2. **Tabla y modelo `FuelPrice`.** `php artisan make:model FuelPrice -mf --no-interaction`. Migración con las seis columnas, default `active` en `status`, FK `registered_by` a `users` e índice compuesto no único `(fuel_type, status)`. Modelo con `#[Fillable]`, casts de `fuel_type`, `status` y `price`, y relación `registeredBy()`. Factory que genera un tipo aleatorio del enum, un precio realista, `status = active` y un `User` administrador. *Verificación:* `php artisan migrate` corre limpio y `FuelPrice::factory()->create()->registeredBy` devuelve un `User`.

3. **Estados de la factory.** `active()` e `inactive()` en `FuelPriceFactory`, para montar históricos en los tests sin escribir `status` a mano. *Verificación:* `FuelPrice::factory()->inactive()->create()->status` devuelve `FuelPriceStatus::Inactive`.

4. **Resource.** `app/Http/Resources/FuelPrice/FuelPriceResource.php` con los seis campos en camelCase y `registeredByName` desde la relación. *Verificación:* la suite existente sigue verde.

5. **Contrato del service.** `app/Interfaces/FuelPrice/FuelPriceServiceInterface.php` con los siete métodos y su PHPDoc de array shapes. Sin implementación.

6. **Service, lecturas.** `app/Services/FuelPrice/FuelPriceService.php` con `getFuelPrices(array $filters)`, `getFuelPriceById(int $id)` y `getCurrentByType(string $fuelType)`. `getFuelPrices` arranca de `FuelPrice::with('registeredBy')`, aplica `fuelType` y `status` solo si son válidos, ordena por `created_at DESC, id DESC` y pagina o no según `limit`. `getFuelPriceById` lanza `NotFoundError` si no existe. `getCurrentByType` busca el único `active` de ese tipo y lanza `NotFoundError` si no hay. *Verificación:* con datos sembrados en tinker, `getCurrentByType('diesel')` devuelve la fila activa.

7. **Service, alta.** `create(User $user, array $data)` dentro de `DB::transaction()`: desactiva con `lockForUpdate()` el `active` de ese `fuel_type` si existe y crea la fila nueva como `active` con `registered_by` del usuario. *Verificación:* dos altas seguidas del mismo tipo dejan una fila `active` y una `inactive`.

8. **Service, guarda de lo activo.** Método privado que resuelve la fila por id, lanza `NotFoundError` si no existe y `BadRequestError` si su `status` es `inactive`. Es la pieza que consumen `update`, `deactivate` y `destroy`.

9. **Service, escrituras restantes.** `update(int $id, array $data)` sobre `price`; `deactivate(int $id)` que pasa a `inactive`; `destroy(int $id)` que hace `delete()` real y devuelve el modelo en memoria. Los tres pasan por la guarda del paso 8. *Verificación:* un `PATCH` sobre una fila inactiva responde 400 y la fila no cambia.

10. **Provider.** `app/Providers/FuelPrice/FuelPriceProvider.php` con el `bind(FuelPriceServiceInterface::class, FuelPriceService::class)`, registrado en `bootstrap/providers.php`. *Verificación:* `app(FuelPriceServiceInterface::class)` resuelve a `FuelPriceService`.

11. **FormRequests.** `StoreFuelPriceRequest`, `UpdateFuelPriceRequest` y `CurrentFuelPriceRequest` en `app/Http/Requests/FuelPrice/`, con `messages()` en español.

12. **Controller.** `app/Http/Controllers/FuelPriceController.php` con las siete acciones, el service inyectado **por parámetro de cada método**, `try/catch` a `ResponseHandler` y ninguna regla de negocio. Solo `store` resuelve el usuario con `auth('api')->user()`.

    **Corrección durante la implementación.** Este paso decía originalmente que `store`, `update`, `deactivate` y `destroy` resolvían el usuario con `auth('api')->user()`, y contradecía a las firmas del contrato que fijan los pasos 5, 7 y 9: solo `create(User $user, array $data)` recibe un `User`. Se implementó lo segundo, que es lo que sostiene el resto de la spec: en las otras tres acciones el usuario no tendría uso, porque la autorización la resuelve el middleware `role:administrator` y la sección 6 exige que el `update` **no** reescriba `registered_by`. Pasarlo habría sido un parámetro muerto que invita a usarlo.

13. **Rutas.** `routes/fuel_prices.php` con el prefijo `fuel-prices`, `jwt.auth` en el grupo, `/current` y `/{fuelPrice}/deactivate` **antes** del `apiResource`, y `role:administrator` en las cuatro acciones de escritura. `require` en `routes/api.php`. *Verificación:* `php artisan route:list --path=fuel-prices` lista las siete rutas en ese orden.

    El prefijo de la ruta es `fuel-prices` (guion) mientras el archivo es `routes/fuel_prices.php` (guion bajo): la URL sigue la convención kebab-case de la API y el archivo la snake_case del proyecto.

14. **Formato.** `vendor/bin/pint --dirty --format agent`.

15. **Tests y documentación.** Disparar el agente `feature-tests` para el Feature test HTTP y el Unit test del service, y el agente `endpoint-docs` para los atributos `OpenApi\Attributes` y `php artisan l5-swagger:generate`.

---

## Criterios de aceptación

**Alta y vigencia**

- [ ] `POST /api/fuel-prices` con `fuelType: diesel` y `price: 34.50` responde 201 y la fila nace con `status: active`.
- [ ] Registrar un segundo precio de `diesel` deja el primero en `inactive` y el segundo en `active`.
- [ ] Registrar un precio de `diesel` no cambia el `status` de ningún precio de `regular`, `premium` ni `diesel_premium`.
- [ ] Tras cualquier secuencia de altas, hay como máximo una fila `active` por cada valor de `FuelType`.
- [ ] `registeredBy` apunta al usuario autenticado aunque el body traiga un `registeredBy` distinto.
- [ ] El body no puede fijar `status`: enviar `status: inactive` en el alta produce igualmente una fila `active`.

**Consulta**

- [ ] `GET /api/fuel-prices/current?fuelType=diesel` devuelve la única fila `active` de ese tipo.
- [ ] `GET /api/fuel-prices/current` sin `fuelType` responde 422.
- [ ] `GET /api/fuel-prices/current?fuelType=gasolina` responde 422.
- [ ] `GET /api/fuel-prices/current?fuelType=premium` responde 404 cuando `premium` no tiene ninguna fila `active`.
- [ ] `GET /api/fuel-prices` devuelve el histórico ordenado con lo más reciente primero.
- [ ] `GET /api/fuel-prices?limit=10` devuelve `total`, `currentPage` y `lastPage` en la raíz del sobre, no bajo `meta`.
- [ ] `GET /api/fuel-prices?limit=abc` devuelve la colección completa sin paginar.
- [ ] `GET /api/fuel-prices?fuelType=diesel&status=inactive` devuelve solo filas que cumplen ambos filtros.
- [ ] `GET /api/fuel-prices?status=vencido` ignora el filtro y responde 200.

**Modificación y baja**

- [ ] `PATCH /api/fuel-prices/{id}` con `price: 35.00` sobre la fila activa responde 200 y el precio cambia.
- [ ] `PATCH` sobre una fila `inactive` responde 400 y la fila no cambia.
- [ ] `PATCH` con `fuelType` o `status` en el body los ignora: solo cambia `price`.
- [ ] `PATCH` con el body vacío responde 422.
- [ ] `PATCH /api/fuel-prices/{id}/deactivate` sobre la fila activa la deja en `inactive`.
- [ ] Tras desactivar el activo de `diesel`, `GET /api/fuel-prices/current?fuelType=diesel` responde 404.
- [ ] Tras desactivar el activo de `diesel`, ninguna fila `inactive` de `diesel` pasó a `active`.
- [ ] `PATCH /{id}/deactivate` sobre una fila `inactive` responde 400.
- [ ] `DELETE /api/fuel-prices/{id}` sobre la fila activa responde 200 y la fila ya no existe en la base.
- [ ] Tras el `DELETE` del activo de `diesel`, ninguna fila `inactive` de `diesel` pasó a `active`.
- [ ] `DELETE` sobre una fila `inactive` responde 400 y la fila sigue existiendo.
- [ ] Cualquier operación sobre un id inexistente responde 404.

**Autorización**

- [ ] Las siete rutas sin token responden 401 con el sobre estándar.
- [ ] `GET /api/fuel-prices`, `/current` y `/{id}` responden 200 con un token de `pilot`, de `carrier` y de `manager`.
- [ ] Ninguna ruta de lectura exige tener empresa: un `carrier` sin empresa las alcanza.
- [ ] `POST`, `PATCH`, `PATCH /deactivate` y `DELETE` responden 403 con un token de `carrier`, de `pilot` y de `manager`.

**Validación y forma de la respuesta**

- [ ] `POST` sin `price`, con `price: 0` o con `price: -1` responde 422.
- [ ] `POST` con `fuelType: super` responde 422.
- [ ] Los mensajes de validación están en español.
- [ ] Toda respuesta viaja en el sobre `{ statusCode, message, data }`.
- [ ] `FuelPriceResource` expone exactamente `id`, `fuelType`, `price`, `status`, `registeredByName` y `createdAt`, en camelCase.
- [ ] `price` sale con dos decimales.

**Integración**

- [ ] `php artisan route:list --path=fuel-prices` lista siete rutas y `current` aparece antes que `{fuelPrice}`.
- [ ] `php artisan test --compact` pasa toda la suite, incluidas las specs anteriores.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `php artisan l5-swagger:generate` regenera `storage/api-docs/api-docs.json` con los siete endpoints documentados.

---

## Decisiones

**Alcance**

- **Sí:** catálogo de precios de referencia. Es un dato nacional, autónomo, que no depende de ningún otro dominio.
- **No:** registro de tanqueos por vehículo (galones, odómetro, estación, monto real). Depende del dominio de viajes, que aún no existe, y arrastra sus propias decisiones. Va en su propia spec.
- **No:** consumir el boletín del MEM por scraping o por CSV. Mete una dependencia externa frágil sobre HTML que cambia sin avisar. La captura es manual; si algún día se automatiza, se apoya en el CRUD que deja esta spec.

**Vigencia**

- **Sí:** un `status` de dos valores con un único `active` por tipo. Es la forma más directa de responder «¿cuánto cuesta el diésel hoy?» sin comparar fechas.
- **No:** vigencia por rango de fechas (`valid_from` / `valid_to`). Resuelve lo mismo con más columnas y con la posibilidad de huecos y solapes.
- **Sí:** desactivar el anterior dentro de la misma transacción que crea el nuevo. Sin transacción, un fallo intermedio deja el tipo con cero o con dos vigentes.
- **No:** columna `effective_date`. Se fija con `now()` al crear y no es editable, así que es idéntica a `created_at`. La fecha de vigencia es `created_at`.
- **No:** precios programados a futuro. Obligarían a que `active` dejara de significar «rige hoy».

**El histórico es intocable**

- **Sí:** `update`, `deactivate` y `destroy` solo operan sobre la fila `active`. Una sola regla que recordar: sobre lo inactivo no se opera.
- **No:** reactivar un precio inactivo. Si un precio vuelve a su valor anterior, se registra uno nuevo con el mismo monto; reactivar rompería el orden cronológico del histórico.
- **No:** `fuelType` editable. Se consideró y se descartó: un solo `PATCH` podía dejar dos filas activas del mismo tipo. El `PATCH` acepta únicamente `price`.
- **No:** promover automáticamente el `inactive` más reciente cuando se borra o se desactiva el vigente. Se consideró y se descartó: resucitar un precio viejo es peor que quedarse sin vigente, porque el sistema respondería con un dato equivocado en vez de decir que no lo tiene.

**Baja**

- **Sí:** `DELETE` es borrado real. Se separó a propósito de `deactivate`: uno es «esto nunca debió registrarse», el otro es «esto ya no rige».
- **No:** baja lógica como en SPEC 04. Allí `inactive` es un estado operativo del vehículo y la fila debe seguir listándose; aquí `inactive` ya significa «histórico», así que un `DELETE` que solo desactivara sería indistinguible de `deactivate`.
- **Sí:** coexisten `store` (reemplaza) y `deactivate` (retira sin reemplazo). Se consideró exigir desactivar antes de registrar, y se descartó por costar dos llamadas en la operación más común.
- **Sí:** dejar el tipo sin precio vigente tras un `DELETE` o un `deactivate`. Quien lo consuma recibirá un 404 explícito, que es información útil.

**Autorización**

- **Sí:** lectura abierta a cualquier usuario autenticado, sin `carrier.required`. Un piloto que va a tanquear es justo quien necesita el precio, y filtrar por empresa no tiene sentido en un dato nacional.
- **Sí:** escritura solo para `administrator` con `role:administrator`.
- **Sí:** `show` del histórico también abierto. Es coherente con `index` abierto; restringirlo después es más caro que abrirlo ahora.
- **Sí:** `registered_by` desde `auth('api')->user()`, nunca del body. Un campo de auditoría que el cliente puede fijar no audita nada.

**Modelo**

- **Sí:** precio nacional único. Se consideró por departamento (22 filas por cada cambio) y por región del MEM, y se descartaron por coste de mantenimiento frente al uso real.
- **Sí:** GTQ y galón como convención del dominio, documentada en Swagger, no como columnas.
- **Sí:** `decimal(8, 2)`, misma familia que el `capacity` de SPEC 04.
- **Sí:** índice compuesto **no único** sobre `(fuel_type, status)`. Es la consulta caliente de `/current` y del `store`. Único no puede ser: haría imposible tener dos filas inactivas del mismo tipo.
- **No:** relación inversa `User::fuelPrices()`. Nadie necesita listar los precios que capturó un usuario.
- **Sí:** `CurrentFuelPriceRequest` propio para validar la query de `/current`. Sin él, un `fuelType` ausente acabaría en un 404 del service en vez de un 422 con mensaje en español.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| `/current` capturado por el comodín `{fuelPrice}` del `apiResource`, devolviendo un 404 en vez de un 422 | Se declara antes del `apiResource`, como manda la convención del proyecto, y hay un criterio de aceptación que verifica el orden en `route:list` |
| Dos `POST` simultáneos del mismo tipo dejan dos filas `active`: la transacción aísla, pero no bloquea la fila que se va a desactivar | La consulta del activo dentro de `create()` usa `lockForUpdate()`. Con un único administrador capturando precios el escenario es improbable, pero el bloqueo cuesta una línea |
| Borrar o desactivar el vigente deja el tipo sin precio, y un consumidor futuro se rompe | Es el comportamiento decidido, no un accidente: `/current` responde 404 explícito con mensaje en español, en vez de devolver un precio caducado |
| La FK `registered_by` sin `cascadeOnDelete` impide borrar un usuario que capturó precios | Deliberado: el proyecto no borra usuarios, y perder la trazabilidad del precio es peor que un borrado bloqueado. Si algún día se borran usuarios, se resuelve en esa spec |
| `price` viaja como string (`"34.50"`) por el cast `decimal:2`, y el front lo trata como número | Mismo comportamiento que `capacity` en SPEC 04, así que el front ya lo conoce. Queda documentado en el schema OpenAPI del Resource |
| El histórico crece sin límite y el `index` sin `limit` devuelve todo | Cuatro tipos y unos pocos cambios de precio al año: el volumen es despreciable durante años. La paginación por `limit` ya está disponible cuando deje de serlo |

---

## Lo que **no** entra en esta spec

- Registro de tanqueos por vehículo y cálculo de costo de combustible por viaje.
- Importar precios desde el boletín del MEM, por CSV o por scraping.
- Precio por región o departamento.
- Reactivar, editar o borrar filas inactivas.
- Columna `effective_date` y precios programados a futuro.
- Auditoría de cambios de `price`.
- Moneda o unidad configurables.
- Notificaciones al cambiar un precio.
- Rango de fechas, búsqueda por texto o series temporales agrupadas por tipo en `index`.

Cada una de esas, si aterriza, va en su propia spec.
