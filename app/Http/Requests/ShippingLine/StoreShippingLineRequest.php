<?php

namespace App\Http\Requests\ShippingLine;

use App\Models\ShippingLine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreShippingLineRequest',
    title: 'Alta de naviera',
    description: <<<'TEXT'
    Cuerpo JSON para dar de alta una naviera del catálogo nacional. SOLO SE ACEPTA UN CAMPO, name, y es OBLIGATORIO. No hay sigla, ni contacto, ni teléfono, ni correo, ni país, ni web, ni notas, ni logo, ni status: es el alta más pequeña del proyecto, por debajo incluso de la de un Cliente. Cualquier otra clave que se envíe se descarta en silencio, sin error.

    El registeredBy NO se envía: se resuelve desde el usuario autenticado, que por el middleware role:administrator es siempre un administrador. Mandarlo en el cuerpo no cambia nada y la naviera queda registrada a nombre de quien hizo la petición.

    ATENCIÓN — NO HAY status Y NO SE PUEDE CREAR UNA NAVIERA «INACTIVA». Este catálogo no tiene el booleano de publicación que sí tienen Products, Locations, Zones y Departure Points: una naviera existe o está borrada, y el borrado no se deshace por la API.

    ATENCIÓN — EL ÚNICO DUPLICADO POSIBLE SE RESPONDE CON 400 DESDE EL SERVICE, NUNCA CON 422, y es lo contrario de lo que hacen los otros catálogos. El campo no lleva regla unique a propósito: la de Laravel NO VE LAS FILAS BORRADAS y dejaría pasar un nombre ocupado por una naviera eliminada, que después chocaría contra el índice único con un 500. El mensaje es «Ya existe una naviera con ese nombre, que puede haber sido eliminada».

    ATENCIÓN — EL BORRADO NO LIBERA EL NOMBRE. Una naviera eliminada lo sigue reservando para siempre, así que el 400 puede venir de una fila que NO APARECE EN NINGÚN ENDPOINT: por eso el mensaje avisa de que la ocupante «puede haber sido eliminada». No existe forma de listar las borradas para averiguar cuál es, y como el nombre es el único identificador del dominio, no queda ningún otro dato por el que reconocerla.

    EL NOMBRE SE NORMALIZA ANTES DE VALIDARSE: se recorta, se le colapsan los espacios internos y se pasa a mayúsculas. El cliente debe pintar el name de la respuesta, no el que tecleó el usuario.
    TEXT,
    required: ['name'],
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nombre de la naviera. OBLIGATORIO, texto y de 255 caracteres como máximo (mensajes: El nombre de la naviera es obligatorio / El nombre de la naviera debe ser texto / El nombre de la naviera no puede superar los 255 caracteres). ES EL ÚNICO CAMPO DEL CUERPO. Se guarda normalizado: recorte, COLAPSO DE LOS ESPACIOS INTERNOS a uno y mayúsculas, así que enviar "  maersk   line  " crea la naviera "MAERSK LINE" y se puede teclear en minúsculas. Un nombre de solo espacios sale por name.required, porque el recorte lo deja vacío antes de validarse. ATENCIÓN — si otra naviera ya lo usa, VIVA O BORRADA, la respuesta es 400 y NO 422; y como la normalización ocurre antes de comparar, enviar "maersk line" existiendo "MAERSK LINE" también choca. El límite de 255 es el de la columna, no una regla de negocio.',
            type: 'string',
            maxLength: 255,
            example: 'maersk line',
        ),
    ],
    type: 'object',
)]
class StoreShippingLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name before the rules run.
     *
     * Trimming happens here and not in the rules so that a name of only spaces is left
     * empty and caught by required, instead of passing as a 255 character blank.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => ShippingLine::normalizeName($this->input('name'))]);
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
         * El campo no lleva regla unique, a propósito: la de Laravel no ve las filas
         * borradas, así que dejaría pasar un nombre ocupado por una naviera eliminada y el
         * 400 del service llegaría igual, un paso más tarde. El duplicado lo levanta el
         * service, y por un solo camino.
         */
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la naviera es obligatorio',
            'name.string' => 'El nombre de la naviera debe ser texto',
            'name.max' => 'El nombre de la naviera no puede superar los 255 caracteres',
        ];
    }
}
