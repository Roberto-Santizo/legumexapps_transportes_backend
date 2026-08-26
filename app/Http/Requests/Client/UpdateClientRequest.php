<?php

namespace App\Http\Requests\Client;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateClientRequest',
    title: 'Actualización de cliente',
    description: <<<'TEXT'
    Cuerpo JSON para corregir un cliente. Los dos únicos campos editables son code y name, ambos OPCIONALES: se toca solo lo que venga, así que un PATCH que solo manda name deja el código intacto. UN CUERPO VACÍO ES UN NO-OP CON 200 —devuelve el cliente sin cambios— en vez de 422, como en el resto de los PATCH del proyecto.

    Opcional no es lo mismo que vaciable: los dos campos son sometimes|required, de modo que omitir la clave conserva el valor anterior pero enviarla vacía —o con solo espacios, que la normalización recorta— es 422. NINGUNO DE LOS DOS ACEPTA null.

    El registeredBy sigue sin aceptarse: mandarlo se descarta sin error y el autor del alta NO SE REESCRIBE, aunque edite otro administrador. Tampoco hay status que cambiar —este catálogo no lo tiene— ni forma de restaurar un cliente borrado.

    ATENCIÓN — SOBRE UN CLIENTE YA BORRADO ESTE PATCH RESPONDE 400 «El cliente ya fue eliminado», NO 404. Es la única lectura de la API que distingue «ya no está» de «nunca existió»: el GET del detalle devuelve 404 en ambos casos a propósito.

    ATENCIÓN — MISMA REGLA DE DUPLICADOS QUE EL ALTA, Y SIGUE SIENDO 400 Y NUNCA 422. Ninguno de los dos campos lleva regla unique, porque la de Laravel no ve las filas borradas; los choques los levanta el service con «Ya existe un cliente con ese código, que puede haber sido eliminado» y «Ya existe un cliente con ese nombre, que puede haber sido eliminado». La comprobación IGNORA LA PROPIA FILA, así que reenviar el propio código o el propio nombre responde 200 y no choca consigo mismo. Si los dos están ocupados, gana el mensaje del código.

    Los dos campos se normalizan antes de validarse con la MISMA ASIMETRÍA del alta: el name colapsa los espacios internos, el code no —un código con cualquier espacio es 422—.
    TEXT,
    properties: [
        new OA\Property(
            property: 'code',
            description: 'Nuevo código del cliente. OPCIONAL: omitirlo conserva el actual. Si se envía no puede venir vacío (El código del cliente es obligatorio), ni superar los 15 caracteres (El código del cliente no puede superar los 15 caracteres), NI CONTENER NINGÚN ESPACIO O TABULADOR (El código no puede contener espacios). Se guarda recortado y en mayúsculas. Reenviar el propio código del cliente que se edita responde 200; usar el de OTRO cliente —vivo o borrado— responde 400, no 422.',
            type: 'string',
            maxLength: 15,
            example: 'cli-002',
        ),
        new OA\Property(
            property: 'name',
            description: 'Nueva razón social del cliente. OPCIONAL: omitirla conserva la actual. Si se envía no puede venir vacía (El nombre del cliente es obligatorio) ni superar los 255 caracteres (El nombre del cliente no puede superar los 255 caracteres). Se guarda recortada, con los espacios internos colapsados y en mayúsculas. Reenviar el propio nombre responde 200; usar el de OTRO cliente —vivo o borrado— responde 400, no 422, y la comparación es insensible a mayúsculas porque se hace ya normalizada.',
            type: 'string',
            maxLength: 255,
            example: 'agro del norte',
        ),
    ],
    type: 'object',
)]
class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the code and the name before the rules run.
     *
     * Same asymmetry as the store request: the name collapses inner whitespace, the
     * code does not.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Client::normalizeCode($this->input('code'))]);
        }

        if (is_string($this->input('name'))) {
            $this->merge(['name' => Client::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * Los dos campos son sometimes|required: omitirlos deja el valor anterior, pero
         * mandarlos vacíos es un error. Un cuerpo vacío es un no-op con 200, como en el
         * resto de los PATCH del proyecto. registeredBy sigue sin aceptarse: mandarlo se
         * descarta sin error y el autor no cambia.
         */
        return [
            'code' => ['sometimes', 'required', 'string', 'max:15', 'regex:/^\S+$/u'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'El código del cliente es obligatorio',
            'code.string' => 'El código del cliente debe ser texto',
            'code.max' => 'El código del cliente no puede superar los 15 caracteres',
            'code.regex' => 'El código no puede contener espacios',
            'name.required' => 'El nombre del cliente es obligatorio',
            'name.string' => 'El nombre del cliente debe ser texto',
            'name.max' => 'El nombre del cliente no puede superar los 255 caracteres',
        ];
    }
}
