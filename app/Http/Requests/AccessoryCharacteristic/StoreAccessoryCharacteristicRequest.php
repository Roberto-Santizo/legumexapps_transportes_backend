<?php

namespace App\Http\Requests\AccessoryCharacteristic;

use App\Models\AccessoryCharacteristic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreAccessoryCharacteristicRequest',
    title: 'Alta de característica de accesorio',
    description: <<<'TEXT'
    Cuerpo JSON para dar de alta UNA característica de UN accesorio. Solo se aceptan tres campos —accessory_id, name y value— y LOS TRES SON OBLIGATORIOS: no hay campos opcionales en este alta.

    ATENCIÓN — accessory_id VA EN SNAKE_CASE, al contrario que el query param accessoryId del listado, que va en camelCase. Es la única incoherencia de caja del dominio y hay que respetarla: enviar accessoryId en el cuerpo NO vincula nada y devuelve 422 por accessory_id ausente (El accesorio es obligatorio).

    ATENCIÓN — un accessory_id que no corresponde a ningún accesorio es 422 por la regla exists (mensaje El accesorio no existe), NO 404. El 404 con ese mismo mensaje existe en el service pero por HTTP nunca se alcanza en el alta: el FormRequest corta antes. En el LISTADO, en cambio, el mismo id inexistente sí devuelve 404, porque allí no hay regla exists. Dos códigos distintos para el mismo error según el endpoint.

    ATENCIÓN — REPETIR UN NOMBRE EN EL MISMO ACCESORIO ES 400, NO 422: aquí NO hay regla unique. La colisión la corta el service con el mensaje de negocio "El accesorio ya tiene una característica con ese nombre", y el índice único (accessory_id, name) queda de red de seguridad. Es la asimetría deliberada frente a Accessories, donde los duplicados sí salen por 422. El MISMO nombre en OTRO accesorio devuelve 201: la unicidad es por accesorio, no global.

    ATENCIÓN — name y value SE NORMALIZAN ANTES DE VALIDARSE, y con reglas DISTINTAS. El name se recorta, se le colapsan los espacios internos y se pasa a mayúsculas: "  placa   trasera " guarda "PLACA TRASERA". El value SOLO se recorta: "  Diésel " guarda "Diésel", conservando su caja y sus espacios internos. Como la normalización ocurre antes, los límites max:255 y max:500 cuentan el texto ya recortado, no el relleno de los extremos.

    El registeredBy NO se acepta: la autoría sale del usuario autenticado —que por el middleware role:administrator es siempre un administrador— y vuelve en la respuesta como el NOMBRE de esa persona; mandar registeredBy o registered_by en el cuerpo se ignora sin error. Tampoco hay status ni fecha: la tabla no tiene estado y el createdAt lo pone la base.

    Un POST crea EXACTAMENTE UNA fila: no hay alta en lote ni endpoint de sincronización. Tres características son tres llamadas, sin transacción que las agrupe.
    TEXT,
    required: ['accessory_id', 'name', 'value'],
    properties: [
        new OA\Property(
            property: 'accessory_id',
            description: 'OBLIGATORIO. Identificador del accesorio al que se le añade la característica (accessories.id). EN SNAKE_CASE: es accessory_id, no accessoryId —el camelCase se usa en el query param del listado y en la salida del recurso, pero no aquí—. Debe ser un entero y EXISTIR: un id inexistente devuelve 422 por la regla exists con el mensaje El accesorio no existe, no 404. El STATUS DEL ACCESORIO NO IMPORTA: uno inactive o under_repair acepta características igual que uno active, porque la ficha de una pieza retirada sigue siendo información válida. Ausente o no entero es 422 (El accesorio es obligatorio / El accesorio debe ser un número entero). Una vez creada la fila este vínculo es INMUTABLE: el PATCH no lo acepta.',
            type: 'integer',
            example: 12,
        ),
        new OA\Property(
            property: 'name',
            description: 'OBLIGATORIO. Nombre de la característica: PLACA, TIPO DE COMBUSTIBLE, MEDIDA. Texto libre —NO hay catálogo de nombres permitidos ni autocompletado— de máximo 255 caracteres, que es el ancho de la columna. Se NORMALIZA antes de validar y de guardar: recorte, colapso de espacios internos y MAYÚSCULAS, así que se puede enviar en minúsculas y el cliente debe pintar el name de la respuesta, no el que tecleó el usuario. ATENCIÓN — debe ser único DENTRO DEL MISMO ACCESORIO, y la colisión NO es 422 sino 400 del service (El accesorio ya tiene una característica con ese nombre); el mismo nombre en otro accesorio es 201. Como la comparación va sobre el nombre ya normalizado, "placa" choca con "PLACA". Ausente, vacío, no textual o de más de 255 caracteres devuelve 422 (El nombre de la característica es obligatorio / El nombre de la característica debe ser texto / El nombre de la característica no puede superar los 255 caracteres).',
            type: 'string',
            maxLength: 255,
            example: 'placa',
        ),
        new OA\Property(
            property: 'value',
            description: 'OBLIGATORIO. Valor de la característica, SIEMPRE TEXTO: no hay tipo declarado ni unidad, así que una placa, un tipo de combustible, una fecha o una cantidad viajan todos como cadena y ningún informe podrá operar con ellos sin limpiarlos a mano. Máximo 500 caracteres, el ancho de la columna: 500 se acepta y 501 es 422. ATENCIÓN — SOLO SE RECORTA, y a diferencia del name NO se pasa a mayúsculas ni se le colapsan los espacios internos: "  Diésel " se guarda como "Diésel" y "Acero   inoxidable" conserva sus espacios. Es la asimetría deliberada del dominio: el nombre es un identificador y el valor es contenido del usuario. Ausente, vacío, no textual o demasiado largo devuelve 422 (El valor de la característica es obligatorio / El valor de la característica debe ser texto / El valor de la característica no puede superar los 500 caracteres).',
            type: 'string',
            maxLength: 500,
            example: 'P-123ABC',
        ),
    ],
    type: 'object',
)]
class StoreAccessoryCharacteristicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name and the value before the rules run.
     *
     * The two normalizers are asymmetric on purpose: the name is an identifier, so it
     * is trimmed, collapsed and upper cased; the value is user content, so it is only
     * trimmed — upper casing «Diésel» would ruin it.
     *
     * Normalizing before validating also makes `max:500` count the trimmed value, not
     * the padding around it.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => AccessoryCharacteristic::normalizeName($this->input('name'))]);
        }

        if (is_string($this->input('value'))) {
            $this->merge(['value' => AccessoryCharacteristic::normalizeValue($this->input('value'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * Sin regla unique: repetir un nombre en el mismo accesorio lo corta el service
         * con un 400, no un 422. Es la asimetría deliberada de la spec — el índice único
         * protege la integridad y la guarda del service entrega el mensaje de negocio.
         *
         * registeredBy no se acepta: la autoría sale del usuario autenticado.
         */
        return [
            /** El 422 por `exists` cubre el id inexistente antes de que el service levante su 404. */
            'accessory_id' => ['required', 'integer', 'exists:accessories,id'],
            'name' => ['required', 'string', 'max:255'],
            /** 500 es el límite de la columna, no una regla de negocio. */
            'value' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accessory_id.required' => 'El accesorio es obligatorio',
            'accessory_id.integer' => 'El accesorio debe ser un número entero',
            'accessory_id.exists' => 'El accesorio no existe',
            'name.required' => 'El nombre de la característica es obligatorio',
            'name.string' => 'El nombre de la característica debe ser texto',
            'name.max' => 'El nombre de la característica no puede superar los 255 caracteres',
            'value.required' => 'El valor de la característica es obligatorio',
            'value.string' => 'El valor de la característica debe ser texto',
            'value.max' => 'El valor de la característica no puede superar los 500 caracteres',
        ];
    }
}
