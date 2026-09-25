<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\Util\Text;

/**
 * /ruleta /blackjack /casino
 */
final class GameCommands
{
    public static function register(App $app): void
    {
        $r = $app->commands;
        $sec = 'Casino';

        $r->register('ruleta', function (CommandContext $c) use ($app): void {
            $c->userId();
            $app->games->open($c->session, 'ruleta');
        }, '- ruleta francesa', $sec);

        $r->register('blackjack', function (CommandContext $c) use ($app): void {
            $c->userId();
            $app->games->open($c->session, 'blackjack');
        }, '- blackjack', $sec, true, ['bj', '21']);

        $r->register('casino', function (CommandContext $c) use ($app): void {
            $c->reply('Casino: {green}/ruleta{default} (ruleta francesa) y {green}/blackjack{default}. Apuesta de '
                . Text::coins($app->casino->minBet()) . ' a ' . Text::coins($app->casino->maxBet()) . ' URU Coins.');
        }, '- juegos disponibles', $sec, false, ['juegos']);
    }
}
