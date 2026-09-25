<?php

declare(strict_types=1);

namespace Claudia\Players;

use Claudia\Config;
use Claudia\UserError;

/**
 * Qué rol mínimo hace falta para cada acción de administración (config/roles.json).
 * Si un permiso no está en la config se usa el valor por defecto de acá; si no existe, solo owner.
 */
final class Permissions
{
    /** @var array<string, string> permiso => rol mínimo */
    public const DEFAULTS = [
        'admin.menu' => 'admin',          // ver el menú de administración
        'admin.amxmenu' => 'admin',       // atajo al menú de AMX Mod X
        'announce' => 'admin',            // anuncio de Claudia a todo el servidor
        'players.profile' => 'admin',     // perfil de cualquier jugador desde el menú
        'players.nickname' => 'admin',    // borrar el apodo de un jugador
        'groups.view' => 'admin',         // info y lista de todos los grupos
        'groups.edit' => 'admin',         // nombre, tag, descripción, privacidad
        'groups.members' => 'admin',      // agregar / sacar miembros
        'players.profile_full' => 'staff', // memoria de la IA, préstamos y movimientos
        'players.memory' => 'staff',      // borrar la memoria de la IA sobre un jugador
        'groups.create' => 'staff',
        'groups.delete' => 'staff',
        'groups.owner' => 'staff',
        'coins.give' => 'staff',          // con tope por operación (coin_limits)
        'coins.take' => 'staff',
        'ai.toggle' => 'staff',           // silenciar / reactivar a Claudia
        'players.password' => 'owner',    // contraseña temporal para un jugador
        'loans.forgive' => 'owner',       // perdonar deudas y sacar del Clearing
        'promos.force' => 'owner',        // activar una promoción ya
        'config.reload' => 'owner',
        'service.status' => 'owner',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    public function minRole(string $permission): int
    {
        $name = $this->config->string("roles.permissions.{$permission}", self::DEFAULTS[$permission] ?? 'owner');
        return Role::fromName($name);
    }

    public function allows(int $role, string $permission): bool
    {
        return $role >= $this->minRole($permission);
    }

    public function check(int $role, string $permission): void
    {
        if (!$this->allows($role, $permission)) {
            throw new UserError('No tenés permiso para eso (hace falta ser ' . Role::name($this->minRole($permission)) . ').', 'forbidden');
        }
    }

    /** Tope de coins por operación de dar/quitar para ese rol (0 = sin tope). */
    public function coinLimit(int $role): int
    {
        return max(0, $this->config->int('roles.coin_limits.' . Role::name($role), $role >= Role::OWNER ? 0 : 10000));
    }

    /**
     * No se puede actuar sobre alguien de rol igual o mayor (salvo el owner).
     * Solo se sabe el rol de los que están conectados.
     */
    public function checkTarget(int $actorRole, ?Session $target): void
    {
        if ($target !== null && $actorRole < Role::OWNER && $target->role >= $actorRole && $actorRole > Role::USER) {
            throw new UserError('No podés hacer eso con alguien de tu mismo rango o superior.', 'forbidden');
        }
    }
}
