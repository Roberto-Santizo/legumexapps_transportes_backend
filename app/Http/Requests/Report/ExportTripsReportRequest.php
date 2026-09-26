<?php

namespace App\Http\Requests\Report;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ExportTripsReportQuery',
    description: 'Query string de GET /api/reports/trips. Solo el rango se valida: los dos extremos son obligatorios, Y-m-d estricto y dateTo no anterior a dateFrom. Sin rango máximo; el freno es el tope de 5000 viajes. Los demás filtros de GET /api/trips son opcionales y tolerantes y no se validan.',
    required: ['dateFrom', 'dateTo'],
    properties: [
        new OA\Property(property: 'dateFrom', type: 'string', format: 'date', example: '2026-09-01'),
        new OA\Property(property: 'dateTo', type: 'string', format: 'date', example: '2026-09-30'),
    ],
    type: 'object',
)]
class ExportTripsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the query string, not a body.
     *
     * Only the range is validated: both ends are required and strict `Y-m-d`, with no
     * maximum span —the real brake is `ReportService::MAX_ROWS`—. The other filters of
     * `GET /api/trips` stay optional and tolerant, so they are not declared here, and
     * `limit` is dropped by the service.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dateFrom' => ['required', 'date_format:Y-m-d'],
            'dateTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dateFrom.required' => 'La fecha inicial es obligatoria',
            'dateFrom.date_format' => 'La fecha inicial debe tener el formato AAAA-MM-DD',
            'dateTo.required' => 'La fecha final es obligatoria',
            'dateTo.date_format' => 'La fecha final debe tener el formato AAAA-MM-DD',
            'dateTo.after_or_equal' => 'La fecha final no puede ser anterior a la fecha inicial',
        ];
    }
}
