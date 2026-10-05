<?php

namespace App\Services\TripEmergencyExpense;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripEmergencyExpense\TripEmergencyExpenseServiceInterface;
use App\Models\Trip;
use App\Models\TripEmergencyExpense;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Override;
use Throwable;

class TripEmergencyExpenseService implements TripEmergencyExpenseServiceInterface
{
    /**
     * Smallest page size accepted, as in the rest of the project.
     */
    private const MIN_PER_PAGE = 10;

    /**
     * Largest page size accepted, so nobody asks for the whole table at once.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * Bucket prefix of the receipts, stored as they arrive.
     */
    private const RECEIPT_DIRECTORY = 'trip-emergency-expenses';

    /**
     * The trip domain resolves the reading scope of SPEC 24, and the storage contract
     * keeps the receipts.
     *
     * Both injected by constructor —the by method parameter rule is the controller's
     * alone—. Precedent: TripExpenseService (SPEC 31) and VehicleExpenseService (SPEC 19).
     */
    public function __construct(
        private TripServiceInterface $tripService,
        private FileStorageServiceInterface $fileStorage,
    ) {}

    #[Override]
    public function getTripEmergencyExpenses(User $user, int $tripId, array $filters): array
    {
        /**
         * Shipment no ve dinero: la ruta ya lo deja fuera, y esto cubre a quien llegue al
         * service sin pasar por ella. El piloto asignado sí lee: el dato es de su viaje.
         */
        if ($user->role === UserRole::Shipment) {
            throw new ForbiddenError('No tienes permisos para consultar los gastos emergentes de un viaje');
        }

        $trip = $this->tripService->getTripById($user, $tripId);

        $query = TripEmergencyExpense::query()
            ->with('registeredBy')
            ->where('trip_id', $trip->id);

        /**
         * El acumulado se calcula sobre la consulta clonada y ANTES de paginar. Suma
         * todas las filas: aquí no hay confirmación que esperar.
         */
        $totalAmount = (clone $query)->sum('amount');

        /** Orden fijo `id ASC`: `created_at` empataría entre dos filas del mismo segundo. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return [
            'emergencyExpenses' => $perPage === null ? $query->get() : $query->paginate($perPage),
            'totalAmount' => number_format((float) $totalAmount, 2, '.', ''),
        ];
    }

    #[Override]
    public function create(User $user, int $tripId, array $data): TripEmergencyExpense
    {
        $trip = Trip::withTrashed()->find($tripId);

        if ($trip === null) {
            throw new NotFoundError('El viaje no existe');
        }

        $this->ensureTripIsNotDeleted($trip);
        $this->ensureCarrierTookTheTrip($user, $trip);

        /**
         * Un imprevisto de carretera no ocurre en un viaje pendiente, y uno que llega
         * tarde a un viaje finalizado es una corrección, para la que existe el PATCH.
         */
        if ($trip->status !== TripStatus::InRoute) {
            throw new BadRequestError('Solo se pueden registrar gastos emergentes en un viaje en ruta');
        }

        /** Se sube DESPUÉS de las guardas, para que un 4xx no deje archivos huérfanos. */
        $receipt = $this->storeReceipt($data['receipt'] ?? null);

        try {
            $expense = TripEmergencyExpense::create([
                'trip_id' => $trip->id,
                'amount' => $data['amount'],
                'description' => trim($data['description']),
                'receipt' => $receipt,
                /** El autor sale del usuario autenticado, nunca del body. */
                'registered_by' => $user->id,
            ]);
        } catch (Throwable $error) {
            /** delete() nunca lanza, así que la limpieza no puede enmascarar el error original. */
            $this->fileStorage->delete($receipt);

            throw $error;
        }

        return $expense->load('registeredBy');
    }

    #[Override]
    public function update(User $user, int $tripEmergencyExpenseId, array $data): TripEmergencyExpense
    {
        $expense = $this->resolveWritableExpense($user, $tripEmergencyExpenseId);

        /** `trip_id` y `registered_by` son inmutables: solo se miran estos dos campos. */
        if (array_key_exists('amount', $data)) {
            $expense->amount = $data['amount'];
        }

        if (array_key_exists('description', $data)) {
            $expense->description = trim((string) $data['description']);
        }

        $previousReceipt = null;
        $newReceipt = null;

        if (($data['receipt'] ?? null) instanceof UploadedFile) {
            $previousReceipt = $expense->receipt;
            $newReceipt = $this->storeReceipt($data['receipt']);
            $expense->receipt = $newReceipt;
        } elseif ($this->wantsReceiptRemoved($data) && $expense->receipt !== null) {
            $previousReceipt = $expense->receipt;
            $expense->receipt = null;
        }

        try {
            /** save() no escribe nada si nada cambió: un cuerpo vacío deja `updated_at` intacto. */
            $expense->save();
        } catch (Throwable $error) {
            $this->fileStorage->delete($newReceipt);

            throw $error;
        }

        /** El anterior se borra después de persistir: al revés, un fallo dejaría la fila apuntando a un objeto ya borrado. */
        if ($previousReceipt !== null) {
            $this->fileStorage->delete($previousReceipt);
        }

        return $expense->load('registeredBy');
    }

    #[Override]
    public function delete(User $user, int $tripEmergencyExpenseId): TripEmergencyExpense
    {
        $expense = $this->resolveWritableExpense($user, $tripEmergencyExpenseId);

        $expense->load('registeredBy');

        /** Borrado real: un gasto mal tecleado es basura, no historial (SPEC 19). */
        $expense->delete();

        /** Después de la fila, y delete() nunca lanza: un fallo de limpieza no altera el 200. */
        $this->fileStorage->delete($expense->receipt);

        return $expense;
    }

    /**
     * Resolve an emergency expense the caller is about to correct or delete.
     *
     * The four guards of `PATCH` and `DELETE`, in the order the contract fixes: the
     * expense must exist, its trip must not be deleted, must have been taken by the
     * caller's company and must not be `pending`. `in_route` and `finished` are both
     * accepted — the receipt usually arrives after the trip is closed.
     */
    private function resolveWritableExpense(User $user, int $tripEmergencyExpenseId): TripEmergencyExpense
    {
        /** El viaje se lee con los borrados a la vista, para responder 400 y no un 403 confuso. */
        $expense = TripEmergencyExpense::query()
            ->with(['trip' => fn ($query) => $query->withTrashed()])
            ->find($tripEmergencyExpenseId);

        if ($expense === null) {
            throw new NotFoundError('El gasto emergente no existe');
        }

        /** @var Trip $trip */
        $trip = $expense->trip;

        $this->ensureTripIsNotDeleted($trip);
        $this->ensureCarrierTookTheTrip($user, $trip);

        /** Solo alcanzable por el hueco de SPEC 24: el PATCH del administrador devuelve un viaje a `pending`. */
        if ($trip->status === TripStatus::Pending) {
            throw new BadRequestError('No se pueden modificar los gastos emergentes de un viaje pendiente');
        }

        return $expense;
    }

    /**
     * Refuse a deleted trip with a 400, distinguishable from a trip that never existed.
     */
    private function ensureTripIsNotDeleted(Trip $trip): void
    {
        if ($trip->trashed()) {
            throw new BadRequestError('El viaje ya fue eliminado');
        }
    }

    /**
     * Refuse a company that did not take this trip — the literal copy of SPEC 31.
     *
     * An unassigned trip is **not** free here: «nobody took it» and «another company took
     * it» share the same 403. The administrator belongs to no company and writes on any
     * trip, but only on an assigned one. The comparison lands on the **company** of
     * `assigned_by` and not on the user itself, as every scope check of SPEC 24 does.
     */
    private function ensureCarrierTookTheTrip(User $user, Trip $trip): void
    {
        if ($user->role === UserRole::Administrator) {
            if ($trip->pilot_id === null) {
                throw new BadRequestError('El viaje aún no fue asignado');
            }

            return;
        }

        $carrier = $user->currentCarrier();

        if ($carrier === null) {
            throw new ForbiddenError('No perteneces a ninguna empresa transportista');
        }

        if ($trip->assignedBy?->currentCarrier()?->id !== $carrier->id) {
            throw new ForbiddenError('No puedes registrar gastos emergentes en un viaje que no tomó tu empresa transportista');
        }
    }

    /**
     * Store the receipt as is and return its key, or null when none arrived.
     *
     * No cropping and no re-encoding: `ImageProcessorServiceInterface` does not take part,
     * because a receipt cropped to a square is unreadable (SPEC 19).
     */
    private function storeReceipt(mixed $receipt): ?string
    {
        if (! $receipt instanceof UploadedFile) {
            return null;
        }

        return $this->fileStorage->storeUpload($receipt, self::RECEIPT_DIRECTORY);
    }

    /**
     * Read the `removeReceipt` flag, tolerant to the strings a multipart body carries.
     *
     * @param  array<string, mixed>  $data
     */
    private function wantsReceiptRemoved(array $data): bool
    {
        return filter_var($data['removeReceipt'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is clamped
     * to [10, 100]. Opt in like the rest of the project.
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
