<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\Net\Out;
use Claudia\Players\Role;
use Claudia\UserError;

/**
 * /radio <tema o link> | /radio saltar | /radio cola | /radio sacar <n>
 * (/radio on y /radio off los maneja el plugin claudia_radio en el servidor de juego.)
 */
final class RadioCommands
{
    public static function register(App $app): void
    {
        $radio = $app->radio;

        $app->commands->register('radio', function (CommandContext $c) use ($app, $radio): void {
            $uid = $c->userId();
            $sub = mb_strtolower($c->argv[0] ?? '');
            $staff = $c->role >= Role::STAFF;

            if ($sub === '' || $sub === 'ayuda') {
                $st = $radio->status();
                $now = $st['playing'] !== null ? "Sonando: {green}{$st['playing']['title']}{default}. " : 'No está sonando nada. ';
                $c->reply($now . 'Pedí un tema con {green}/radio <nombre o link de YouTube>{default}. /radio cola, /radio saltar, /radio off para no escucharla.');
                return;
            }
            if ($sub === 'on' || $sub === 'off') {
                // Si llegó hasta acá, el plugin de la radio no está cargado.
                throw new UserError('La radio no está disponible en este servidor.');
            }
            if ($sub === 'saltar' || $sub === 'skip') {
                $r = $radio->skip($uid, $staff);
                if (!$r['skipped']) {
                    $app->out->chat(Out::ALL, "Radio: {$c->session->nick} quiere saltar el tema ({$r['votes']}/{$r['needed']}). /radio saltar para votar.");
                }
                return;
            }
            if ($sub === 'cola' || $sub === 'lista') {
                $st = $radio->status();
                if ($st['playing'] === null && $st['queue'] === []) {
                    $c->reply('La cola está vacía. Pedí un tema con /radio <nombre o link>.');
                    return;
                }
                $lines = [];
                if ($st['playing'] !== null) {
                    $lines[] = "Sonando: {$st['playing']['title']} ({$st['playing']['nick']})";
                }
                foreach ($st['queue'] as $i => $t) {
                    $state = match ($t['status']) {
                        'ready' => '',
                        'pending' => ' [en espera]',
                        default => ' [bajando]',
                    };
                    $lines[] = ($i + 1) . ". {$t['title']} ({$t['nick']}){$state}";
                }
                $c->reply(implode(' | ', $lines));
                return;
            }
            if ($sub === 'sacar' || $sub === 'quitar') {
                $pos = (int) ($c->argv[1] ?? 0);
                $title = $radio->remove($uid, $staff, $pos);
                $c->reply("Saqué \"{$title}\" de la cola.");
                return;
            }

            $item = $radio->request($uid, $c->session->nick, $c->args);
            $pos = count($radio->status()['queue']);
            $c->reply("Radio: buscando {green}{$item['query']}{default}... (puesto {$pos} en la cola)");
        }, '<tema o link> - pedir un tema en la radio (saltar, cola, sacar <n>, on, off)', 'Radio', true, ['musica', 'música']);
    }
}
