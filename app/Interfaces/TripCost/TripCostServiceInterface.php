<?php

namespace App\Interfaces\TripCost;

use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\Trip;
use App\Models\User;

interface TripCostServiceInterface
{
    /**
     * Break the direct cost of one **finished** trip down into its four components, in GTQ.
     *
     * The first contract of the project that only reads and only computes: nothing here
     * is stored, no column holds the result and no snapshot freezes it. Every input is
     * historical and does not move —the fuel price in force at each `loaded_at`, the
     * salary in force at `start_date`, the already closed `traveled_hours`—, so the
     * number is recomputed on every read and always comes out the same. Precedent:
     * `currentValue` in SPEC 17 and `durationMinutes` in SPEC 27, at the scale of four
     * domains.
     *
     * It takes the caller and an id, never a `Trip`: the scope of SPEC 24 is resolved
     * inside through `TripServiceInterface::getTripById()`, so no caller can hand in a
     * trip it never had the right to load.
     *
     * Four guards, and their order is contract: the trip must exist (NotFoundError, and
     * a deleted trip answers the same 404 —this is a read and it follows `GET /{trip}`,
     * not the 400 of the write routes—), the caller must not be a `pilot` (ForbiddenError,
     * **including the assigned one**: the breakdown exposes his own monthly salary, same
     * treatment as `/positions` and `/timeouts`), the trip must be within the caller's
     * scope (ForbiddenError) and it must be `finished` (BadRequestError). A trip in
     * `pending` or `in_route` has no partial cost.
     *
     * **A missing input is worth `0.00`, never an error**: no pilot or vehicle assigned,
     * a `salary` in `null`, a `traveled_hours` in `null` (a trip closed before SPEC 32)
     * or a load with no captured price for its date. The absent input comes out as
     * `null` in the breakdown so the hole is visible, while its subtotal stays at zero:
     * a 400 over an incomplete catalog would leave without cost a trip that did burn
     * fuel.
     *
     * @return array{
     *     trip: Trip,
     *     traveledHours: float|null,
     *     fuel: array{
     *         gallons: float,
     *         byType: list<array{fuelType: string, gallons: float, pricePerGallon: float|null, amount: float}>,
     *         subtotal: float,
     *     },
     *     expenses: array{count: int, subtotal: float},
     *     pilot: array{monthlySalary: float|null, subtotal: float},
     *     vehicle: array{monthlyInsuranceCost: float|null, subtotal: float},
     *     totalCost: float,
     * }
     * traveledHours is the single multiplier of both prorations and travels in the root
     * on purpose: repeating it inside `pilot` and `vehicle` would invite the reader to
     * believe the two could differ. `byType` groups the **confirmed** loads by fuel
     * type, and is an empty list —never null— when there are none. `totalCost` is the
     * sum of the four **already rounded** subtotals, so the breakdown adds up to the
     * total on screen instead of missing it by a cent.
     *
     * @throws NotFoundError when the trip does not exist or has been deleted
     * @throws ForbiddenError when the caller is a pilot or the trip is out of its scope
     * @throws BadRequestError when the trip is not finished yet
     */
    public function getTripCost(User $user, int $tripId): array;
}
