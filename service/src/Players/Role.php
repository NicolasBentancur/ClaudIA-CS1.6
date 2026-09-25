<?php

declare(strict_types=1);

namespace Claudia\Players;

/**
 * Roles del servidor, de menor a mayor. Cada rol tiene todo lo del anterior.
 * El plugin calcula el rol con las flags de AMXX (claudia_admin_flags, claudia_staff_flags,
 * claudia_owner_flags) y lo manda en cada mensaje; la consola del servidor es owner.
 */
final class Role
{
    public const USER = 0;
    public const ADMIN = 1;
    public const STAFF = 2;
    public const OWNER = 3;

    private const NAMES = [self::USER => 'jugador', self::ADMIN => 'admin', self::STAFF => 'staff', self::OWNER => 'owner'];

    public static function name(int $role): string
    {
        return self::NAMES[self::clamp($role)];
    }

    public static function fromName(string $name): int
    {
        $found = array_search(strtolower(trim($name)), self::NAMES, true);
        return $found === false ? self::OWNER : (int) $found;
    }

    public static function clamp(int $role): int
    {
        return max(self::USER, min(self::OWNER, $role));
    }
}
