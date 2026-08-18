<?php

namespace App\Http\Resources\VehicleExpense;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `registeredBy` is the name of the user that created the expense, not its
     * id: the screen prints it and nobody navigates to that user. The id stays
     * in the `registered_by` column and never leaves the API.
     *
     * `expenseDate` is the day the expense happened and carries no time, while
     * `createdAt` is when it was captured — two different things.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicleId' => $this->vehicle_id,
            'category' => $this->category?->value,
            'nature' => $this->nature?->value,
            /** Amount in GTQ, always with two decimals. */
            'amount' => $this->amount,
            'expenseDate' => $this->expense_date?->format('d-m-Y'),
            'description' => $this->description,
            'registeredBy' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
