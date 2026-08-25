<?php

namespace App\Services\VehicleExpense;

use App\Enums\UserRole;
use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use Illuminate\Http\UploadedFile;
use Override;

class VehicleExpenseService implements VehicleExpenseServiceInterface
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
     * Roles that reach every company's vehicles instead of just their own.
     */
    private const UNSCOPED_ROLES = [UserRole::Administrator, UserRole::Manager];

    /**
     * Format `expense_date` bounds travel in.
     */
    private const DATE_FORMAT = 'Y-m-d';

    /**
     * Fields the update accepts.
     *
     * `vehicle_id` and `registered_by` are deliberately absent: an expense
     * never moves between vehicles, and it keeps the user that created it.
     */
    private const UPDATABLE_FIELDS = ['category', 'nature', 'amount', 'expense_date', 'description'];

    /**
     * Directory every invoice file is stored under.
     *
     * Named after what it holds and not after the resource it hangs from, so
     * another domain can drop its invoices here the day it has any.
     */
    private const INVOICE_DIRECTORY = 'invoices';

    public function __construct(private readonly FileStorageServiceInterface $fileStorage) {}

    #[Override]
    public function getVehicleExpenses(User $user, array $filters): array
    {
        $vehicle = $this->resolveVehicle($user, (int) $filters['vehicleId']);

        $query = VehicleExpense::query()
            ->with('registeredBy')
            ->where('vehicle_id', '=', $vehicle->id);

        $category = isset($filters['category']) ? VehicleExpenseCategory::tryFrom($filters['category']) : null;

        if ($category !== null) {
            $query->where('category', '=', $category->value);
        }

        $nature = isset($filters['nature']) ? VehicleExpenseNature::tryFrom($filters['nature']) : null;

        if ($nature !== null) {
            $query->where('nature', '=', $nature->value);
        }

        $dateFrom = $this->normalizeDate($filters['dateFrom'] ?? null);

        if ($dateFrom !== null) {
            $query->where('expense_date', '>=', $dateFrom);
        }

        $dateTo = $this->normalizeDate($filters['dateTo'] ?? null);

        if ($dateTo !== null) {
            $query->where('expense_date', '<=', $dateTo);
        }

        /** El acumulado se calcula sobre la consulta ya filtrada y ANTES de paginar: es la suma de todos los gastos que cumplen los filtros, no la de la página devuelta. */
        $totalAmount = (clone $query)->sum('amount');

        $query->orderByDesc('expense_date')->orderByDesc('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return [
            'expenses' => $perPage === null ? $query->get() : $query->paginate($perPage),
            'totalAmount' => number_format((float) $totalAmount, 2, '.', ''),
        ];
    }

    #[Override]
    public function createVehicleExpense(array $data, User $user): VehicleExpense
    {
        /** El ámbito se resuelve ANTES de subir nada, para que un 403 no deje archivos huérfanos en el bucket. */
        $vehicle = $this->resolveVehicle($user, (int) $data['vehicle_id']);

        $isInvoiced = ($data['is_invoiced'] ?? false) === true;

        /** registered_by sale del usuario autenticado y no del cuerpo: mandarlo en el body no cambia nada. */
        $expense = VehicleExpense::create([
            'vehicle_id' => $vehicle->id,
            'category' => $data['category'],
            'nature' => $data['nature'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'description' => $data['description'],
            'is_invoiced' => $isInvoiced,
            'invoice' => $this->storeInvoice($isInvoiced, $data['invoice'] ?? null),
            'registered_by' => $user->id,
        ]);

        return $expense->load('registeredBy');
    }

    #[Override]
    public function getVehicleExpenseById(User $user, int $id): VehicleExpense
    {
        return $this->resolveVehicleExpense($user, $id);
    }

    #[Override]
    public function updateVehicleExpense(array $data, int $id, User $user): VehicleExpense
    {
        $expense = $this->resolveVehicleExpense($user, $id);

        $payload = array_intersect_key($data, array_flip(self::UPDATABLE_FIELDS));

        /** Un cuerpo vacio es un no-op que igualmente responde 200. */
        if ($payload !== []) {
            $expense->update($payload);
        }

        return $expense->load('registeredBy');
    }

    #[Override]
    public function deleteVehicleExpense(int $id, User $user): VehicleExpense
    {
        $expense = $this->resolveVehicleExpense($user, $id);

        /** El borrado es real: la fila desaparece y un segundo DELETE del mismo id responde 404. */
        $expense->delete();

        return $expense;
    }

    /**
     * Store the invoice file and return its key, or null when there is none.
     *
     * The boolean rules: with a false flag whatever arrived is discarded right
     * here and nothing reaches the bucket, so nobody pays for an upload that
     * will never be read. The file is persisted as-is — cropping an invoice to
     * a square would make it unreadable — and the FormRequest is what
     * guarantees it is there when the flag is true.
     */
    private function storeInvoice(bool $isInvoiced, mixed $invoice): ?string
    {
        if (! $isInvoiced || ! $invoice instanceof UploadedFile) {
            return null;
        }

        return $this->fileStorage->storeUpload($invoice, self::INVOICE_DIRECTORY);
    }

    /**
     * Resolve the expense matching the given id, within the user's scope.
     *
     * The scope is decided by the expense's vehicle, which is the only thing
     * that ties it to a company. A carrier reaching an expense of another
     * company gets a 403 and not a 404, the same rule SPEC 04 already applies
     * to the vehicle itself.
     *
     * @throws NotFoundError when the expense does not exist
     * @throws ForbiddenError when a carrier reaches an expense of another company
     */
    private function resolveVehicleExpense(User $user, int $id): VehicleExpense
    {
        $expense = VehicleExpense::query()->with(['vehicle', 'registeredBy'])->find($id);

        if ($expense === null) {
            throw new NotFoundError('El gasto no existe');
        }

        if ($this->isVehicleOutOfScope($user, $expense->vehicle)) {
            throw new ForbiddenError('No puedes acceder a un gasto que no pertenece a tu empresa transportista');
        }

        return $expense;
    }

    /**
     * Resolve the vehicle an expense hangs from, within the user's scope.
     *
     * The guard shared by every endpoint of the domain: the vehicle must exist
     * and, for a carrier, belong to its own company. The vehicle's status is
     * never checked — an inactive vehicle takes expenses like any other,
     * because the maintenance may predate its deactivation.
     *
     * @throws NotFoundError when the vehicle does not exist
     * @throws ForbiddenError when a carrier reaches a vehicle of another company
     */
    private function resolveVehicle(User $user, int $vehicleId): Vehicle
    {
        $vehicle = Vehicle::query()->find($vehicleId);

        if ($vehicle === null) {
            throw new NotFoundError('El vehículo no existe');
        }

        if ($this->isVehicleOutOfScope($user, $vehicle)) {
            throw new ForbiddenError('No puedes acceder a un vehículo que no pertenece a tu empresa transportista');
        }

        return $vehicle;
    }

    /**
     * Whether the given vehicle falls outside what the user is allowed to see.
     *
     * Returns a boolean instead of throwing so each caller raises its own
     * message: the listing and the store talk about a vehicle, the rest of the
     * endpoints talk about an expense.
     *
     * @throws ForbiddenError when a scoped user belongs to no company
     */
    private function isVehicleOutOfScope(User $user, Vehicle $vehicle): bool
    {
        $scopedCarrierId = $this->resolveScopedCarrierId($user);

        return $scopedCarrierId !== null && $vehicle->carrier_id !== $scopedCarrierId;
    }

    /**
     * Company the given user is confined to, or null when it reaches them all.
     *
     * @throws ForbiddenError when a scoped user belongs to no company
     */
    private function resolveScopedCarrierId(User $user): ?int
    {
        if (in_array($user->role, self::UNSCOPED_ROLES, true)) {
            return null;
        }

        $carrier = $user->currentCarrier();

        if ($carrier === null) {
            throw new ForbiddenError('No perteneces a ninguna empresa transportista');
        }

        return $carrier->id;
    }

    /**
     * Page size to apply, or null when the caller did not ask for pagination.
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }

    /**
     * Validate a date bound of the listing, or null when it is unusable.
     *
     * Tolerant like every other filter of the project: a malformed bound is
     * ignored instead of failing, so the caller gets the full listing rather
     * than a 422. The round trip through the format is what rejects a date that
     * parses but does not exist, such as 2026-13-45.
     */
    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value);

        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return $date->format(self::DATE_FORMAT);
    }
}
