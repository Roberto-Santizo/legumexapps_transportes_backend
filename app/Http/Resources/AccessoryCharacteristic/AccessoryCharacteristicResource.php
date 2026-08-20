<?php

namespace App\Http\Resources\AccessoryCharacteristic;

use App\Models\AccessoryCharacteristic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccessoryCharacteristic
 */
class AccessoryCharacteristicResource extends JsonResource
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
            /**
             * Un número, no un objeto anidado: quien llega hasta aquí ya tiene el
             * accesorio, porque tuvo que mandar su id. No hay accessoryName.
             */
            'accessoryId' => $this->accessory_id,
            /** Siempre en mayúsculas y con los espacios internos colapsados. */
            'name' => $this->name,
            /** Exactamente como se guardó: solo se le recortaron los extremos. */
            'value' => $this->value,
            /** El nombre de quien capturó la característica, no su id. */
            'registeredBy' => $this->registeredBy?->name,
            /** Formato del proyecto, que no es ISO 8601. No hay updatedAt. */
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
