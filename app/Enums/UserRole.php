<?php

namespace App\Enums;

enum UserRole: string
{
    case Administrator = 'administrator';
    case Carrier = 'carrier';
    case Pilot = 'pilot';
    case Manager = 'manager';

    /**
     * Human readable role name, in Spanish, for user facing output.
     */
    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrador',
            self::Carrier => 'Transportista',
            self::Pilot => 'Piloto',
            self::Manager => 'Encargado',
        };
    }
}
