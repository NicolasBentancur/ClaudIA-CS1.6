<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Games\Blackjack\Hand;
use Claudia\Games\Blackjack\Shoe;
use Claudia\Games\Blackjack\Table;
use PHPUnit\Framework\TestCase;

final class BlackjackTest extends TestCase
{
    /** Mesa con cartas fijas. Orden de reparto: jugador, crupier, jugador, crupier, luego lo que se pida. */
    private function table(array $ranks, array $rules = []): Table
    {
        $shoe = new Shoe(6);
        $shoe->stack(array_map(fn ($r) => Shoe::card((string) $r), $ranks));
        return new Table($shoe, $rules);
    }

    public function testHandValuesWithAces(): void
    {
        $h = new Hand(10);
        $h->add(Shoe::card('A'));
        $h->add(Shoe::card('6'));
        $this->assertSame(17, $h->value());
        $this->assertTrue($h->soft());
        $h->add(Shoe::card('9'));
        $this->assertSame(16, $h->value());
        $this->assertFalse($h->soft());
        $h->add(Shoe::card('A'));
        $this->assertSame(17, $h->value());
    }

    public function testShoeHasSixDecks(): void
    {
        $shoe = new Shoe(6);
        $this->assertSame(312, $shoe->remaining());
    }

    public function testPlayerBlackjackPaysThreeToTwo(): void
    {
        $t = $this->table(['A', '9', 'K', '7']);
        $t->deal(100);
        $this->assertSame(Table::DONE, $t->phase);
        $this->assertSame('blackjack', $t->hands[0]->result);
        $this->assertSame(250, $t->totalReturn());
    }

    public function testBlackjackAgainstDealerBlackjackIsPush(): void
    {
        $t = $this->table(['A', 'A', 'K', 'K']);
        $t->deal(100);
        $this->assertSame('push', $t->hands[0]->result);
        $this->assertSame(100, $t->totalReturn());
    }

    public function testDealerPeeksBlackjack(): void
    {
        $t = $this->table(['9', 'A', '9', 'Q']);
        $t->deal(100);
        $this->assertSame(Table::DONE, $t->phase);
        $this->assertTrue($t->dealerBlackjack);
        $this->assertSame('lose', $t->hands[0]->result);
    }

    public function testDealerStandsOnSoft17(): void
    {
        $t = $this->table(['10', 'A', '8', '6', '5']);
        $t->deal(100);
        $t->stand();
        $this->assertSame(Table::DEALER, $t->phase);
        $this->assertFalse($t->dealerStep());
        $this->assertCount(2, $t->dealer->cards);
        $this->assertSame('win', $t->hands[0]->result); // 18 vs 17
        $this->assertSame(200, $t->totalReturn());
    }

    public function testDealerHitsSoft17WhenConfigured(): void
    {
        $t = $this->table(['10', 'A', '8', '6', '5', '5'], ['dealer_hits_soft17' => true]);
        $t->deal(100);
        $t->stand();
        $this->assertTrue($t->dealerStep());  // A+6 blando -> pide (12)
        $this->assertTrue($t->dealerStep());  // 12 -> pide (17 duro)
        $this->assertFalse($t->dealerStep());
        $this->assertSame(17, $t->dealer->value());
        $this->assertSame('win', $t->hands[0]->result);
    }

    public function testDoubleDownDoublesBetAndTakesOneCard(): void
    {
        $t = $this->table(['6', '10', '5', '7', 'K', '2']);
        $t->deal(50);
        $this->assertTrue($t->canDouble());
        $t->double();
        $this->assertSame(100, $t->hands[0]->bet);
        $this->assertCount(3, $t->hands[0]->cards);
        $this->assertSame(Table::DEALER, $t->phase);
        while ($t->dealerStep()) {
        }
        // 21 contra 17 del crupier.
        $this->assertSame('win', $t->hands[0]->result);
        $this->assertSame(200, $t->totalReturn());
    }

    public function testSplitCreatesTwoHandsOnlyOnce(): void
    {
        $t = $this->table(['8', '10', '8', '7', '3', '8', '10', '10']);
        $t->deal(50);
        $this->assertTrue($t->canSplit());
        $t->split();
        $this->assertCount(2, $t->hands);
        $this->assertSame(50, $t->totalBet() - 50);
        $this->assertSame(11, $t->hands[0]->value()); // 8 + 3
        $this->assertFalse($t->canSplit());           // solo una vez
        $this->assertTrue($t->canDouble());           // doblar tras dividir
        $t->hit();                                    // 8+3+10 = 21 -> se planta solo
        $this->assertSame(1, $t->active);
        $this->assertSame(16, $t->hands[1]->value()); // 8 + 8
        $t->hit();                                    // +10 = 26, se pasa
        $this->assertSame(Table::DEALER, $t->phase);
        while ($t->dealerStep()) {
        }
        $this->assertSame('win', $t->hands[0]->result);  // 21 vs 17 (el 21 tras split no es blackjack)
        $this->assertSame('bust', $t->hands[1]->result);
        $this->assertSame(100, $t->totalReturn());
    }

    public function testSplitAcesGetOneCardEach(): void
    {
        $t = $this->table(['A', '9', 'A', '8', 'K', '5']);
        $t->deal(50);
        $t->split();
        $this->assertTrue($t->hands[0]->done);
        $this->assertTrue($t->hands[1]->done);
        $this->assertSame(Table::DEALER, $t->phase);
        $this->assertSame(21, $t->hands[0]->value());
        $this->assertFalse($t->hands[0]->blackjack());
        while ($t->dealerStep()) {
        }
        $this->assertSame('win', $t->hands[0]->result);  // 21 vs 17 paga 1:1
        $this->assertSame('lose', $t->hands[1]->result); // 16 vs 17
        $this->assertSame(100, $t->totalReturn());
    }

    public function testBustEndsRoundWithoutDealerPlaying(): void
    {
        $t = $this->table(['10', '9', '6', '7', 'K']);
        $t->deal(20);
        $t->hit();
        $this->assertSame(Table::DONE, $t->phase);
        $this->assertSame('bust', $t->hands[0]->result);
        $this->assertCount(2, $t->dealer->cards);
    }
}
