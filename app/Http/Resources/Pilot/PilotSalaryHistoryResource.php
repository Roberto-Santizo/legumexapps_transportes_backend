<?php

namespace App\Http\Resources\Pilot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PilotSalaryHistoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * One entry of the salary log. `changedAt` is the date the change took effect: the
     * change rules from the moment it is saved, so created_at is all the log needs.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Null on the first assignment: there was no salary before it. */
            'previousSalary' => $this->previous_salary,
            'newSalary' => $this->new_salary,
            'changedById' => $this->changed_by,
            'changedByName' => $this->changedBy?->name,
            'changedAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
