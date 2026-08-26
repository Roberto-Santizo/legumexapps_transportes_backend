# SPEC 21 — Tipo de destino

> **Estado:** Aprobado
> **Depende de:** SPEC 01, SPEC 15
> **Fecha:** 2026-08-26
> **Objetivo:** Añadir a los destinos una columna `type` con dos valores —`port` y `destination`—, obligatoria en el alta, editable en la edición y filtrable en el listado, sin que el tipo gobierne ninguna tarifa.

Depende de **SPEC 01** por el guard JWT, el enum `UserRole` y el middleware `role:`, y de **SPEC 15** por la tabla `locations`, el modelo `Location` y el dominio completo (`LocationService`, sus FormRequests, su Resource y sus rutas).

Es la segunda spec que **amplía un dominio ya publicado sin crear uno nuevo**, después de SPEC 13: no aparece ninguna tabla, ningún controller y ninguna ruta. Todo el trabajo es una migración aditiva sobre `locations`, un enum nuevo y la propagación de una columna por las capas que ya existen.

**Revierte una decisión escrita de SPEC 20.** Aquella spec descartó el discriminador por tipo —«`locations` no gana ninguna columna `type`»— porque un punto de partida podría colarse como destino cotizable. Esta spec no reabre esa decisión: `departure_points` sigue siendo una tabla aparte y sigue sin tocarse. Lo que se añade es un discriminador **dentro** del catálogo de destinos, entre destinos que ya son todos cotizables. Un puerto es un destino; un punto de partida no lo es, y esa sigue siendo la línea que separa las dos tablas.

**Lo que esta spec no es:** una regla de negocio. `type` es una etiqueta de catálogo: no cambia el precio, no cambia el ámbito por rol, no restringe qué tarifas se pueden crear y no aparece en la cotización.

---

## Alcance

**Dentro:**

- **Una migración aditiva sobre `locations`** con una columna nueva, `type`. No se crea ninguna tabla, no se borra ninguna columna y no se toca ninguna de las ocho que ya existen.
- **La columna nace con `default('destination')`**, para que las filas ya registradas sobrevivan a la migración sin backfill manual. Aquí el default **sí es un valor de negocio**, no relleno: todos los destinos que existen hoy son destinos ordinarios, y ese es exactamente su tipo. Es la diferencia con SPEC 13, donde `1` significaba «fila anterior a la spec».
- **Enum nuevo `App\Enums\LocationType`** con dos casos: `Port = 'port'` y `Destination = 'destination'`. Mismo patrón que `VehicleType`, `VehicleStatus` y `VehicleCondition`: enum de respaldo `string`, claves en TitleCase y valores en inglés. Traducir a pantalla es responsabilidad del cliente.
- `Location` amplía su `#[Fillable]` con `type` y suma el cast al enum; `LocationFactory` genera el tipo con `Destination` por defecto y un estado `port()` para los tests.
- **`type` es obligatorio en `POST /api/locations`.** El alta pasa de cuatro campos a cinco y omitirlo es **422**. Es un cambio incompatible para el cliente actual, asumido a propósito, igual que hizo SPEC 13 con el alta de vehículos.
- **`type` es editable en `PATCH /api/locations/{location}`** con la semántica parcial de siempre (`sometimes|required`): enviarlo vacío es 422, omitirlo no lo toca. **Sin ninguna restricción:** un destino con tarifas colgando puede pasar a `port` y volver, y el `PATCH` responde 200 sin avisar.
- **Filtro nuevo `type` en `GET /api/locations`**, sumado a los `status`, `search` y `limit` que ya existen. **Coincidencia exacta** contra el valor del enum y **tolerante**: un valor inválido se ignora y devuelve el listado completo, nunca una lista vacía ni un 422, exactamente como `status`.
- `LocationResource` expone `type` en camelCase —la clave se llama `type`— con el valor crudo del enum (`"port"` | `"destination"`), sin traducir y sin ocultarlo por rol. El recurso pasa de diez a once claves.
- El PHPDoc de `LocationServiceInterface` actualiza sus tres array shapes (`$filters`, el del alta y el de la edición).
- **La unicidad de `name` sigue siendo global**, no por tipo: el índice único de la columna no se toca y no puede existir `PUERTO QUETZAL` como puerto y como destino a la vez. Lo mismo con `google_place_id`.
- Tests Pest y documentación Swagger delegados a los agentes `feature-tests` y `endpoint-docs`.
- Actualizar `references/locations-api.md` con el campo, el filtro y el aviso del cambio incompatible en el alta.

**Fuera de alcance (para specs futuras):**

- **Que el tipo influya en el precio.** `FreightRate`, `GET /api/freight-rates/quote` y `FreightQuoteResource` **no cambian de forma ni de parámetros**, la cotización no devuelve el tipo y `resolveBand()` no lo mira. Un puerto se cotiza exactamente igual que cualquier otro destino.
- **Que el tipo restrinja qué tarifas se pueden crear.** `ensureLocationAndProductAreActive()` sigue mirando solo `status`; no se añade ninguna comprobación de tipo ni en el alta ni en la edición de una tarifa.
- **Impedir el cambio de tipo cuando el destino ya tiene tarifas.** Se descarta a propósito: el tipo es una etiqueta, y una etiqueta mal capturada se corrige.
- **Bitácora del cambio de tipo.** Editar pisa el valor anterior sin dejar rastro, como el resto de campos del dominio.
- **Un tercer tipo** (aduana, frontera, centro de acopio) ahora o mediante tabla de catálogo. Son dos valores en un enum; añadir un tercero será una migración de una línea el día que se pida.
- **Columnas propias del puerto:** código de puerto, terminal, muelle, horario de atención o agente aduanal. Lo que haga falta se teclea en `description`.
- **Que `departure_points` gane un `type`.** El dominio de SPEC 20 queda byte a byte sin tocar, y **no hay validación cruzada** entre las dos tablas por tipo ni por ningún otro campo.
- **Unicidad de `name` por tipo.** El índice único global se queda como está.
- **Ámbito por rol distinto según el tipo.** La lectura sigue abierta a cualquier autenticado y la escritura sigue siendo solo `administrator`; nadie ve ni edita un subconjunto del catálogo por su tipo.
- **Backfill manual o revisión de los destinos existentes.** Todos quedan como `destination` y reclasificar los que sean puertos es un `PATCH` a mano.
- **Migrar los destinos de tipo `port` a otra tabla** o fusionar catálogos. Esta spec no lo prepara.

---

## Modelo de datos

Esta spec **no crea ninguna tabla**. Todo el modelo de datos es una migración aditiva sobre `locations`, un enum nuevo y la propagación de una columna por el modelo y la factory.

### 1. Migración `add_type_to_locations_table`

```php
Schema::table('locations', function (Blueprint $table) {
    /**
     * Etiqueta de catálogo, no regla de negocio: no interviene en ninguna tarifa.
     * El default es el valor real de todas las filas anteriores a esta spec.
     */
    $table->string('type')->default(LocationType::Destination->value)->after('description');
});
```

| Columna | Tipo | Valores | Nulo |
|---|---|---|---|
| `type` | `string` + enum `LocationType` | `port` \| `destination` | no, default `destination` |

Decisiones de la migración:

- **`string` con enum en PHP, no un `enum` nativo de PostgreSQL.** Es lo que ya hacen `vehicles.type`, `vehicles.status`, `vehicles.condition` y `fuel_prices.fuel_type`: el motor guarda texto y el enum de PHP es quien manda. Añadir un tercer tipo el día que se pida será una línea en el enum, no una migración con `ALTER TYPE`.
- **El default se queda permanente en la columna**, no se retira en una segunda migración, igual que en SPEC 13. Como el FormRequest exige el campo, el default solo protege inserciones directas a base de datos y las filas ya existentes.
- **Sin `nullable`.** Un destino siempre tiene tipo; `null` no significaría nada distinto de `destination`.
- **Sin índice.** Es una columna de dos valores sin selectividad, filtrada por igualdad sobre un catálogo pequeño; un B-tree solo encarecería las escrituras. Mismo criterio que `status` en SPEC 15 y SPEC 20.
- Va `after('description')` para que quede junto a los campos descriptivos, antes de los de anclaje a Google.
- El `down()` revierte con `dropColumn('type')`.

### 2. Enum `App\Enums\LocationType`

```php
namespace App\Enums;

enum LocationType: string
{
    case Port = 'port';
    case Destination = 'destination';
}
```

Décimo enum del proyecto y mismo patrón que los nueve anteriores: respaldo `string`, claves en TitleCase y valores en inglés en minúsculas. `Destination` se llama así y no `Location` a propósito: `"type": "location"` dentro de un destino no dice nada.

### 3. Modelo `Location`

```php
#[Fillable(['name', 'description', 'type', 'google_place_id', 'latitude', 'longitude', 'status', 'registered_by'])]

protected function casts(): array
{
    return [
        'type' => LocationType::class,
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'status' => 'boolean',
    ];
}
```

El cast al enum es lo que hace que el service compare con `LocationType::tryFrom()` sin tocar cadenas sueltas y que el Resource pueda sacar `->value`. `normalizeName()` y la relación `registeredBy()` no cambian.

### 4. Factory

`LocationFactory::definition()` suma `'type' => LocationType::Destination` y gana un estado `port()`, hermano de los `active()` / `inactive()` que ya tiene:

```php
public function port(): static
{
    return $this->state(fn (array $attributes) => [
        'type' => LocationType::Port,
    ]);
}
```

El default de la factory es `Destination` para que los tests de SPEC 15 y de `FreightRate` que crean destinos sin especificar tipo sigan valiendo sin tocarse.

### 5. Salida — `LocationResource`

Once claves en lugar de diez. La nueva va después de `description`:

```json
{
  "id": 3,
  "name": "TERMINAL DE CARGA PUERTO QUETZAL",
  "description": "Entrada por el portón 4",
  "type": "port",
  "googlePlaceId": "ChIJd8BlQ2BZwokRAFUEcm_qrcA",
  "latitude": "13.92330000",
  "longitude": "-90.78500000",
  "status": true,
  "registeredByName": "Roberto Santizo",
  "createdAt": "26-08-2026 09:14:03 AM",
  "updatedAt": "26-08-2026 09:14:03 AM"
}
```

- `type` sale con el **valor crudo del enum**, en inglés y minúsculas, sin traducir. El cliente decide cómo lo muestra.
- Las otras diez claves no cambian de nombre, de formato ni de orden. `FreightQuoteResource` **no gana** este campo.

---

## Plan de implementación

Cada paso deja el sistema funcionando y es commiteable por sí solo.

1. **Enum.** Crear `app/Enums/LocationType.php` con los dos casos. No lo consume nadie todavía, así que la suite entera sigue en verde. Verificación: `php artisan test --compact --filter=Location` pasa igual que antes.

2. **Migración, modelo y factory.** `php artisan make:migration add_type_to_locations_table --table=locations` con la columna `type` (`string`, default `destination`, `after('description')`) y su `down()`; añadir `'type'` al `#[Fillable]` de `Location` y el cast al enum; sumar `'type' => LocationType::Destination` a `LocationFactory::definition()` y el estado `port()`. Correr `php artisan migrate`. Verificación: `php artisan tinker --execute 'Location::first()->type;'` devuelve el enum y las filas viejas salen como `Destination`.

3. **Contrato.** Actualizar el PHPDoc de `LocationServiceInterface`: `type` en el array shape de `$filters` (`getLocations`), en el del alta (`create`) y en el de la edición (`update`). Sin métodos nuevos: el contrato mantiene los mismos siete.

4. **Service.** En `getLocations()`, resolver el filtro con `LocationType::tryFrom($filters['type'] ?? '')` y aplicar `where('type', ...)` solo si no es `null` — tolerante por construcción, sin `try/catch` ni 422. En `create()`, tomar `type` del array de datos como el resto de campos. En `update()`, aplicarlo con `isset`, junto a `name`, `googlePlaceId`, `latitude`, `longitude` y `status`. Ninguna guarda nueva: no hay unicidad ni validación cruzada que sostener.

5. **FormRequests.**
   - `StoreLocationRequest` — `type` `required|string|in:port,destination` (con `Rule::enum(LocationType::class)`), con su mensaje en español: «El tipo de destino es obligatorio» / «El tipo de destino no es válido».
   - `UpdateLocationRequest` — el mismo como `sometimes|required`.
   - El filtro `type` del listado **no** se valida en ningún sitio: el índice no tiene FormRequest y el service lo ignora si no encaja.

6. **Resource.** `LocationResource` devuelve `'type' => $this->type?->value` después de `description`. Verificación: `GET /api/locations` trae once claves y las diez viejas idénticas.

7. **Tests.** Disparar el agente `feature-tests` sobre `Location` para **ampliar** los archivos existentes, no para reescribirlos: el alta sin `type` en 422, el alta con tipo inválido en 422, el `PATCH` que cambia el tipo de un destino con tarifas en 200, el filtro exacto, el filtro tolerante, el default de las filas migradas y las once claves del Resource. Correr `php artisan test --compact --filter=Location`.

8. **Regresión de SPEC 15 y SPEC 20.** `php artisan test --compact --filter='FreightRate|DeparturePoint'` en verde sin haber tocado ningún archivo de esos dominios — es la prueba de que la columna no se coló en la cotización ni en el catálogo hermano.

9. **Documentación.** Disparar el agente `endpoint-docs` sobre `Location` y regenerar `storage/api-docs/api-docs.json`.

10. **Cierre.** `vendor/bin/pint --dirty --format agent` y actualizar `references/locations-api.md` con el campo nuevo, el filtro y un aviso destacado de que el alta es un **cambio incompatible**.

---

## Criterios de aceptación

**Estructura**

- [ ] `locations` tiene una columna `type` de tipo `string`, no nullable, con default `destination`, y las otras ocho columnas quedan exactamente como las dejó SPEC 15.
- [ ] Existe `App\Enums\LocationType` con exactamente dos casos: `Port = 'port'` y `Destination = 'destination'`.
- [ ] `locations` no gana ningún índice nuevo, y los únicos de `name` y `google_place_id` siguen siendo globales.
- [ ] `php artisan route:list --path=locations` muestra exactamente las mismas seis rutas de antes, con los mismos middlewares.
- [ ] `LocationServiceInterface` mantiene los mismos siete métodos; ninguno se añade ni se borra.
- [ ] `departure_points`, `DeparturePoint`, `DeparturePointService` y sus rutas no cambian en ninguna línea.

**Migración de las filas existentes**

- [ ] Tras `php artisan migrate`, todos los destinos ya registrados tienen `type = 'destination'` y ninguno queda en `null`.
- [ ] El `down()` de la migración elimina la columna y deja la tabla como estaba.

**Alta**

- [ ] `POST /api/locations` con `name`, `type`, `googlePlaceId`, `latitude` y `longitude` responde **201** y guarda el tipo enviado.
- [ ] `POST` **sin** `type` responde **422** con «El tipo de destino es obligatorio».
- [ ] `POST` con `type: "puerto"`, `type: "PORT"` o `type: ""` responde **422**: la validación es exacta y sensible a mayúsculas.
- [ ] `POST` con `type: "port"` guarda un puerto sin ninguna comprobación adicional: no se valida el nombre, ni las coordenadas, ni nada relacionado con que sea puerto.
- [ ] Un puerto y un destino **no** pueden compartir `name`: el segundo responde 422 igual que antes, porque la unicidad sigue siendo global.

**Edición**

- [ ] `PATCH /api/locations/{location}` con `type: "port"` sobre un destino responde **200** y cambia el tipo.
- [ ] Cambiar el tipo de un destino que **ya tiene tarifas** responde **200**; las tarifas quedan intactas y siguen cotizando igual.
- [ ] `PATCH` con `type: ""` o con un valor fuera del enum responde **422**; omitir `type` deja el valor intacto.
- [ ] El resto del `PATCH` no cambia: cuerpo vacío sigue siendo 200 sin cambios y `description: null` sigue borrando la descripción.

**Listado**

- [ ] `?type=port` devuelve solo los puertos; `?type=destination` solo los destinos ordinarios.
- [ ] `?type=puerto`, `?type=PORT` o `?type=` **se ignoran** y devuelven el listado completo, no una lista vacía ni un 422.
- [ ] El filtro `type` se combina con `status`, `search` y `limit` sin interferir con ninguno.
- [ ] El orden sigue siendo `id ASC` y la paginación opt-in sigue acotada a `[10, 100]`.

**Salida**

- [ ] `LocationResource` devuelve exactamente **once** claves en camelCase; las diez de SPEC 15 conservan nombre, formato y valor.
- [ ] `type` sale como cadena cruda del enum (`"port"` o `"destination"`), sin traducir y sin envolver en objeto.
- [ ] `latitude` y `longitude` siguen saliendo como cadena con ocho decimales, y las fechas en `d-m-Y h:i:s A`.

**Roles**

- [ ] Los cuatro roles autenticados siguen obteniendo **200** en `GET /api/locations` y ven el campo `type` sin diferencias.
- [ ] `carrier`, `manager` y `pilot` siguen obteniendo **403** en `POST`, `PATCH`, `DELETE` y `toggle-status`; el tipo no cambia el ámbito de nadie.

**Regresión y cierre**

- [ ] `GET /api/freight-rates/quote` acepta los mismos parámetros y devuelve la misma forma que antes de esta spec; `FreightQuoteResource` **no** incluye `type`.
- [ ] Crear o editar una tarifa sobre un destino de tipo `port` funciona igual que sobre uno `destination`.
- [ ] `php artisan test --compact --filter='FreightRate|DeparturePoint'` pasa sin que ningún archivo de esos dominios haya cambiado.
- [ ] `php artisan test --compact` pasa entera.
- [ ] `vendor/bin/pint --dirty --format agent` no reporta cambios pendientes.
- [ ] `storage/api-docs/api-docs.json` documenta `type` en el schema `Location`, en el body del alta y de la edición, y como query param del listado; `references/locations-api.md` avisa del cambio incompatible en el alta.

---

## Decisiones tomadas y descartadas

**1. Columna `type` en `locations`, no una tabla `ports` aparte.**
Se descartó repetir la jugada de SPEC 20 y crear un catálogo hermano. Aquella spec separó `departure_points` porque un punto de partida **no es cotizable** y colarlo como destino habría sido un error silencioso de precio. Un puerto **sí es un destino cotizable**: cuelga de él una `FreightRate`, se manda como `locationId` en `/quote` y se comporta igual en todo. Una tabla aparte obligaría a duplicar la FK de `freight_rates` o a resolver dos catálogos en la cotización, sin ganar nada.

**2. Valores del enum en inglés, con `Destination` en lugar de `Location`.**
Los nueve enums del proyecto llevan valores en inglés y minúsculas, y romper eso por un solo campo dejaría un JSON mestizo. `"type": "location"` dentro de un recurso `Location` es una tautología que no informa; `destination` dice lo que el campo distingue. Traducir a «Puerto» / «Destino» es del cliente, como ya lo es para `VehicleType` y `FuelType`.

**3. `type` obligatorio en el alta, aunque sea un cambio incompatible.**
Se valoró dejarlo opcional con default `destination` también por la API. Se descartó: el default de la columna existe para las filas ya migradas, no para que el front siga sin decidir. Si el alta lo acepta omitido, todo destino nuevo nace `destination` por inercia y los puertos se capturan mal desde el primer día. Es el mismo criterio de SPEC 13, que subió el alta de vehículos de siete a trece campos sin periodo de gracia.

**4. `default('destination')` permanente en la columna, sin backfill.**
Todos los destinos existentes **son** destinos ordinarios: el default no es relleno como el `1` de SPEC 13, es el valor correcto. Por eso no hace falta script de backfill ni columna `nullable` que diga «no capturado». Reclasificar los que resulten ser puertos es un `PATCH` a mano, y son pocos.

**5. El tipo es editable sin restricción, incluso con tarifas colgando.**
Se descartó bloquear el cambio cuando el destino ya tiene `FreightRate`. El tipo no gobierna ninguna tarifa, así que bloquearlo protegería de un riesgo que no existe e impediría corregir una etiqueta mal capturada. Es la misma decisión que SPEC 15 tomó para `google_place_id`, que también es editable conservando las tarifas.

**6. El tipo no influye en el precio ni restringe las tarifas.**
La alternativa —que un puerto cotice distinto, o que solo ciertos productos lleguen a puerto— convertiría esto en una spec de negocio con cambios en `FreightRateService`, en `/quote` y en la banda de combustible. Se descarta hasta que exista una regla real que lo pida; cuando exista, será su propia spec y podrá apoyarse en esta columna.

**7. Filtro tolerante, no validado.**
`?type=basura` devuelve el listado completo en vez de 422. Es la regla escrita del proyecto para los filtros de catálogo (`status`, `search`, `locationId`, `condition`): un parámetro roto no debe vaciar una pantalla ni romper una petición de lectura. La coherencia pesa más que el aviso temprano.

**8. Sin índice sobre `type`.**
Dos valores en un catálogo de decenas o cientos de filas: la selectividad es nula y el planificador haría escaneo secuencial de todas formas. Mismo criterio que `status` en SPEC 15 y SPEC 20.

**9. La unicidad de `name` sigue siendo global, no por tipo.**
Se valoró un único compuesto `(name, type)` para permitir el mismo nombre en las dos categorías. Se descartó: cambiar un índice único sobre una tabla publicada es una migración de riesgo, y el caso que resolvería —dos sitios distintos con nombre idéntico— se resuelve mejor tecleando nombres distintos, que es lo que ya obliga el catálogo hoy.

**10. `departure_points` no gana `type`.**
No hay ninguna distinción pendiente entre puntos de partida y añadirla «por simetría» sería adivinar. SPEC 20 dejó escrito que los dos catálogos son copias declaradas que se irán separando por deriva; esta es la primera deriva, y es deliberada.

**11. Sin bitácora del cambio de tipo.**
El único dominio con historial es el salario del piloto (SPEC 11), y lo tiene porque un salario es un compromiso con fecha de vigencia. Una etiqueta de catálogo no lo es.

---

## Riesgos identificados

**1. El cliente actual deja de poder dar de alta destinos.**
`POST /api/locations` pasa a exigir `type` sin periodo de gracia: cualquier front que hoy manda cuatro campos empezará a recibir 422 en el instante en que se despliegue esta spec. Es el riesgo más inmediato y el más seguro de materializarse. *Mitigación:* está declarado como cambio incompatible en el alcance y en la decisión 3; `references/locations-api.md` debe abrirlo con ese aviso y el despliegue del backend debe coordinarse con el del front, como se hizo en SPEC 13.

**2. Un puerto capturado como `destination` no lo detecta nadie.**
El tipo no se cruza con el nombre, ni con las coordenadas, ni con el `googlePlaceId`, así que «TERMINAL DE CARGA PUERTO QUETZAL» puede quedar como `destination` para siempre y responder 200 en todas partes. *Mitigación:* asumido. El catálogo es de administradores y la corrección es un `PATCH` de un campo.

**3. Presión para que el tipo empiece a decidir precios.**
En cuanto exista la columna, la petición siguiente será «los puertos cotizan distinto». Esa regla tocaría `FreightRateService`, `/quote` y la banda de combustible, y esta spec **no la prepara**. *Mitigación:* está fuera de alcance por escrito y la decisión 6 explica por qué; cuando llegue el caso real, será su propia spec y encontrará la columna ya poblada.

**4. Las filas migradas mienten por omisión durante un tiempo.**
Entre la migración y la reclasificación manual, el filtro `?type=port` devuelve **lista vacía** aunque el catálogo tenga puertos reales. Un cliente que construya una pantalla sobre ese filtro parecerá roto sin estarlo. *Mitigación:* el paso 10 del plan lo deja escrito en la referencia del front; la reclasificación es trabajo de datos, no de código.

**5. Deriva entre `locations` y `departure_points`.**
SPEC 20 nació como copia literal y ya avisó de este riesgo. Esta spec es la primera divergencia estructural entre las dos tablas: dejan de tener las mismas columnas. *Mitigación:* es deliberada (decisión 10) y queda registrada aquí, para que quien compare los dos catálogos en el futuro sepa que la diferencia no es un olvido.

---

## Lo que **no** entra en esta spec

- Que el tipo influya en el precio, en la banda de combustible o en `GET /api/freight-rates/quote`.
- Que el tipo restrinja qué tarifas o qué productos se pueden asociar a un destino.
- Impedir el cambio de tipo cuando el destino ya tiene tarifas.
- Un tercer tipo, o convertir el enum en tabla de catálogo.
- Columnas propias del puerto: código, terminal, muelle, horario, agente aduanal.
- Que `departure_points` gane un `type`, o cualquier validación cruzada entre las dos tablas.
- Unicidad de `name` por tipo.
- Ámbito por rol distinto según el tipo.
- Bitácora del cambio de tipo.
- Backfill automático o reclasificación masiva de los destinos existentes.

Cada una de ellas, si llega, va en su propia spec.
