<?php

declare(strict_types=1);

namespace Claudia\Menus;

use Claudia\App;
use Claudia\Commands\GroupAdminCommands;
use Claudia\Players\Role;
use Claudia\Players\Session;
use Claudia\UserError;
use Claudia\Util\Text;
use Closure;

/**
 * Menú de administración. Cada opción aparece solo si el rol tiene el permiso (config/roles.json);
 * por defecto:
 *   admin: jugadores (perfil, borrar apodo, sacar del grupo), grupos (ver, editar, miembros), anuncios, menú de AMXX
 *   staff: + perfil completo, dar/quitar coins (con tope), borrar memoria de la IA, crear/borrar grupos,
 *          cambiar dueños, silenciar a Claudia
 *   owner: + contraseñas temporales, perdonar deudas, forzar promociones, recargar config, estado del servicio
 * Además, nadie (salvo el owner) puede actuar sobre alguien conectado de su mismo rango o superior.
 */
final class AdminMenus
{
    private ?Closure $home = null;

    public function __construct(private readonly App $app, private readonly MenuKit $k)
    {
    }

    private function can(Session $s, string $perm): bool
    {
        return $this->app->perms->allows($s->role, $perm);
    }

    /** @param Closure|null $home menú al que vuelve "Volver" (el principal) */
    public function main(?Closure $home = null): Closure
    {
        if ($home !== null) {
            $this->home = $home;
        }
        return function (Session $s): Menu {
            $this->app->perms->check($s->role, 'admin.menu');
            $ai = $this->app->config->bool('ai.enabled', true);
            $m = new Menu('Administración', ['Rol: ' . Role::name($s->role) . ' | IA: ' . ($ai ? 'activa' : 'silenciada')]);
            $m->addIf($this->can($s, 'players.profile'), 'Jugadores', fn () => $this->k->pickPlayer('Elegí un jugador', fn (Session $s, array $u) => $this->player((int) $u['id']), $this->main(), null, true));
            $m->addIf($this->can($s, 'groups.view'), 'Grupos', fn () => $this->groups());
            $m->addIf($this->can($s, 'announce'), 'Anuncio de Claudia', $this->k->ask('Texto que Claudia dice a todo el servidor', function (Session $s, string $t) {
                $this->app->admin->announce($s->role, $s->nick, $t);
                return MenuService::STAY;
            }));
            $m->addIf($this->can($s, 'ai.toggle'), $ai ? 'Silenciar a Claudia (IA)' : '\yReactivar a Claudia (IA)', function (Session $s) {
                $on = $this->app->admin->toggleAi($s->role, $s->nick);
                $this->app->out->chat($s->slot, $on ? 'Claudia vuelve a hablar.' : 'Claudia quedó silenciada (hasta que la reactives o se recargue la config).');
                return MenuService::STAY;
            });
            $m->addIf($this->can($s, 'promos.force'), 'Activar una promoción', fn () => $this->promos());
            $m->addIf($this->can($s, 'service.status'), 'Estado del servicio', function (Session $s) {
                $lines = $this->app->admin->status($s->role);
                $this->app->out->chat($s->slot, $lines[0]);
                foreach ($lines as $line) {
                    $this->app->out->console($s->slot, $line);
                }
                $this->app->out->chat($s->slot, 'Detalle en tu consola.');
                return MenuService::STAY;
            });
            $m->addIf($this->can($s, 'config.reload'), 'Recargar configuración', fn () => $this->k->confirm('Recargar', '¿Recargar los JSON de configuración?', function (Session $s) {
                $this->app->out->chat($s->slot, $this->app->admin->reloadConfig($s->role, $s->nick));
                return $this->main();
            }, $this->main()));
            $m->addIf($this->can($s, 'admin.amxmenu'), 'Menú de AMX Mod X', function (Session $s) {
                $this->app->out->clientCommand($s->slot, 'amxmodmenu');
                return null;
            });
            return $m->back($this->home);
        };
    }

    /* ------------------------------------------------------------------
     * Jugadores
     * ---------------------------------------------------------------- */

    public function player(int $uid): Closure
    {
        return function (Session $s) use ($uid): Menu {
            $u = (array) $this->app->users->find($uid);
            $nick = (string) $u['nick'];
            $online = $this->app->sessions->byUser($uid);
            $g = $this->app->groups->ofUser($uid);
            $m = new Menu($nick, [
                ($online !== null ? 'Conectado (' . Role::name($online->role) . ')' : 'Desconectado') . ' | ' . Text::coins((int) $u['coins']) . ' coins'
                    . ($g !== null ? " | [{$g['tag']}]" : ''),
            ]);
            $act = fn (Closure $fn) => fn (Session $s) => $this->say($s, $fn($s));
            $user = fn () => (array) $this->app->users->find($uid);

            $m->addIf($this->can($s, 'players.profile'), 'Ver perfil' . ($this->can($s, 'players.profile_full') ? ' completo' : ''), $this->k->cmd('perfil', $nick));
            $m->addIf($this->can($s, 'coins.give'), 'Dar coins', $this->k->ask("Coins para {$nick}" . $this->limitText($s), fn (Session $s, string $t) => $this->say($s, $this->app->admin->coins($s->role, $s->nick, $user(), $this->amount($t), true))));
            $m->addIf($this->can($s, 'coins.take'), 'Quitar coins', $this->k->ask("Coins a quitarle a {$nick}" . $this->limitText($s), fn (Session $s, string $t) => $this->say($s, $this->app->admin->coins($s->role, $s->nick, $user(), $this->amount($t), false))));
            $m->addIf($this->can($s, 'players.nickname') && ($u['apodo'] ?? '') !== '', "Borrar apodo ({$u['apodo']})", $act(fn (Session $s) => $this->app->admin->clearNickname($s->role, $s->nick, $user())));
            $m->addIf($this->can($s, 'players.memory'), 'Borrar la memoria de Claudia', fn () => $this->k->confirm('Borrar memoria', "¿Que Claudia se olvide de todo lo que sabe de {$nick}?", $act(fn (Session $s) => $this->app->admin->clearMemory($s->role, $s->nick, $user())), $this->player($uid)));
            if ($this->can($s, 'loans.forgive') && $this->app->loans->totalDebt($uid) > 0) {
                $m->add('Perdonar deudas (' . Text::coins($this->app->loans->totalDebt($uid)) . ')', fn () => $this->k->confirm('Perdonar deudas', "¿Perdonarle todas las deudas a {$nick}? Sale del Clearing.", $act(fn (Session $s) => 'Le perdonaste ' . Text::coins($this->app->admin->forgiveDebt($s->role, $s->nick, $user())) . " a {$nick}."), $this->player($uid)));
            }
            $m->addIf($this->can($s, 'players.password'), 'Contraseña temporal', fn () => $this->k->confirm('Contraseña temporal', "¿Generar una contraseña nueva para {$nick}? La vieja deja de andar.", function (Session $s) use ($user, $nick) {
                $temp = $this->app->admin->resetPassword($s->role, $s->nick, $user());
                $this->app->out->chat($s->slot, "Contraseña temporal de {$nick}: {green}{$temp}{default} (también en tu consola). Que la cambie con /cambiarclave.");
                $this->app->out->console($s->slot, "Contraseña temporal de {$nick}: {$temp}");
                return $this->player((int) $user()['id']);
            }, $this->player($uid)));
            if ($g !== null && $this->can($s, 'groups.members') && (int) $g['owner_id'] !== $uid) {
                $m->add("Sacarlo de [{$g['tag']}]", fn () => $this->k->confirm('Sacar del grupo', "¿Sacar a {$nick} de {$g['name']}?", $this->groupAction("expulsar {$g['tag']} {$nick}", $this->player($uid)), $this->player($uid)));
            }
            return $m->back($this->main());
        };
    }

    /* ------------------------------------------------------------------
     * Grupos
     * ---------------------------------------------------------------- */

    public function groups(): Closure
    {
        return function (Session $s): Menu {
            $rows = $this->app->groups->ranking(100);
            $m = new Menu('Grupos', [count($rows) . ' grupos']);
            if ($this->can($s, 'groups.create')) {
                $m->add('\yCrear un grupo', fn () => $this->k->pickPlayer('Dueño del grupo nuevo', function (Session $s, array $owner) {
                    return $this->app->menus->prompt($s, "Tag del grupo de {$owner['nick']} (hasta 6 caracteres)", function (Session $s, string $tag) use ($owner) {
                        $tag = $this->app->groups->validateTag($tag);
                        return $this->app->menus->prompt($s, "Nombre del grupo [{$tag}]", fn (Session $s, string $name) => $this->groupAction("crear {$owner['nick']} {$tag} {$name}", $this->groups())($s));
                    });
                }, $this->groups(), fn (Session $me, Session $o) => $this->app->groups->ofUser((int) $o->userId) === null, true));
            }
            foreach ($rows as $row) {
                $gid = (int) $row['id'];
                $m->add("[{$row['tag']}] {$row['name']} \\d({$row['members']})" . ((int) $row['private'] === 1 ? ' \d- privado' : ''), fn () => $this->group($gid));
            }
            return $m->back($this->main());
        };
    }

    public function group(int $gid): Closure
    {
        return function (Session $s) use ($gid): Menu {
            $g = $this->app->groups->byId($gid);
            if ($g === null) {
                return (new Menu('Grupo', ['Ese grupo ya no existe']))->back($this->groups());
            }
            $tag = (string) $g['tag'];
            $private = (int) $g['private'] === 1;
            $m = new Menu("[{$tag}] {$g['name']}", [
                'Dueño: ' . $this->app->nick((int) $g['owner_id']) . ' | ' . $this->app->groups->memberCount($gid) . ' miembros | Fondo: ' . Text::coins((int) $g['pool']),
            ]);
            $back = $this->group($gid);
            $m->add('Info completa (consola)', function (Session $s) use ($tag) {
                $lines = GroupAdminCommands::run($this->app, $s->nick, "info {$tag}", $s->role);
                foreach ($lines as $line) {
                    $this->app->out->console($s->slot, $line);
                }
                $this->app->out->chat($s->slot, 'Detalle del grupo en tu consola.');
                return MenuService::STAY;
            });
            if ($this->can($s, 'groups.edit')) {
                $m->add('Renombrar', $this->k->ask('Nombre nuevo', fn (Session $s, string $t) => $this->groupAction("renombrar {$tag} {$t}", $back)($s)));
                $m->add('Cambiar tag', $this->k->ask('Tag nuevo', fn (Session $s, string $t) => $this->groupAction("tag {$tag} {$t}", $back)($s)));
                $m->add('Cambiar descripción', $this->k->ask('Descripción nueva', fn (Session $s, string $t) => $this->groupAction("desc {$tag} {$t}", $back)($s)));
                $m->add($private ? 'Hacerlo público' : 'Hacerlo privado', $this->groupAction('privacidad ' . $tag . ' ' . ($private ? 'publico' : 'privado'), $back));
            }
            if ($this->can($s, 'groups.members')) {
                $m->add('Agregar un jugador', fn () => $this->k->pickPlayer('Agregar a...', fn (Session $s, array $u) => $this->groupAction("agregar {$tag} {$u['nick']}", $back)($s), $back, fn (Session $me, Session $o) => $this->app->groups->ofUser((int) $o->userId) === null, true));
                $m->add('Sacar a un miembro', fn () => $this->memberPicker($gid, 'Sacar a...', fn (Session $s, string $nick) => $this->k->confirm('Sacar', "¿Sacar a {$nick} del grupo?", $this->groupAction("expulsar {$tag} {$nick}", $back), $back)));
            }
            if ($this->can($s, 'groups.owner')) {
                $m->add('Cambiar dueño', fn () => $this->memberPicker($gid, 'Nuevo dueño', fn (Session $s, string $nick) => $this->groupAction("dueno {$tag} {$nick}", $back)($s)));
            }
            if ($this->can($s, 'groups.delete')) {
                $m->add('\rEliminar el grupo', fn () => $this->k->confirm('Eliminar grupo', "¿Eliminar [{$tag}] {$g['name']}? El fondo se reparte entre los miembros.", $this->groupAction("borrar {$tag}", $this->groups()), $back));
            }
            return $m->back($this->groups());
        };
    }

    /** Menú con los miembros de un grupo (menos el dueño). */
    private function memberPicker(int $gid, string $title, Closure $onPick): Closure
    {
        return function () use ($gid, $title, $onPick): Menu {
            $g = (array) $this->app->groups->byId($gid);
            $m = new Menu($title);
            foreach ($this->app->groups->members($gid) as $mem) {
                if ((int) $mem['user_id'] !== (int) ($g['owner_id'] ?? 0)) {
                    $nick = (string) $mem['nick'];
                    $m->add($nick, fn (Session $s) => $onPick($s, $nick));
                }
            }
            if ($m->items === []) {
                $m->disabled('No hay otros miembros', 'El grupo no tiene otros miembros.');
            }
            return $m->back($this->group($gid));
        };
    }

    /** Acción que ejecuta una acción de admin de grupos, avisa el resultado y abre $next. */
    private function groupAction(string $args, Closure $next): Closure
    {
        return function (Session $s) use ($args, $next) {
            $lines = GroupAdminCommands::run($this->app, $s->nick, $args, $s->role);
            $this->app->out->chat($s->slot, $lines[0]);
            return $next;
        };
    }

    /* ------------------------------------------------------------------ */

    public function promos(): Closure
    {
        return function (): Menu {
            $m = new Menu('Promociones', ['Se activan ya y se anuncian a todos']);
            foreach ($this->app->promos->configured() as $id => $p) {
                $m->add((string) ($p['name'] ?? $id), fn () => $this->k->confirm('Activar promoción', '¿Activar "' . ($p['name'] ?? $id) . '"? ' . Text::truncateBytes((string) ($p['description'] ?? ''), 80), function (Session $s) use ($id) {
                    $this->app->out->chat($s->slot, $this->app->admin->forcePromo($s->role, $s->nick, (string) $id));
                    return $this->main();
                }, $this->promos()));
            }
            return $m->back($this->main());
        };
    }

    /** Muestra el resultado en el chat y vuelve a mostrar el menú. */
    private function say(Session $s, string $text): string
    {
        $this->app->out->chat($s->slot, $text);
        return MenuService::STAY;
    }

    private function amount(string $raw): int
    {
        $n = Text::parseAmount($raw);
        if ($n === null) {
            throw new UserError('Monto inválido.');
        }
        return $n;
    }

    private function limitText(Session $s): string
    {
        $limit = $this->app->perms->coinLimit($s->role);
        return $limit > 0 ? ' (máx. ' . Text::coins($limit) . ')' : '';
    }
}
