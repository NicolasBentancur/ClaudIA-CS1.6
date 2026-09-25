<?php

declare(strict_types=1);

namespace Claudia\Games\Blackjack;

/**
 * Zapato de N mazos barajado con CSPRNG (Fisher-Yates con random_int).
 * Carta = entero 0..51: rango = c % 13 (0=A, 1=2 ... 9=10, 10=J, 11=Q, 12=K), palo = intdiv(c, 13).
 */
final class Shoe
{
    private const RANKS = ['A', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K'];
    private const SUITS = ['s', 'h', 'd', 'c'];

    /** @var list<int> */
    private array $cards = [];
    private int $total = 0;

    public function __construct(private readonly int $decks = 6, private readonly float $penetration = 0.75)
    {
        $this->shuffle();
    }

    public function shuffle(): void
    {
        $cards = [];
        for ($d = 0; $d < $this->decks; $d++) {
            for ($c = 0; $c < 52; $c++) {
                $cards[] = $c;
            }
        }
        for ($i = count($cards) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$cards[$i], $cards[$j]] = [$cards[$j], $cards[$i]];
        }
        $this->cards = $cards;
        $this->total = count($cards);
    }

    /** true si ya se repartió más de la penetración configurada (se rebaraja antes de la próxima mano). */
    public function needsShuffle(): bool
    {
        return count($this->cards) < $this->total * (1 - $this->penetration);
    }

    public function draw(): int
    {
        if ($this->cards === []) {
            $this->shuffle();
        }
        return array_pop($this->cards);
    }

    /** Solo tests: fija las próximas cartas (la primera del array sale primero). @param list<int> $cards */
    public function stack(array $cards): void
    {
        foreach (array_reverse($cards) as $c) {
            $this->cards[] = $c;
        }
    }

    public function remaining(): int
    {
        return count($this->cards);
    }

    public static function rank(int $card): int
    {
        return $card % 13;
    }

    /** Valor base: A=1 (el 11 se resuelve en la mano), figuras=10. */
    public static function value(int $card): int
    {
        $r = self::rank($card);
        return $r === 0 ? 1 : min(10, $r + 1);
    }

    /** @return array{r:string, s:string} */
    public static function describe(int $card): array
    {
        return ['r' => self::RANKS[self::rank($card)], 's' => self::SUITS[intdiv($card % 52, 13)]];
    }

    /** Carta a partir de rango ('A','2'..'K') y palo ('s','h','d','c'). Útil en tests. */
    public static function card(string $rank, string $suit = 's'): int
    {
        return (int) array_search($suit, self::SUITS, true) * 13 + (int) array_search($rank, self::RANKS, true);
    }
}
