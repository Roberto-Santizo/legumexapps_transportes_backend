<?php

namespace App\Http\Resources\Client;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `deletedAt` is null on four of the five endpoints —index, show, store and
     * update— because none of them can reach a deleted client. The exception is the
     * response of the DELETE itself, which paints the row that was just soft deleted
     * and therefore carries the deletion timestamp, in the same `d-m-Y h:i:s A`
     * format as the other two dates. That is the only way a caller ever sees this key
     * with a value, and it documents towards the front end that this catalog —unlike
     * the other six— deletes for real.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
            'deletedAt' => $this->deleted_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
