<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * /trabajos /empleadores /postular /trabajo /cobrar /renunciar
 */
final class JobCommands
{
    public static function register(App $app): void
    {
        $r = $app->commands;
        $sec = 'Trabajo';

        $r->register('trabajos', function (CommandContext $c) use ($app): void {
            $c->console('===== Trabajos =====');
            $ids = [];
            foreach ($app->jobs->jobs() as $id => $job) {
                $ids[] = $id;
                $levels = (array) $job['levels'];
                $first = reset($levels);
                $last = end($levels);
                $c->console("[{$id}] {$job['name']}: {$job['description']}");
                $c->console('   Sueldo base: ' . Text::coins((int) $first['salary']) . ' a ' . Text::coins((int) $last['salary']) . ' por día, ' . count($levels) . ' niveles | Empleadores: ' . implode(', ', array_keys((array) $job['employers'])));
            }
            $c->reply('Trabajos: {green}' . implode(', ', $ids) . '{default}. Detalle en la consola. Mirá quién contrata con /empleadores <trabajo>.');
        }, '- trabajos disponibles', $sec, false);

        $r->register('empleadores', function (CommandContext $c) use ($app): void {
            $id = (string) $c->arg(0, '');
            if ($id === '') {
                throw new UserError('Uso: /empleadores <trabajo>. Mirá /trabajos.');
            }
            $job = $app->jobs->job($id);
            foreach ((array) $job['employers'] as $eid => $e) {
                $c->reply("{green}[{$eid}]{default} {$e['name']}: {$e['description']} (sueldo x" . ($e['salary_multiplier'] ?? 1) . ')');
            }
            $c->reply('Postulate con /postular ' . mb_strtolower($id) . ' <empleador>');
        }, '<trabajo> - empleadores de un trabajo', $sec, false);

        $r->register('postular', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            if (count($c->argv) < 2) {
                throw new UserError('Uso: /postular <trabajo> <empleador>');
            }
            $res = $app->jobs->apply($uid, (string) $c->arg(0), (string) $c->arg(1));
            $info = $app->jobs->info($uid);
            $c->reply("Te tomaron en {green}{$info['employerName']}{default} como {$info['levelName']} ({$info['jobName']}). Sueldo: "
                . Text::coins($info['salary']) . ' por día. Cobrá con /cobrar cada 24 hs.');
        }, '<trabajo> <empleador> - conseguir trabajo', $sec);

        $r->register('trabajo', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $info = $app->jobs->info($uid);
            if ($info === null) {
                $c->reply('No tenés trabajo. Mirá /trabajos.');
                return;
            }
            $e = (array) $app->jobs->employment($uid);
            $wait = $app->jobs->secondsToClaim($uid);
            $c->reply("{green}{$info['jobName']}{default} en {$info['employerName']} - {$info['levelName']} (sueldo " . Text::coins($info['salary'])
                . ') | Antigüedad: ' . Text::duration(\Claudia\Clock::now() - (int) $e['hired_at'])
                . ' | ' . ($wait > 0 ? 'Próximo cobro en ' . Text::duration($wait) : 'Ya podés /cobrar'));
            $c->reply('Actividad desde el último cobro: ' . Text::duration((int) $e['act_playtime']) . " jugado, {$e['act_kills']} kills, {$e['act_messages']} mensajes.");
        }, '- tu trabajo actual', $sec, true, ['empleo']);

        $r->register('cobrar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $res = $app->jobs->claim($uid);
            $pct = (int) round($res['performance'] * 100);
            $c->reply("Cobraste {green}" . Text::coins($res['salary']) . "{default} de sueldo. Rendimiento: {$pct}%."
                . ($res['bonus'] > 0 ? ' Bonus: {green}+' . Text::coins($res['bonus']) . '{default}.' : ''));
            if ($res['promoted']) {
                $c->reply("¡Te ascendieron a {green}{$res['levelName']}{default}!");
            }
            if ($res['fired']) {
                $c->reply("{team}Te echaron de {$res['jobName']}{default} por bajo rendimiento. Si volvés a ese trabajo, entrás un nivel más abajo.");
            }
        }, '- cobrar el sueldo (cada 24 hs)', $sec, true, ['sueldo']);

        $r->register('renunciar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $info = $app->jobs->info($uid);
            if (Text::fold((string) $c->arg(0, '')) !== 'si') {
                if ($info === null) {
                    throw new UserError('No tenés trabajo.');
                }
                $c->reply("¿Seguro que querés renunciar a {$info['jobName']}? Si volvés vas a entrar un nivel más abajo. Confirmá con /renunciar si");
                return;
            }
            $app->jobs->quit($uid);
            $c->reply('Renunciaste. Ahora sos oficialmente un vago.');
        }, '- dejar tu trabajo', $sec);
    }
}
