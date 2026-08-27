<?php

namespace App\Http\Requests\ShippingLine;

use App\Models\ShippingLine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateShippingLineRequest',
    title: 'Actualización de naviera',
    description: <<<'TEXT'
    Cuerpo JSON para corregir una naviera. El único campo editable es name, y es OPCIONAL: se toca solo lo que venga. UN CUERPO VACÍO ES UN NO-OP CON 200 —devuelve la naviera sin cambios— en vez de 422, como en el resto de los PATCH del proyecto.

    Opcional no es lo mismo que vaciable: el campo es sometimes|required, de modo que omitir la clave conserva el valor anterior pero enviarla vacía —o con solo espacios, que la normalización recorta— es 422. NO ACEPTA null.

    El registeredBy sigue sin aceptarse: mandarlo se descarta sin error y el autor del alta NO SE REESCRIBE, aunque edite otro administrador. Tampoco hay status que cambiar —este catálogo no lo tiene— ni forma de restaurar una naviera borrada.

    ATENCIÓN — SOBRE UNA NAVIERA YA BORRADA ESTE PATCH RESPONDE 400 «La naviera ya fue eliminada», NO 404. Es la asimetría deliberada del dominio: solo la escritura distingue «ya no está» de «nunca existió», porque el GET del detalle devuelve 404 en ambos casos a propósito.

    ATENCIÓN — MISMA REGLA DE DUPLICADOS QUE EL ALTA, Y SIGUE SIENDO 400 Y NUNCA 422. El campo no lleva regla unique, porque la de Laravel no ve las filas borradas; el choque lo levanta el service con «Ya existe una naviera con ese nombre, que puede haber sido eliminada». La comprobación IGNORA LA PROPIA FILA, así que reenviar el propio nombre responde 200 y no choca consigo mismo.

    El nombre se normaliza antes de validarse igual que en el alta: recorte, colapso de los espacios internos y mayúsculas.
    TEXT,
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nuevo nombre de la naviera. OPCIONAL: omitirlo conserva el actual y responde 200 sin cambios. Si se envía no puede venir vacío ni de solo espacios (El nombre de la naviera es obligatorio), debe ser texto (El nombre de la naviera debe ser texto) y no puede superar los 255 caracteres (El nombre de la naviera no puede superar los 255 caracteres). Se guarda recortado, con los espacios internos colapsados y en mayúsculas. Reenviar el propio nombre de la naviera que se edita responde 200; usar el de OTRA naviera —viva o borrada— responde 400, no 422, y la comparación es insensible a mayúsculas porque se hace ya normalizada.',
            type: 'string',
            maxLength: 255,
            example: 'maersk line guatemala',
        ),
    ],
    type: 'object',
)]
class UpdateShippingLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name before the rules run.
     *
     * Same rule as the store request: this catalog has a single business field and it
     * is validated identically whether it is being created or corrected.
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
         * sometimes|required: omitir el campo deja el valor anterior, pero mandarlo vacío
         * es un error. Un cuerpo vacío es un no-op con 200, como en el resto de los PATCH
         * del proyecto. registeredBy sigue sin aceptarse: mandarlo se descarta sin error y
         * el autor no cambia.
         */
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
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
