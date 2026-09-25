<?php

declare(strict_types=1);

namespace Claudia\Games\Slots;

use Claudia\Config;
use Claudia\Economy\Wallet;
use Claudia\Games\Casino;
use Claudia\Games\GameHandler;
use Claudia\Games\GameManager;
use Claudia\Games\GameSession;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Sesión de un slot en el MOTD. El giro (con sus cascadas y bonus) se resuelve y se paga en el
 * servidor en un solo paso; la página recibe la lista de pasos y los anima.
 *
 *   página -> {"type":"spin","bet":100} | {"type":"buy","bet":100,"option":"giros"} | {"type":"sfx","name":"stop"}
 *   servicio -> init | result | error
 *
 * Los sonidos los pide la página ("sfx") en el momento justo de la animación; el servicio
 * los valida contra la lista del JSON y los reproduce en el juego vía el plugin.
 */
final class SlotGame implements GameHandler
{
    private const SFX_PER_SECOND = 8;

    public function __construct(
        private readonly string $game,
        private readonly Config $config,
        private readonly GameManager $manager,
        private readonly Casino $casino,
        private readonly Wallet $wallet,
        private readonly ?Rng $rng = null,
    ) {
    }

    public function onOpen(GameSession $s): void
    {
        $engine = SlotFactory::fromConfig($this->config, $this->game);
        $balance = $this->wallet->balance($s->userId);
        $s->send([
            'type' => 'init',
            'game' => $this->game,
            'name' => $this->cfg('name', $this->game),
            'layout' => $engine->layout(),
            'balance' => $balance,
            'bets' => $this->betLevels(),
            'buy' => $this->buyOptions(),
            'maxWin' => (float) $this->cfg('max_win', 5000),
        ]);
    }

    public function onMessage(GameSession $s, array $msg): void
    {
        switch ((string) ($msg['type'] ?? '')) {
            case 'spin':
                $this->play($s, (int) ($msg['bet'] ?? 0), null);
                return;
            case 'buy':
                $this->play($s, (int) ($msg['bet'] ?? 0), (string) ($msg['option'] ?? ''));
                return;
            case 'sfx':
                $this->sfx($s, (string) ($msg['name'] ?? ''));
                return;
        }
        throw new UserError('Acción desconocida.');
    }

    public function onClose(GameSession $s): void
    {
        // Los giros se pagan al instante: no queda nada pendiente.
    }

    private function play(GameSession $s, int $bet, ?string $buy): void
    {
        if (!in_array($bet, $this->betLevels(), true)) {
            throw new UserError('Elegí una apuesta válida.');
        }
        $this->casino->validateTotal($bet);
        $cost = $bet;
        $buyName = null;
        if ($buy !== null) {
            $options = $this->buyOptions();
            $option = null;
            foreach ($options as $o) {
                if ($o['id'] === $buy) {
                    $option = $o;
                }
            }
            if ($option === null) {
                throw new UserError('Esa compra no está disponible.');
            }
            $cost = (int) round($option['price'] * $bet);
            $buyName = $option['name'];
        }

        $engine = SlotFactory::fromConfig($this->config, $this->game);
        $roundId = $this->casino->openRound($s->userId, $this->game, $cost, ['bet' => $bet, 'buy' => $buy]);
        $result = $engine->spin($this->rng ?? new Rng(), $buy);

        $maxWin = (float) $this->cfg('max_win', 5000);
        $capped = $result['win'] > $maxWin;
        $multiple = min($result['win'], $maxWin);
        $payout = (int) floor($multiple * $bet);

        $name = (string) $this->cfg('name', $this->game);
        $summary = "Jugó al slot {$name}" . ($buyName !== null ? " comprando \"{$buyName}\"" : '') . '. ' . $result['summary'];
        $settled = $this->casino->settle($roundId, $payout, trim($summary), [
            'bet' => $bet,
            'buy' => $buy,
            'win' => round($result['win'], 4),
            'bonus' => $result['bonus'],
        ]);

        $s->send([
            'type' => 'result',
            'bet' => $bet,
            'cost' => $cost,
            'buy' => $buy,
            'steps' => $result['steps'],
            'bonus' => $result['bonus'],
            'multiple' => round($multiple, 4),
            'capped' => $capped,
            'payout' => $payout,
            'net' => $settled['net'],
            'confiscated' => $settled['confiscated'],
            'balance' => $settled['balance'],
        ]);
        if ($settled['confiscated'] > 0) {
            $s->send(['type' => 'notice', 'message' => 'Clearing: te confiscaron ' . Text::coins($settled['confiscated']) . ' URU Coins para pagar tu deuda.']);
        }
    }

    private function sfx(GameSession $s, string $name): void
    {
        if ($this->cfg("sounds.{$name}", '') === '') {
            return;
        }
        $now = microtime(true);
        $recent = array_values(array_filter($s->state['sfx'] ?? [], fn (float $t) => $now - $t < 1.0));
        if (count($recent) >= self::SFX_PER_SECOND) {
            return;
        }
        $recent[] = $now;
        $s->state['sfx'] = $recent;
        $this->manager->sound($s, $name);
    }

    /** @return list<int> */
    private function betLevels(): array
    {
        $levels = array_map('intval', (array) $this->cfg('bet_levels', [10, 20, 50, 100]));
        return array_values(array_filter($levels, fn (int $b) => $b >= $this->casino->minBet() && $b <= $this->casino->maxBet()));
    }

    /** @return list<array{id:string, name:string, price:float}> */
    private function buyOptions(): array
    {
        $out = [];
        foreach ((array) $this->cfg('buy', []) as $id => $o) {
            if (is_array($o) && ($o['enabled'] ?? false)) {
                $out[] = ['id' => (string) $id, 'name' => (string) ($o['name'] ?? $id), 'price' => (float) ($o['price'] ?? 100)];
            }
        }
        return $out;
    }

    private function cfg(string $key, mixed $default = null): mixed
    {
        return $this->config->get("games.{$this->game}.{$key}", $default);
    }
}
