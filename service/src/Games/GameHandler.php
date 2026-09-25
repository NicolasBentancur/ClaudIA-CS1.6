<?php

declare(strict_types=1);

namespace Claudia\Games;

/**
 * Lógica de un juego para UNA sesión (un jugador con el MOTD abierto).
 */
interface GameHandler
{
    /** La página se conectó (o reconectó): mandar el estado completo. */
    public function onOpen(GameSession $session): void;

    /** Mensaje de la página (acción del jugador). */
    public function onMessage(GameSession $session, array $msg): void;

    /** La sesión expira o se reemplaza: cerrar rondas pendientes. */
    public function onClose(GameSession $session): void;
}
