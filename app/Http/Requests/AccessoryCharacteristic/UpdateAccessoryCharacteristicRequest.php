<?php

namespace App\Http\Requests\AccessoryCharacteristic;

use App\Models\AccessoryCharacteristic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateAccessoryCharacteristicRequest',
    title: 'Actualización de característica de accesorio',
    description: <<<'TEXT'
    Cuerpo JSON para editar una característica. Solo se aceptan DOS campos —name y value—, los dos OPCIONALES por separado: un PATCH que solo manda value deja el name intacto, y al revés. Un CUERPO VACÍO responde 200 como no-op, devolviendo la característica sin cambios, NO 422.

    ATENCIÓN — accessory_id NO APARECE Y SE IGNORA EN SILENCIO: enviarlo (o enviar accessoryId) NO devuelve 422, no mueve la fila de accesorio y la respuesta 200 trae el accessoryId original. Un cliente que reenvíe el objeto entero creerá que la movió. Mover una característica de accesorio es BORRARLA Y CREARLA de nuevo.

    ATENCIÓN — los dos campos son "sometimes|required": omitirlos está bien, pero enviarlos VACÍOS es 422. No hay forma de limpiar un name ni un value, porque ninguna de las dos columnas es nullable —una característica sin valor no informa de nada—.

    El name y el value se NORMALIZAN igual que en el alta y con la misma asimetría: el name se recorta, colapsa y sube a mayúsculas; el value solo se recorta. La comprobación de nombre repetido corre contra EL ACCESORIO YA GUARDADO —no contra uno que venga en el cuerpo— e ignora la propia fila: chocar con OTRA característica del mismo accesorio es 400 (El accesorio ya tiene una característica con ese nombre), reenviar SU PROPIO nombre sin cambios es 200, y usar un nombre que ya existe en OTRO accesorio también es 200.

    El registeredBy NO se acepta y NO se reescribe: la característica conserva a quien la capturó aunque la edite otro administrador. Editar pisa el valor anterior SIN dejar rastro: no hay historial de cambios ni updatedAt en la respuesta. La ruta acepta PATCH y PUT indistintamente, con el mismo comportamiento: el PUT no reemplaza el recurso completo.
    TEXT,
    properties: [
        new OA\Property(
            property: 'name',
            description: 'OPCIONAL. Nuevo nombre de la característica. Si se omite, el nombre no se toca; si se envía, no puede ir vacío (sometimes|required): el 422 es El nombre de la característica es obligatorio. Máximo 255 caracteres y misma normalización que en el alta —recorte, colapso de espacios internos y MAYÚSCULAS—, así que el cliente debe pintar el name de la respuesta. ATENCIÓN — si el nombre ya normalizado lo tiene OTRA característica DEL MISMO ACCESORIO la respuesta es 400 (El accesorio ya tiene una característica con ese nombre), NO 422; reenviar el propio nombre de la fila es 200 porque la comprobación ignora su propio id, y usar un nombre que ya existe en otro accesorio también es 200.',
            type: 'string',
            maxLength: 255,
            example: 'placa trasera',
        ),
        new OA\Property(
            property: 'value',
            description: 'OPCIONAL. Nuevo valor de la característica, siempre texto y de máximo 500 caracteres. Si se omite, el valor no se toca; si se envía, no puede ir vacío (sometimes|required): el 422 es El valor de la característica es obligatorio. SOLO SE RECORTA: no se pasa a mayúsculas ni se le colapsan los espacios internos, igual que en el alta. Editarlo PISA el valor anterior sin dejar rastro —no hay historial ni updatedAt— y no hay ninguna regla de unicidad sobre él: dos características del mismo accesorio pueden compartir valor.',
            type: 'string',
            maxLength: 500,
            example: 'P-999XYZ',
        ),
    ],
    type: 'object',
)]
class UpdateAccessoryCharacteristicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name and the value before the rules run, exactly as the store does.
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
         * accessory_id no aparece a propósito: la característica no se mueve de accesorio,
         * y mandarlo se ignora en silencio en vez de dar 422. Los dos campos son
         * opcionales por separado y un cuerpo vacío se acepta como no-op.
         */
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'value' => ['sometimes', 'required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la característica es obligatorio',
            'name.string' => 'El nombre de la característica debe ser texto',
            'name.max' => 'El nombre de la característica no puede superar los 255 caracteres',
            'value.required' => 'El valor de la característica es obligatorio',
            'value.string' => 'El valor de la característica debe ser texto',
            'value.max' => 'El valor de la característica no puede superar los 500 caracteres',
        ];
    }
}
