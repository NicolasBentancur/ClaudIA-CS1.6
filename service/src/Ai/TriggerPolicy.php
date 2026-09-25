<?php

declare(strict_types=1);

namespace Claudia\Ai;

use Claudia\Config;
use Claudia\Util\Text;

/**
 * Decide si un mensaje de chat dispara una petición a la IA. Criterios, en orden:
 *
 *  1. Cooldown por jugador: si en los últimos N segundos obtuvo una respuesta o mencionó
 *     a Claudia, no se hace la petición (el jugador puede seguir chateando normalmente).
 *  2. Tope global de peticiones por minuto (ventana deslizante).
 *  3. Mención directa (o acortada) a Claudia.
 *  4. El jugador jugó un juego del plugin hace menos de N segundos.
 */
final class TriggerPolicy
{
    public const MENTION = 'mention';
    public const GAME = 'game';

    /** @var array<string, int> última respuesta o mención por jugador */
    private array $lastActivity = [];

    /** @var list<float> */
    private array $window = [];

    /** @var array<string, bool> */
    private array $inFlight = [];

    public function __construct(
        private readonly Config $config,
        private readonly RecentGames $games,
    ) {
    }

    public function mentions(string $text): bool
    {
        $folded = Text::fold($text);
        foreach ($this->config->array('ai.mention_keywords', ['claudia']) as $kw) {
            $kw = preg_quote(Text::fold((string) $kw), '/');
            if ($kw !== '' && preg_match('/(?<![\p{L}\p{N}])@?' . $kw . '(?![\p{L}\p{N}])/u', $folded)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string|null self::MENTION, self::GAME o null si no corresponde pedir.
     */
    public function evaluate(string $key, ?int $userId, string $text, float $now): ?string
    {
        $mention = $this->mentions($text);
        $game = $userId !== null && $this->games->recent($userId, (int) $now) !== null;
        if (!$mention && !$game) {
            return null;
        }
        if (isset($this->inFlight[$key])) {
            return null;
        }
        $cooldown = $this->config->int('ai.cooldown_seconds', 25);
        $last = $this->lastActivity[$key] ?? null;
        $cooling = $last !== null && ($now - $last) < $cooldown;
        if ($mention) {
            $this->lastActivity[$key] = (int) $now;
        }
        if ($cooling) {
            return null;
        }
        if (!$this->globalAvailable($now)) {
            return null;
        }
        return $mention ? self::MENTION : self::GAME;
    }

    /** Reserva un lugar en el tope global. Devuelve false si no hay cupo. */
    public function takeGlobal(float $now): bool
    {
        if (!$this->globalAvailable($now)) {
            return false;
        }
        $this->window[] = $now;
        return true;
    }

    public function globalAvailable(float $now): bool
    {
        $this->window = array_values(array_filter($this->window, fn (float $t) => $now - $t < 60.0));
        return count($this->window) < $this->config->int('ai.global_limit_per_minute', 5);
    }

    public function begin(string $key): void
    {
        $this->inFlight[$key] = true;
    }

    public function end(string $key, bool $responded, int $now): void
    {
        unset($this->inFlight[$key]);
        if ($responded) {
            $this->lastActivity[$key] = $now;
        }
    }
}
