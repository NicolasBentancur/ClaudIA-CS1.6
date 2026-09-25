<?php

declare(strict_types=1);

namespace Claudia\Games\Blackjack;

final class Hand
{
    /** @var list<int> */
    public array $cards = [];
    public bool $doubled = false;
    public bool $done = false;
    public bool $fromSplit = false;
    public bool $splitAces = false;
    public ?string $result = null;
    public int $return = 0;

    public function __construct(public int $bet)
    {
    }

    public function add(int $card): void
    {
        $this->cards[] = $card;
    }

    public function value(): int
    {
        [$v] = $this->evaluate();
        return $v;
    }

    /** true si hay un As contando como 11. */
    public function soft(): bool
    {
        [, $soft] = $this->evaluate();
        return $soft;
    }

    public function busted(): bool
    {
        return $this->value() > 21;
    }

    /** Blackjack natural: 2 cartas que suman 21 y no viene de un split. */
    public function blackjack(): bool
    {
        return !$this->fromSplit && count($this->cards) === 2 && $this->value() === 21;
    }

    /** @return array{0:int, 1:bool} */
    private function evaluate(): array
    {
        $sum = 0;
        $aces = 0;
        foreach ($this->cards as $c) {
            $v = Shoe::value($c);
            $sum += $v;
            if ($v === 1) {
                $aces++;
            }
        }
        if ($aces > 0 && $sum + 10 <= 21) {
            return [$sum + 10, true];
        }
        return [$sum, false];
    }
}
