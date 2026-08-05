<?php

namespace App\Enums;

enum VehicleType: string
{
    case Truck = 'truck';
    case Van = 'van';
    case Trailer = 'trailer';
    case Pickup = 'pickup';
}
