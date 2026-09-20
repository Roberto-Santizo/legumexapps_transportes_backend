<?php

namespace App\Ai\Tools\Trip;

use App\Ai\Tools\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;

/**
 * Common ground of the tools behind `GET /api/trips/{trip}/…`.
 *
 * Every one takes a required `tripId` and an optional `limit`, always paginates
 * —first page, `DEFAULT_LIMIT` rows unless the model asks for more, up to the
 * service ceiling of 100— and reports `total` next to `returned`, like `fleet`.
 * The service resolves the trip through `TripServiceInterface::getTripById()`,
 * so the scope of SPEC 24 applies untouched and a trip out of reach comes back as
 * an `error` the model can explain.
 */
abstract class TripNestedTool extends AssistantTool
{
    protected const array FILTERS = ['limit'];

    /** Rows returned when the model does not ask for a size. */
    protected const int DEFAULT_LIMIT = 25;

    /**
     * Schema of the `tripId` argument.
     */
    protected function tripIdSchema(JsonSchema $schema): Type
    {
        return $schema->integer()->required()->description('Id del viaje. Si no lo conoces, búscalo antes con trips.');
    }

    protected function arguments(Request $request): array
    {
        $arguments = $this->filters($request);
        $arguments['limit'] ??= (string) static::DEFAULT_LIMIT;
        $arguments['tripId'] = $this->resourceId($request, 'tripId');

        return $arguments;
    }

    /**
     * The counts of a page as the model reads them: how many rows exist and how many it got.
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array{total: int, returned: int}
     */
    protected function pageOf(LengthAwarePaginator $page): array
    {
        return [
            'total' => $page->total(),
            'returned' => $page->count(),
        ];
    }
}
