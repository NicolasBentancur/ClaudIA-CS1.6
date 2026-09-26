<?php

declare(strict_types=1);

namespace Claudia\Players;

/**
 * Jugador conectado al servidor de CS (uno por slot).
 */
final class Session
{
    public ?int $userId = null;
    public bool $registered = false;
    public int $failedLogins = 0;
    public int $lockUntil = 0;
    /** Rol en el servidor (Role::USER..OWNER), lo actualiza el plugin en cada mensaje. */
    public int $role = Role::USER;

    /**
     * @param int $connId #userid del motor: identifica la conexión y se conserva en el cambio de
     *                    mapa, pero cambia si el jugador sale y vuelve a entrar (0 = el plugin no lo mandó)
     */
    public function __construct(
        public readonly int $slot,
        public string $nick,
        public readonly string $ip,
        public readonly string $authid,
        public readonly int $joinedAt,
        public readonly int $connId = 0,
    ) {
    }

    public function logged(): bool
    {
        return $this->userId !== null;
    }
}
