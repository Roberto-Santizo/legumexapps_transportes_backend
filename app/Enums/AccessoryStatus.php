<?php

namespace App\Enums;

enum AccessoryStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case UnderRepair = 'under_repair';
}
