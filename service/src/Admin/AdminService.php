<?php

declare(strict_types=1);

namespace Claudia\Admin;

use Claudia\App;
use Claudia\Clock;
use Claudia\Log;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Acciones de administración (las usan el menú de admin y los comandos de consola).
 * Cada una verifica el permiso del rol (config/roles.json) y queda en el log.
 */
final class AdminService
{
    private readonly int $startedAt;

    public function __construct(private readonly App $app)
    {
        $this->startedAt = Clock::now();
    }

    public function announce(int $role, string $actor, string $text): void
    {
        $this->app->perms->check($role, 'announce');
        $text = Text::chatSafe(Text::sanitize($text));
        if ($text === '') {
            throw new UserError('El anuncio está vacío.');
        }
        $this->app->chat->say($text);
        $this->log($actor, 'anuncio', ['texto' => $text]);
    }

    /** @param array<string,mixed> $user */
    public function clearNickname(int $role, string $actor, array $user): string
    {
        $this->app->perms->check($role, 'players.nickname');
        $this->checkTarget($role, $user);
        $this->app->users->setApodo((int) $user['id'], null);
        $this->log($actor, 'borrar apodo', ['usuario' => $user['nick']]);
        $this->app->notify((int) $user['id'], 'Un admin te borró el apodo.');
        return "Le borré el apodo a {$user['nick']}.";
    }

    /** @param array<string,mixed> $user */
    public function clearMemory(int $role, string $actor, array $user): string
    {
        $this->app->perms->check($role, 'players.memory');
        $this->checkTarget($role, $user);
        $n = $this->app->memory->forget((int) $user['id']);
        $this->log($actor, 'borrar memoria', ['usuario' => $user['nick'], 'recuerdos' => $n]);
        return "Claudia se olvidó de {$user['nick']} ({$n} recuerdos borrados).";
    }

    /** @param array<string,mixed> $user */
    public function coins(int $role, string $actor, array $user, int $amount, bool $give): string
    {
        $this->app->perms->check($role, $give ? 'coins.give' : 'coins.take');
        if ($amount <= 0) {
            throw new UserError('Monto inválido.');
        }
        $limit = $this->app->perms->coinLimit($role);
        if ($limit > 0 && $amount > $limit) {
            throw new UserError('Tu tope por operación es de ' . Text::coins($limit) . ' URU Coins.');
        }
        if (!$give) {
            $this->checkTarget($role, $user);
        }
        [$balance, $applied] = $this->app->wallet->adminAdjust((int) $user['id'], $give ? $amount : -$amount, "admin {$actor}");
        $this->log($actor, $give ? 'dar coins' : 'quitar coins', ['usuario' => $user['nick'], 'delta' => $applied]);
        $this->app->notify((int) $user['id'], $give
            ? 'Un admin te dio {green}' . Text::coins($applied) . '{default} URU Coins.'
            : 'Un admin te sacó {green}' . Text::coins(-$applied) . '{default} URU Coins.');
        return ($give ? 'Diste ' : 'Quitaste ') . Text::coins(abs($applied)) . " URU Coins a {$user['nick']}. Saldo: " . Text::coins($balance) . '.';
    }

    /** Silencia o reactiva la IA (hasta que se recargue la configuración). */
    public function toggleAi(int $role, string $actor): bool
    {
        $this->app->perms->check($role, 'ai.toggle');
        $enabled = !$this->app->config->bool('ai.enabled', true);
        $this->app->config->set('ai.enabled', $enabled);
        $this->log($actor, $enabled ? 'activar IA' : 'silenciar IA');
        return $enabled;
    }

    /** @param array<string,mixed> $user @return string la contraseña temporal */
    public function resetPassword(int $role, string $actor, array $user): string
    {
        $this->app->perms->check($role, 'players.password');
        $this->checkTarget($role, $user);
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $temp = '';
        for ($i = 0; $i < 8; $i++) {
            $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $this->app->users->setPassword((int) $user['id'], password_hash($temp, PASSWORD_BCRYPT));
        $this->log($actor, 'contraseña temporal', ['usuario' => $user['nick']]);
        return $temp;
    }

    /** Perdona todas las deudas (sale del Clearing). @param array<string,mixed> $user */
    public function forgiveDebt(int $role, string $actor, array $user): int
    {
        $this->app->perms->check($role, 'loans.forgive');
        $uid = (int) $user['id'];
        if ($this->app->loans->totalDebt($uid) <= 0) {
            throw new UserError("{$user['nick']} no debe nada.");
        }
        $forgiven = $this->app->loans->pay($uid, null, null, 'loan_forgiven', false);
        $this->log($actor, 'perdonar deuda', ['usuario' => $user['nick'], 'monto' => $forgiven]);
        $this->app->notify($uid, 'La administración te perdonó ' . Text::coins($forgiven) . ' de deuda. Arrancás de cero con los bancos.');
        return $forgiven;
    }

    public function forcePromo(int $role, string $actor, string $id): string
    {
        $this->app->perms->check($role, 'promos.force');
        $promos = $this->app->promos->configured();
        if (!isset($promos[$id])) {
            throw new UserError('Esa promoción no existe.');
        }
        $this->app->promos->activate($id, $promos[$id]);
        $this->log($actor, 'forzar promo', ['promo' => $id]);
        return 'Promoción activada: ' . ($promos[$id]['name'] ?? $id) . '.';
    }

    public function reloadConfig(int $role, string $actor): string
    {
        $this->app->perms->check($role, 'config.reload');
        $this->app->reloadConfig();
        $this->log($actor, 'recargar configuración');
        return 'Configuración de Claudia recargada.';
    }

    /** @return list<string> */
    public function status(int $role): array
    {
        $this->app->perms->check($role, 'service.status');
        $db = $this->app->db;
        $online = count($this->app->sessions->all());
        $logged = count($this->app->sessions->logged());
        return [
            'Claudia ' . App::VERSION . ' - funcionando hace ' . Text::duration(Clock::now() - $this->startedAt) . ' - PHP ' . PHP_VERSION,
            "Jugadores: {$online} conectados, {$logged} identificados | IA: " . ($this->app->config->bool('ai.enabled', true) ? 'activa' : 'silenciada'),
            'Usuarios: ' . (int) $db->value('SELECT COUNT(*) FROM users')
                . ' | Coins en circulación: ' . Text::coins((int) $db->value('SELECT COALESCE(SUM(coins), 0) FROM users'))
                . ' | Deuda total: ' . Text::coins((int) $db->value("SELECT COALESCE(SUM(outstanding), 0) FROM loans WHERE status = 'active'")),
            'Grupos: ' . (int) $db->value('SELECT COUNT(*) FROM groups') . ' (fondos: ' . Text::coins((int) $db->value('SELECT COALESCE(SUM(pool), 0) FROM groups')) . ')'
                . ' | Parejas: ' . (int) $db->value('SELECT COUNT(*) FROM relationships WHERE ended_at IS NULL')
                . ' | Familias: ' . (int) $db->value('SELECT COUNT(*) FROM families')
                . ' | Juegos abiertos: ' . $this->app->games->openCount(),
        ];
    }

    /** No se puede actuar sobre alguien conectado de rango igual o mayor. @param array<string,mixed> $user */
    private function checkTarget(int $role, array $user): void
    {
        $this->app->perms->checkTarget($role, $this->app->sessions->byUser((int) $user['id']));
    }

    /** @param array<string,mixed> $ctx */
    private function log(string $actor, string $action, array $ctx = []): void
    {
        Log::info('Admin: ' . $action, ['admin' => $actor] + $ctx);
    }
}
