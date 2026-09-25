<?php

declare(strict_types=1);

namespace Claudia\Menus;

use Claudia\App;
use Claudia\Clock;
use Claudia\Players\Role;
use Claudia\Players\Session;
use Claudia\Social\Proposals;
use Claudia\Util\Text;
use Closure;

/**
 * Menús de jugador (/menu). Cada función devuelve el constructor de un menú: fn (Session): Menu.
 *
 *   Menú principal
 *   ├─ Mi perfil                 ├─ Pareja y familia (propuestas pendientes, pareja, casamiento, familia)
 *   ├─ Economía y bancos         ├─ Grupo (según sea miembro, dueño o no tenga)
 *   ├─ Trabajo                   ├─ Tienda
 *   ├─ Casino                    ├─ Más (rankings, recordatorios, apodo, cumpleaños, ayuda)
 *   └─ Administración (admin, staff u owner; ver AdminMenus)
 */
final class PlayerMenus
{
    public function __construct(
        private readonly App $app,
        private readonly MenuKit $k,
        private readonly AdminMenus $admin,
    ) {
    }

    public function main(): Closure
    {
        return function (Session $s): Menu {
            $uid = (int) $s->userId;
            $lines = ['Saldo: ' . Text::coins($this->app->wallet->balance($uid)) . ' URU Coins'];
            if ($s->role > Role::USER) {
                $lines[0] .= ' | Rol: ' . Role::name($s->role);
            }
            $m = new Menu('Menú', $lines);
            $m->add('Economía y bancos', fn () => $this->economy());
            $m->add('Trabajo', fn () => $this->jobs());
            $m->add('Casino', fn () => $this->casino());
            $m->add('Combate' . ($this->app->combat->pendingFor($uid) !== null ? ' \y(duelo)' : ''), fn () => $this->combat());
            $m->add('Pareja y familia' . $this->pendingBadge($uid), fn () => $this->family());
            $m->add('Grupo' . $this->groupBadge($uid), fn () => $this->group());
            $m->add('Tienda', fn () => $this->shop());
            $m->add('Más opciones', fn () => $this->more());
            $m->addIf($this->app->perms->allows($s->role, 'admin.menu'), '\rAdministración', fn () => $this->admin->main($this->main()));
            return $m;
        };
    }

    /** Menú de administración (con "Volver" al principal). */
    public function adminMenu(): Closure
    {
        return $this->admin->main($this->main());
    }

    /* ------------------------------------------------------------------
     * Economía
     * ---------------------------------------------------------------- */

    public function economy(): Closure
    {
        return function (Session $s): Menu {
            $uid = (int) $s->userId;
            $debt = $this->app->loans->totalDebt($uid);
            $lines = ['Saldo: ' . Text::coins($this->app->wallet->balance($uid)) . ($debt > 0 ? ' | Deuda: ' . Text::coins($debt) : '')];
            if ($this->app->loans->inClearing($uid)) {
                $lines[] = 'Estás en el Clearing';
            }
            $m = new Menu('Economía y bancos', $lines);
            $m->add('Ver saldo', $this->k->cmd('saldo'));
            $m->add('Transferir coins', fn () => $this->k->pickPlayer('Transferir a...', function (Session $s, array $u) {
                return $this->app->menus->prompt($s, "Monto para {$u['nick']}", fn (Session $s, string $t) => $this->k->runThen($s, 'transferir', "{$u['nick']} {$t}", $this->economy()));
            }, $this->economy()));
            $m->add('Últimos movimientos', $this->k->cmd('movimientos'));
            $m->add('Bancos y préstamos', fn () => $this->banks());
            if ($debt > 0) {
                $m->add('Pagar deuda', fn () => $this->payDebt());
            } else {
                $m->disabled('Pagar deuda', 'No debés nada.');
            }
            $m->add('Promociones activas', $this->k->cmd('promos'));
            return $m->back($this->main());
        };
    }

    public function banks(): Closure
    {
        return function (Session $s): Menu {
            $m = new Menu('Bancos', ['Elegí un banco para ver sus plazos o pedir un préstamo']);
            foreach ($this->app->loans->banks() as $id => $b) {
                $m->add("{$b['name']} \\d(hasta " . Text::coins($this->app->loans->maxFor((int) $s->userId, (string) $id)) . ')', fn () => $this->bank((string) $id));
            }
            return $m->back($this->economy());
        };
    }

    public function bank(string $id): Closure
    {
        return function (Session $s) use ($id): Menu {
            $b = $this->app->loans->bank($id);
            $max = $this->app->loans->maxFor((int) $s->userId, $id);
            $m = new Menu($b['name'], [Text::truncateBytes((string) $b['description'], 90), 'Te presta hasta ' . Text::coins($max) . ' | Atraso: ' . round($this->app->loans->effectiveLateRate($id, $b) * 100, 1) . '% por día']);
            foreach ((array) $b['terms'] as $term) {
                $days = (int) $term['days'];
                $rate = round($this->app->loans->effectiveRate($id, $term) * 100, 1);
                $label = "Pedir a {$days} días ({$rate}% de interés)";
                if ($max <= 0) {
                    $m->disabled($label, 'Este banco no te presta ahora (mirá los requisitos con /banco ' . $id . ').');
                    continue;
                }
                $m->add($label, $this->k->ask("Monto a pedir al {$b['name']} a {$days} días (máx. " . Text::coins($max) . ')', fn (Session $s, string $t) => $this->k->runThen($s, 'prestamo', "{$id} {$t} {$days}", $this->economy())));
            }
            $m->add('Ver detalle en el chat', $this->k->cmd('banco', $id));
            return $m->back($this->banks());
        };
    }

    public function payDebt(): Closure
    {
        return function (Session $s): Menu {
            $uid = (int) $s->userId;
            $m = new Menu('Pagar deuda', ['Saldo: ' . Text::coins($this->app->wallet->balance($uid))]);
            $now = Clock::now();
            foreach ($this->app->loans->activeLoans($uid) as $l) {
                $bank = (string) $l['bank'];
                $due = (int) $l['due_at'] < $now ? 'vencido' : 'vence en ' . Text::duration((int) $l['due_at'] - $now);
                $m->add($this->app->loans->bankName($bank) . ': ' . Text::coins((int) $l['outstanding']) . " \\d({$due})", $this->k->ask('Monto a pagar (o "todo")', fn (Session $s, string $t) => $this->k->runThen($s, 'pagar', "{$bank} {$t}", $this->economy())));
            }
            $m->add('\yPagar todo lo que pueda', fn (Session $s) => $this->k->runThen($s, 'pagar', 'todo', $this->economy()));
            return $m->back($this->economy());
        };
    }

    /* ------------------------------------------------------------------
     * Trabajo
     * ---------------------------------------------------------------- */

    public function jobs(): Closure
    {
        return function (Session $s): Menu {
            $uid = (int) $s->userId;
            $info = $this->app->jobs->info($uid);
            if ($info === null) {
                $m = new Menu('Trabajo', ['Estás desempleado/a']);
                $m->add('Buscar trabajo', fn () => $this->jobList());
                $m->add('Ver todos los trabajos (consola)', $this->k->cmd('trabajos'));
                return $m->back($this->main());
            }
            $m = new Menu('Trabajo', ["{$info['jobName']} en {$info['employerName']} ({$info['levelName']})", 'Sueldo: ' . Text::coins((int) $info['salary']) . ' por día']);
            $wait = $this->app->jobs->secondsToClaim($uid);
            if ($wait > 0) {
                $m->disabled('Cobrar sueldo (en ' . Text::duration($wait) . ')', 'Todavía no podés cobrar: faltan ' . Text::duration($wait) . '.');
            } else {
                $m->add('\yCobrar sueldo', $this->k->cmd('cobrar'));
            }
            $m->add('Ver mi trabajo', $this->k->cmd('trabajo'));
            $m->add('Renunciar', fn () => $this->k->confirm('Renunciar', "¿Seguro que querés dejar {$info['employerName']}?", fn (Session $s) => $this->k->runThen($s, 'renunciar', '', $this->jobs()), $this->jobs()));
            return $m->back($this->main());
        };
    }

    public function jobList(): Closure
    {
        return function (): Menu {
            $m = new Menu('Buscar trabajo', ['Elegí un trabajo']);
            foreach ($this->app->jobs->jobs() as $id => $job) {
                $levels = (array) $job['levels'];
                $first = reset($levels);
                $m->add("{$job['name']} \\d(desde " . Text::coins((int) $first['salary']) . '/día)', fn () => $this->employers((string) $id));
            }
            return $m->back($this->jobs());
        };
    }

    public function employers(string $jobId): Closure
    {
        return function () use ($jobId): Menu {
            $job = $this->app->jobs->job($jobId);
            $m = new Menu($job['name'], [Text::truncateBytes((string) ($job['description'] ?? ''), 90), '¿Dónde te postulás?']);
            foreach ((array) $job['employers'] as $eid => $e) {
                $m->add("{$e['name']} \\d(sueldo x" . ($e['salary_multiplier'] ?? 1) . ')', fn () => $this->k->confirm(
                    'Postularse',
                    "¿Postularte como {$job['name']} en {$e['name']}?",
                    fn (Session $s) => $this->k->runThen($s, 'postular', "{$jobId} {$eid}", $this->jobs()),
                    $this->employers($jobId)
                ));
            }
            return $m->back($this->jobList());
        };
    }

    /* ------------------------------------------------------------------
     * Casino
     * ---------------------------------------------------------------- */

    public function casino(): Closure
    {
        return function (Session $s): Menu {
            $m = new Menu('Casino', ['Apuestas de ' . Text::coins($this->app->casino->minBet()) . ' a ' . Text::coins($this->app->casino->maxBet())]);
            $m->add('Ruleta francesa', $this->k->cmdClose('ruleta'));
            $m->add('Blackjack', $this->k->cmdClose('blackjack'));
            $m->add('Los 3 Chanchitos del Banco', $this->k->cmdClose('chanchitos'));
            $m->add('Dulce de Leche Bonanza', $this->k->cmdClose('dulce'));
            $m->add('Mate Rush', $this->k->cmdClose('materush'));
            return $m->back($this->main());
        };
    }

    /* ------------------------------------------------------------------
     * Pareja y familia
     * ---------------------------------------------------------------- */

    public function family(): Closure
    {
        return function (Session $s): Menu {
            $uid = (int) $s->userId;
            $fam = $this->app->family;
            $rel = $fam->relationship($uid);
            $lines = [];
            if ($rel !== null) {
                $lines[] = ($rel['status'] === 'casados' ? 'Casado/a con ' : 'De novio/a con ') . $this->app->nick((int) $rel['partner']) . " ({$rel['kisses']} besos)";
            }
            $surname = $fam->surnameOf($uid);
            if ($surname !== null) {
                $lines[] = "Familia {$surname}";
            }
            $m = new Menu('Pareja y familia', $lines);

            // Propuestas pendientes primero.
            $p = $this->app->proposals->pendingFor($uid, [Proposals::PARTNER, Proposals::CUPID]);
            if ($p !== null) {
                $from = $p['kind'] === Proposals::CUPID ? ($p['from'] === $uid ? $p['to'] : $p['from']) : $p['from'];
                $who = $this->app->nick($from);
                $m->add("\\yAceptar ser pareja de {$who}", $this->k->cmd('aceptar', $who));
                $m->add("\\yRechazar a {$who}", $this->k->cmd('rechazar', $who));
            }
            $p = $this->app->proposals->pendingFor($uid, [Proposals::MARRIAGE, Proposals::ADOPTION]);
            if ($p !== null) {
                $who = $this->app->nick($p['from']);
                $what = $p['kind'] === Proposals::MARRIAGE ? "casarme con {$who}" : "que {$who} me adopte";
                $m->add("\\ySí, quiero {$what}", $this->k->cmd('si'));
                $m->add('\yNo, gracias', $this->k->cmd('no'));
            }

            if ($rel === null) {
                $m->add('Pedirle a alguien que sea mi pareja', fn () => $this->k->pickPlayer(
                    'Pedir pareja a...',
                    fn (Session $s, array $u) => $this->k->runThen($s, 'pareja', (string) $u['nick'], $this->family()),
                    $this->family(),
                    fn (Session $me, Session $o) => $fam->relationship((int) $o->userId) === null,
                    false,
                    false
                ));
                $m->add('Que Claudia haga de celestina', $this->k->cmd('formarpareja'));
            } else {
                $partner = $this->app->nick((int) $rel['partner']);
                $m->add("Besar a {$partner}", $this->k->cmd('besar', $partner));
                if ($rel['status'] === 'pareja') {
                    $ring = $this->ringItem();
                    if ($ring !== null && $this->app->shop->count($uid, $ring) <= 0) {
                        $m->disabled('Pedir casamiento (falta anillo)', 'Para pedir casamiento necesitás un anillo: lo comprás en Tienda.');
                    } else {
                        $m->add("Pedirle casamiento a {$partner}", $this->k->cmd('casarse'));
                    }
                }
                $end = $rel['status'] === 'casados' ? 'Divorciarme' : 'Terminar la relación';
                $m->add($end, fn () => $this->k->confirm($end, "¿Seguro? Se termina todo con {$partner}.", fn (Session $s) => $this->k->runThen($s, 'terminar', '', $this->family()), $this->family()));
            }
            $m->add('Besar a alguien', fn () => $this->k->pickPlayer('Besar a...', fn (Session $s, array $u) => $this->k->runThen($s, 'besar', (string) $u['nick'], $this->family()), $this->family(), null, false, false));
            $m->add('Mi familia (árbol)', fn () => $this->familyTree());
            $m->add('Mis ex', $this->k->cmd('ex'));
            $m->add('Preguntale a Claudia (sí o no)', $this->k->ask('Escribí tu pregunta', fn (Session $s, string $t) => $this->k->run($s, 'siono', $t)));
            return $m->back($this->main());
        };
    }

    public function familyTree(): Closure
    {
        return function (Session $s): Menu {
            $uid = (int) $s->userId;
            $fam = $this->app->family;
            $current = $fam->currentFamily($uid);
            $children = $fam->childrenOf($uid);
            $lines = [];
            $parents = $fam->parentsOf($uid);
            if ($parents !== []) {
                $lines[] = 'Padres: ' . implode(' y ', array_map(fn ($id) => $this->app->nick($id), $parents));
            }
            if ($children !== []) {
                $lines[] = 'Hijos: ' . implode(', ', array_map(fn ($id) => $this->app->nick($id), $children));
            }
            $m = new Menu('Familia', $lines);
            $m->add('Ver el árbol completo (consola)', $this->k->cmd('familia'));
            if ($current !== null) {
                $count = count($fam->childrenOfFamily((int) $current['id']));
                $max = $this->app->config->int('social.max_children', 4);
                if ($count >= $max) {
                    $m->disabled("Adoptar ({$count}/{$max})", "Ya tienen {$max} hijos, que es el máximo.");
                } else {
                    $m->add("Adoptar \\d({$count}/{$max})", fn () => $this->k->pickPlayer(
                        'Adoptar a...',
                        fn (Session $s, array $u) => $this->k->runThen($s, 'adoptar', (string) $u['nick'], $this->familyTree()),
                        $this->familyTree(),
                        fn (Session $me, Session $o) => $fam->childFamily((int) $o->userId) === null,
                        false,
                        false
                    ));
                }
                $m->add('Ponerle apellido a la familia', $this->k->ask('Apellido de la familia (o "borrar")', fn (Session $s, string $t) => $this->k->run($s, 'apellido', $t)));
            } else {
                $m->disabled('Adoptar', 'Solo las parejas casadas pueden adoptar.');
            }
            if ($children !== []) {
                $m->add('Desheredar a un hijo', fn () => $this->disownMenu());
            }
            if ($fam->childFamily($uid) !== null) {
                $m->add('Emanciparme', fn () => $this->k->confirm('Emanciparme', '¿Seguro que te vas de tu familia?', fn (Session $s) => $this->k->runThen($s, 'emancipar', '', $this->familyTree()), $this->familyTree()));
            }
            $m->add('Familias del servidor', $this->k->cmd('familias'));
            return $m->back($this->family());
        };
    }

    public function disownMenu(): Closure
    {
        return function (Session $s): Menu {
            $m = new Menu('Desheredar', ['Elegí a quién sacar de la familia']);
            foreach ($this->app->family->childrenOf((int) $s->userId) as $child) {
                $nick = $this->app->nick($child);
                $m->add($nick, fn () => $this->k->confirm('Desheredar', "¿Seguro que querés desheredar a {$nick}?", fn (Session $s) => $this->k->runThen($s, 'desheredar', $nick, $this->familyTree()), $this->disownMenu()));
            }
            return $m->back($this->familyTree());
        };
    }

    /* ------------------------------------------------------------------
     * Grupo
     * ---------------------------------------------------------------- */

    public function group(): Closure
    {
        return function (Session $s): Menu {
            $uid = (int) $s->userId;
            $g = $this->app->groups->ofUser($uid);
            if ($g === null) {
                $m = new Menu('Grupo', ['No estás en ningún grupo']);
                $m->add('Grupos públicos', fn () => $this->publicGroups());
                $m->add('Entrar a un grupo por nombre', $this->k->ask('Nombre o tag del grupo', fn (Session $s, string $t) => $this->k->runThen($s, 'unirse', $t, $this->group())));
                $price = $this->app->groups->price();
                if ($this->app->wallet->balance($uid) < $price) {
                    $m->disabled('Fundar un grupo (' . Text::coins($price) . ')', 'Fundar un grupo cuesta ' . Text::coins($price) . ' URU Coins.');
                } else {
                    $m->add('Fundar un grupo (' . Text::coins($price) . ')', $this->k->cmdClose('creargrupo'));
                }
                $m->add('Ranking de grupos', $this->k->cmd('topgrupos'));
                return $m->back($this->main());
            }
            $gid = (int) $g['id'];
            $owner = (int) $g['owner_id'] === $uid;
            $m = new Menu("[{$g['tag']}] {$g['name']}", [
                $this->app->groups->memberCount($gid) . '/' . $this->app->groups->maxMembers() . ' miembros | ' . $g['kills'] . ' kills (#' . $this->app->groups->position($gid) . ') | Fondo: ' . Text::coins((int) $g['pool']),
            ]);
            if ($owner) {
                $req = count($this->app->groups->requests($gid));
                $m->add('\yAdministrar el grupo' . ($req > 0 ? " \\r({$req} solicitudes)" : ''), fn () => $this->ownGroup());
            }
            $m->add('Mensaje al grupo', $this->k->ask('Mensaje para tu grupo', fn (Session $s, string $t) => $this->k->run($s, 'g', $t)));
            $m->add('Miembros', $this->k->cmd('miembros'));
            $m->add('Donar al fondo', $this->k->ask('Monto a donar al fondo', fn (Session $s, string $t) => $this->k->run($s, 'donar', $t)));
            $m->add('Movimientos del fondo', $this->k->cmd('fondo'));
            $m->add('Info del grupo', $this->k->cmd('grupo'));
            $m->add('Ranking de grupos', $this->k->cmd('topgrupos'));
            if (!$owner) {
                $m->add('Salir del grupo', fn () => $this->k->confirm('Salir del grupo', "¿Seguro que te vas de {$g['name']}?", fn (Session $s) => $this->k->runThen($s, 'salirg', '', $this->group()), $this->group()));
            }
            return $m->back($this->main());
        };
    }

    public function publicGroups(): Closure
    {
        return function (): Menu {
            $m = new Menu('Grupos públicos');
            foreach ($this->app->groups->listPublic() as $row) {
                $name = (string) $row['name'];
                $m->add("[{$row['tag']}] {$name} \\d({$row['members']}/" . $this->app->groups->maxMembers() . ')', fn () => $this->groupPreview($name));
            }
            if ($m->items === []) {
                $m->disabled('No hay grupos públicos', 'Todavía no hay grupos públicos. ¡Fundá el primero!');
            }
            return $m->back($this->group());
        };
    }

    public function groupPreview(string $name): Closure
    {
        return function () use ($name): Menu {
            $g = $this->app->groups->find($name);
            $m = new Menu("[{$g['tag']}] {$g['name']}", [Text::truncateBytes((string) $g['description'], 90), 'Dueño: ' . $this->app->nick((int) $g['owner_id']) . " | {$g['kills']} kills"]);
            $m->add('\yEntrar', fn (Session $s) => $this->k->runThen($s, 'unirse', $name, $this->group()));
            $m->add('Ver miembros', $this->k->cmd('miembros', $name));
            return $m->back($this->publicGroups());
        };
    }

    public function ownGroup(): Closure
    {
        return function (Session $s): Menu {
            $g = $this->app->groups->owned((int) $s->userId);
            $gid = (int) $g['id'];
            $private = (int) $g['private'] === 1;
            $members = fn (string $title, Closure $onPick) => function (Session $s) use ($gid, $title, $onPick): Menu {
                $m = new Menu($title);
                foreach ($this->app->groups->members($gid) as $mem) {
                    if ((int) $mem['user_id'] !== (int) $s->userId) {
                        $nick = (string) $mem['nick'];
                        $m->add($nick, fn (Session $s) => $onPick($s, $nick));
                    }
                }
                if ($m->items === []) {
                    $m->disabled('No hay otros miembros', 'Todavía no hay otros miembros en el grupo.');
                }
                return $m->back($this->ownGroup());
            };
            $req = $this->app->groups->requests($gid);
            $m = new Menu('Administrar ' . $g['name'], ['Fondo: ' . Text::coins((int) $g['pool']) . ' | ' . ($private ? 'Privado' : 'Público')]);
            if ($req !== []) {
                $m->add('\ySolicitudes (' . count($req) . ')', fn () => $this->requestsMenu());
            } else {
                $m->disabled('Solicitudes (0)', 'No hay solicitudes pendientes.');
            }
            $m->add('Pagarle a un miembro desde el fondo', fn () => $members('Pagar desde el fondo a...', fn (Session $s, string $nick) => $this->app->menus->prompt($s, "Monto del fondo para {$nick}", fn (Session $s, string $t) => $this->k->runThen($s, 'fondo', "dar {$nick} {$t}", $this->ownGroup()))));
            $m->add('Expulsar a un miembro', fn () => $members('Expulsar a...', fn (Session $s, string $nick) => $this->k->confirm('Expulsar', "¿Echar a {$nick} del grupo?", fn (Session $s) => $this->k->runThen($s, 'expulsarg', $nick, $this->ownGroup()), $this->ownGroup())));
            $m->add('Pasarle el grupo a otro', fn () => $members('Nuevo dueño...', fn (Session $s, string $nick) => $this->k->confirm('Traspasar', "¿Pasarle el grupo a {$nick}? Dejás de ser el dueño.", fn (Session $s) => $this->k->runThen($s, 'traspasarg', $nick, $this->group()), $this->ownGroup())));
            $m->add('Cambiar la descripción', $this->k->ask('Nueva descripción del grupo', fn (Session $s, string $t) => $this->k->run($s, 'editarg', "descripcion {$t}")));
            $m->add($private ? 'Hacerlo público' : 'Hacerlo privado', fn (Session $s) => $this->k->run($s, 'editarg', 'privacidad ' . ($private ? 'publico' : 'privado')));
            $m->add('\rDisolver el grupo', fn () => $this->k->confirm('Disolver', "¿Disolver {$g['name']}? El fondo se reparte entre los miembros.", function (Session $s) {
                $this->app->cooldowns->take('disolver:' . $s->userId, $this->app->config->int('groups.dissolve_confirm_seconds', 30));
                return $this->k->runThen($s, 'disolver', 'si', $this->group());
            }, $this->ownGroup()));
            return $m->back($this->group());
        };
    }

    public function requestsMenu(): Closure
    {
        return function (Session $s): Menu {
            $g = $this->app->groups->owned((int) $s->userId);
            $m = new Menu('Solicitudes', ['Elegí a quién aceptar o rechazar']);
            foreach ($this->app->groups->requests((int) $g['id']) as $r) {
                $nick = (string) $r['nick'];
                $m->add($nick, fn () => function () use ($nick): Menu {
                    return (new Menu("Solicitud de {$nick}"))
                        ->add('\yAceptar', fn (Session $s) => $this->k->runThen($s, 'aceptarg', $nick, $this->requestsMenu()))
                        ->add('Rechazar', fn (Session $s) => $this->k->runThen($s, 'rechazarg', $nick, $this->requestsMenu()))
                        ->back($this->requestsMenu());
                });
            }
            if ($m->items === []) {
                $m->disabled('No hay solicitudes', 'No hay solicitudes pendientes.');
            }
            return $m->back($this->ownGroup());
        };
    }

    /* ------------------------------------------------------------------
     * Tienda y más
     * ---------------------------------------------------------------- */

    public function shop(): Closure
    {
        return function (Session $s): Menu {
            $uid = (int) $s->userId;
            $m = new Menu('Tienda', ['Saldo: ' . Text::coins($this->app->wallet->balance($uid))]);
            foreach ($this->app->shop->items() as $key => $item) {
                $label = "{$item['name']} \\d(" . Text::coins($item['price']) . ')';
                if ($item['type'] === 'grupo') {
                    $m->add($label, $this->k->cmdClose('comprar', (string) $key));
                    continue;
                }
                $have = $this->app->shop->count($uid, (string) $key);
                if ($item['max'] > 0 && $have >= $item['max']) {
                    $m->disabled("{$item['name']} (tenés {$have})", "Ya tenés {$have}, no podés tener más.");
                    continue;
                }
                $m->add($label, fn () => $this->k->confirm('Comprar', "¿Comprar {$item['name']} por " . Text::coins($item['price']) . '? ' . $item['description'], fn (Session $s) => $this->k->runThen($s, 'comprar', (string) $key, $this->shop()), $this->shop()));
            }
            $m->add('Mi inventario', $this->k->cmd('inventario'));
            return $m->back($this->main());
        };
    }

    /* ------------------------------------------------------------------
     * Combate: duelos, racha, MVP, arma bonus y recompensas
     * ---------------------------------------------------------------- */

    public function combat(): Closure
    {
        return function (Session $s): Menu {
            $uid = (int) $s->userId;
            $cb = $this->app->combat;
            $streak = $cb->streak($uid);
            $m = new Menu('Combate', [
                "Racha: {$streak['kills']} kills | Pozo: " . Text::coins($streak['pot']),
                'Arma bonus: ' . ($cb->weapon() ?? '(al empezar la ronda)'),
            ]);
            $pending = $cb->pendingFor($uid);
            if ($pending !== null) {
                $who = $this->app->nick($pending['a']);
                $m->add("\\yAceptar duelo de {$who} (" . Text::coins($pending['amount']) . ')', $this->k->cmdClose('aceptar_duelo'));
                $m->add("\\yRechazar duelo de {$who}", $this->k->cmd('rechazar_duelo'));
            }
            if ($cb->activeDuelOf($uid) !== null) {
                $m->disabled('Retar a un duelo', 'Ya estás en un duelo: el que mate al otro gana.');
            } else {
                $m->add('Retar a un duelo', fn () => $this->k->pickPlayer('Retar a...', function (Session $s, array $u) {
                    return $this->app->menus->prompt($s, "Apuesta contra {$u['nick']} (1 a 30.000)", fn (Session $s, string $t) => $this->k->run($s, 'duelo', "{$u['nick']} {$t}"));
                }, $this->combat(), null, false, false));
            }
            $m->add('Mi racha', $this->k->cmd('racha'));
            $m->add('Mi racha de MVP', $this->k->cmd('mvp'));
            $m->add('Recompensa activa', $this->k->cmd('bounty'));
            $m->add('Poner una recompensa', fn () => $this->k->pickPlayer('Recompensa por...', function (Session $s, array $u) {
                return $this->app->menus->prompt($s, "Recompensa por {$u['nick']} (mínimo 100)", fn (Session $s, string $t) => $this->k->runThen($s, 'bounty', "{$u['nick']} {$t}", $this->combat()));
            }, $this->combat()));
            return $m->back($this->main());
        };
    }

    public function more(): Closure
    {
        return function (): Menu {
            $m = new Menu('Más opciones');
            $m->add('Mi perfil', $this->k->cmd('perfil'));
            $m->add('Rankings', fn () => $this->rankings());
            $m->add('Mis recordatorios', $this->k->cmd('recordatorios'));
            $m->add('Nuevo recordatorio', $this->k->ask('Cuándo y qué (ej: 2h sacar la basura, 25/12 20:00 cena)', fn (Session $s, string $t) => $this->k->run($s, 'recordar', $t)));
            $m->add('Borrar un recordatorio', $this->k->ask('Número del recordatorio (mirá "Mis recordatorios")', fn (Session $s, string $t) => $this->k->run($s, 'borrarrecordatorio', $t)));
            $m->add('Mi cumpleaños', $this->k->ask('Tu cumpleaños (DD/MM)', fn (Session $s, string $t) => $this->k->run($s, 'cumple', $t)));
            $m->add('Mi apodo', $this->k->ask('Cómo querés que te diga Claudia (o "borrar")', fn (Session $s, string $t) => $this->k->run($s, 'apodo', $t)));
            $m->add('Lista de comandos (consola)', $this->k->cmd('ayuda'));
            return $m->back($this->main());
        };
    }

    public function rankings(): Closure
    {
        return function (): Menu {
            return (new Menu('Rankings'))
                ->add('Los más ricos', $this->k->cmd('top', 'economia'))
                ->add('Los mejores jugadores', $this->k->cmd('top', 'juego'))
                ->add('Grupos', $this->k->cmd('topgrupos'))
                ->add('Familias', $this->k->cmd('familias'))
                ->back($this->more());
        };
    }

    /* ------------------------------------------------------------------ */

    private function pendingBadge(int $uid): string
    {
        $p = $this->app->proposals->pendingFor($uid, [Proposals::PARTNER, Proposals::CUPID, Proposals::MARRIAGE, Proposals::ADOPTION]);
        return $p !== null ? ' \r(propuesta)' : '';
    }

    private function groupBadge(int $uid): string
    {
        $g = $this->app->groups->ofUser($uid);
        if ($g === null) {
            return '';
        }
        $req = (int) $g['owner_id'] === $uid ? count($this->app->groups->requests((int) $g['id'])) : 0;
        return " \\d[{$g['tag']}]" . ($req > 0 ? " \\r({$req})" : '');
    }

    private function ringItem(): ?string
    {
        if (!$this->app->config->bool('social.marriage.requires_ring', true)) {
            return null;
        }
        $ring = $this->app->config->string('social.marriage.ring_item', 'anillo');
        return array_key_exists($ring, $this->app->shop->items()) ? $ring : null;
    }
}
