<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\Net\Out;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * /ajedrez <nick> <apuesta> | /ajedrez aceptar | /ajedrez rechazar | /ajedrez volver
 */
final class ChessCommands
{
    public static function register(App $app): void
    {
        $chess = $app->chess;

        $app->commands->register('ajedrez', function (CommandContext $c) use ($app, $chess): void {
            $uid = $c->userId();
            $sub = mb_strtolower($c->argv[0] ?? '');

            if ($sub === '' || $sub === 'ayuda') {
                $mins = intdiv((int) $app->config->get('games.ajedrez.time_seconds', 300), 60);
                $c->reply("Ajedrez 1 contra 1 por coins ({$mins} min cada uno): {green}/ajedrez <nick> <apuesta>{default}. El que gana se lleva el pozo"
                    . ' (la casa cobra ' . round((float) $app->config->get('games.ajedrez.house_rate', 0.05) * 100) . '%). Mientras juegan pasan a espectador.');
                return;
            }
            if ($sub === 'aceptar') {
                $m = $chess->accept($uid);
                $app->out->chat(Out::ALL, "Ajedrez: {green}{$m->names[$m->white]}{default} vs {green}{$m->names[$m->black]}{default} por "
                    . Text::coins($chess->prize($m->bet)) . ' URU Coins.');
                return;
            }
            if ($sub === 'rechazar') {
                $ch = $chess->decline($uid);
                $c->reply('Rechazaste el desafío de ajedrez.');
                $app->notify($ch['a'], "{$c->session->nick} no quiso jugar al ajedrez.");
                return;
            }
            if ($sub === 'volver' || $sub === 'tablero') {
                $chess->reopen($c->session);
                return;
            }

            if (count($c->argv) < 2) {
                throw new UserError('Uso: /ajedrez <nick> <apuesta>');
            }
            $argv = $c->argv;
            $amount = Text::parseAmount((string) array_pop($argv));
            if ($amount === null) {
                throw new UserError('Apuesta inválida.');
            }
            $target = $app->findOnline(implode(' ', $argv));
            $ch = $chess->challenge($uid, (int) $target->userId, $amount);
            $secs = (int) $app->config->get('games.ajedrez.accept_seconds', 60);
            $c->reply("Desafiaste a {green}{$target->nick}{default} al ajedrez por " . Text::coins($amount) . " URU Coins. Tiene {$secs} segundos para aceptar.");
            $app->out->chat($target->slot, "{green}{$c->session->nick}{default} te desafía al ajedrez por {green}" . Text::coins($amount)
                . '{default} URU Coins. /ajedrez aceptar o /ajedrez rechazar');
            $chess->offerMenu($target, $ch);
        }, '<nick> <apuesta> - desafiar a alguien al ajedrez (aceptar, rechazar, volver)', 'Casino', true, ['chess']);
    }
}
