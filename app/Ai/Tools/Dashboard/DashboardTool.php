<?php

namespace App\Ai\Tools\Dashboard;

use App\Errors\ApiException;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Common ground of the four read-only tools the dashboard assistant can call.
 *
 * Every tool wraps one method of `DashboardServiceInterface` and forwards the
 * authenticated user, so the scope rules of SPEC 29 apply untouched: a `carrier`
 * only ever reads its own company and `carrierId` is a voluntary filter for
 * `administrator`/`manager`. The model never touches the database.
 *
 * Arguments travel to the service exactly as the model sent them: the service
 * already normalizes them tolerantly (`filter_var(..., FILTER_NULL_ON_FAILURE)`,
 * `tryFrom()`), so an invalid value is ignored instead of failing the call.
 *
 * Business errors (`ApiException`) are returned to the model as a JSON `error`
 * instead of thrown, so it can explain the situation rather than crash the turn.
 */
abstract class DashboardTool implements Tool
{
    /**
     * Argument names this tool forwards to the service.
     *
     * @var list<string>
     */
    protected const array FILTERS = [];

    public function __construct(
        protected readonly User $user,
        protected readonly DashboardServiceInterface $dashboard,
    ) {}

    /**
     * Tool name as the system prompt refers to it (`trips_summary`, not the class basename).
     */
    abstract public function name(): string;

    /**
     * Run the underlying service call and shape its result for the model.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    abstract protected function query(array $filters): array;

    public function handle(Request $request): string
    {
        try {
            return $this->toJson($this->query($this->filters($request)));
        } catch (ApiException $exception) {
            return $this->toJson(['error' => $exception->getMessage()]);
        }
    }

    /**
     * Keep only the arguments this tool declares, dropping anything the model invented
     * and the empty values it sometimes sends for optional parameters.
     *
     * The service expects its filters as they come off a query string, so every scalar
     * is handed over as a string: the model sends `true` or `10` as JSON types, and
     * `resolvePerPage()` / the `inRoute` filter would otherwise ignore them.
     *
     * @return array<string, string>
     */
    protected function filters(Request $request): array
    {
        $filters = [];

        foreach ($request->all(static::FILTERS) as $name => $value) {
            if ($value === null || $value === '' || ! is_scalar($value)) {
                continue;
            }

            $filters[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $filters;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function toJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Schema of the `carrierId` argument, shared by the four tools.
     */
    protected function carrierIdSchema(JsonSchema $schema): Type
    {
        return $schema->integer()
            ->description('Id de la empresa transportista. Solo lo aplica un administrator o manager; para un carrier se ignora y siempre lee su propia empresa. Un id inexistente se ignora.');
    }

    /**
     * Schemas of `dateFrom`/`dateTo`, shared by the two aggregate tools.
     *
     * @return array<string, Type>
     */
    protected function dateRangeSchema(JsonSchema $schema, string $cutsOn): array
    {
        return [
            'dateFrom' => $schema->string()
                ->description("Inicio del rango en formato YYYY-MM-DD, por día completo, sobre {$cutsOn}. Sin él se agrega desde el primer registro."),
            'dateTo' => $schema->string()
                ->description("Fin del rango en formato YYYY-MM-DD, por día completo, sobre {$cutsOn}. Sin él se agrega hasta el último registro."),
        ];
    }
}
