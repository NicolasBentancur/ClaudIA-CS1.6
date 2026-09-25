<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\Util\Text;

/**
 * /ruleta /blackjack /chanchitos /dulce /materush /slots /casino
 */
final class GameCommands
{
    public static function register(App $app): void
    {
        $r = $app->commands;
        $sec = 'Casino';

        $open = fn (string $game) => function (CommandContext $c) use ($app, $game): void {
            $c->userId();
            $app->games->open($c->session, $game);
        };

        $r->register('ruleta', $open('ruleta'), '- ruleta francesa', $sec);
        $r->register('blackjack', $open('blackjack'), '- blackjack', $sec, true, ['bj', '21']);
        $r->register('chanchitos', $open('chanchitos'), '- slot Los 3 Chanchitos del Banco', $sec, true, ['chanchos']);
        $r->register('dulce', $open('dulce'), '- slot Dulce de Leche Bonanza', $sec, true, ['bonanza']);
        $r->register('materush', $open('materush'), '- slot Mate Rush', $sec, true, ['mate', 'rush']);

        $r->register('slots', function (CommandContext $c) use ($app): void {
            $c->reply('Slots: {green}/chanchitos{default} (Los 3 Chanchitos del Banco), {green}/dulce{default} (Dulce de Leche Bonanza) y {green}/materush{default} (Mate Rush).');
        }, '- slots disponibles', $sec, false, ['tragamonedas', 'tragaperras']);

        $r->register('casino', function (CommandContext $c) use ($app): void {
            $c->reply('Casino: {green}/ruleta{default}, {green}/blackjack{default} y los slots {green}/chanchitos{default}, {green}/dulce{default} y {green}/materush{default}. Apuesta de '
                . Text::coins($app->casino->minBet()) . ' a ' . Text::coins($app->casino->maxBet()) . ' URU Coins.');
        }, '- juegos disponibles', $sec, false, ['juegos']);
    }
}
