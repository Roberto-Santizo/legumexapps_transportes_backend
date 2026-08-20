<?php

namespace App\Http\Resources\AccessoryCharacteristic;

use App\Models\AccessoryCharacteristic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AccessoryCharacteristic',
    title: 'Característica de accesorio',
    description: <<<'TEXT'
    Par nombre/valor libre que describe UN accesorio del inventario nacional: su PLACA, su TIPO DE COMBUSTIBLE, su MEDIDA. El conjunto de campos NO está fijado por el esquema: dos accesorios pueden describirse con listas completamente distintas sin migrar nada, y ninguno está obligado a tener ninguna característica.

    ATENCIÓN — NO ES UN CATÁLOGO: no existe una tabla de nombres permitidos, ni autocompletado servido por la API, ni tipo declarado por característica. El nombre se teclea libre y el valor es SIEMPRE TEXTO: un valor "12" y un valor "doce" son igual de válidos, y ningún cliente debe asumir que puede operar aritméticamente con el value.

    ATENCIÓN — LA NORMALIZACIÓN ES ASIMÉTRICA: el name se recorta, se le COLAPSAN los espacios internos y se pasa a MAYÚSCULAS —es un identificador, y al estar siempre en mayúsculas la unicidad resulta insensible a mayúsculas sin depender del collation—; el value SOLO se recorta por los extremos y NO cambia de caja ni pierde sus espacios internos, porque es contenido del usuario y subirlo a mayúsculas lo estropearía ("Diésel" no es "DIÉSEL"). Es deliberado, no un descuido.

    ATENCIÓN — accessoryId sale como NÚMERO, no como objeto anidado: quien llega hasta aquí ya tiene el accesorio, porque tuvo que mandar su id para listar o para crear. NO hay accessoryName, ni accessory embebido, ni ningún otro dato del accesorio. Tampoco hay updatedAt: por la API no hay forma de saber cuándo se editó por última vez, ni existe historial de cambios.

    ATENCIÓN — el vínculo con el accesorio es INMUTABLE: el PATCH no acepta accessory_id y mandarlo se IGNORA EN SILENCIO, así que este accessoryId es el mismo desde el alta hasta el borrado. Mover una característica de accesorio es borrarla y crearla de nuevo.

    La unicidad del name es POR ACCESORIO, no global: dos accesorios distintos pueden tener ambos "PLACA" —lo normal—, pero uno solo no puede tener dos. Y la baja es FÍSICA: no hay status, no hay SoftDeletes y un DELETE hace desaparecer la fila de la tabla.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la fila (accessory_characteristics.id). Es el valor que va en el parámetro {accessoryCharacteristic} de las rutas de detalle, actualización y baja. NO es el id del accesorio: para eso está accessoryId. Como el borrado es FÍSICO, tras un DELETE este id deja de existir y cualquier lectura posterior devuelve 404, no una fila marcada como inactiva.',
            type: 'integer',
            example: 4,
        ),
        new OA\Property(
            property: 'accessoryId',
            description: 'Identificador del accesorio que esta característica describe (accessories.id), como NÚMERO y no como objeto anidado: NO hay accessoryName ni ningún otro dato del accesorio en la respuesta. Es el mismo valor que se envía como query param accessoryId en el listado y como accessory_id en el cuerpo del alta —ojo con la diferencia de caja entre los dos—. Es INMUTABLE: el PATCH no lo acepta y mandarlo se ignora en silencio, así que nunca cambia a lo largo de la vida de la fila. El estado del accesorio (active, inactive o under_repair) no interviene en nada: la ficha de una pieza retirada sigue siendo información válida.',
            type: 'integer',
            example: 12,
        ),
        new OA\Property(
            property: 'name',
            description: 'Nombre de la característica, SIEMPRE EN MAYÚSCULAS y con los espacios internos colapsados a uno: enviar "  placa   trasera " guarda y devuelve "PLACA TRASERA". El cliente debe pintar SIEMPRE este name, no el que tecleó el usuario. Es único DENTRO DE SU ACCESORIO, no a nivel global: repetirlo en el mismo accesorio es un 400 del service (El accesorio ya tiene una característica con ese nombre) y repetirlo en otro accesorio es perfectamente válido. Al no haber catálogo, nada impide que un accesorio tenga "PLACA", otro "PLACA TRASERA" y un tercero "NO. DE PLACA": la normalización unifica caja y espacios, nunca sinónimos.',
            type: 'string',
            example: 'PLACA',
        ),
        new OA\Property(
            property: 'value',
            description: 'Valor de la característica, EXACTAMENTE COMO SE GUARDÓ: solo se le recortaron los extremos. ATENCIÓN — NO se pasa a mayúsculas y NO se le colapsan los espacios internos, al contrario que al name: enviar "  Diésel " devuelve "Diésel", y "Acero   inoxidable" conserva sus espacios. Es SIEMPRE TEXTO, sin tipo declarado ni unidad: una fecha, un número o un booleano viajan aquí como la cadena que tecleó el usuario, y convertirlos es cosa del cliente. Máximo 500 caracteres, que es también el ancho de la columna. No participa en ninguna búsqueda: el dominio no tiene filtro search.',
            type: 'string',
            example: 'P-123ABC',
        ),
        new OA\Property(
            property: 'registeredBy',
            description: 'ATENCIÓN — es el NOMBRE del administrador que capturó la característica, NO su id: la pantalla lo imprime y nadie navega a ese usuario. Sale de la relación registeredBy y nunca del cuerpo: se toma del usuario autenticado en el alta, y mandar registeredBy o registered_by en el JSON no cambia quién queda registrado. El PATCH NO lo reescribe, así que sigue apuntando a quien creó la fila aunque la haya editado después otro administrador. Es null solo si ese usuario ya no se puede resolver.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha en la que se capturó la fila, con el formato propio del proyecto d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM). ATENCIÓN — NO ES ISO 8601, por eso se documenta como string SIN format date-time: un cliente generado desde este schema que intentara parsearlo como ISO fallaría. El recurso NO expone updatedAt, así que esta es la única marca de tiempo disponible y editar el value no la mueve.',
            type: 'string',
            nullable: true,
            example: '20-08-2026 09:14:33 AM',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'AccessoryCharacteristicListResponse',
    title: 'Listado de características sin paginar',
    description: 'Respuesta de GET /api/accessory-characteristics cuando no se envía limit o cuando el limit no es numérico: se devuelven TODAS las características del accesorio pedido y el sobre NO incluye total, currentPage ni lastPage. Siempre ordenadas por id ASC —el orden en que se capturaron— y siempre de un solo accesorio: no existe un listado global de las características de todo el inventario. Un accesorio que existe pero no tiene ninguna característica devuelve 200 con data vacío; un accessoryId que no existe devuelve 404, nunca una lista vacía.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Características obtenidas correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AccessoryCharacteristic')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedAccessoryCharacteristicListResponse',
    title: 'Listado de características paginado',
    description: 'Respuesta de GET /api/accessory-characteristics cuando se envía un limit numérico: los metadatos de paginación —total, currentPage y lastPage— salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, NO anidados bajo meta. El total cuenta las características de ESE accesorio, no del inventario entero.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/AccessoryCharacteristicListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
/**
 * @mixin AccessoryCharacteristic
 */
class AccessoryCharacteristicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /**
             * Un número, no un objeto anidado: quien llega hasta aquí ya tiene el
             * accesorio, porque tuvo que mandar su id. No hay accessoryName.
             */
            'accessoryId' => $this->accessory_id,
            /** Siempre en mayúsculas y con los espacios internos colapsados. */
            'name' => $this->name,
            /** Exactamente como se guardó: solo se le recortaron los extremos. */
            'value' => $this->value,
            /** El nombre de quien capturó la característica, no su id. */
            'registeredBy' => $this->registeredBy?->name,
            /** Formato del proyecto, que no es ISO 8601. No hay updatedAt. */
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
