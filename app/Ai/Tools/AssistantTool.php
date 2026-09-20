<?php

namespace App\Ai\Tools;

use App\Errors\ApiException;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Common ground of every read-only tool the dashboard assistant can call.
 *
 * Every tool wraps one method of a service contract and forwards the authenticated
 * user, so the scope rules of each domain apply untouched: the model never touches
 * the database. Which contract is wrapped is up to the subclass; this base only owns
 * the user and the plumbing between the model's arguments and the service's filters.
 *
 * Arguments travel to the service exactly as the model sent them: the services
 * already normalize them tolerantly (`filter_var(..., FILTER_NULL_ON_FAILURE)`,
 * `tryFrom()`), so an invalid value is ignored instead of failing the call.
 *
 * Business errors (`ApiException`) are returned to the model as a JSON `error`
 * instead of thrown, so it can explain the situation rather than crash the turn.
 */
abstract class AssistantTool implements Tool
{
    /**
     * Argument names this tool forwards to the service.
     *
     * @var list<string>
     */
    protected const array FILTERS = [];

    public function __construct(
        protected readonly User $user,
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
            return $this->toJson($this->query($this->arguments($request)));
        } catch (ApiException $exception) {
            return $this->toJson(['error' => $exception->getMessage()]);
        }
    }

    /**
     * Everything `query()` receives: the tolerant filters by default, plus whatever the
     * subclass reads on its own —a required id, validated before any service call—.
     *
     * @return array<string, mixed>
     */
    protected function arguments(Request $request): array
    {
        return $this->filters($request);
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
     * Read the id the tool cannot work without, as the model sent it.
     *
     * The `ValidationException` is not caught on purpose: the SDK gateway relays its
     * message to the model as the tool's result, so it can retry with the argument
     * instead of the whole turn failing.
     */
    protected function resourceId(Request $request, string $name): int
    {
        $validated = $request->validate(
            [$name => ['required', 'integer', 'min:1']],
            [
                "{$name}.required" => "El argumento {$name} es obligatorio",
                "{$name}.integer" => "El argumento {$name} debe ser un entero",
                "{$name}.min" => "El argumento {$name} debe ser un entero positivo",
            ],
        );

        return (int) $validated[$name];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function toJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Schema of the `limit` argument of a listing that always paginates.
     */
    protected function limitSchema(JsonSchema $schema, int $min, int $default, string $rows): Type
    {
        return $schema->integer()
            ->min($min)
            ->max(100)
            ->description("Cuántos {$rows} devolver, entre {$min} y 100. Por defecto {$default}.");
    }

    /**
     * Schemas of `dateFrom`/`dateTo`, shared by every tool with a date range.
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
