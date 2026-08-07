<?php

namespace App\Services\Product;

use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Product\ProductServiceInterface;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class ProductService implements ProductServiceInterface
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
    public function getProducts(array $filters): LengthAwarePaginator|Collection
    {
        $query = Product::query()->with('registeredBy');

        /** Cualquier valor que no sea booleano se ignora en vez de vaciar el catálogo. */
        $status = isset($filters['status'])
            ? filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        $search = Product::normalizeName($filters['search'] ?? '');

        if ($search !== '') {
            /** El name está siempre en mayúsculas, así que normalizar el término basta para ser insensible a mayúsculas. */
            $query->where('name', 'LIKE', '%'.$search.'%');
        }

        /** Un catálogo se lee en el orden en que se dio de alta. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getProductById(int $id): Product
    {
        $product = Product::query()->with('registeredBy')->find($id);

        if ($product === null) {
            throw new NotFoundError('El producto no existe');
        }

        return $product;
    }

    #[Override]
    public function create(User $user, array $data): Product
    {
        /** Se normaliza aquí aunque el FormRequest ya lo haya hecho: el service es llamable directamente. */
        $name = Product::normalizeName($data['name']);

        $this->ensureNameIsAvailable($name);

        $product = Product::create([
            'name' => $name,
            /** El estado no sale del body: un producto nace siempre disponible. */
            'status' => true,
            'registered_by' => $user->id,
        ]);

        return $product->load('registeredBy');
    }

    #[Override]
    public function update(int $id, array $data): Product
    {
        $product = $this->getProductById($id);

        if (isset($data['name'])) {
            $name = Product::normalizeName($data['name']);

            /** Se ignora la propia fila: reenviar su mismo nombre no puede chocar consigo misma. */
            $this->ensureNameIsAvailable($name, $product->id);

            $product->name = $name;
        }

        if (isset($data['status'])) {
            $product->status = $data['status'];
        }

        /** registered_by no se reescribe: sigue apuntando a quien dio de alta el producto. */
        $product->save();

        return $product;
    }

    #[Override]
    public function toggleStatus(int $id): Product
    {
        $product = $this->getProductById($id);

        $product->status = ! $product->status;

        $product->save();

        return $product;
    }

    #[Override]
    public function destroy(int $id): Product
    {
        $product = $this->getProductById($id);

        /** Baja lógica e idempotente: sobre un producto ya inactivo no falla y lo deja igual. */
        $product->status = false;

        $product->save();

        return $product;
    }

    /**
     * Refuse a name that another product already holds.
     *
     * Duplicates what the unique rule of the request and the unique index of the
     * table already cover, on purpose: without it a direct call to the service
     * would surface the index violation as a 500 instead of a business error.
     * Throws a BadRequestError when the name is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the name, so an update can resend its own.
     */
    private function ensureNameIsAvailable(string $name, ?int $ignoreId = null): void
    {
        $exists = Product::query()
            ->where('name', '=', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe un producto con ese nombre');
        }
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is
     * clamped to [10, 100].
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
