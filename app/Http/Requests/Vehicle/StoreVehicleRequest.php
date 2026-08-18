<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleCondition;
use App\Enums\VehicleType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreVehicleRequest',
    title: 'Alta de vehículo',
    description: <<<'TEXT'
    Cuerpo multipart/form-data para registrar un vehículo. Los TRECE campos son obligatorios: omitir cualquiera devuelve 422.

    CAMBIO INCOMPATIBLE respecto a la versión anterior de la API: el alta pedía siete campos (plate, brand, model, year, capacity, type e image) y ahora exige seis más (condition, kilometers_per_gallon, purchase_price, monthly_insurance_cost, mileage y engine_number). No hay periodo de gracia ni transición: cualquier cliente que siga enviando el cuerpo antiguo recibe 422 en TODAS las altas hasta que actualice su formulario. La edición y el listado sí siguen siendo compatibles, lo que puede dar la falsa impresión de que el alta también lo es.

    condition NO es status: son dos ejes distintos y ninguno reemplaza al otro. status es el estado operativo (active, inactive, under_repair), gobierna la baja lógica del DELETE y la unicidad condicional de la placa, y NO se acepta en este cuerpo: el vehículo nace siempre en active y enviarlo no cambia nada, se descarta sin error. condition (new, used) es cómo se adquirió el vehículo y no gobierna nada: no influye en el status inicial ni en ninguna otra regla del dominio.

    El carrier_id tampoco se envía: se resuelve desde la empresa del usuario autenticado.

    No hay ninguna validación cruzada entre los campos nuevos: un vehículo con condition = new y mileage = 90000 es válido, y un purchase_price de 0.01 también.

    La unicidad de la placa no es una regla de este FormRequest, vive en el servicio y depende del estado de las filas existentes, por lo que una placa ya tomada devuelve 400 y no 422. El número de motor, en cambio, NO es único: dos vehículos, de la misma empresa o de empresas distintas, pueden compartir engine_number y los dos se registran con 201.
    TEXT,
    required: ['plate', 'brand', 'model', 'year', 'capacity', 'type', 'condition', 'kilometers_per_gallon', 'purchase_price', 'monthly_insurance_cost', 'mileage', 'engine_number', 'image'],
    properties: [
        new OA\Property(
            property: 'plate',
            description: 'Placa del vehículo. Se normaliza a mayúsculas antes de validar la unicidad y antes de persistir, así que p123abc y P123ABC son la misma placa.',
            type: 'string',
            maxLength: 15,
            example: 'P123ABC',
        ),
        new OA\Property(property: 'brand', description: 'Marca del vehículo.', type: 'string', maxLength: 100, example: 'Kenworth'),
        new OA\Property(property: 'model', description: 'Modelo del vehículo.', type: 'string', maxLength: 100, example: 'T680'),
        new OA\Property(
            property: 'year',
            description: 'Año del vehículo. Debe ser un entero entre 1900 y el año siguiente al actual; fuera de ese rango devuelve 422.',
            type: 'integer',
            maximum: 2027,
            minimum: 1900,
            example: 2021,
        ),
        new OA\Property(
            property: 'capacity',
            description: 'Capacidad de carga EN LIBRAS. Debe ser numérica y no negativa. La unidad es solo una convención del dominio: nada impide enviar kilos y nada lo detectará.',
            type: 'number',
            format: 'float',
            minimum: 0,
            example: 15000.5,
        ),
        new OA\Property(
            property: 'type',
            description: 'Tipo de vehículo. Un valor fuera del enum devuelve 422.',
            type: 'string',
            enum: ['truck', 'van', 'trailer', 'pickup'],
            example: 'truck',
        ),
        new OA\Property(
            property: 'condition',
            description: 'Condición con la que se adquirió el vehículo. Un valor fuera del enum (por ejemplo antiguo) devuelve 422. NO CONFUNDIR CON status: status es el estado operativo (active, inactive, under_repair), gobierna la baja lógica y la unicidad de la placa, y no se acepta en el alta; condition solo dice si el vehículo se compró nuevo o usado y no gobierna nada, ni siquiera el status inicial, que siempre es active. Los valores viajan en inglés; traducirlos es responsabilidad del cliente.',
            type: 'string',
            enum: ['new', 'used'],
            example: 'used',
        ),
        new OA\Property(
            property: 'kilometers_per_gallon',
            description: 'Rendimiento de combustible EN KILÓMETROS POR GALÓN. Numérico y mayor que cero: el mínimo aceptado es 0.01 y un 0 devuelve 422, porque un rendimiento nulo es captura errónea, no un dato. Se guarda como decimal(6,2), así que el máximo es 9999.99 y se redondea a dos decimales. La unidad es una convención del dominio: nada impide enviar millas por galón o litros y nada lo detectará. El backend no convierte unidades ni usa este valor para calcular nada: es un dato de ficha y no alimenta la cotización de fletes.',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            example: 12.5,
        ),
        new OA\Property(
            property: 'purchase_price',
            description: 'Valor de compra del vehículo EN QUETZALES (GTQ). Numérico y mayor que cero: el mínimo aceptado es 0.01 y un 0 devuelve 422. Se guarda como decimal(12,2), así que el máximo es 9999999999.99 y se redondea a dos decimales. La moneda es una convención del dominio y no se guarda en base: nada valida que el importe sean quetzales. Es lo que costó el vehículo y no se recalcula nunca: no hay depreciación ni valor actual.',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            example: 185000,
        ),
        new OA\Property(
            property: 'monthly_insurance_cost',
            description: 'Costo del seguro EN QUETZALES (GTQ) y POR MES. Las dos cosas son convención del dominio y no se guardan en base: la columna no dice ni la moneda ni la periodicidad, así que enviar la prima anual la registrará como si fuera mensual y nada lo detectará. Numérico y mayor que cero: el mínimo aceptado es 0.01 y un 0 devuelve 422. Se guarda como decimal(10,2), máximo 99999999.99. Del seguro solo se guarda este número: no hay aseguradora, número de póliza, vigencia, deducible ni cobertura.',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            example: 1250,
        ),
        new OA\Property(
            property: 'mileage',
            description: 'Kilometraje del odómetro EN KILÓMETROS ENTEROS. A diferencia de los tres campos anteriores SÍ admite 0, porque un vehículo recién comprado con cero kilómetros es legítimo; un valor negativo devuelve 422 y uno con decimales también (120000.5 es 422, la regla es integer). Se guarda como entero sin signo, máximo 4294967295. En el alta lo captura sin restricción quien pueda registrar el vehículo; ATENCIÓN, en la edición este campo tiene autorización propia y solo un administrator puede cambiarlo (ver UpdateVehicleRequest). No hay bitácora: ningún cambio de kilometraje deja rastro.',
            type: 'integer',
            minimum: 0,
            example: 120000,
        ),
        new OA\Property(
            property: 'engine_number',
            description: 'Número de motor del vehículo, obligatorio. Se normaliza a MAYÚSCULAS antes de persistir, así que abc123 se guarda y se devuelve como ABC123, y el filtro engineNumber del listado busca sobre ese valor ya en mayúsculas. NO ES ÚNICO: a diferencia de la placa, dos vehículos pueden compartir el mismo número de motor sin conflicto (no hay índice ni comprobación en el servicio) y reactivar un vehículo sigue revalidando solo la placa. Máximo 50 caracteres; más devuelve 422. La columna admite null en base, pero por la API nunca se llega a ese estado: aquí es obligatorio y en la edición no se puede vaciar, así que un engineNumber null solo aparece en vehículos anteriores a esta versión.',
            type: 'string',
            maxLength: 50,
            example: 'ABC123456',
        ),
        new OA\Property(
            property: 'image',
            description: 'Archivo de imagen, obligatorio. Solo se aceptan jpg, jpeg y png; cualquier otro tipo devuelve 422. No puede pesar más de 3 MB (3072 KB, límite inclusivo). El archivo se almacena, pero NO tal cual: antes de subirlo se recorta a un cuadrado centrado y se reescala a 800x800 px, conservando el formato de entrada. Se pierden los bordes del lado largo y el original no se guarda en ningún sitio. Si la imagen no se puede procesar o el almacenamiento falla, la respuesta es 400 y el vehículo no se crea.',
            type: 'string',
            format: 'binary',
        ),
    ],
    type: 'object',
)]
class StoreVehicleRequest extends FormRequest
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
            'plate' => ['required', 'string', 'max:15'],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'capacity' => ['required', 'numeric', 'min:0'],
            'type' => ['required', Rule::enum(VehicleType::class)],
            'condition' => ['required', Rule::enum(VehicleCondition::class)],
            'kilometers_per_gallon' => ['required', 'numeric', 'min:0.01'],
            'purchase_price' => ['required', 'numeric', 'min:0.01'],
            'monthly_insurance_cost' => ['required', 'numeric', 'min:0.01'],
            'mileage' => ['required', 'integer', 'min:0'],
            'engine_number' => ['required', 'string', 'max:50'],
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:3072'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plate.required' => 'La placa es obligatoria',
            'plate.string' => 'La placa debe ser texto',
            'plate.max' => 'La placa no puede superar los 15 caracteres',
            'brand.required' => 'La marca es obligatoria',
            'brand.string' => 'La marca debe ser texto',
            'brand.max' => 'La marca no puede superar los 100 caracteres',
            'model.required' => 'El modelo es obligatorio',
            'model.string' => 'El modelo debe ser texto',
            'model.max' => 'El modelo no puede superar los 100 caracteres',
            'year.required' => 'El año es obligatorio',
            'year.integer' => 'El año debe ser un número entero',
            'year.min' => 'El año no puede ser anterior a 1900',
            'year.max' => 'El año no puede ser posterior a '.(date('Y') + 1),
            'capacity.required' => 'La capacidad en libras es obligatoria',
            'capacity.numeric' => 'La capacidad debe ser un número en libras',
            'capacity.min' => 'La capacidad no puede ser negativa',
            'type.required' => 'El tipo de vehículo es obligatorio',
            'type.enum' => 'El tipo de vehículo no es válido',
            'condition.required' => 'La condición del vehículo es obligatoria',
            'condition.enum' => 'La condición del vehículo no es válida',
            'kilometers_per_gallon.required' => 'El rendimiento en kilómetros por galón es obligatorio',
            'kilometers_per_gallon.numeric' => 'El rendimiento debe ser un número en kilómetros por galón',
            'kilometers_per_gallon.min' => 'El rendimiento debe ser mayor que cero',
            'purchase_price.required' => 'El valor de compra es obligatorio',
            'purchase_price.numeric' => 'El valor de compra debe ser un número',
            'purchase_price.min' => 'El valor de compra debe ser mayor que cero',
            'monthly_insurance_cost.required' => 'El costo mensual del seguro es obligatorio',
            'monthly_insurance_cost.numeric' => 'El costo mensual del seguro debe ser un número',
            'monthly_insurance_cost.min' => 'El costo mensual del seguro debe ser mayor que cero',
            'mileage.required' => 'El kilometraje es obligatorio',
            'mileage.integer' => 'El kilometraje debe ser un número entero de kilómetros',
            'mileage.min' => 'El kilometraje no puede ser negativo',
            'engine_number.required' => 'El número de motor es obligatorio',
            'engine_number.string' => 'El número de motor debe ser texto',
            'engine_number.max' => 'El número de motor no puede superar los 50 caracteres',
            'image.required' => 'La imagen es obligatoria',
            'image.file' => 'La imagen debe ser un archivo',
            'image.mimes' => 'La imagen debe ser un archivo jpg, jpeg o png',
            'image.max' => 'La imagen no puede pesar más de 3 MB',
        ];
    }
}
