<?php

namespace App\Interfaces\FuelPrice;

use App\Models\FuelPrice;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface FuelPriceServiceInterface
{
    /**
     * List the price history, most recent first.
     *
     * The catalogue is national: every authenticated user reaches every row,
     * with no company scoping. Invalid filters are ignored instead of failing.
     *
     * @param  array{fuelType?: string|null, status?: string|null, limit?: string|null}  $filters
     *                                                                                            fuelType: value of FuelType; status: value of FuelPriceStatus;
     *                                                                                            limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, FuelPrice>|Collection<int, FuelPrice>
     */
    public function getFuelPrices(array $filters): LengthAwarePaginator|Collection;

    /**
     * Return the price matching the given id, whatever its status.
     *
     * Throws a NotFoundError when the row does not exist.
     */
    public function getFuelPriceById(int $id): FuelPrice;

    /**
     * Return the single active price of the given fuel type.
     *
     * Throws a NotFoundError when that type has no price in effect, which is
     * the expected outcome after its active row was deactivated or deleted.
     *
     * @param  string  $fuelType  value of FuelType, already validated by the request.
     */
    public function getCurrentByType(string $fuelType): FuelPrice;

    /**
     * Register a new price and put it in effect for its fuel type.
     *
     * Runs in a transaction: the previous active row of that same type — if any —
     * moves to inactive before the new row is inserted, so a type never ends up
     * with zero or two prices in effect. Other fuel types are left untouched.
     *
     * @param  array{fuelType: string, price: float}  $data
     *                                                       price travels in GTQ per gallon. registered_by comes from the given
     *                                                       user, never from the body.
     */
    public function create(User $user, array $data): FuelPrice;

    /**
     * Update the price of the row matching the given id.
     *
     * Only price changes: fuel_type, status and registered_by are never rewritten.
     * Throws a NotFoundError when the row does not exist and a BadRequestError
     * when it is already inactive: the history is read-only.
     *
     * @param  array{price: float}  $data
     */
    public function update(int $id, array $data): FuelPrice;

    /**
     * Move the active row matching the given id to inactive.
     *
     * Its fuel type is left with no price in effect until a new one is registered:
     * no inactive row is promoted. Throws a NotFoundError when the row does not
     * exist and a BadRequestError when it is already inactive.
     */
    public function deactivate(int $id): FuelPrice;

    /**
     * Permanently delete the active row matching the given id.
     *
     * This is a real delete, not a logical one: the row disappears, its fuel type
     * is left with no price in effect and no inactive row is promoted. Throws a
     * NotFoundError when the row does not exist and a BadRequestError when it is
     * inactive. Returns the in-memory model so the response can state what was deleted.
     */
    public function destroy(int $id): FuelPrice;
}
