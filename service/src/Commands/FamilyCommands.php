<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\Clock;
use Claudia\Net\Out;
use Claudia\Social\Proposals;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Pareja y familia:
 * /pareja /aceptar /rechazar /mipareja /terminar /ex /casarse /si /no /adoptar /familia
 * /apellido /familias /emancipar /desheredar /formarpareja /besar /siono
 */
final class FamilyCommands
{
    public static function register(App $app): void
    {
        $r = $app->commands;
        $sec = 'Pareja y familia';
        $ttl = fn () => $app->config->int('social.proposal_seconds', 90);

        /* ---------------------------- Pareja ---------------------------- */

        $r->register('pareja', function (CommandContext $c) use ($app, $ttl): void {
            $uid = $c->userId();
            if ($c->args === '') {
                throw new UserError('Uso: /pareja <nick>. Para ver tu pareja: /mipareja');
            }
            $target = $app->findOnline($c->args);
            $to = (int) $target->userId;
            $app->family->checkCanDate($uid, $to);
            if ($app->proposals->exists(Proposals::PARTNER, $to, $uid)) {
                // Los dos se lo pidieron: se da por aceptado.
                self::becomePartners($app, $to, $uid);
                return;
            }
            $app->proposals->add(Proposals::PARTNER, $uid, $to, $ttl());
            $c->reply("Le pediste a {green}{$target->nick}{default} que sea tu pareja. Tiene {$ttl()} segundos para contestar.");
            $app->out->chat($target->slot, "{green}{$app->nick($uid)}{default} te pide que seas su pareja. Escribí {green}/aceptar{default} o {green}/rechazar{default}.");
        }, '<nick> - pedirle a alguien que sea tu pareja', $sec, true, ['noviazgo']);

        $r->register('aceptar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $from = $c->args !== '' ? (int) $app->findUser($c->args)['id'] : null;
            $p = $app->proposals->pendingFor($uid, [Proposals::PARTNER, Proposals::CUPID], $from);
            if ($p === null) {
                throw new UserError('No tenés ninguna propuesta de pareja pendiente.');
            }
            if ($p['kind'] === Proposals::CUPID) {
                $other = $p['from'] === $uid ? $p['to'] : $p['from'];
                if (!$app->proposals->acceptCupid($p['id'], $uid)) {
                    $c->reply("Aceptaste. Falta que {$app->nick($other)} escriba /aceptar.");
                    $app->notify($other, "{$app->nick($uid)} aceptó la idea de Claudia. Si querés, escribí {green}/aceptar{default}.");
                    return;
                }
                $app->proposals->remove($p['id']);
                self::becomePartners($app, $p['from'], $p['to']);
                return;
            }
            $app->proposals->remove($p['id']);
            self::becomePartners($app, $p['from'], $uid);
        }, '[nick] - aceptar una propuesta de pareja', $sec);

        $r->register('rechazar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $from = $c->args !== '' ? (int) $app->findUser($c->args)['id'] : null;
            $p = $app->proposals->pendingFor($uid, [Proposals::PARTNER, Proposals::CUPID], $from);
            if ($p === null) {
                throw new UserError('No tenés ninguna propuesta de pareja pendiente.');
            }
            $app->proposals->remove($p['id']);
            $other = $p['kind'] === Proposals::CUPID && $p['to'] !== $uid ? $p['to'] : $p['from'];
            $c->reply("Rechazaste a {$app->nick($other)}.");
            $app->notify($other, "{$app->nick($uid)} " . ($p['kind'] === Proposals::CUPID ? 'no quiso saber nada con la idea de Claudia.' : 'te rechazó. Otra vez será.'));
        }, '[nick] - rechazar una propuesta de pareja', $sec);

        $r->register('mipareja', function (CommandContext $c) use ($app): void {
            $self = $c->session->userId;
            if ($c->args !== '') {
                $user = $app->findUser($c->args);
                $uid = (int) $user['id'];
                $who = (string) $user['nick'];
            } else {
                $uid = $c->userId();
                $who = null;
            }
            $rel = $app->family->relationship($uid);
            if ($rel === null) {
                $c->reply($who === null ? 'Estás solito/a. Pedile a alguien con /pareja <nick>, o dejá que Claudia haga de celestina con /formarpareja.' : "{$who} no tiene pareja.");
                return;
            }
            $partner = $app->nick((int) $rel['partner']);
            $now = Clock::now();
            if ($rel['status'] === 'casados') {
                $text = "casado/a con {green}{$partner}{default} hace " . Text::duration($now - (int) $rel['married_at'])
                    . ' (juntos hace ' . Text::duration($now - (int) $rel['started_at']) . ')';
            } else {
                $text = "de novio/a con {green}{$partner}{default} hace " . Text::duration($now - (int) $rel['started_at']);
            }
            $c->reply(($who === null ? 'Estás ' : "{$who} está ") . $text . " - {$rel['kisses']} besos.");
            if ($who === null && $self !== null && $rel['status'] === 'pareja') {
                $c->reply('Cuando estén listos: /casarse' . ($app->config->bool('social.marriage.requires_ring', true) ? ' (necesitás un anillo de la /tienda).' : '.'));
            }
        }, '[nick] - estado de tu pareja (o la de otro)', $sec, false);

        $r->register('terminar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $end = $app->family->endRelationship($uid, $app->family->isMarried($uid) ? 'divorcio' : 'terminó');
            $partner = $app->nick($end['partner']);
            $app->proposals->removeInvolving($uid, Proposals::MARRIAGE);
            $app->proposals->removeInvolving($end['partner'], Proposals::MARRIAGE);
            if ($end['married']) {
                $app->out->chat(Out::ALL, "{$app->nick($uid)} y {$partner} se divorciaron. Los hijos siguen siendo de los dos.");
            } else {
                $c->reply("Cortaste con {$partner}. Fueron " . Text::duration(Clock::now() - $end['since']) . ' juntos.');
            }
            $app->notify($end['partner'], "{$app->nick($uid)} " . ($end['married'] ? 'pidió el divorcio.' : 'cortó con vos. Arriba ese ánimo.'));
        }, '- terminar tu relación (si están casados, es divorcio)', $sec, true, ['cortar', 'divorcio', 'divorciarse']);

        $r->register('ex', function (CommandContext $c) use ($app): void {
            if ($c->args !== '') {
                $user = $app->findUser($c->args);
                $uid = (int) $user['id'];
                $who = (string) $user['nick'];
            } else {
                $uid = $c->userId();
                $who = null;
            }
            $exes = $app->family->exes($uid);
            if ($exes === []) {
                $c->reply(($who === null ? 'No tenés' : "{$who} no tiene") . ' ex parejas. Historial limpio.');
                return;
            }
            $list = array_map(fn ($e) => $e['nick'] . ((int) $e['was_married'] === 1 ? ' (casados)' : '') . ' - ' . Text::duration((int) $e['ended_at'] - (int) $e['started_at']), $exes);
            $c->reply('{green}Ex' . ($who === null ? '' : " de {$who}") . ':{default} ' . implode(' | ', $list));
        }, '[nick] - últimas ex parejas', $sec, false, ['exs']);

        /* --------------------------- Casamiento ------------------------- */

        $r->register('casarse', function (CommandContext $c) use ($app, $ttl): void {
            $uid = $c->userId();
            $partner = $app->family->checkCanMarry($uid);
            $ring = self::ringItem($app);
            if ($ring !== null && $app->shop->count($uid, $ring) <= 0) {
                throw new UserError('Para pedir casamiento necesitás un anillo. Compralo con /comprar ' . $ring . ' (' . Text::coins($app->shop->item($ring)['price']) . ' URU Coins).');
            }
            $session = $app->sessions->byUser($partner);
            if ($session === null) {
                throw new UserError("{$app->nick($partner)} no está conectado/a. Pedíselo cuando esté.");
            }
            $app->proposals->add(Proposals::MARRIAGE, $uid, $partner, $ttl());
            $c->reply("Le pediste casamiento a {green}{$app->nick($partner)}{default}. Ahora a esperar...");
            $app->out->chat($session->slot, "{green}{$app->nick($uid)}{default} se arrodilla y te pide casamiento" . ($ring !== null ? ' con anillo y todo' : '') . '. ¿Aceptás? Escribí {green}/si{default} o {green}/no{default}.');
        }, '- pedirle casamiento a tu pareja', $sec, true, ['casamiento', 'boda']);

        $r->register('si', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $p = $app->proposals->pendingFor($uid, [Proposals::MARRIAGE, Proposals::ADOPTION]);
            if ($p === null) {
                throw new UserError('No tenés ninguna propuesta de casamiento o adopción pendiente.');
            }
            $app->proposals->remove($p['id']);
            if ($p['kind'] === Proposals::MARRIAGE) {
                self::wed($app, $p['from'], $uid);
            } else {
                self::adopt($app, $p['from'], $uid);
            }
        }, '- aceptar una propuesta de casamiento o adopción', $sec, true, ['sí', 'acepto']);

        $r->register('no', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $p = $app->proposals->pendingFor($uid, [Proposals::MARRIAGE, Proposals::ADOPTION]);
            if ($p === null) {
                throw new UserError('No tenés ninguna propuesta de casamiento o adopción pendiente.');
            }
            $app->proposals->remove($p['id']);
            if ($p['kind'] === Proposals::MARRIAGE) {
                $c->reply('Le dijiste que no al casamiento. Uf.');
                $app->notify($p['from'], "{$app->nick($uid)} te dijo que no... Por ahora no hay boda. (El anillo lo seguís teniendo.)");
            } else {
                $c->reply('Rechazaste la adopción.');
                $app->notify($p['from'], "{$app->nick($uid)} no quiso ser adoptado/a.");
            }
        }, '- rechazar una propuesta de casamiento o adopción', $sec);

        /* ---------------------------- Familia --------------------------- */

        $r->register('adoptar', function (CommandContext $c) use ($app, $ttl): void {
            $uid = $c->userId();
            if ($c->args === '') {
                throw new UserError('Uso: /adoptar <nick>');
            }
            $target = $app->findOnline($c->args);
            $to = (int) $target->userId;
            $f = $app->family->checkCanAdopt($uid, $to);
            $app->proposals->add(Proposals::ADOPTION, $uid, $to, $ttl());
            $spouse = (int) $f['parent_a'] === $uid ? (int) $f['parent_b'] : (int) $f['parent_a'];
            $c->reply("Le propusiste a {green}{$target->nick}{default} ser parte de tu familia. Tiene {$ttl()} segundos para contestar.");
            $app->out->chat($target->slot, "{green}{$app->nick($uid)}{default} y {$app->nick($spouse)} te quieren adoptar" . self::surnameSuffix($f)
                . '. Escribí {green}/si{default} o {green}/no{default}.');
            $app->notify($spouse, "{$app->nick($uid)} le propuso a {$target->nick} adoptarlo/a.");
        }, '<nick> - adoptar a alguien (solo casados, máximo ' . $app->config->int('social.max_children', 4) . ' hijos)', $sec);

        $r->register('familia', function (CommandContext $c) use ($app): void {
            if ($c->args !== '') {
                $user = $app->findUser($c->args);
            } else {
                $user = (array) $app->users->find($c->userId());
            }
            self::printTree($app, $c, (int) $user['id'], (string) $user['nick']);
        }, '[nick] - árbol genealógico (en consola)', $sec, false, ['arbol']);

        $r->register('apellido', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            if ($c->args === '') {
                $s = $app->family->surnameOf($uid);
                $c->reply($s === null ? 'Tu familia no tiene apellido. Ponele uno con /apellido <texto> (tenés que estar casado/a).' : "Tu apellido es {green}{$s}{default}.");
                return;
            }
            $clear = in_array(Text::fold($c->args), ['borrar', 'quitar'], true);
            $res = $app->family->setSurname($uid, $clear ? null : $c->args);
            $f = $res['family'];
            $spouse = (int) $f['parent_a'] === $uid ? (int) $f['parent_b'] : (int) $f['parent_a'];
            $msg = $clear ? "{$app->nick($uid)} le sacó el apellido a la familia." : "La familia ahora se llama {green}{$res['surname']}{default} (lo puso {$app->nick($uid)}).";
            $c->reply($clear ? 'Listo, la familia quedó sin apellido.' : "Listo: familia {green}{$res['surname']}{default}. Se lo pasé a " . (count($res['children']) === 0 ? 'nadie más (no tienen hijos todavía).' : count($res['children']) . ' hijo/s.'));
            foreach (array_merge([$spouse], $res['children']) as $id) {
                $app->notify($id, $msg);
            }
        }, '<texto> - apellido de tu familia (se les pasa a todos los hijos; "borrar" para quitarlo)', $sec);

        $r->register('familias', function (CommandContext $c) use ($app): void {
            $list = $app->family->familiesBySize(15);
            if ($list === []) {
                $c->reply('Todavía no hay familias. Alguien que se case, por favor.');
                return;
            }
            $c->console('===== Familias =====');
            $short = [];
            foreach ($list as $i => $f) {
                $name = self::familyName($app, $f);
                $c->console(sprintf('%2d. %s - %d integrantes (%d hijos)%s', $i + 1, $name, $f['size'], $f['children'], $f['married'] ? '' : ' [divorciados]'));
                if ($i < 5) {
                    $short[] = ($i + 1) . ". {$name} ({$f['size']})";
                }
            }
            $c->reply('{green}Familias más grandes:{default} ' . implode(' | ', $short) . ' (lista completa en la consola)');
        }, '- familias más grandes', $sec, false);

        $r->register('emancipar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            $f = $app->family->emancipate($uid);
            $c->reply('Te emancipaste. Ahora sos libre (y pagás tus cuentas).');
            foreach ([(int) $f['parent_a'], (int) $f['parent_b']] as $p) {
                $app->notify($p, "{$app->nick($uid)} se emancipó y dejó la familia.");
            }
        }, '- irte de tu familia', $sec, true, ['independizarse']);

        $r->register('desheredar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            if ($c->args === '') {
                throw new UserError('Uso: /desheredar <nick>');
            }
            $child = $app->findUser($c->args);
            $f = $app->family->disown($uid, (int) $child['id']);
            $spouse = (int) $f['parent_a'] === $uid ? (int) $f['parent_b'] : (int) $f['parent_a'];
            $c->reply("Desheredaste a {$child['nick']}. Ya no es parte de la familia.");
            $app->notify((int) $child['id'], "{$app->nick($uid)} te desheredó. Quedaste afuera de la familia.");
            $app->notify($spouse, "{$app->nick($uid)} desheredó a {$child['nick']}.");
        }, '<nick> - sacar a un hijo de la familia', $sec);

        /* ----------------------------- Social --------------------------- */

        $r->register('formarpareja', function (CommandContext $c) use ($app, $ttl): void {
            $left = $app->cooldowns->left('cupido');
            if ($left > 0) {
                throw new UserError('Claudia ya hizo de celestina hace poco. Probá en ' . Text::duration($left) . '.');
            }
            $single = [];
            foreach ($app->sessions->logged() as $s) {
                if ($app->family->relationship((int) $s->userId) === null) {
                    $single[] = (int) $s->userId;
                }
            }
            shuffle($single);
            $pair = null;
            for ($i = 0; $i < count($single) && $pair === null; $i++) {
                for ($j = $i + 1; $j < count($single); $j++) {
                    if (!$app->family->closeRelatives($single[$i], $single[$j])) {
                        $pair = [$single[$i], $single[$j]];
                        break;
                    }
                }
            }
            if ($pair === null) {
                throw new UserError('No hay dos solteros conectados que puedan ser pareja. Qué tristeza.');
            }
            $app->cooldowns->take('cupido', $app->config->int('social.cupid.cooldown_seconds', 300));
            $app->proposals->add(Proposals::CUPID, $pair[0], $pair[1], $ttl());
            $lines = $app->config->array('social.cupid.lines', ['{a} y {b}, ¿se animan? /aceptar los dos.']);
            $app->out->chat(Out::ALL, strtr((string) $lines[array_rand($lines)], ['{a}' => '{green}' . $app->nick($pair[0]) . '{default}', '{b}' => '{green}' . $app->nick($pair[1]) . '{default}']));
        }, '- Claudia junta al azar a dos solteros conectados', $sec, false, ['celestina', 'cupido']);

        $r->register('besar', function (CommandContext $c) use ($app): void {
            $uid = $c->userId();
            if ($c->args === '') {
                throw new UserError('Uso: /besar <nick>');
            }
            $target = $app->findOnline($c->args);
            $to = (int) $target->userId;
            if ($to === $uid) {
                throw new UserError('Besarte a vos mismo es raro hasta para mí.');
            }
            $left = $app->cooldowns->take("beso:{$uid}", $app->config->int('social.kiss.cooldown_seconds', 20));
            if ($left > 0) {
                throw new UserError('Pará un poco con los besos. Esperá ' . Text::duration($left) . '.');
            }
            $total = $app->family->kiss($uid, $to);
            $vars = ['{a}' => '{green}' . $app->nick($uid) . '{default}', '{b}' => '{green}' . $target->nick . '{default}'];
            $lines = $app->config->array($total !== null ? 'social.kiss.partner_lines' : 'social.kiss.lines', ['{a} le dio un beso a {b}']);
            $app->out->chat(Out::ALL, strtr((string) $lines[array_rand($lines)], $vars) . ($total !== null ? " ({$total} besos)" : ''));
            if ($total === null) {
                // Si alguno tiene pareja, Claudia mete cizaña.
                $jealous = $app->family->partnerOf($to) ?? $app->family->partnerOf($uid);
                if ($jealous !== null && $jealous !== $uid && $jealous !== $to) {
                    $jl = $app->config->array('social.kiss.jealous_lines', []);
                    if ($jl !== []) {
                        $app->out->chat(Out::ALL, strtr((string) $jl[array_rand($jl)], $vars + ['{c}' => $app->nick($jealous)]));
                    }
                }
            }
        }, '<nick> - darle un beso a alguien', $sec, true, ['beso']);

        $r->register('siono', function (CommandContext $c) use ($app): void {
            $question = Text::chatSafe(Text::sanitize($c->args));
            if ($question === '') {
                throw new UserError('Uso: /siono <pregunta>');
            }
            $key = 'siono:' . ($c->session->userId ?? 'slot' . $c->session->slot);
            if ($app->cooldowns->take($key, $app->config->int('social.yes_no.cooldown_seconds', 10)) > 0) {
                throw new UserError('Pará, dejame pensar. Preguntá de nuevo en unos segundos.');
            }
            $roll = random_int(1, 100);
            $bucket = $roll <= 45 ? 'yes' : ($roll <= 90 ? 'no' : 'maybe');
            $answers = $app->config->array("social.yes_no.{$bucket}", ['Capaz.']);
            $q = (string) preg_replace('/^¿+|\?+$/u', '', $question);
            $app->out->chat(Out::ALL, "{$c->session->nick} pregunta: ¿{$q}? -> {green}" . $answers[array_rand($answers)] . '{default}');
        }, '<pregunta> - Claudia te contesta sí o no', $sec, false, ['8ball']);
    }

    /* ------------------------------------------------------------------ */

    private static function becomePartners(App $app, int $a, int $b): void
    {
        $app->family->startRelationship($a, $b);
        $app->proposals->removeInvolving($a, Proposals::PARTNER);
        $app->proposals->removeInvolving($b, Proposals::PARTNER);
        $app->proposals->removeInvolving($a, Proposals::CUPID);
        $app->proposals->removeInvolving($b, Proposals::CUPID);
        $app->out->chat(Out::ALL, "{green}{$app->nick($a)}{default} y {green}{$app->nick($b)}{default} ahora son pareja. ¡Que dure!");
    }

    private static function wed(App $app, int $proposer, int $accepter): void
    {
        $ring = self::ringItem($app);
        $app->db->transaction(function () use ($app, $proposer, $accepter, $ring): void {
            if ($ring !== null && !$app->shop->take($proposer, $ring)) {
                throw new UserError("{$app->nick($proposer)} ya no tiene el anillo. Sin anillo no hay boda.");
            }
            $app->family->marry($proposer, $accepter);
        });
        $app->out->chat(Out::ALL, "{green}¡{$app->nick($proposer)} y {$app->nick($accepter)} se casaron!{default} Vivan los novios. Ahora pueden ponerle /apellido a la familia y /adoptar.");
        foreach ([$proposer, $accepter] as $uid) {
            $s = $app->sessions->byUser($uid);
            if ($s !== null) {
                $app->out->sound($s->slot, $app->config->string('social.sounds.wedding', 'claudia/ganar'));
            }
        }
    }

    private static function adopt(App $app, int $parent, int $child): void
    {
        $f = $app->family->adopt($parent, $child);
        $spouse = (int) $f['parent_a'] === $parent ? (int) $f['parent_b'] : (int) $f['parent_a'];
        $app->out->chat(Out::ALL, "{green}{$app->nick($parent)}{default} y {green}{$app->nick($spouse)}{default} adoptaron a {green}{$app->nick($child)}{default}" . self::surnameSuffix($f) . '. ¡Bienvenido/a a la familia!');
    }

    private static function ringItem(App $app): ?string
    {
        if (!$app->config->bool('social.marriage.requires_ring', true)) {
            return null;
        }
        $ring = $app->config->string('social.marriage.ring_item', 'anillo');
        return array_key_exists($ring, $app->shop->items()) ? $ring : null;
    }

    /** @param array<string,mixed> $f */
    private static function surnameSuffix(array $f): string
    {
        return ($f['surname'] ?? '') !== '' ? " (familia {$f['surname']})" : '';
    }

    /** @param array{surname:?string, parent_a:int, parent_b:int} $f */
    private static function familyName(App $app, array $f): string
    {
        $parents = $app->nick($f['parent_a']) . ' y ' . $app->nick($f['parent_b']);
        return $f['surname'] !== null && $f['surname'] !== '' ? "Familia {$f['surname']} ({$parents})" : "Familia de {$parents}";
    }

    private static function printTree(App $app, CommandContext $c, int $uid, string $nick): void
    {
        $t = $app->family->tree($uid);
        $names = fn (array $ids) => $ids === [] ? '-' : implode(', ', array_map(fn ($id) => $app->nick((int) $id), $ids));
        $c->console('===== Familia de ' . $nick . ' =====');
        $c->console('Apellido: ' . ($t['surname'] ?? '-'));
        $partner = $t['partner'] === null ? '-' : $app->nick($t['partner']) . ($t['status'] === 'casados' ? ' (casados)' : ' (novios)');
        $c->console('Pareja: ' . $partner);
        $c->console('Padres: ' . $names($t['parents']));
        $c->console('Hijos: ' . $names($t['children']));
        $c->console('Hermanos: ' . $names($t['siblings']));
        $c->console('Abuelos: ' . $names($t['grandparents']));
        $c->console('Tíos: ' . $names($t['uncles']));
        $c->console('Primos: ' . $names($t['cousins']));
        $size = count($t['parents']) + count($t['children']) + count($t['siblings']) + count($t['grandparents']) + count($t['uncles']) + count($t['cousins']);
        $c->reply(($t['surname'] !== null ? "Familia {green}{$t['surname']}{default}: " : "Familia de {green}{$nick}{default}: ")
            . "pareja {$partner}, {$size} parientes. El árbol completo está en la consola.");
    }
}
