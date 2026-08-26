<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Http\Resources\Client\ClientResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\Client\ClientServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request, ClientServiceInterface $clientService)
    {
        try {
            $clients = $clientService->getClients($this->filters($request));

            $data = $clients instanceof LengthAwarePaginator
                ? new PaginatedResource($clients, ClientResource::class)
                : ClientResource::collection($clients);

            return ResponseHandler::success($data, 'Clientes obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreClientRequest $request, ClientServiceInterface $clientService)
    {
        try {
            $client = $clientService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new ClientResource($client), 'Cliente registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $client, ClientServiceInterface $clientService)
    {
        try {
            $found = $clientService->getClientById($client);

            return ResponseHandler::success(new ClientResource($found), 'Cliente obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateClientRequest $request, int $client, ClientServiceInterface $clientService)
    {
        try {
            $updated = $clientService->update($client, $request->validated());

            return ResponseHandler::success(new ClientResource($updated), 'Cliente actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $client, ClientServiceInterface $clientService)
    {
        try {
            $deleted = $clientService->destroy($client);

            return ResponseHandler::success(new ClientResource($deleted), 'Cliente eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters the service understands.
     *
     * @return array{search: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $this->queryString($request, 'search'),
            'limit' => $this->queryString($request, 'limit'),
        ];
    }

    /**
     * Read a query string parameter, keeping only actual strings.
     *
     * An array or a missing key degrades to null, which every filter treats as absent.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
