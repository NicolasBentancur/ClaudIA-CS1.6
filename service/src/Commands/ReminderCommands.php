<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * /cumple /recordar /recordatorios /borrarrecordatorio
 */
final class ReminderCommands
{
    public static function register(App $app): void
    {
        $r = $app->commands;
        $sec = 'Recordatorios';

        $r->register('cumple', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $raw = trim($c->args);
            if ($raw === '') {
                $u = $app->users->find($uid);
                if (($u['birthday'] ?? null) === null) {
                    $c->reply('No me dijiste tu cumpleaños. Usá /cumple DD/MM');
                } else {
                    [$m, $d] = explode('-', (string) $u['birthday']);
                    $c->reply("Tu cumple es el {green}{$d}/{$m}{default}. Ese día te saludo.");
                }
                return;
            }
            $date = $app->reminders->setBirthday($uid, $raw);
            $c->reply("Anotado: tu cumple es el {green}{$date}{default}.");
        }, 'DD/MM - tu fecha de cumpleaños', $sec, true, ['cumpleaños', 'cumpleanos']);

        $r->register('recordar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            if (count($c->argv) < 2) {
                throw new UserError('Uso: /recordar <cuándo> <texto>. Ej: /recordar 30m cobrar el sueldo | /recordar 25/12 18:00 regalo');
            }
            $due = $app->reminders->add($uid, $c->argv);
            $c->reply('Te lo recuerdo el {green}' . $app->reminders->formatDate($due) . '{default} (en ' . Text::duration($due - \Claudia\Clock::now()) . ').');
        }, '<30m|2h|1d|DD/MM [HH:MM]|HH:MM> <texto> - crear un recordatorio', $sec, true, ['recordame']);

        $r->register('recordatorios', function (CommandContext $c) use ($app): void {
            $list = $app->reminders->pending($c->userId());
            if ($list === []) {
                $c->reply('No tenés recordatorios pendientes.');
                return;
            }
            foreach ($list as $i => $rem) {
                $c->reply(($i + 1) . '. {green}' . $app->reminders->formatDate((int) $rem['due_at']) . '{default} ' . $rem['text']);
            }
        }, '- tus recordatorios pendientes', $sec);

        $r->register('borrarrecordatorio', function (CommandContext $c) use ($app): void {
            $n = (int) $c->arg(0, '0');
            if ($n <= 0) {
                throw new UserError('Uso: /borrarrecordatorio <número> (mirá /recordatorios)');
            }
            $text = $app->reminders->delete($c->userId(), $n);
            $c->reply("Borré el recordatorio: {$text}");
        }, '<número> - borrar un recordatorio', $sec);
    }
}
