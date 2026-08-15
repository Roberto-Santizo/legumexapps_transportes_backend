<?php

namespace App\Http\Resources\Pilot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PilotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Wraps a carrier_pilots row with its user and carrier already loaded. The exposed
     * id is the pilot's user_id — the identifier the client already has on screen and
     * the one that travels in {pilot} — never the id of the pivot row.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user_id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'carrierId' => $this->carrier_id,
            'carrierName' => $this->carrier?->name,
            /** Monthly base salary in GTQ; null means it has not been assigned yet. */
            'salary' => $this->salary,
            'joinedAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
