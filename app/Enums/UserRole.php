<?php

namespace App\Enums;

enum UserRole: string
{
    case Administrator = 'administrator';
    case Carrier = 'carrier';
    case Pilot = 'pilot';
    case Manager = 'manager';
    case Export = 'export';
    case User = 'user';
    case Shipment = 'shipment';

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
            self::Export => 'Exportación',
            self::User => 'Usuario',
            self::Shipment => 'Embarque',
        };
    }

    /**
     * Comma separated role values for a `role:` middleware, leaving the given roles out.
     *
     * Lets a route file open a read to "everybody but" without listing every role by
     * hand, so a role added later is let in by default rather than forgotten.
     */
    public static function allExcept(self ...$excluded): string
    {
        $roles = array_filter(self::cases(), fn (self $role): bool => ! in_array($role, $excluded, true));

        return implode(',', array_map(fn (self $role): string => $role->value, $roles));
    }
}
