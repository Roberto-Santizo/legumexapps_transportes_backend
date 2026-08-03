<?php

namespace App\Enums;

enum UserRole: string
{
    case Administrator = 'administrator';
    case Carrier = 'carrier';
    case Pilot = 'pilot';
    case Manager = 'manager';
}
