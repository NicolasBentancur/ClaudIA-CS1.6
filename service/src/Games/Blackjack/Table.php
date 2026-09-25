<?php

declare(strict_types=1);

namespace Claudia\Games\Blackjack;

/**
 * Lógica pura del blackjack (sin dinero ni red). Reglas por defecto:
 *  - 6 mazos, rebaraja al 75 %.
 *  - El crupier se planta en 17 blando (S17) y revisa si tiene blackjack con un As o un 10 a la vista.
 *  - Blackjack paga 3:2; empate devuelve la apuesta.
 *  - Doblar con las dos primeras cartas (también después de dividir).
 *  - Dividir una vez (2 manos) cartas del mismo valor; los ases divididos reciben una sola carta.
 */
final class Table
{
    public const BETTING = 'betting';
    public const PLAYER = 'player';
    public const DEALER = 'dealer';
    public const DONE = 'done';

    public string $phase = self::BETTING;

    /** @var list<Hand> */
    public array $hands = [];
    public Hand $dealer;
    public int $active = 0;
    public bool $dealerBlackjack = false;

    private array $rules;

    public function __construct(public Shoe $shoe, array $rules = [])
    {
        $this->rules = $rules + [
            'dealer_hits_soft17' => false,
            'blackjack_payout' => 1.5,
            'max_splits' => 1,
            'double_after_split' => true,
            'split_by_value' => true,
        ];
        $this->dealer = new Hand(0);
    }

    public function deal(int $bet): void
    {
        if ($this->phase === self::PLAYER || $this->phase === self::DEALER) {
            throw new \LogicException('Mano en curso');
        }
        if ($this->shoe->needsShuffle()) {
            $this->shoe->shuffle();
        }
        $this->hands = [new Hand($bet)];
        $this->dealer = new Hand(0);
        $this->active = 0;
        $this->dealerBlackjack = false;
        $this->hands[0]->add($this->shoe->draw());
        $this->dealer->add($this->shoe->draw());
        $this->hands[0]->add($this->shoe->draw());
        $this->dealer->add($this->shoe->draw());
        $this->phase = self::PLAYER;

        $up = Shoe::value($this->dealer->cards[0]);
        if (($up === 1 || $up === 10) && $this->dealer->blackjack()) {
            $this->dealerBlackjack = true;
            $this->hands[0]->done = true;
            $this->phase = self::DONE;
            $this->resolve();
            return;
        }
        if ($this->hands[0]->blackjack()) {
            $this->hands[0]->done = true;
            $this->phase = self::DONE;
            $this->resolve();
        }
    }

    public function current(): ?Hand
    {
        return $this->phase === self::PLAYER ? ($this->hands[$this->active] ?? null) : null;
    }

    public function canHit(): bool
    {
        $h = $this->current();
        return $h !== null && !$h->done && !$h->splitAces && $h->value() < 21;
    }

    public function canStand(): bool
    {
        $h = $this->current();
        return $h !== null && !$h->done;
    }

    public function canDouble(): bool
    {
        $h = $this->current();
        return $h !== null && !$h->done && count($h->cards) === 2 && !$h->splitAces
            && (!$h->fromSplit || $this->rules['double_after_split']);
    }

    public function canSplit(): bool
    {
        $h = $this->current();
        if ($h === null || $h->done || count($h->cards) !== 2 || count($this->hands) > (int) $this->rules['max_splits']) {
            return false;
        }
        [$a, $b] = $h->cards;
        return $this->rules['split_by_value']
            ? Shoe::value($a) === Shoe::value($b)
            : Shoe::rank($a) === Shoe::rank($b);
    }

    public function hit(): void
    {
        if (!$this->canHit()) {
            throw new \LogicException('No se puede pedir');
        }
        $h = $this->hands[$this->active];
        $h->add($this->shoe->draw());
        if ($h->value() >= 21) {
            $this->finishHand();
        }
    }

    public function stand(): void
    {
        if (!$this->canStand()) {
            throw new \LogicException('No se puede plantar');
        }
        $this->finishHand();
    }

    /** El que llama ya descontó la apuesta extra. */
    public function double(): void
    {
        if (!$this->canDouble()) {
            throw new \LogicException('No se puede doblar');
        }
        $h = $this->hands[$this->active];
        $h->bet *= 2;
        $h->doubled = true;
        $h->add($this->shoe->draw());
        $this->finishHand();
    }

    /** El que llama ya descontó la apuesta de la mano nueva. */
    public function split(): void
    {
        if (!$this->canSplit()) {
            throw new \LogicException('No se puede dividir');
        }
        $h = $this->hands[$this->active];
        $second = array_pop($h->cards);
        $new = new Hand($h->bet);
        $new->add($second);
        $h->fromSplit = $new->fromSplit = true;
        $aces = Shoe::rank($h->cards[0]) === 0;
        $h->splitAces = $new->splitAces = $aces;
        $h->add($this->shoe->draw());
        $new->add($this->shoe->draw());
        array_splice($this->hands, $this->active + 1, 0, [$new]);
        if ($aces) {
            $h->done = true;
            $new->done = true;
            $this->advance();
        } elseif ($h->value() === 21) {
            $this->finishHand();
        }
    }

    /** Termina todas las manos del jugador (cerrar el MOTD a mitad de mano). */
    public function standAll(): void
    {
        if ($this->phase !== self::PLAYER) {
            return;
        }
        foreach ($this->hands as $h) {
            $h->done = true;
        }
        $this->advance();
    }

    public function dealerShouldHit(): bool
    {
        $v = $this->dealer->value();
        return $v < 17 || ($v === 17 && $this->dealer->soft() && $this->rules['dealer_hits_soft17']);
    }

    /** Un paso del crupier. Devuelve true si sacó carta, false si terminó (y resuelve). */
    public function dealerStep(): bool
    {
        if ($this->phase !== self::DEALER) {
            return false;
        }
        if ($this->dealerShouldHit()) {
            $this->dealer->add($this->shoe->draw());
            return true;
        }
        $this->phase = self::DONE;
        $this->resolve();
        return false;
    }

    public function totalBet(): int
    {
        return array_sum(array_map(fn (Hand $h) => $h->bet, $this->hands));
    }

    public function totalReturn(): int
    {
        return array_sum(array_map(fn (Hand $h) => $h->return, $this->hands));
    }

    private function finishHand(): void
    {
        $this->hands[$this->active]->done = true;
        $this->advance();
    }

    private function advance(): void
    {
        foreach ($this->hands as $i => $h) {
            if (!$h->done) {
                $this->active = $i;
                if ($h->value() === 21) {
                    // 21 tras dividir: se planta solo.
                    $h->done = true;
                    continue;
                }
                return;
            }
        }
        $allBusted = array_reduce($this->hands, fn (bool $c, Hand $h) => $c && $h->busted(), true);
        if ($allBusted) {
            $this->phase = self::DONE;
            $this->resolve();
            return;
        }
        $this->phase = self::DEALER;
    }

    private function resolve(): void
    {
        $dealerValue = $this->dealer->value();
        $dealerBj = $this->dealer->blackjack();
        foreach ($this->hands as $h) {
            if ($h->busted()) {
                [$h->result, $h->return] = ['bust', 0];
            } elseif ($dealerBj) {
                [$h->result, $h->return] = $h->blackjack() ? ['push', $h->bet] : ['lose', 0];
            } elseif ($h->blackjack()) {
                [$h->result, $h->return] = ['blackjack', $h->bet + (int) floor($h->bet * (float) $this->rules['blackjack_payout'])];
            } elseif ($dealerValue > 21 || $h->value() > $dealerValue) {
                [$h->result, $h->return] = ['win', $h->bet * 2];
            } elseif ($h->value() === $dealerValue) {
                [$h->result, $h->return] = ['push', $h->bet];
            } else {
                [$h->result, $h->return] = ['lose', 0];
            }
        }
    }
}
