<?php

namespace App\Http\Resources\DeviceToken;

use App\Models\UserDeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserDeviceToken
 */
class DeviceTokenResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'platform' => $this->platform?->value,
            /** Sin userId: el dueño es siempre quien hace la petición. */
            'lastSeenAt' => $this->last_seen_at?->format('d-m-Y h:i:s A'),
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
