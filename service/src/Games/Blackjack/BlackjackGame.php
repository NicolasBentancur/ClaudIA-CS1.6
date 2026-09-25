<?php

declare(strict_types=1);

namespace Claudia\Games\Blackjack;

use Claudia\Config;
use Claudia\Economy\Wallet;
use Claudia\Games\Casino;
use Claudia\Games\GameHandler;
use Claudia\Games\GameManager;
use Claudia\Games\GameSession;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Blackjack individual por MOTD. Acciones de la página:
 *   {"type":"bet","amount":N} | {"type":"hit"} | {"type":"stand"} | {"type":"double"} | {"type":"split"}
 * El servicio responde siempre con {"type":"state", ...} (estado completo de la mesa).
 */
final class BlackjackGame implements GameHandler
{
    private const DEALER_DELAY = 0.8;

    public function __construct(
        private readonly Config $config,
        private readonly GameManager $manager,
        private readonly Casino $casino,
        private readonly Wallet $wallet,
    ) {
    }

    public function onOpen(GameSession $s): void
    {
        if (!isset($s->state['table'])) {
            $rules = $this->config->array('games.blackjack.rules');
            $s->state['table'] = new Table(
                new Shoe((int) ($rules['decks'] ?? 6), (float) ($rules['penetration'] ?? 0.75)),
                $rules
            );
            $s->state['round'] = null;
            $s->state['busy'] = false;
        }
        $this->sendState($s, ['init' => true]);
    }

    public function onMessage(GameSession $s, array $msg): void
    {
        /** @var Table $t */
        $t = $s->state['table'];
        $type = (string) ($msg['type'] ?? '');
        if ($t->phase === Table::DEALER) {
            throw new UserError('Esperá que juegue el crupier.');
        }
        switch ($type) {
            case 'bet':
                if ($t->phase === Table::PLAYER) {
                    throw new UserError('Ya hay una mano en juego.');
                }
                $amount = (int) ($msg['amount'] ?? 0);
                $this->casino->validateTotal($amount);
                $s->state['round'] = $this->casino->openRound($s->userId, 'blackjack', $amount);
                $s->state['busy'] = true;
                $t->deal($amount);
                $this->manager->sound($s, 'card');
                break;
            case 'hit':
                $this->require($t->canHit(), 'No podés pedir ahora.');
                $t->hit();
                $this->manager->sound($s, 'card');
                break;
            case 'stand':
                $this->require($t->canStand(), 'No podés plantarte ahora.');
                $t->stand();
                break;
            case 'double':
                $this->require($t->canDouble(), 'No podés doblar ahora.');
                $this->casino->raiseRound((int) $s->state['round'], $s->userId, $t->current()->bet);
                $t->double();
                $this->manager->sound($s, 'chip');
                break;
            case 'split':
                $this->require($t->canSplit(), 'No podés dividir esa mano.');
                $this->casino->raiseRound((int) $s->state['round'], $s->userId, $t->current()->bet);
                $t->split();
                $this->manager->sound($s, 'card');
                break;
            default:
                throw new UserError('Acción desconocida.');
        }
        $this->progress($s);
    }

    public function onClose(GameSession $s): void
    {
        /** @var Table|null $t */
        $t = $s->state['table'] ?? null;
        if ($t === null || $s->state['round'] === null) {
            return;
        }
        $t->standAll();
        while ($t->dealerStep()) {
            // el crupier juega sin animación
        }
        $this->settle($s, false);
    }

    private function progress(GameSession $s): void
    {
        /** @var Table $t */
        $t = $s->state['table'];
        if ($t->phase === Table::DEALER) {
            $this->sendState($s);
            $this->manager->later($s, self::DEALER_DELAY, fn () => $this->dealerTurn($s));
            return;
        }
        if ($t->phase === Table::DONE) {
            $this->settle($s, true);
            return;
        }
        $this->sendState($s);
    }

    private function dealerTurn(GameSession $s): void
    {
        /** @var Table $t */
        $t = $s->state['table'];
        if ($t->dealerStep()) {
            $this->manager->sound($s, 'card');
            $this->sendState($s);
            $this->manager->later($s, self::DEALER_DELAY, fn () => $this->dealerTurn($s));
            return;
        }
        $this->settle($s, true);
    }

    private function settle(GameSession $s, bool $notify): void
    {
        /** @var Table $t */
        $t = $s->state['table'];
        $roundId = $s->state['round'];
        if ($roundId === null) {
            return;
        }
        $s->state['round'] = null;
        $s->state['busy'] = false;
        $results = array_map(fn (Hand $h) => $h->result, $t->hands);
        $summary = 'Jugó al blackjack: '
            . implode(', ', array_map(fn (Hand $h) => $h->value() . ' (' . $this->resultName((string) $h->result) . ')', $t->hands))
            . ' contra ' . $t->dealer->value() . ' del crupier.';
        $settled = $this->casino->settle((int) $roundId, $t->totalReturn(), $summary, ['results' => $results]);
        if (!$notify) {
            return;
        }
        $this->sendState($s, ['net' => $settled['net'], 'payout' => $settled['payout']]);
        $this->manager->sound($s, $settled['net'] > 0 ? 'win' : ($settled['net'] < 0 ? 'lose' : 'push'));
        if ($settled['confiscated'] > 0) {
            $s->send(['type' => 'notice', 'message' => 'Clearing: te confiscaron ' . Text::coins($settled['confiscated']) . ' URU Coins para pagar tu deuda.']);
        }
    }

    private function sendState(GameSession $s, array $extra = []): void
    {
        /** @var Table $t */
        $t = $s->state['table'];
        $hideHole = $t->phase === Table::PLAYER;
        $dealerCards = array_map(fn (int $c) => Shoe::describe($c), $t->dealer->cards);
        if ($hideHole && isset($dealerCards[1])) {
            $dealerCards[1] = ['r' => '?', 's' => '?'];
        }
        $dealerValue = $hideHole
            ? ($t->dealer->cards === [] ? 0 : (Shoe::value($t->dealer->cards[0]) === 1 ? 11 : Shoe::value($t->dealer->cards[0])))
            : $t->dealer->value();
        $balance = $this->wallet->balance($s->userId);
        $rules = $this->config->array('games.blackjack.rules');
        $s->send([
            'type' => 'state',
            'phase' => $t->phase,
            'dealer' => ['cards' => $dealerCards, 'value' => $dealerValue, 'blackjack' => !$hideHole && $t->dealer->blackjack()],
            'hands' => array_map(fn (Hand $h) => [
                'cards' => array_map(fn (int $c) => Shoe::describe($c), $h->cards),
                'value' => $h->value(),
                'soft' => $h->soft(),
                'bet' => $h->bet,
                'doubled' => $h->doubled,
                'done' => $h->done,
                'result' => $h->result,
                'return' => $h->return,
            ], $t->hands),
            'active' => $t->active,
            'actions' => [
                'hit' => $t->canHit(),
                'stand' => $t->canStand(),
                'double' => $t->canDouble() && $balance >= ($t->current()?->bet ?? PHP_INT_MAX),
                'split' => $t->canSplit() && $balance >= ($t->current()?->bet ?? PHP_INT_MAX),
                'bet' => $t->phase === Table::BETTING || $t->phase === Table::DONE,
            ],
            'balance' => $balance,
            'chips' => $this->casino->chipsFor($balance),
            'min' => $this->casino->minBet(),
            'max' => $this->casino->maxBet(),
            'rules' => [
                'decks' => (int) ($rules['decks'] ?? 6),
                'payout' => '3:2',
                'soft17' => ($rules['dealer_hits_soft17'] ?? false) ? 'pide' : 'se planta',
            ],
        ] + $extra);
    }

    private function resultName(string $r): string
    {
        return match ($r) {
            'blackjack' => 'blackjack',
            'win' => 'ganó',
            'push' => 'empate',
            'bust' => 'se pasó',
            default => 'perdió',
        };
    }

    private function require(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new UserError($message);
        }
    }
}
