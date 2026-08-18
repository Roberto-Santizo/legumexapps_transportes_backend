<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateVehicleRequest',
    title: 'Actualización de vehículo',
    description: <<<'TEXT'
    Actualización parcial: los catorce campos son opcionales y solo se modifica lo que llega. Un campo enviado vacío sí es error (422). A diferencia del alta, aquí sí se acepta status.

    A diferencia del alta, este cuerpo NO es un cambio incompatible: los seis campos nuevos de la ficha (condition, kilometers_per_gallon, purchase_price, monthly_insurance_cost, mileage y engine_number) se suman como opcionales y un cliente que siga enviando el cuerpo antiguo sigue funcionando igual.

    condition NO es status, y aquí conviven los dos en el mismo cuerpo: status es el estado operativo (active, inactive, under_repair), gobierna la baja lógica del DELETE y la unicidad condicional de la placa —cambiarlo puede revalidar la placa y devolver 400—; condition (new, used) es cómo se adquirió el vehículo y no gobierna nada. Son ejes independientes: cambiar condition no altera status y cambiar status no altera condition.

    ATENCIÓN — mileage tiene AUTORIZACIÓN PROPIA, la única del proyecto a nivel de campo: el endpoint es alcanzable por carrier y administrator, pero solo un administrator puede enviar un mileage DISTINTO al almacenado. Un carrier que lo intente recibe 403 con el mensaje "Solo un administrador puede modificar el kilometraje del vehículo" y NINGÚN otro campo del cuerpo se aplica: la petición se aborta entera, incluida la imagen, que ni siquiera llega a subirse. Enviar el mismo valor que el vehículo ya tiene no es un cambio y pasa con 200 para cualquier rol, que es el caso del front que reenvía el formulario completo con el campo oculto. La regla vive en el servicio, no en un middleware, así que no se ve mirando las rutas.

    La empresa dueña del vehículo no se puede cambiar: no existe campo para ello.
    TEXT,
    properties: [
        new OA\Property(
            property: 'plate',
            description: 'Nueva placa. Se normaliza a mayúsculas. Solo se revalida la unicidad cuando cambia respecto a la que ya tiene el vehículo, así que reenviar la misma placa nunca es un conflicto consigo mismo. Si la placa nueva la usa un vehículo de cualquier empresa cuyo status no sea inactive, la respuesta es 400.',
            type: 'string',
            maxLength: 15,
            example: 'P456XYZ',
        ),
        new OA\Property(property: 'brand', description: 'Nueva marca. Si se envía, no puede estar vacía.', type: 'string', maxLength: 100, example: 'Freightliner'),
        new OA\Property(property: 'model', description: 'Nuevo modelo. Si se envía, no puede estar vacío.', type: 'string', maxLength: 100, example: 'Cascadia'),
        new OA\Property(
            property: 'year',
            description: 'Nuevo año. Entero entre 1900 y el año siguiente al actual.',
            type: 'integer',
            maximum: 2027,
            minimum: 1900,
            example: 2023,
        ),
        new OA\Property(
            property: 'capacity',
            description: 'Nueva capacidad de carga EN LIBRAS. Numérica y no negativa; la unidad no se valida.',
            type: 'number',
            format: 'float',
            minimum: 0,
            example: 18000.75,
        ),
        new OA\Property(
            property: 'type',
            description: 'Nuevo tipo de vehículo. Un valor fuera del enum devuelve 422.',
            type: 'string',
            enum: ['truck', 'van', 'trailer', 'pickup'],
            example: 'trailer',
        ),
        new OA\Property(
            property: 'condition',
            description: 'Nueva condición de adquisición. Si se envía, no puede estar vacía y un valor fuera del enum devuelve 422; si se omite, no se toca. NO CONFUNDIR CON status, que también viaja en este cuerpo: status es el estado operativo y gobierna la baja lógica y la unicidad de la placa; condition solo dice si el vehículo se compró nuevo o usado y no gobierna nada. Son independientes: cambiar condition no altera status, y cambiar status no altera condition.',
            type: 'string',
            enum: ['new', 'used'],
            example: 'new',
        ),
        new OA\Property(
            property: 'kilometers_per_gallon',
            description: 'Nuevo rendimiento EN KILÓMETROS POR GALÓN. Si se envía, debe ser numérico y mayor que cero: el mínimo es 0.01 y un 0 devuelve 422. Se guarda como decimal(6,2), máximo 9999.99. Si se omite, no se toca. La unidad no se valida y el valor no alimenta ningún cálculo.',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            example: 14.75,
        ),
        new OA\Property(
            property: 'purchase_price',
            description: 'Nuevo valor de compra EN QUETZALES (GTQ). Si se envía, debe ser numérico y mayor que cero: el mínimo es 0.01 y un 0 devuelve 422. Se guarda como decimal(12,2), máximo 9999999999.99. Si se omite, no se toca. La moneda es convención del dominio y no se valida; el importe no se recalcula ni se deprecia nunca, solo se corrige a mano por aquí.',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            example: 190000,
        ),
        new OA\Property(
            property: 'monthly_insurance_cost',
            description: 'Nuevo costo del seguro EN QUETZALES (GTQ) y POR MES. Las dos cosas son convención del dominio y no se validan: mandar la prima anual la guardará como si fuera mensual. Si se envía, debe ser numérico y mayor que cero: el mínimo es 0.01 y un 0 devuelve 422. Se guarda como decimal(10,2), máximo 99999999.99. Si se omite, no se toca.',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            example: 1400,
        ),
        new OA\Property(
            property: 'mileage',
            description: 'Nuevo kilometraje EN KILÓMETROS ENTEROS. ATENCIÓN, ES EL ÚNICO CAMPO DEL PROYECTO CON AUTORIZACIÓN PROPIA: aunque el endpoint lo alcanzan carrier y administrator, solo un administrator puede enviarlo con un valor DISTINTO al que ya tiene el vehículo. Un carrier que lo intente recibe 403 con el mensaje "Solo un administrador puede modificar el kilometraje del vehículo", y entonces NINGÚN otro campo del cuerpo se aplica: el PATCH se aborta entero y ni siquiera se sube la imagen, para no dejar archivos huérfanos. Enviar EL MISMO valor que el vehículo ya tiene NO es un cambio: pasa con 200 sea cual sea el rol y el resto del cuerpo se aplica con normalidad; es el caso del front que reenvía el formulario completo con el campo oculto. La comparación es sobre enteros, así que la cadena "120000" y el número 120000 son el mismo kilometraje y no disparan el 403. Un administrator puede subirlo Y BAJARLO sin restricción, sin bitácora y sin forma de recuperar el valor anterior. Validación normal: entero, mínimo 0 (se admite), negativo o con decimales es 422. La regla vive en el servicio, no en un middleware: no se ve mirando las rutas.',
            type: 'integer',
            minimum: 0,
            example: 135000,
        ),
        new OA\Property(
            property: 'engine_number',
            description: 'Nuevo número de motor. Se normaliza a MAYÚSCULAS antes de persistir: xyz789 se guarda como XYZ789. NO SE PUEDE VACIAR: si se envía, tiene que traer valor, y un null (igual que una cadena vacía) devuelve 422; para dejarlo sin tocar hay que omitir la clave. La columna admite null en base solo por los vehículos anteriores a esta versión, pero por la API no hay forma de volver a ese estado. NO ES ÚNICO: dos vehículos pueden compartirlo sin conflicto, no se comprueba en el alta ni aquí, y reactivar un vehículo desactivado sigue revalidando solo la placa, nunca el número de motor. Máximo 50 caracteres.',
            type: 'string',
            maxLength: 50,
            example: 'XYZ789012',
        ),
        new OA\Property(
            property: 'image',
            description: 'Nuevo archivo de imagen. Solo jpg, jpeg y png, y no más de 3 MB (3072 KB, límite inclusivo). Igual que en el alta, se recorta a un cuadrado centrado de 800x800 px antes de subirla, conservando el formato. Al reemplazarla se borra la imagen anterior del almacenamiento, de forma irreversible. Requiere enviar el cuerpo como multipart/form-data.',
            type: 'string',
            format: 'binary',
        ),
        new OA\Property(
            property: 'status',
            description: 'Nuevo estado operativo. Un valor fuera del enum devuelve 422. ATENCIÓN: sacar un vehículo de inactive (a active o a under_repair) revalida su placa aunque no se envíe, porque un vehículo que vuelve al servicio no puede compartir placa con otro que ya la usa. Si en el intervalo otra empresa registró esa placa, la reactivación devuelve 400 con el mensaje: No puedes reactivar este vehículo: su placa ya está registrada en otro vehículo que no está desactivado. Resolver ese conflicto (liberar la placa o cambiarla en la misma operación) está fuera del alcance de la SPEC 04: hoy el vehículo se queda desactivado. Mientras el vehículo siga en inactive, su placa duplicada no molesta y el resto de campos se pueden actualizar con normalidad.',
            type: 'string',
            enum: ['active', 'inactive', 'under_repair'],
            example: 'under_repair',
        ),
    ],
    type: 'object',
)]
class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'plate' => ['sometimes', 'required', 'string', 'max:15'],
            'brand' => ['sometimes', 'required', 'string', 'max:100'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'year' => ['sometimes', 'required', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'capacity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'type' => ['sometimes', 'required', Rule::enum(VehicleType::class)],
            'condition' => ['sometimes', 'required', Rule::enum(VehicleCondition::class)],
            'kilometers_per_gallon' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'purchase_price' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'monthly_insurance_cost' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'mileage' => ['sometimes', 'required', 'integer', 'min:0'],
            'engine_number' => ['sometimes', 'required', 'string', 'max:50'],
            'image' => ['sometimes', 'required', 'file', 'mimes:jpg,jpeg,png', 'max:3072'],
            'status' => ['sometimes', 'required', Rule::enum(VehicleStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plate.required' => 'La placa no puede estar vacía',
            'plate.string' => 'La placa debe ser texto',
            'plate.max' => 'La placa no puede superar los 15 caracteres',
            'brand.required' => 'La marca no puede estar vacía',
            'brand.string' => 'La marca debe ser texto',
            'brand.max' => 'La marca no puede superar los 100 caracteres',
            'model.required' => 'El modelo no puede estar vacío',
            'model.string' => 'El modelo debe ser texto',
            'model.max' => 'El modelo no puede superar los 100 caracteres',
            'year.required' => 'El año no puede estar vacío',
            'year.integer' => 'El año debe ser un número entero',
            'year.min' => 'El año no puede ser anterior a 1900',
            'year.max' => 'El año no puede ser posterior a '.(date('Y') + 1),
            'capacity.required' => 'La capacidad en libras no puede estar vacía',
            'capacity.numeric' => 'La capacidad debe ser un número en libras',
            'capacity.min' => 'La capacidad no puede ser negativa',
            'type.required' => 'El tipo de vehículo no puede estar vacío',
            'type.enum' => 'El tipo de vehículo no es válido',
            'condition.required' => 'La condición del vehículo no puede estar vacía',
            'condition.enum' => 'La condición del vehículo no es válida',
            'kilometers_per_gallon.required' => 'El rendimiento en kilómetros por galón no puede estar vacío',
            'kilometers_per_gallon.numeric' => 'El rendimiento debe ser un número en kilómetros por galón',
            'kilometers_per_gallon.min' => 'El rendimiento debe ser mayor que cero',
            'purchase_price.required' => 'El valor de compra no puede estar vacío',
            'purchase_price.numeric' => 'El valor de compra debe ser un número',
            'purchase_price.min' => 'El valor de compra debe ser mayor que cero',
            'monthly_insurance_cost.required' => 'El costo mensual del seguro no puede estar vacío',
            'monthly_insurance_cost.numeric' => 'El costo mensual del seguro debe ser un número',
            'monthly_insurance_cost.min' => 'El costo mensual del seguro debe ser mayor que cero',
            'mileage.required' => 'El kilometraje no puede estar vacío',
            'mileage.integer' => 'El kilometraje debe ser un número entero de kilómetros',
            'mileage.min' => 'El kilometraje no puede ser negativo',
            'engine_number.required' => 'El número de motor no puede estar vacío',
            'engine_number.string' => 'El número de motor debe ser texto',
            'engine_number.max' => 'El número de motor no puede superar los 50 caracteres',
            'image.required' => 'La imagen no puede estar vacía',
            'image.file' => 'La imagen debe ser un archivo',
            'image.mimes' => 'La imagen debe ser un archivo jpg, jpeg o png',
            'image.max' => 'La imagen no puede pesar más de 3 MB',
            'status.required' => 'El estado no puede estar vacío',
            'status.enum' => 'El estado del vehículo no es válido',
        ];
    }
}
