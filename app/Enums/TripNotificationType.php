<?php

namespace App\Enums;

enum TripNotificationType: string
{
    case Assigned = 'trip.assigned';
    case Started = 'trip.started';
    case Finished = 'trip.finished';
}
