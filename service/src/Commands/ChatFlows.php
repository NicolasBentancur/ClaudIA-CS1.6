<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\Clock;
use Claudia\Log;
use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Formularios por chat activos (uno por jugador). Mientras hay uno activo, el plugin captura
 * lo que el jugador escribe y lo manda como "input". "cancelar" (o /cancelar) lo corta.
 */
final class ChatFlows
{
    /** @var array<int, array{flow:ChatFlow, uid:?int, expires:int, timeout:int}> por slot */
    private array $active = [];

    public function __construct(private readonly Out $out)
    {
    }

    public function start(Session $s, ChatFlow $flow, int $timeout): void
    {
        if (isset($this->active[$s->slot])) {
            $this->cancel($s, false);
        }
        $this->active[$s->slot] = ['flow' => $flow, 'uid' => $s->userId, 'expires' => Clock::now() + $timeout, 'timeout' => $timeout];
        $this->out->capture($s->slot, true);
        try {
            $flow->begin($s);
        } catch (\Throwable $e) {
            $this->finish($s->slot);
            throw $e;
        }
    }

    public function has(Session $s): bool
    {
        return isset($this->active[$s->slot]) && $this->active[$s->slot]['uid'] === $s->userId;
    }

    public function input(Session $s, string $text): void
    {
        $entry = $this->active[$s->slot] ?? null;
        if ($entry === null || $entry['uid'] !== $s->userId) {
            // El plugin quedó capturando de más (ej. se reinició el servicio): se libera.
            $this->out->capture($s->slot, false);
            return;
        }
        $text = trim($text);
        if (in_array(Text::fold($text), ['cancelar', 'cancel', 'salir'], true)) {
            $this->cancel($s, false);
            return;
        }
        $this->active[$s->slot]['expires'] = Clock::now() + $entry['timeout'];
        try {
            if ($entry['flow']->answer($s, $text)) {
                $this->finish($s->slot);
            }
        } catch (UserError $e) {
            $this->out->chat($s->slot, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Error en formulario: ' . $e->getMessage(), ['at' => $e->getFile() . ':' . $e->getLine()]);
            $this->out->chat($s->slot, 'Se me trancó el formulario, empezá de nuevo.');
            $this->finish($s->slot);
        }
    }

    public function cancel(Session $s, bool $timeout): void
    {
        $entry = $this->active[$s->slot] ?? null;
        if ($entry === null) {
            return;
        }
        $this->finish($s->slot);
        $entry['flow']->cancelled($s, $timeout);
    }

    /** El jugador se fue o se deslogueó: se descarta sin avisar. */
    public function drop(int $slot): void
    {
        if (isset($this->active[$slot])) {
            $this->finish($slot);
        }
    }

    /** @param callable(int):?Session $session */
    public function tick(callable $session): void
    {
        $now = Clock::now();
        foreach ($this->active as $slot => $entry) {
            if ($entry['expires'] >= $now) {
                continue;
            }
            $s = $session($slot);
            if ($s !== null) {
                $this->cancel($s, true);
            } else {
                unset($this->active[$slot]);
            }
        }
    }

    private function finish(int $slot): void
    {
        unset($this->active[$slot]);
        $this->out->capture($slot, false);
    }
}
