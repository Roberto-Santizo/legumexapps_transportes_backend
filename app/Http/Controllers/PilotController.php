<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Pilot\UpdatePilotSalaryRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Pilot\PilotResource;
use App\Http\Resources\Pilot\PilotSalaryHistoryResource;
use App\Interfaces\Pilot\PilotServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class PilotController extends Controller
{
    public function index(Request $request, PilotServiceInterface $pilotService)
    {
        try {
            $pilots = $pilotService->getPilots(auth('api')->user(), $this->filters($request));

            $data = $pilots instanceof LengthAwarePaginator
                ? new PaginatedResource($pilots, PilotResource::class)
                : PilotResource::collection($pilots);

            return ResponseHandler::success($data, 'Pilotos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function updateSalary(UpdatePilotSalaryRequest $request, int $pilot, PilotServiceInterface $pilotService)
    {
        try {
            $updated = $pilotService->updateSalary($pilot, $request->validated(), auth('api')->user());

            return ResponseHandler::success(new PilotResource($updated), 'Salario actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function salaryHistory(Request $request, int $pilot, PilotServiceInterface $pilotService)
    {
        try {
            $histories = $pilotService->getSalaryHistory(
                $pilot,
                auth('api')->user(),
                $this->queryString($request, 'limit'),
            );

            $data = $histories instanceof LengthAwarePaginator
                ? new PaginatedResource($histories, PilotSalaryHistoryResource::class)
                : PilotSalaryHistoryResource::collection($histories);

            return ResponseHandler::success($data, 'Historial de salario obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read the listing filters from the query string.
     *
     * Which of them are valid, and what to do with the invalid ones, is decided
     * by the service; here they are only normalized to strings, since anything
     * else is not a valid value.
     *
     * @return array{carrierId: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'carrierId' => $this->queryString($request, 'carrierId'),
            'limit' => $this->queryString($request, 'limit'),
        ];
    }

    /**
     * Read a query string parameter, discarding anything that is not a string.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
