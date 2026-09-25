<?php

namespace App\Services\FinishedProduct;

use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\FinishedProduct\FinishedProductServiceInterface;
use App\Models\Client;
use App\Models\FinishedProduct;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class FinishedProductService implements FinishedProductServiceInterface
{
    /**
     * Smallest page size accepted, so nobody sweeps the table row by row.
     */
    private const MIN_PER_PAGE = 10;

    /**
     * Largest page size accepted, so nobody asks for the whole table at once.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Relations every response needs, eager loaded so the listing has no N+1.
     */
    private const RELATIONS = ['client', 'registeredBy'];

    #[Override]
    public function getFinishedProducts(array $filters): LengthAwarePaginator|Collection
    {
        /** Sin withTrashed(): para el listado, un producto borrado no existe. */
        $query = FinishedProduct::query()->with(self::RELATIONS);

        /**
         * El término se recorta y se pasa a mayúsculas: code y name están siempre en
         * mayúsculas, así que basta para ser insensible a mayúsculas contra los dos.
         */
        $search = FinishedProduct::normalizeName(trim($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('code', 'LIKE', '%'.$search.'%')
                    ->orWhere('name', 'LIKE', '%'.$search.'%');
            });
        }

        /** Tolerante: un clientId no numérico se ignora en vez de vaciar el listado. */
        $clientId = filter_var($filters['clientId'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

        if ($clientId !== null) {
            $query->where('client_id', '=', $clientId);
        }

        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getFinishedProductById(int $id): FinishedProduct
    {
        /** Sin withTrashed(): borrado e inexistente salen por el mismo 404. */
        $finishedProduct = FinishedProduct::query()->with(self::RELATIONS)->find($id);

        if ($finishedProduct === null) {
            throw new NotFoundError('El producto terminado no existe');
        }

        return $finishedProduct;
    }

    #[Override]
    public function createFinishedProduct(array $data, User $user): FinishedProduct
    {
        /** Se normaliza aquí aunque el FormRequest ya lo haya hecho: el service es llamable directamente. */
        $code = FinishedProduct::normalizeCode($data['code']);
        $clientId = (int) $data['clientId'];

        $this->ensureCodeIsAvailable($code);
        $this->ensureClientIsActive($clientId);

        $finishedProduct = FinishedProduct::create([
            'code' => $code,
            'name' => FinishedProduct::normalizeName($data['name']),
            'presentation' => $data['presentation'],
            'boxes_per_pallet' => $data['boxesPerPallet'],
            'client_id' => $clientId,
            /** El autor sale del usuario autenticado, nunca del body. */
            'registered_by' => $user->id,
        ]);

        return $finishedProduct->load(self::RELATIONS);
    }

    #[Override]
    public function updateFinishedProduct(int $id, array $data): FinishedProduct
    {
        $finishedProduct = $this->resolveWritableFinishedProduct($id);

        if (isset($data['code'])) {
            $code = FinishedProduct::normalizeCode($data['code']);

            /** Se ignora la propia fila: reenviar su mismo código no puede chocar consigo misma. */
            $this->ensureCodeIsAvailable($code, $finishedProduct->id);

            $finishedProduct->code = $code;
        }

        if (isset($data['name'])) {
            $finishedProduct->name = FinishedProduct::normalizeName($data['name']);
        }

        if (isset($data['presentation'])) {
            $finishedProduct->presentation = $data['presentation'];
        }

        if (isset($data['boxesPerPallet'])) {
            $finishedProduct->boxes_per_pallet = $data['boxesPerPallet'];
        }

        if (isset($data['clientId'])) {
            /** También si es el mismo cliente: reenviar un cliente ya borrado es 400. */
            $this->ensureClientIsActive((int) $data['clientId']);

            $finishedProduct->client_id = (int) $data['clientId'];
        }

        /** registered_by no se reescribe: sigue apuntando a quien dio de alta el producto. */
        $finishedProduct->save();

        /** load() y no loadMissing(): si cambió el cliente, la relación ya cargada estaría obsoleta. */
        return $finishedProduct->load(self::RELATIONS);
    }

    #[Override]
    public function deleteFinishedProduct(int $id): FinishedProduct
    {
        $finishedProduct = $this->resolveWritableFinishedProduct($id);

        /** Borrado lógico sin vuelta atrás: el segundo intento lo corta resolveWritableFinishedProduct() con un 400. */
        $finishedProduct->delete();

        return $finishedProduct;
    }

    /**
     * Resolve the row a write is aiming at, refusing one that has already been deleted.
     *
     * The only place that reads deleted rows by id, so a write can tell "never existed"
     * (404) from "already gone" (400). Shared guard of update and delete.
     * Throws a NotFoundError when the row does not exist and a BadRequestError when it
     * has already been deleted.
     */
    private function resolveWritableFinishedProduct(int $id): FinishedProduct
    {
        $finishedProduct = FinishedProduct::withTrashed()->with(self::RELATIONS)->find($id);

        if ($finishedProduct === null) {
            throw new NotFoundError('El producto terminado no existe');
        }

        if ($finishedProduct->trashed()) {
            throw new BadRequestError('El producto terminado ya fue eliminado');
        }

        return $finishedProduct;
    }

    /**
     * Refuse a code that another finished product already holds, deleted or not.
     *
     * Duplicates the unique index on purpose, so a direct call gets a 400 and not a 500;
     * the form request carries no unique rule because Laravel's would skip deleted rows.
     * Throws a BadRequestError when the code is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the code, so an update can resend its own.
     */
    private function ensureCodeIsAvailable(string $code, ?int $ignoreId = null): void
    {
        $exists = FinishedProduct::withTrashed()
            ->where('code', '=', $code)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe un producto terminado con ese código, que puede haber sido eliminado');
        }
    }

    /**
     * Refuse a client that has been soft deleted.
     *
     * The form request's exists rule reads the raw table and lets deleted clients
     * through. A missing id only reaches this point on a direct call to the service,
     * and is a NotFoundError there.
     * Throws a BadRequestError when the client has been deleted.
     */
    private function ensureClientIsActive(int $clientId): void
    {
        $client = Client::withTrashed()->find($clientId);

        if ($client === null) {
            throw new NotFoundError('El cliente no existe');
        }

        if ($client->trashed()) {
            throw new BadRequestError('El cliente seleccionado ya fue eliminado');
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
