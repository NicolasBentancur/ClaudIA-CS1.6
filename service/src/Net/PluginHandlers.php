<?php

declare(strict_types=1);

namespace Claudia\Net;

use Claudia\App;
use Claudia\Commands\EconomyCommands;
use Claudia\Commands\GroupAdminCommands;
use Claudia\Players\Role;
use Claudia\Players\Session;
use Claudia\UserError;

/**
 * Mensajes que manda el plugin y cómo se atienden.
 */
final class PluginHandlers
{
    public static function register(App $app, PluginLink $link): void
    {
        $link->setHelloData(fn () => [
            'version' => App::VERSION,
            'commands' => $app->commands->names(),
        ]);

        $link->onConnected(function (array $hello) use ($app): void {
            $app->sessions->leaveAll();
            $app->games->setFallbackHost((string) ($hello['ip'] ?? ''));
        });
        $link->onDisconnected(function () use ($app): void {
            $app->sessions->leaveAll();
        });

        // Rol que calcula el plugin con las flags de AMXX (los plugins viejos mandan staff/admin).
        $roleOf = fn (array $d): int => Role::clamp(isset($d['role'])
            ? (int) $d['role']
            : ((bool) ($d['staff'] ?? false) ? Role::STAFF : ((bool) ($d['admin'] ?? false) ? Role::ADMIN : Role::USER)));

        $session = function (array $d) use ($app, $roleOf): Session {
            $s = $app->sessions->get((int) ($d['slot'] ?? 0));
            if ($s === null) {
                throw new UserError('Sesión desconocida, reconectate.', 'no_session');
            }
            if (isset($d['role']) || isset($d['staff']) || isset($d['admin'])) {
                $s->role = $roleOf($d);
            }
            return $s;
        };

        $link->on('player.join', function (array $d) use ($app, $roleOf): array {
            $slot = (int) $d['slot'];
            $nick = (string) $d['nick'];
            $ip = (string) ($d['ip'] ?? '');
            $conn = (int) ($d['userid'] ?? 0);
            $s = $app->sessions->join($slot, $nick, $ip, (string) ($d['authid'] ?? ''), $conn);
            $s->role = $roleOf($d);
            $s->registered = $app->auth->isRegistered($nick);
            $resumeId = $app->sessions->takeResume($nick, $ip, $conn);
            if ($resumeId !== null) {
                $app->auth->resume($s, $resumeId);
            } elseif (($d['was_logged'] ?? false) && $s->registered) {
                // El plugin nos dice que ya estaba logueado (el servicio se reinició).
                $app->auth->resumeAfterRestart($s);
            }
            return ['registered' => $s->registered, 'logged' => $s->logged()];
        });

        $link->on('player.leave', function (array $d) use ($app): array {
            $app->flows->drop((int) $d['slot']);
            $app->menus->drop((int) $d['slot']);
            $s = $app->sessions->leave((int) $d['slot']);
            if ($s?->userId !== null) {
                $app->games->closeForUser($s->userId);
                $app->combat->onLeave($s->userId);
                $app->chess->onLeave($s->userId);
            }
            return [];
        });

        $link->on('player.rename', function (array $d) use ($app, $session): array {
            $s = $session($d);
            $app->auth->logout($s);
            $s->nick = (string) $d['nick'];
            $s->registered = $app->auth->isRegistered($s->nick);
            return ['registered' => $s->registered, 'logged' => false];
        });

        $link->on('player.alive', function (array $d) use ($session): array {
            $session($d)->alive = (bool) ($d['alive'] ?? false);
            return [];
        });

        $link->on('auth.register', function (array $d) use ($app, $session): array {
            $s = $session($d);
            $app->auth->register($s, (string) ($d['password'] ?? ''));
            return ['registered' => true, 'logged' => true];
        });

        $link->on('auth.login', function (array $d) use ($app, $session): array {
            $s = $session($d);
            $app->auth->login($s, (string) ($d['password'] ?? ''));
            return ['registered' => true, 'logged' => true];
        });

        $link->on('auth.change', function (array $d) use ($app, $session): array {
            $app->auth->changePassword($session($d), (string) ($d['old'] ?? ''), (string) ($d['new'] ?? ''));
            return [];
        });

        $link->on('chat', function (array $d) use ($app, $session): array {
            $app->chat->onMessage($session($d), (string) ($d['text'] ?? ''), (bool) ($d['dead'] ?? false));
            return [];
        });

        // Respuesta a un formulario por chat (el plugin la captura sin mostrarla en el chat).
        $link->on('input', function (array $d) use ($app, $session): array {
            $app->flows->input($session($d), (string) ($d['text'] ?? ''));
            return [];
        });

        // Menús de HUD: opción elegida, texto pedido con messagemode y menú cerrado.
        $link->on('menu.select', function (array $d) use ($app, $session): array {
            $app->menus->select($session($d), (int) ($d['menu'] ?? 0), (int) ($d['item'] ?? -1), (int) ($d['page'] ?? 0));
            return [];
        });
        $link->on('menu.input', function (array $d) use ($app, $session): array {
            $app->menus->input($session($d), (string) ($d['text'] ?? ''));
            return [];
        });
        $link->on('menu.close', function (array $d) use ($app, $session): array {
            $app->menus->close($session($d));
            return [];
        });

        $link->on('cmd', function (array $d) use ($app, $session): array {
            $app->commands->dispatch($session($d), (string) ($d['name'] ?? ''), (string) ($d['args'] ?? ''));
            return [];
        });

        $link->on('stats', function (array $d) use ($app): array {
            foreach ((array) ($d['players'] ?? []) as $p) {
                $s = $app->sessions->get((int) ($p['slot'] ?? 0));
                if ($s !== null && $s->userId !== null) {
                    $app->stats->add($s->userId, (array) $p);
                    $app->groups->onActivity($s->userId, max(0, (int) ($p['kills'] ?? 0)), max(0, (int) ($p['rounds'] ?? 0)));
                }
            }
            return [];
        });

        // Modo de juego: cada muerte en el momento (DeathMsg) y el inicio/fin de ronda.
        $link->on('game.kill', function (array $d) use ($app): array {
            $killerSlot = (int) ($d['killer'] ?? 0);
            $victimSlot = (int) ($d['victim'] ?? 0);
            $uid = fn (int $slot): ?int => $slot > 0 ? $app->sessions->get($slot)?->userId : null;
            $app->combat->onKill($uid($killerSlot), $uid($victimSlot), (string) ($d['weapon'] ?? ''),
                $killerSlot === 0 || $killerSlot === $victimSlot, (bool) ($d['teamkill'] ?? false));
            return [];
        });
        $link->on('round.start', function (array $d) use ($app): array {
            $app->combat->roundStart();
            return [];
        });
        $link->on('radio.done', function (array $d) use ($app): array {
            $app->radio->done((int) ($d['id'] ?? 0));
            return [];
        });
        $link->on('round.end', function (array $d) use ($app): array {
            $app->combat->roundEnd();
            return [];
        });

        $link->on('admin.coins', function (array $d) use ($app, $roleOf): array {
            $msg = EconomyCommands::adminCoins(
                $app,
                (int) ($d['slot'] ?? 0),
                (string) ($d['admin_name'] ?? 'consola'),
                (string) ($d['target'] ?? ''),
                (string) ($d['amount'] ?? ''),
                ($d['mode'] ?? 'give') === 'give',
                isset($d['role']) ? $roleOf($d) : Role::OWNER
            );
            return ['message' => $msg];
        });

        // amx_grupo / amx_crearg / amx_borrarg (el plugin ya verificó las flags de admin).
        $link->on('admin.group', function (array $d) use ($app, $roleOf): array {
            $lines = GroupAdminCommands::run($app, (string) ($d['admin_name'] ?? 'consola'), (string) ($d['args'] ?? ''), isset($d['role']) ? $roleOf($d) : Role::OWNER);
            return ['message' => $lines[0], 'lines' => array_slice($lines, 1)];
        });

        $link->on('admin.reload', function (array $d) use ($app, $roleOf): array {
            return ['message' => $app->admin->reloadConfig(isset($d['role']) ? $roleOf($d) : Role::OWNER, (string) ($d['admin_name'] ?? 'consola'))];
        });
    }
}
