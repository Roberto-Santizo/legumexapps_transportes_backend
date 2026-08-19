<?php

namespace App\Enums;

enum VehicleExpenseNature: string
{
    case Preventive = 'preventive';
    case Corrective = 'corrective';
}
