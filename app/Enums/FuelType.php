<?php

namespace App\Enums;

enum FuelType: string
{
    case Regular = 'regular';
    case Premium = 'premium';
    case Diesel = 'diesel';
    case DieselPremium = 'diesel_premium';
}
