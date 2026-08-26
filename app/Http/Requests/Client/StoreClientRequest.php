<?php

namespace App\Http\Requests\Client;

use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreClientRequest',
    title: 'Alta de cliente',
    description: <<<'TEXT'
    Cuerpo JSON para dar de alta un cliente del catálogo nacional. SOLO SE ACEPTAN DOS CAMPOS, code y name, y los dos son OBLIGATORIOS. No hay dirección, ni NIT, ni teléfono, ni contacto, ni status: es el alta más pequeña del proyecto. Cualquier otra clave que se envíe se descarta en silencio, sin error.

    El registeredBy NO se envía: se resuelve desde el usuario autenticado, que por el middleware role:administrator es siempre un administrador. Mandarlo en el cuerpo no cambia nada y el cliente queda registrado a nombre de quien hizo la petición.

    ATENCIÓN — NO HAY status Y NO SE PUEDE CREAR UN CLIENTE «INACTIVO». Este catálogo no tiene el booleano de publicación que sí tienen Products, Locations, Zones y Departure Points: un cliente existe o está borrado, y el borrado no se deshace por la API.

    ATENCIÓN — LOS DOS DUPLICADOS POSIBLES SE RESPONDEN CON 400 DESDE EL SERVICE, NUNCA CON 422, y es lo contrario de lo que hacen los otros catálogos. Ninguno de los dos campos lleva regla unique a propósito: la de Laravel NO VE LAS FILAS BORRADAS y dejaría pasar un código ocupado por un cliente eliminado, que después chocaría contra el índice único con un 500. Los mensajes son «Ya existe un cliente con ese código, que puede haber sido eliminado» y «Ya existe un cliente con ese nombre, que puede haber sido eliminado». Si el código y el nombre están ocupados a la vez, GANA EL MENSAJE DEL CÓDIGO: se comprueba primero.

    ATENCIÓN — EL BORRADO NO LIBERA NI EL CÓDIGO NI EL NOMBRE. Un cliente eliminado los sigue reservando para siempre, así que el 400 puede venir de una fila que NO APARECE EN NINGÚN ENDPOINT: por eso el mensaje avisa de que el ocupante «puede haber sido eliminado». No existe forma de listar los borrados para averiguar cuál es.

    ATENCIÓN — LOS DOS CAMPOS SE NORMALIZAN ANTES DE VALIDARSE, Y NO IGUAL. El name se recorta, se le colapsan los espacios internos y se pasa a mayúsculas; el code SOLO se recorta y se pasa a mayúsculas —no se le colapsa nada—, porque un código con espacios se rechaza con 422 en vez de arreglarse por dentro. El cliente debe pintar el code y el name de la respuesta, no los que tecleó el usuario.
    TEXT,
    required: ['code', 'name'],
    properties: [
        new OA\Property(
            property: 'code',
            description: 'Código del cliente. OBLIGATORIO, texto y de 15 caracteres como máximo —15 exactos se aceptan, 16 es 422 (El código del cliente no puede superar los 15 caracteres)—. Lo teclea el administrador: no se genera solo, al contrario que el code de un Carrier. Se guarda recortado y en MAYÚSCULAS, así que se puede enviar en minúsculas. ATENCIÓN — NO ADMITE NINGÚN ESPACIO NI TABULADOR, ni siquiera interior: "CLI 001" devuelve 422 con el mensaje literal «El código no puede contener espacios» en vez de guardarse como "CLI001", para que un error de captura se vea. Un código de SOLO espacios sale por code.required, porque el recorte lo deja vacío antes de validarse (El código del cliente es obligatorio). ATENCIÓN — si otro cliente ya lo usa, VIVO O BORRADO, la respuesta es 400 y NO 422.',
            type: 'string',
            maxLength: 15,
            example: 'cli-001',
        ),
        new OA\Property(
            property: 'name',
            description: 'Razón social del cliente. OBLIGATORIA, texto y de 255 caracteres como máximo (mensajes: El nombre del cliente es obligatorio / El nombre del cliente debe ser texto / El nombre del cliente no puede superar los 255 caracteres). Se guarda normalizada: recorte, COLAPSO DE LOS ESPACIOS INTERNOS a uno y mayúsculas, así que enviar "  agro   del sur  " crea el cliente "AGRO DEL SUR". Un nombre de solo espacios sale por name.required. ATENCIÓN — si otro cliente ya lo usa, VIVO O BORRADO, la respuesta es 400 y NO 422; y como la normalización ocurre antes de comparar, enviar "agro del sur" existiendo "AGRO DEL SUR" también choca. El límite de 255 es el de la columna, no una regla de negocio.',
            type: 'string',
            maxLength: 255,
            example: 'agroexportadora del sur',
        ),
    ],
    type: 'object',
)]
class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the code and the name before the rules run.
     *
     * The two normalizers differ on purpose: the name collapses inner whitespace, the
     * code does not — it does not need to, because a code carrying any space at all is
     * rejected a line later by its own regex.
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
         * registeredBy no se acepta: la autoría sale del usuario autenticado, que por el
         * middleware role:administrator es siempre un administrador.
         *
         * Ninguno de los dos campos lleva regla unique, a propósito: la de Laravel no ve
         * las filas borradas, así que dejaría pasar un código ocupado por un cliente
         * eliminado y el 400 del service llegaría igual, un paso más tarde. Los dos
         * duplicados los levanta el service, y por un solo camino.
         */
        return [
            /** El espacio en un código es un error de captura, no una variante: se rechaza en vez de colapsarlo. */
            'code' => ['required', 'string', 'max:15', 'regex:/^\S+$/u'],
            'name' => ['required', 'string', 'max:255'],
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
