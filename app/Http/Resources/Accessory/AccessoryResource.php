<?php

namespace App\Http\Resources\Accessory;

use App\Models\Accessory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Accessory
 */
class AccessoryResource extends JsonResource
{
    /**
     * Days counted as a year when ageing an accessory.
     *
     * Fixed at 365, with no leap year correction: the difference is hours on a figure
     * that is already an accounting convention.
     */
    private const DAYS_PER_YEAR = 365;

    /**
     * Transform the resource into an array.
     *
     * `registeredBy` is the name of the user that captured the accessory, not its id:
     * the screen prints it and nobody navigates to that user.
     *
     * `purchaseDate` is the day the accessory was bought and carries no time, while
     * `createdAt` is when it was captured — two different things.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            /** Price in GTQ, always with two decimals. */
            'price' => $this->price,
            'purchaseDate' => $this->purchase_date?->format('d-m-Y'),
            'annualDepreciation' => $this->annual_depreciation,
            'currentValue' => $this->currentValue(),
            'status' => $this->status?->value,
            'registeredBy' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }

    /**
     * Derive what the accessory is worth today.
     *
     * Straight-line depreciation, with the age counted as a fraction of years by days
     * so the value moves every day instead of jumping on each anniversary, and a floor
     * at 0.00 so a fully depreciated accessory never comes back negative.
     *
     *     years        = purchase_date->diffInDays(today) / 365
     *     depreciated  = price * (annual_depreciation / 100) * years
     *     currentValue = round(max(0, price - depreciated), 2)
     *
     * This is the only place the formula lives: there is no column backing it, no
     * scheduled job recomputing it and no cache, so the same accessory read today and
     * a month from now returns two different values with nobody writing to the table.
     *
     * It travels as a two decimal string, like `price`: it is money, and this project
     * does not send money as a float.
     */
    private function currentValue(): string
    {
        $price = (float) $this->price;
        $years = $this->purchase_date->diffInDays(Carbon::today()) / self::DAYS_PER_YEAR;
        $depreciated = $price * ((float) $this->annual_depreciation / 100) * $years;

        return number_format(max(0, $price - $depreciated), 2, '.', '');
    }
}
