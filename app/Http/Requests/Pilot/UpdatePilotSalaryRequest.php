<?php

namespace App\Http\Requests\Pilot;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdatePilotSalaryRequest',
    title: 'Asignación de salario a un piloto',
    description: <<<'TEXT'
    Cuerpo JSON de PATCH /api/pilots/{pilot}/salary. Tiene UN SOLO campo, salary, y es OBLIGATORIO: al contrario que los PATCH de FreightRates, Zones o Products, aquí un CUERPO VACÍO es un 422 y no un no-op con 200. El endpoint existe únicamente para cambiar el salario, así que no mandarlo es un error del cliente.

    NO SE ACEPTA NINGÚN OTRO CAMPO. En particular NO se acepta changedBy: el autor del cambio sale SIEMPRE del usuario autenticado, y mandarlo en el cuerpo no tiene ningún efecto —ni lo escribe, ni produce error—. Tampoco hay reason, notes ni effective_from: la bitácora registra el qué y el quién, no el por qué, y el cambio rige desde que se guarda.

    Sirve tanto para la PRIMERA asignación —el piloto tenía salary null— como para cualquier cambio posterior. No hay dos endpoints distintos: la diferencia solo se nota en la bitácora, donde la primera entrada es la única con previousSalary null.

    ATENCIÓN — MANDAR EL MISMO SALARIO QUE EL PILOTO YA TIENE RESPONDE 400, no 200 ni 422, y no escribe nada en la bitácora. La comparación se hace sobre el valor FORMATEADO A DOS DECIMALES, así que contra un salary de '4500.00' los tres valores 4500, 4500.00 y 4500.004 son el mismo salario y los tres devuelven 400. Es deliberado: corta de raíz el reenvío del mismo formulario y garantiza que toda fila del historial sea un cambio real.

    BAJAR EL SALARIO ESTÁ PERMITIDO, sin restricción y sin aprobación: se registra exactamente igual que una subida. Lo que no se puede es poner a alguien en cero (min 0.01), porque eso sería desvincularlo y no existe en esta API.

    El campo NO acepta moneda ni periodicidad: el número se interpreta siempre como QUETZALES AL MES.
    TEXT,
    required: ['salary'],
    properties: [
        new OA\Property(
            property: 'salary',
            description: 'Nuevo salario base MENSUAL del piloto EN QUETZALES (GTQ). Obligatorio, numérico, entre 0.01 y 99999999.99 — el mínimo cierra la puerta al cero y a los negativos, y el máximo es el tope de la columna decimal(10,2). Se acepta como número (4500.5) o como cadena numérica ("4500.50"); en la respuesta vuelve siempre como CADENA con dos decimales. Los decimales de más allá del segundo se pierden al guardar, y la comprobación del salario repetido también los ignora: 4500.004 se considera igual a 4500.00. No es un aumento ni un delta: es el salario resultante, absoluto. Enviar null, 0, un negativo, "abc" o superar el máximo devuelve 422; enviar el salario que el piloto ya tiene devuelve 400.',
            type: 'number',
            format: 'float',
            maximum: 99999999.99,
            minimum: 0.01,
            example: 4500.00,
        ),
    ],
    type: 'object',
)]
class UpdatePilotSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The single field this endpoint exists for, and it is required.
     *
     * Not `sometimes`: this PATCH is only ever called to change the salary, so an empty
     * body is a 422 and not a no-op. `min:0.01` closes the door on zero and on negative
     * values — putting somebody at zero is unlinking them, and that is another spec.
     *
     * `changedBy` is not accepted here nor anywhere else in the body: it comes from the
     * authenticated user.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'salary' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'salary.required' => 'El salario es obligatorio',
            'salary.numeric' => 'El salario debe ser un número en quetzales',
            'salary.min' => 'El salario debe ser mayor que cero',
            'salary.max' => 'El salario no puede superar los 99999999.99 quetzales',
        ];
    }
}
