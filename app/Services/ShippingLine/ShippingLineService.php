<?php

namespace App\Services\ShippingLine;

use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\ShippingLine\ShippingLineServiceInterface;
use App\Models\ShippingLine;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class ShippingLineService implements ShippingLineServiceInterface
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
    public function getShippingLines(array $filters): LengthAwarePaginator|Collection
    {
        /**
         * Sin withTrashed(): el scope del trait deja fuera a las borradas y no hay ningún
         * filtro que las devuelva. Para el listado, una naviera borrada no existe.
         */
        $query = ShippingLine::query()->with('registeredBy');

        /**
         * El término se normaliza como un nombre —colapsando espacios—; name está siempre
         * en mayúsculas, así que con eso basta para ser insensible a mayúsculas.
         */
        $search = ShippingLine::normalizeName($filters['search'] ?? '');

        if ($search !== '') {
            $query->where('name', 'LIKE', '%'.$search.'%');
        }

        /** El catálogo se lee en el orden en que se dio de alta. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getShippingLineById(int $id): ShippingLine
    {
        /**
         * Deliberadamente sin withTrashed(): quien lee no distingue una naviera borrada de
         * una que nunca existió, y los dos casos salen por el mismo 404. La distinción solo
         * la hace resolveWritableShippingLine(), para poder dar un 400 con sentido a quien
         * intenta editar o volver a borrar.
         */
        $shippingLine = ShippingLine::query()->with('registeredBy')->find($id);

        if ($shippingLine === null) {
            throw new NotFoundError('La naviera no existe');
        }

        return $shippingLine;
    }

    #[Override]
    public function create(User $user, array $data): ShippingLine
    {
        /** Se normaliza aquí aunque el FormRequest ya lo haya hecho: el service es llamable directamente. */
        $name = ShippingLine::normalizeName($data['name']);

        $this->ensureNameIsAvailable($name);

        $shippingLine = ShippingLine::create([
            'name' => $name,
            /** El autor sale del usuario autenticado, nunca del body. */
            'registered_by' => $user->id,
        ]);

        return $shippingLine->load('registeredBy');
    }

    #[Override]
    public function update(int $id, array $data): ShippingLine
    {
        $shippingLine = $this->resolveWritableShippingLine($id);

        if (isset($data['name'])) {
            $name = ShippingLine::normalizeName($data['name']);

            /** Se ignora la propia fila: reenviar su mismo nombre no puede chocar consigo misma. */
            $this->ensureNameIsAvailable($name, $shippingLine->id);

            $shippingLine->name = $name;
        }

        /** registered_by no se reescribe: sigue apuntando a quien dio de alta la naviera. */
        $shippingLine->save();

        return $shippingLine->load('registeredBy');
    }

    #[Override]
    public function destroy(int $id): ShippingLine
    {
        $shippingLine = $this->resolveWritableShippingLine($id);

        /**
         * Borrado lógico de verdad, no la baja idempotente de los otros catálogos: la fila
         * desaparece de la API para siempre, sin dejar de ocupar su nombre, y el segundo
         * intento lo corta resolveWritableShippingLine() con un 400.
         */
        $shippingLine->delete();

        return $shippingLine;
    }

    /**
     * Resolve the row a write is aiming at, refusing one that has already been deleted.
     *
     * The only place in the domain that looks at the deleted rows, and the reason this
     * service can tell "this shipping line never existed" (404) from "this shipping line
     * is gone" (400) — a distinction the boolean catalogs never need, because there
     * nothing ever disappears. Shared guard of update() and destroy().
     * Throws a NotFoundError when the row does not exist and a BadRequestError when it
     * has already been deleted.
     */
    private function resolveWritableShippingLine(int $id): ShippingLine
    {
        /** Con las borradas a la vista: sin ellas, el segundo DELETE solo podría ser un 404. */
        $shippingLine = ShippingLine::withTrashed()->with('registeredBy')->find($id);

        if ($shippingLine === null) {
            throw new NotFoundError('La naviera no existe');
        }

        if ($shippingLine->trashed()) {
            throw new BadRequestError('La naviera ya fue eliminada');
        }

        return $shippingLine;
    }

    /**
     * Refuse a name that another shipping line already holds, deleted or not.
     *
     * Duplicates what the unique index of the table already covers, on purpose: without
     * it a direct call to the service would surface the index violation as a 500 instead
     * of a business error. The form request carries no unique rule at all, because
     * Laravel's would skip the deleted rows and let a taken name through.
     *
     * The message names the deleted case explicitly, and here it matters more than
     * anywhere else: the name is the only identifier this catalog has, so the row holding
     * it may be invisible in every endpoint and the error would otherwise read like a lie
     * to whoever just deleted that very shipping line by mistake.
     * Throws a BadRequestError when the name is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the name, so an update can resend its own.
     */
    private function ensureNameIsAvailable(string $name, ?int $ignoreId = null): void
    {
        $exists = ShippingLine::withTrashed()
            ->where('name', '=', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe una naviera con ese nombre, que puede haber sido eliminada');
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
