<?php

namespace App\Services\Client;

use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Client\ClientServiceInterface;
use App\Models\Client;
use App\Models\User;
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

    #[Override]
    public function create(User $user, array $data): Client
    {
        /** Se normaliza aquí aunque el FormRequest ya lo haya hecho: el service es llamable directamente. */
        $code = Client::normalizeCode($data['code']);
        $name = Client::normalizeName($data['name']);

        $this->ensureCodeIsAvailable($code);
        $this->ensureNameIsAvailable($name);

        $client = Client::create([
            'code' => $code,
            'name' => $name,
            /** El autor sale del usuario autenticado, nunca del body. */
            'registered_by' => $user->id,
        ]);

        return $client->load('registeredBy');
    }

    #[Override]
    public function update(int $id, array $data): Client
    {
        $client = $this->resolveWritableClient($id);

        if (isset($data['code'])) {
            $code = Client::normalizeCode($data['code']);

            /** Se ignora la propia fila: reenviar su mismo código no puede chocar consigo misma. */
            $this->ensureCodeIsAvailable($code, $client->id);

            $client->code = $code;
        }

        if (isset($data['name'])) {
            $name = Client::normalizeName($data['name']);

            $this->ensureNameIsAvailable($name, $client->id);

            $client->name = $name;
        }

        /** registered_by no se reescribe: sigue apuntando a quien dio de alta el cliente. */
        $client->save();

        return $client->load('registeredBy');
    }

    #[Override]
    public function destroy(int $id): Client
    {
        $client = $this->resolveWritableClient($id);

        /**
         * Borrado lógico de verdad, no la baja idempotente de los otros catálogos: la fila
         * desaparece de la API para siempre, sin dejar de ocupar su código y su nombre, y
         * el segundo intento lo corta resolveWritableClient() con un 400.
         */
        $client->delete();

        return $client;
    }

    /**
     * Resolve the row a write is aiming at, refusing one that has already been deleted.
     *
     * The only place in the domain that looks at the deleted rows, and the reason this
     * service can tell "this client never existed" (404) from "this client is gone"
     * (400) — a distinction the boolean catalogs never need, because there nothing ever
     * disappears. Shared guard of update() and destroy().
     * Throws a NotFoundError when the row does not exist and a BadRequestError when it
     * has already been deleted.
     */
    private function resolveWritableClient(int $id): Client
    {
        /** Con los borrados a la vista: sin ellos, el segundo DELETE solo podría ser un 404. */
        $client = Client::withTrashed()->with('registeredBy')->find($id);

        if ($client === null) {
            throw new NotFoundError('El cliente no existe');
        }

        if ($client->trashed()) {
            throw new BadRequestError('El cliente ya fue eliminado');
        }

        return $client;
    }

    /**
     * Refuse a code that another client already holds, deleted or not.
     *
     * Duplicates what the unique index of the table already covers, on purpose: without
     * it a direct call to the service would surface the index violation as a 500 instead
     * of a business error. The form request carries no unique rule at all, because
     * Laravel's would skip the deleted rows and let a taken code through.
     *
     * The message names the deleted case explicitly: the row holding the code may be
     * invisible in every endpoint, so without that hint the error reads like a lie to
     * whoever just deleted that very client by mistake.
     * Throws a BadRequestError when the code is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the code, so an update can resend its own.
     */
    private function ensureCodeIsAvailable(string $code, ?int $ignoreId = null): void
    {
        $exists = Client::withTrashed()
            ->where('code', '=', $code)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe un cliente con ese código, que puede haber sido eliminado');
        }
    }

    /**
     * Refuse a name that another client already holds, deleted or not.
     *
     * Sibling of ensureCodeIsAvailable(), with the same reasoning: deleting a client
     * releases neither its code nor its name, so the check has to see the deleted rows.
     * Throws a BadRequestError when the name is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the name, so an update can resend its own.
     */
    private function ensureNameIsAvailable(string $name, ?int $ignoreId = null): void
    {
        $exists = Client::withTrashed()
            ->where('name', '=', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe un cliente con ese nombre, que puede haber sido eliminado');
        }
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
