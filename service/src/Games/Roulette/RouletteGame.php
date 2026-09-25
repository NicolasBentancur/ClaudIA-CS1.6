<?php

declare(strict_types=1);

namespace Claudia\Games\Roulette;

use Claudia\Config;
use Claudia\Economy\Wallet;
use Claudia\Games\Casino;
use Claudia\Games\GameHandler;
use Claudia\Games\GameManager;
use Claudia\Games\GameSession;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Ruleta francesa individual. Flujo:
 *   página -> {"type":"spin","bets":[...]}
 *   servicio -> spin_start, frames (toda la trayectoria de una vez: la página la pasa a animación CSS), result
 * Sonidos (giro, rebotes, ganar/perder) se reproducen en el juego vía el plugin,
 * sincronizados con los tiempos de la simulación.
 */
final class RouletteGame implements GameHandler
{
    /** Sesiones girando en este momento (para bajar a 15 fps si hay carga). */
    private static int $spinning = 0;

    /** Margen que la página espera antes de reproducir, para absorber la latencia. */
    private const CLIENT_BUFFER = 0.25;

    public function __construct(
        private readonly Config $config,
        private readonly GameManager $manager,
        private readonly Casino $casino,
        private readonly Wallet $wallet,
        private readonly BallPhysics $physics,
    ) {
    }

    public function onOpen(GameSession $s): void
    {
        $s->state += ['wheel' => (float) random_int(0, 359), 'busy' => false, 'history' => []];
        $balance = $this->wallet->balance($s->userId);
        $s->send([
            'type' => 'init',
            'game' => 'ruleta',
            'balance' => $balance,
            'chips' => $this->casino->chipsFor($balance),
            'min' => $this->casino->minBet(),
            'max' => $this->casino->maxBet(),
            'wheel' => $s->state['wheel'],
            'order' => Wheel::ORDER,
            'red' => Wheel::RED,
            'history' => $s->state['history'],
            'busy' => $s->state['busy'],
        ]);
    }

    public function onMessage(GameSession $s, array $msg): void
    {
        if (($msg['type'] ?? '') !== 'spin') {
            throw new UserError('Acción desconocida.');
        }
        if ($s->state['busy'] ?? false) {
            throw new UserError('Esperá que termine el giro.');
        }
        $bets = Bets::normalize((array) ($msg['bets'] ?? []));
        $total = Bets::total($bets);
        $this->casino->validateTotal($total);

        $result = Wheel::draw();
        $roundId = $this->casino->openRound($s->userId, 'ruleta', $total, ['bets' => $bets]);
        $fps = self::$spinning >= $this->config->int('games.ruleta.max_spins_at_full_fps', 8)
            ? $this->config->int('games.ruleta.fallback_fps', 15)
            : $this->config->int('games.ruleta.fps', 30);
        $sim = $this->physics->simulate($result, (float) ($s->state['wheel'] ?? 0), $fps);

        self::$spinning++;
        $s->state['busy'] = true;
        $s->state['round'] = ['id' => $roundId, 'bets' => $bets, 'result' => $result, 'settled' => false];
        $s->state['wheel'] = $sim['wheelEnd'];

        $s->send([
            'type' => 'spin_start',
            'fps' => $fps,
            'duration' => $sim['duration'],
            'buffer' => self::CLIENT_BUFFER,
            'balance' => $this->wallet->balance($s->userId),
            'total' => $total,
        ]);
        $this->manager->sound($s, 'spin');
        $s->send(['type' => 'frames', 'f' => $sim['frames'], 'last' => true]);
        foreach ($sim['bounces'] as $t) {
            $this->manager->later($s, $t + self::CLIENT_BUFFER, fn () => $this->manager->sound($s, 'bounce'));
        }
        $this->manager->later($s, $sim['settle'] + self::CLIENT_BUFFER + 0.3, fn () => $this->finish($s));
    }

    public function onClose(GameSession $s): void
    {
        // Si cierran el MOTD a mitad de giro, la ronda se liquida igual.
        if (isset($s->state['round']) && !$s->state['round']['settled']) {
            $this->finish($s, false);
        }
    }

    private function finish(GameSession $s, bool $notify = true): void
    {
        $round = $s->state['round'] ?? null;
        if ($round === null || $round['settled']) {
            return;
        }
        $s->state['round']['settled'] = true;
        self::$spinning = max(0, self::$spinning - 1);

        $result = (int) $round['result'];
        $payout = Bets::payout($round['bets'], $result);
        $color = Wheel::color($result);
        $settled = $this->casino->settle((int) $round['id'], $payout, "Jugó a la ruleta: salió el {$result} ({$color}).", [
            'bets' => $round['bets'],
            'result' => $result,
        ]);

        $winners = [];
        foreach ($round['bets'] as $i => $b) {
            $ret = Bets::betReturn($b, $result);
            if ($ret > 0) {
                $winners[] = ['index' => $i, 'type' => $b['type'], 'numbers' => $b['numbers'], 'return' => $ret];
            }
        }
        array_unshift($s->state['history'], $result);
        $s->state['history'] = array_slice($s->state['history'], 0, 12);
        $s->state['busy'] = false;

        if (!$notify) {
            return;
        }
        $s->send([
            'type' => 'result',
            'number' => $result,
            'color' => $color,
            'payout' => $payout,
            'net' => $settled['net'],
            'confiscated' => $settled['confiscated'],
            'balance' => $settled['balance'],
            'chips' => $this->casino->chipsFor($settled['balance']),
            'winners' => $winners,
            'history' => $s->state['history'],
        ]);
        $this->manager->sound($s, $settled['net'] > 0 ? 'win' : 'lose');
        if ($settled['confiscated'] > 0) {
            $s->send(['type' => 'notice', 'message' => 'Clearing: te confiscaron ' . Text::coins($settled['confiscated']) . ' URU Coins para pagar tu deuda.']);
        }
    }
}
