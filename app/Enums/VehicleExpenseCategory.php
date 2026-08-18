<?php

namespace App\Enums;

enum VehicleExpenseCategory: string
{
    case Tires = 'tires';
    case OilChange = 'oil_change';
    case Brakes = 'brakes';
    case SparePart = 'spare_part';
    case Battery = 'battery';
    case Suspension = 'suspension';
    case Engine = 'engine';
    case Transmission = 'transmission';
    case ElectricalSystem = 'electrical_system';
    case CoolingSystem = 'cooling_system';
    case Filters = 'filters';
    case AlignmentBalancing = 'alignment_balancing';
    case Clutch = 'clutch';
    case Exhaust = 'exhaust';
    case AirConditioning = 'air_conditioning';
    case BodyworkPaint = 'bodywork_paint';
    case GlassMirrors = 'glass_mirrors';
    case Inspection = 'inspection';
    case Washing = 'washing';
    case Towing = 'towing';
    case Labor = 'labor';
    case Other = 'other';
}
