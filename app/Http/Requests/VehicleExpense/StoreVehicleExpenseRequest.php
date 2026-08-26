<?php

namespace App\Http\Requests\VehicleExpense;

use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreVehicleExpenseRequest',
    title: 'Alta de gasto de vehículo',
    description: <<<'TEXT'
    Cuerpo para registrar un gasto de mantenimiento. Los SIETE campos son obligatorios y ninguno acepta null: omitir cualquiera devuelve 422. El único condicional es invoice, obligatorio solo cuando is_invoiced es verdadero.

    ATENCIÓN — CAMBIO INCOMPATIBLE SIN PERIODO DE GRACIA: is_invoiced es obligatorio desde esta versión. Un alta que antes devolvía 201 con seis campos ahora devuelve 422 con el mensaje Debes indicar si el gasto fue facturado. No tiene valor por omisión de cara a la API: quien registra el gasto decide siempre.

    ATENCIÓN — CUANDO SE ADJUNTA FACTURA EL CUERPO VA EN multipart/form-data, NO EN JSON. Un archivo no viaja en un cuerpo JSON. Sin factura (is_invoiced en false) sirven los dos formatos.

    ATENCIÓN — EL CUERPO VA EN snake_case Y LA RESPUESTA VUELVE EN camelCase. Aquí se envían vehicle_id y expense_date, y el recurso devuelto trae vehicleId y expenseDate. No se puede reenviar un objeto VehicleExpense tal como salió de un GET: vehicleId y expenseDate no son campos válidos de este cuerpo, y los obligatorios vehicle_id y expense_date faltarían.

    No existe validación cruzada entre category y nature: cualquiera de las 22 categorías se acepta con preventive y con corrective. Tampoco hay validación cruzada con el vehículo: su estado NO importa, y un vehículo con status inactive acepta gastos igual que uno active, porque el mantenimiento pudo ocurrir antes de la baja.

    Campos que NO se aceptan aquí y que enviarlos no hace nada: registered_by —sale siempre del usuario autenticado, así que mandar el id de otro usuario no lo cambia— y cualquier otro nombre desconocido, que se descarta en silencio.

    vehicle_id NO lleva regla exists: un vehículo inexistente lo resuelve el servicio y responde 404 (El vehículo no existe), y uno de otra empresa responde 403 (No puedes acceder a un vehículo que no pertenece a tu empresa transportista). Ninguno de los dos casos es un 422.
    TEXT,
    required: ['vehicle_id', 'category', 'nature', 'amount', 'expense_date', 'description', 'is_invoiced'],
    properties: [
        new OA\Property(
            property: 'vehicle_id',
            description: 'Identificador del vehículo al que se imputa el gasto (vehicles.id). Debe ser un entero; un valor no entero devuelve 422 con el mensaje El vehículo debe ser un número entero. Queda FIJADO PARA SIEMPRE: el PATCH no acepta este campo y mover un gasto de vehículo es borrarlo y volverlo a crear. En la respuesta sale como vehicleId.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'category',
            description: 'Categoría del gasto. Debe ser uno de los 22 valores del enum; cualquier otro (por ejemplo motor_fundido) devuelve 422 con el mensaje La categoría del gasto no es válida. Los valores viajan en inglés y en snake_case, y traducirlos es responsabilidad del cliente. other es un valor más y no recibe trato especial.',
            type: 'string',
            enum: [
                'tires', 'oil_change', 'brakes', 'spare_part', 'battery', 'suspension',
                'engine', 'transmission', 'electrical_system', 'cooling_system', 'filters',
                'alignment_balancing', 'clutch', 'exhaust', 'air_conditioning', 'bodywork_paint',
                'glass_mirrors', 'inspection', 'washing', 'towing', 'labor', 'other',
            ],
            example: 'tires',
        ),
        new OA\Property(
            property: 'nature',
            description: 'Naturaleza del gasto: preventive si se hizo antes de que fallara, corrective si se hizo porque ya falló. Obligatorio y sin tercer valor: no hay "sin clasificar". Un valor fuera del enum devuelve 422 con el mensaje La naturaleza del gasto no es válida. NO depende de category: brakes + preventive y brakes + corrective se aceptan igual.',
            type: 'string',
            enum: ['preventive', 'corrective'],
            example: 'preventive',
        ),
        new OA\Property(
            property: 'amount',
            description: 'Monto del gasto EN QUETZALES (GTQ). Numérico y MAYOR QUE CERO: el mínimo aceptado es 0.01 y un 0 o un negativo devuelven 422 con el mensaje El monto debe ser mayor que cero, porque un gasto de cero es un error de captura, no un gasto. El máximo es 99999999.99 —lo que cabe en decimal(10,2)—; superarlo devuelve 422. Se envía como número y se DEVUELVE como cadena con dos decimales. La moneda es convención del dominio: nada valida que el importe sean quetzales.',
            type: 'number',
            format: 'float',
            maximum: 99999999.99,
            minimum: 0.01,
            example: 1250.5,
        ),
        new OA\Property(
            property: 'expense_date',
            description: 'DÍA en que ocurrió el gasto, en formato Y-m-d. NO ADMITE FECHAS FUTURAS (before_or_equal:today): la de mañana devuelve 422 con el mensaje La fecha del gasto no puede ser futura, mientras que la de hoy se acepta —un gasto se registra cuando ya ocurrió, y el mantenimiento programado está fuera de alcance—. No lleva hora: cuándo se capturó ya lo dice createdAt. ATENCIÓN al formato de vuelta: entra como 2026-08-12 y el recurso lo devuelve como 12-08-2026 en el campo expenseDate.',
            type: 'string',
            format: 'date',
            example: '2026-08-12',
        ),
        new OA\Property(
            property: 'description',
            description: 'Detalle libre del gasto. OBLIGATORIO —no es un campo de notas opcional— y de hasta 1000 caracteres; superarlos devuelve 422. Es donde caben hoy el taller, el número de factura y la pieza concreta: no existen columnas supplier ni invoice_number, así que este texto es el único sitio donde queda esa información.',
            type: 'string',
            maxLength: 1000,
            example: 'Cuatro llantas nuevas, taller El Rodaje, factura A-9912',
        ),
        new OA\Property(
            property: 'is_invoiced',
            description: 'Si el gasto fue facturado. OBLIGATORIO: omitirlo devuelve 422 con el mensaje Debes indicar si el gasto fue facturado, y es el CAMBIO INCOMPATIBLE de esta versión. Acepta true, false, 1, 0 y las cadenas "1" y "0" —lo que manda un formulario multipart—; cualquier otro valor, por ejemplo "quizá", devuelve 422 con el mensaje La facturación debe ser verdadero o falso. QUEDA FIJADO PARA SIEMPRE: el PATCH no lo acepta y mandarlo allí no es un error, simplemente se ignora. Corregir un gasto mal facturado es borrarlo y volverlo a crear. En la respuesta sale como isInvoiced y como booleano JSON de verdad, nunca como 1 ni 0.',
            type: 'boolean',
            example: true,
        ),
        new OA\Property(
            property: 'invoice',
            description: 'Archivo de la factura: jpg, jpeg, png o pdf, de 3 MB (3072 KB) como máximo —el límite es inclusivo—. OBLIGATORIO SOLO SI is_invoiced ES VERDADERO: en ese caso omitirlo devuelve 422 con el mensaje La factura es obligatoria cuando el gasto fue facturado, un tipo no permitido devuelve La factura debe ser un archivo jpg, jpeg, png o pdf y pasarse de tamaño devuelve La factura no puede pesar más de 3 MB. CUIDADO — SI is_invoiced ES FALSO EL ARCHIVO SE DESCARTA EN SILENCIO: la respuesta es 201, no 422, el gasto se crea con invoiceUrl en null y no se sube nada; el cliente puede detectarlo leyendo isInvoiced en la respuesta y avisar en pantalla. El archivo se guarda TAL CUAL, sin recorte ni recompresión, y el tipo se deduce de su contenido, no de su nombre: un factura.exe con contenido JPEG se guarda como .jpg. SE FIJA EN EL ALTA Y NO SE PUEDE CAMBIAR: el PATCH lo ignora en silencio y responde 200 sin subir nada. Requiere upload_max_filesize y post_max_size de 4M o más en el servidor; si PHP corta antes, el error que llega es un required confuso y no uno de tamaño.',
            type: 'string',
            format: 'binary',
        ),
    ],
    type: 'object',
)]
class StoreVehicleExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Values accepted as a true `is_invoiced`, matching Laravel's own boolean rule.
     *
     * @var list<bool|int|string>
     */
    private const TRUE_VALUES = [true, 1, '1'];

    /**
     * Values accepted as a false `is_invoiced`.
     *
     * @var list<bool|int|string>
     */
    private const FALSE_VALUES = [false, 0, '0'];

    /**
     * Cast `is_invoiced` to a real boolean, but only when the key is present.
     *
     * Merging on absence would turn a missing flag into a false one and let the
     * `required` rule pass, which is exactly what this spec refuses: whoever
     * registers the expense always decides. Anything outside the accepted
     * representations is left untouched so the `boolean` rule rejects it.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('is_invoiced')) {
            return;
        }

        $value = $this->input('is_invoiced');

        if (in_array($value, self::TRUE_VALUES, true)) {
            $this->merge(['is_invoiced' => true]);

            return;
        }

        if (in_array($value, self::FALSE_VALUES, true)) {
            $this->merge(['is_invoiced' => false]);
        }
    }

    /**
     * The seven business fields are required, and none of them accepts null.
     *
     * `vehicle_id` deliberately carries no `exists` rule: a vehicle that does
     * not exist is resolved by the service and answers 404, not 422, and one
     * belonging to another company answers 403.
     *
     * `invoice` is only validated when `is_invoiced` is already normalized to
     * true. `required_if` is not used on purpose: it compares loosely against
     * the string 'true' and breaks with the '1' a multipart form sends. With a
     * false flag the file carries no rules at all, so whatever arrives is
     * discarded in silence instead of answering 422.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'vehicle_id' => ['required', 'integer'],
            'category' => ['required', Rule::enum(VehicleExpenseCategory::class)],
            'nature' => ['required', Rule::enum(VehicleExpenseNature::class)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:1000'],
            'is_invoiced' => ['required', 'boolean'],
        ];

        if ($this->input('is_invoiced') === true) {
            $rules['invoice'] = ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:3072'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'El vehículo es obligatorio',
            'vehicle_id.integer' => 'El vehículo debe ser un número entero',
            'category.required' => 'La categoría del gasto es obligatoria',
            'category.enum' => 'La categoría del gasto no es válida',
            'nature.required' => 'La naturaleza del gasto es obligatoria',
            'nature.enum' => 'La naturaleza del gasto no es válida',
            'amount.required' => 'El monto es obligatorio',
            'amount.numeric' => 'El monto debe ser un número',
            'amount.min' => 'El monto debe ser mayor que cero',
            'amount.max' => 'El monto no puede superar los 99999999.99',
            'expense_date.required' => 'La fecha del gasto es obligatoria',
            'expense_date.date' => 'La fecha del gasto no es válida',
            'expense_date.before_or_equal' => 'La fecha del gasto no puede ser futura',
            'description.required' => 'La descripción es obligatoria',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 1000 caracteres',
            'is_invoiced.required' => 'Debes indicar si el gasto fue facturado',
            'is_invoiced.boolean' => 'La facturación debe ser verdadero o falso',
            'invoice.required' => 'La factura es obligatoria cuando el gasto fue facturado',
            'invoice.file' => 'La factura debe ser un archivo',
            'invoice.mimes' => 'La factura debe ser un archivo jpg, jpeg, png o pdf',
            'invoice.max' => 'La factura no puede pesar más de 3 MB',
        ];
    }
}
