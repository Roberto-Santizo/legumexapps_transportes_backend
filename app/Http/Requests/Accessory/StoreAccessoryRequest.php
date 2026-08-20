<?php

namespace App\Http\Requests\Accessory;

use App\Models\Accessory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreAccessoryRequest',
    title: 'Alta de accesorio',
    description: <<<'TEXT'
    Cuerpo JSON para dar de alta un accesorio del inventario nacional. Los campos aceptados son name, code, description, price, purchaseDate y annualDepreciation; todos son obligatorios salvo description.

    ATENCIÓN — UNA FILA ES UNA UNIDAD FÍSICA: NO existe el campo quantity. Para registrar dos llantas iguales hay que hacer DOS altas, con DOS códigos distintos; mandar una cantidad no crea varias filas ni da error, simplemente se ignora.

    El status NO se acepta: el accesorio nace siempre "active" y enviarlo se descarta sin error, así que no hay forma de crear un accesorio ya dado de baja o ya en reparación — para eso está el PATCH. El registeredBy tampoco se envía: se resuelve desde el usuario autenticado, que por el middleware role:administrator es siempre un administrador, y sale en la respuesta como el NOMBRE de esa persona. El currentValue tampoco se acepta: es un campo DERIVADO Y DE SOLO SALIDA que se calcula en cada lectura; mandarlo se IGNORA EN SILENCIO, sin 422 y sin aviso.

    ATENCIÓN — name y code se NORMALIZAN antes de validarse y antes de guardarse, pero CON REGLAS DISTINTAS. El name se recorta, se le COLAPSAN los espacios internos y se pasa a mayúsculas: "  gato   hidráulico  " crea "GATO HIDRÁULICO". El code se recorta y se pasa a mayúsculas pero NO se le colapsan los espacios internos, porque es un identificador y no una frase: "A 100" y "A100" son dos códigos DISTINTOS y los dos se aceptan. Como la normalización ocurre ANTES de las reglas unique, enviar "gato hidráulico" existiendo ya "GATO HIDRÁULICO" devuelve 422, no 500 ni una fila duplicada.

    Los DOS duplicados posibles se responden igual, con 422 desde el FormRequest: "Ya existe un accesorio con ese nombre" y "Ya existe un accesorio con ese código". No hay aquí la asimetría 422/400 de Locations. El service repite esas mismas comprobaciones y esos mismos mensajes como 400, pero solo se ven en una llamada directa al service: por HTTP siempre corta antes el FormRequest.

    ATENCIÓN — LA UNICIDAD DEL CÓDIGO ES GLOBAL Y NO MIRA EL STATUS: un accesorio dado de baja NO libera su código, así que no se puede reutilizar el código de una unidad retirada. Es la diferencia deliberada con la placa de un vehículo, que un status inactive sí libera.
    TEXT,
    required: ['name', 'code', 'price', 'purchaseDate', 'annualDepreciation'],
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nombre del accesorio: el TIPO de pieza, no la unidad. Se guarda normalizado y en MAYÚSCULAS —recorte, colapso de espacios internos y mayúsculas—, así que se puede enviar en minúsculas y el cliente debe pintar el name de la respuesta, no el que tecleó el usuario. Debe ser único a nivel global, comparado ya normalizado, de modo que la unicidad resulta insensible a mayúsculas. Ausente, vacío, no textual o de más de 255 caracteres devuelve 422 (mensajes: El nombre del accesorio es obligatorio / El nombre del accesorio debe ser texto / El nombre del accesorio no puede superar los 255 caracteres / Ya existe un accesorio con ese nombre). El límite de 255 es el de la columna, no una regla de negocio.',
            type: 'string',
            maxLength: 255,
            example: 'gato hidráulico 3 toneladas',
        ),
        new OA\Property(
            property: 'code',
            description: 'Código interno o número de serie de la unidad física. OBLIGATORIO —no se autogenera— y ÚNICO A NIVEL GLOBAL. ATENCIÓN — su normalización NO es la del name: se recorta y se pasa a mayúsculas pero NO se colapsan los espacios internos, así que "A 100" y "A100" son códigos DISTINTOS y los dos se pueden dar de alta a la vez. ATENCIÓN — la unicidad NO MIRA EL STATUS: el código de un accesorio dado de baja sigue ocupado para siempre, al contrario que la placa de un vehículo, que un inactive libera. Ausente, no textual o de más de 255 caracteres devuelve 422 (mensajes: El código del accesorio es obligatorio / El código del accesorio debe ser texto / El código del accesorio no puede superar los 255 caracteres / Ya existe un accesorio con ese código).',
            type: 'string',
            maxLength: 255,
            example: 'acc-0042',
        ),
        new OA\Property(
            property: 'description',
            description: 'Descripción libre del accesorio: marca, medidas, dónde está guardado. ÚNICO CAMPO OPCIONAL y nullable: omitirlo o enviar null guarda null. Máximo 1000 caracteres, y solo se valida que sea texto (mensajes: La descripción debe ser texto / La descripción no puede superar los 1000 caracteres). NO se normaliza —se guarda tal cual, con su capitalización— y NO participa en el filtro search del listado, que solo mira name y code.',
            type: 'string',
            maxLength: 1000,
            nullable: true,
            example: 'Marca Truper, guardado en bodega central, estante 4',
        ),
        new OA\Property(
            property: 'price',
            description: 'Precio de compra de la unidad, en GTQ (la moneda es convención: ningún campo la nombra). OBLIGATORIO, numérico y en [0.01, 99999999.99]. ATENCIÓN — el mínimo es 0.01 y NO 0: un precio de 0 es un error de captura, porque un accesorio siempre costó algo; enviar 0 devuelve 422 con "El precio debe ser mayor a 0". Ausente, no numérico o por encima del máximo también es 422 (El precio es obligatorio / El precio debe ser numérico / El precio no puede superar 99999999.99). Se guarda con dos decimales y VUELVE COMO CADENA en el recurso. Es la base del cálculo de currentValue.',
            type: 'number',
            format: 'float',
            maximum: 99999999.99,
            minimum: 0.01,
            example: 1250,
        ),
        new OA\Property(
            property: 'purchaseDate',
            description: 'Día en que se compró el accesorio. OBLIGATORIA, fecha válida y NO FUTURA: un accesorio se registra cuando ya se compró, y una fecha posterior a hoy sería una orden de compra, no un activo (mensaje: La fecha de compra no puede ser futura). Hoy SÍ se acepta, y ese día currentValue es exactamente igual a price. Ausente o no parseable es 422 (La fecha de compra es obligatoria / La fecha de compra debe ser una fecha válida). Se envía en formato ISO (Y-m-d) pero VUELVE en d-m-Y en el recurso: los formatos de entrada y de salida NO coinciden. Es el origen del cálculo de la antigüedad y por tanto del currentValue.',
            type: 'string',
            format: 'date',
            example: '2024-03-15',
        ),
        new OA\Property(
            property: 'annualDepreciation',
            description: 'Porcentaje ANUAL de depreciación LINEAL. OBLIGATORIO, numérico y en [0, 100] — los DOS extremos se aceptan. Es un PORCENTAJE, no una fracción: 20 significa 20 % anual, no 2000 %. Con 0 el accesorio no se deprecia nunca y su currentValue es igual a price para siempre; con 100 llega a 0.00 al cumplir el año. Ausente, no numérico o fuera de rango es 422 (El porcentaje de depreciación anual es obligatorio / El porcentaje de depreciación anual debe ser numérico / El porcentaje de depreciación anual no puede ser negativo / El porcentaje de depreciación anual no puede superar 100). NO se valida contra la vida útil ni contra nada más: no hay reglas cruzadas con price ni con purchaseDate.',
            type: 'number',
            format: 'float',
            maximum: 100,
            minimum: 0,
            example: 20,
        ),
    ],
    type: 'object',
)]
class StoreAccessoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name and the code before the rules run.
     *
     * Without it "gato hidráulico" would pass the unique rule while GATO HIDRÁULICO
     * exists and blow up against the unique index with a 500 instead of a 422.
     *
     * The two normalizers differ on purpose: the name collapses inner whitespace, the
     * code does not, because `A 100` and `A100` are two different codes.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Accessory::normalizeName($this->input('name'))]);
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => Accessory::normalizeCode($this->input('code'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * status y registeredBy no se aceptan: el accesorio nace activo y la autoría sale
         * del usuario autenticado. currentValue tampoco: es un campo derivado de solo
         * salida, y mandarlo se ignora en silencio.
         */
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('accessories', 'name')],
            /** Único global: la unicidad no mira el status, así que un inactive no libera su código. */
            'code' => ['required', 'string', 'max:255', Rule::unique('accessories', 'code')],
            'description' => ['nullable', 'string', 'max:1000'],
            /** Cero es un error de captura: un accesorio siempre costó algo. */
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            /** Un accesorio se registra cuando ya se compró; una fecha futura sería una orden de compra. */
            'purchaseDate' => ['required', 'date', 'before_or_equal:today'],
            /** Se admite 0: hay activos que no se deprecian y siempre valen su precio. */
            'annualDepreciation' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del accesorio es obligatorio',
            'name.string' => 'El nombre del accesorio debe ser texto',
            'name.max' => 'El nombre del accesorio no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un accesorio con ese nombre',
            'code.required' => 'El código del accesorio es obligatorio',
            'code.string' => 'El código del accesorio debe ser texto',
            'code.max' => 'El código del accesorio no puede superar los 255 caracteres',
            'code.unique' => 'Ya existe un accesorio con ese código',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 1000 caracteres',
            'price.required' => 'El precio es obligatorio',
            'price.numeric' => 'El precio debe ser numérico',
            'price.min' => 'El precio debe ser mayor a 0',
            'price.max' => 'El precio no puede superar 99999999.99',
            'purchaseDate.required' => 'La fecha de compra es obligatoria',
            'purchaseDate.date' => 'La fecha de compra debe ser una fecha válida',
            'purchaseDate.before_or_equal' => 'La fecha de compra no puede ser futura',
            'annualDepreciation.required' => 'El porcentaje de depreciación anual es obligatorio',
            'annualDepreciation.numeric' => 'El porcentaje de depreciación anual debe ser numérico',
            'annualDepreciation.min' => 'El porcentaje de depreciación anual no puede ser negativo',
            'annualDepreciation.max' => 'El porcentaje de depreciación anual no puede superar 100',
        ];
    }
}
