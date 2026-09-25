<?php

declare(strict_types=1);

namespace Claudia\Net;

use Claudia\App;
use Claudia\Commands\EconomyCommands;
use Claudia\Log;
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

        $session = function (array $d) use ($app): Session {
            $s = $app->sessions->get((int) ($d['slot'] ?? 0));
            if ($s === null) {
                throw new UserError('Sesión desconocida, reconectate.', 'no_session');
            }
            return $s;
        };

        $link->on('player.join', function (array $d) use ($app): array {
            $slot = (int) $d['slot'];
            $nick = (string) $d['nick'];
            $ip = (string) ($d['ip'] ?? '');
            $s = $app->sessions->join($slot, $nick, $ip, (string) ($d['authid'] ?? ''));
            $s->registered = $app->auth->isRegistered($nick);
            $resumeId = $app->sessions->takeResume($nick, $ip);
            if ($resumeId === null && ($d['was_logged'] ?? false) && $s->registered) {
                // El plugin nos dice que ya estaba logueado (el servicio se reinició).
                $resumeId = (int) $app->users->findByNick($nick)['id'];
            }
            if ($resumeId !== null) {
                $app->auth->resume($s, $resumeId);
            }
            return ['registered' => $s->registered, 'logged' => $s->logged()];
        });

        $link->on('player.leave', function (array $d) use ($app): array {
            $s = $app->sessions->leave((int) $d['slot']);
            if ($s?->userId !== null) {
                $app->games->closeForUser($s->userId);
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

        $link->on('cmd', function (array $d) use ($app, $session): array {
            $app->commands->dispatch($session($d), (string) ($d['name'] ?? ''), (string) ($d['args'] ?? ''), (bool) ($d['staff'] ?? false), (bool) ($d['admin'] ?? false));
            return [];
        });

        $link->on('stats', function (array $d) use ($app): array {
            foreach ((array) ($d['players'] ?? []) as $p) {
                $s = $app->sessions->get((int) ($p['slot'] ?? 0));
                if ($s !== null && $s->userId !== null) {
                    $app->stats->add($s->userId, (array) $p);
                }
            }
            return [];
        });

        $link->on('admin.coins', function (array $d) use ($app): array {
            $msg = EconomyCommands::adminCoins(
                $app,
                (int) ($d['slot'] ?? 0),
                (string) ($d['admin_name'] ?? 'consola'),
                (string) ($d['target'] ?? ''),
                (string) ($d['amount'] ?? ''),
                ($d['mode'] ?? 'give') === 'give'
            );
            return ['message' => $msg];
        });

        $link->on('admin.reload', function (array $d) use ($app): array {
            $app->config->reload();
            $app->out->setTag($app->config->string('service.chat_tag', '{green}[Claudia]{default} '));
            $app->recentGames->setWindow($app->config->int('ai.recent_game_seconds', 20));
            $app->loans->syncBanks();
            Log::info('Configuración recargada');
            return ['message' => 'Configuración de Claudia recargada.'];
        });
    }
}
