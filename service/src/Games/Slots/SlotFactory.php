<?php

declare(strict_types=1);

namespace Claudia\Games\Slots;

use Claudia\Config;

/**
 * Crea el motor matemático de cada slot a partir de config/games/<slot>.json.
 */
final class SlotFactory
{
    public const GAMES = [
        'chanchitos' => ChanchitosEngine::class,
        'dulce' => DulceEngine::class,
        'materush' => MateRushEngine::class,
    ];

    public static function engine(string $game, array $math): SlotEngine
    {
        $class = self::GAMES[$game] ?? throw new \InvalidArgumentException("Slot desconocido: {$game}");
        return new $class($math);
    }

    public static function fromConfig(Config $config, string $game): SlotEngine
    {
        return self::engine($game, $config->array("games.{$game}.math"));
    }
}
