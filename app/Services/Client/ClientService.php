<?php

namespace App\Services\Client;

use App\Errors\NotFoundError;
use App\Interfaces\Client\ClientServiceInterface;
use App\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class ClientService implements ClientServiceInterface
{
    /**
     * Smallest page size accepted, so nobody sweeps the table row by row.
     */
    private const MIN_PER_PAGE = 10;

    /**
     * Largest page size accepted, so nobody asks for the whole table at once.
     */
    private const MAX_PER_PAGE = 100;

    #[Override]
    public function getClients(array $filters): LengthAwarePaginator|Collection
    {
        /**
         * Sin withTrashed(): el scope del trait deja fuera a los borrados y no hay
         * ningún filtro que los devuelva. Para el listado, un cliente borrado no existe.
         */
        $query = Client::query()->with('registeredBy');

        /**
         * El término se normaliza como un nombre —colapsando espacios— porque es la
         * búsqueda habitual; code y name están siempre en mayúsculas, así que con eso
         * basta para ser insensible a mayúsculas contra los dos.
         */
        $search = Client::normalizeName($filters['search'] ?? '');

        if ($search !== '') {
            /** Agrupado por costumbre: hoy no hay otro filtro, pero el orWhere suelto se saltaría el que se añada. */
            $query->where(function ($query) use ($search) {
                $query->where('code', 'LIKE', '%'.$search.'%')
                    ->orWhere('name', 'LIKE', '%'.$search.'%');
            });
        }

        /** El catálogo se lee en el orden en que se dio de alta. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getClientById(int $id): Client
    {
        /**
         * Deliberadamente sin withTrashed(): quien lee no distingue un cliente borrado
         * de uno que nunca existió, y los dos casos salen por el mismo 404. La distinción
         * solo la hace resolveWritableClient(), para poder dar un 400 con sentido a quien
         * intenta editar o volver a borrar.
         */
        $client = Client::query()->with('registeredBy')->find($id);

        if ($client === null) {
            throw new NotFoundError('El cliente no existe');
        }

        return $client;
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is clamped
     * to [10, 100].
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
