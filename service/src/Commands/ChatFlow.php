<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\Players\Session;

/**
 * Formulario por chat: el jugador contesta escribiendo en el chat (el plugin no lo muestra
 * a los demás mientras dure). Si answer() lanza UserError, se le muestra el error y se
 * repite la misma pregunta.
 */
interface ChatFlow
{
    /** Primera pregunta. */
    public function begin(Session $s): void;

    /** Procesa una respuesta. Devuelve true cuando el formulario terminó. */
    public function answer(Session $s, string $text): bool;

    /** Se canceló (por el jugador o por tiempo). */
    public function cancelled(Session $s, bool $timeout): void;
}
