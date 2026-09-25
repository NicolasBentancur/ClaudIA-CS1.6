<?php

declare(strict_types=1);

namespace Claudia\Commands;

use Claudia\App;
use Claudia\Log;
use Claudia\Net\Out;
use Claudia\Players\Role;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Administración de grupos (cada acción pide su permiso de config/roles.json):
 *   chat:    /admingrupo <acción> ...   (alias /ag)
 *   consola: amx_grupo <acción> ...     (y los atajos amx_crearg / amx_borrarg)
 *
 * Los admins crean grupos gratis (no se cobra ni se crean coins) y al borrar uno el fondo
 * se reparte entre los miembros, igual que cuando lo disuelve el dueño.
 */
final class GroupAdminCommands
{
    /** @var list<string> */
    public const USAGE = [
        'crear <dueño> <tag> <nombre> - crea un grupo gratis con ese dueño',
        'borrar <grupo> - elimina el grupo (el fondo se reparte entre los miembros)',
        'info <grupo> - datos completos del grupo',
        'lista - todos los grupos, incluidos los privados',
        'renombrar <tag> <nombre nuevo>',
        'tag <tag> <tag nuevo>',
        'desc <tag> <descripción>',
        'privacidad <tag> publico|privado',
        'dueno <tag> <nick> - cambia el dueño (si no es miembro, lo mete)',
        'agregar <tag> <nick> - mete a un jugador sin pedir permiso',
        'expulsar <tag> <nick> - saca a un miembro',
    ];

    public static function register(App $app): void
    {
        $app->commands->register('admingrupo', function (CommandContext $c) use ($app): void {
            if (!$app->perms->allows($c->role, 'groups.view')) {
                throw new UserError('No tenés permiso para administrar grupos.');
            }
            $lines = self::run($app, $c->session->nick, $c->args, $c->role);
            $c->reply(array_shift($lines));
            foreach ($lines as $line) {
                $c->console($line);
            }
            if ($lines !== []) {
                $c->reply('El detalle está en tu consola.');
            }
        }, '<acción> ... - administrar grupos (solo admins; /admingrupo ayuda)', 'Admin', false, ['ag', 'admingrupos']);
    }

    /**
     * Ejecuta una acción de admin. La primera línea es el resumen (va al chat o a la consola);
     * las demás son detalle.
     * @return non-empty-list<string>
     */
    public static function run(App $app, string $admin, string $args, int $role = Role::OWNER): array
    {
        $argv = preg_split('/\s+/u', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $action = Text::fold((string) array_shift($argv));
        $g = $app->groups;
        $rest = fn (int $from = 0) => implode(' ', array_slice($argv, $from));
        $need = function (int $n) use ($argv, $action): void {
            if (count($argv) < $n) {
                throw new UserError("Faltan datos. Uso: {$action} " . self::usageFor($action));
            }
        };
        $perm = [
            'crear' => 'groups.create', 'create' => 'groups.create',
            'borrar' => 'groups.delete', 'eliminar' => 'groups.delete', 'delete' => 'groups.delete',
            'info' => 'groups.view', 'lista' => 'groups.view', 'list' => 'groups.view',
            'renombrar' => 'groups.edit', 'nombre' => 'groups.edit', 'tag' => 'groups.edit', 'desc' => 'groups.edit', 'descripcion' => 'groups.edit',
            'privacidad' => 'groups.edit', 'privado' => 'groups.edit', 'publico' => 'groups.edit',
            'dueno' => 'groups.owner', 'owner' => 'groups.owner',
            'agregar' => 'groups.members', 'meter' => 'groups.members', 'add' => 'groups.members',
            'expulsar' => 'groups.members', 'sacar' => 'groups.members', 'kick' => 'groups.members',
        ][$action] ?? null;
        if ($perm !== null) {
            $app->perms->check($role, $perm);
        }
        $log = fn (array $ctx = []) => Log::info('Admin grupo', ['admin' => $admin, 'accion' => $action, 'args' => $args] + $ctx);

        switch ($action) {
            case 'crear':
            case 'create':
                $need(3);
                $owner = $app->findUser($argv[0]);
                $name = $g->validateName($rest(2));
                $tag = $g->validateTag($argv[1]);
                $group = $g->create((int) $owner['id'], $name, $tag, 'Grupo creado por la administración', false, false);
                $log(['grupo' => $group['id']]);
                $app->out->chat(Out::ALL, "Se fundó el grupo {green}[{$group['tag']}] {$group['name']}{default} (dueño: {$owner['nick']}). /unirse {$group['name']}");
                $app->notify((int) $owner['id'], "Un admin te hizo dueño/a de {green}[{$group['tag']}] {$group['name']}{default}. Cambiá la descripción con /editarg.");
                return ["Grupo [{$group['tag']}] {$group['name']} creado, dueño {$owner['nick']}."];

            case 'borrar':
            case 'eliminar':
            case 'delete':
                $need(1);
                $group = $g->find($rest());
                $res = $g->dissolveGroup($group);
                $log(['grupo' => $group['id'], 'miembros' => count($res['members']), 'reparto' => $res['share']]);
                foreach ($res['members'] as $m) {
                    $app->notify($m, "La administración eliminó el grupo {$group['name']}." . ($res['share'] > 0 ? ' Te tocaron ' . Text::coins($res['share']) . ' del fondo.' : ''));
                }
                return ["Grupo [{$group['tag']}] {$group['name']} eliminado. " . count($res['members']) . ' miembros'
                    . ($res['share'] > 0 ? ', ' . Text::coins($res['share']) . ' del fondo a cada uno.' : '.')];

            case 'info':
                $need(1);
                $group = $g->find($rest());
                $gid = (int) $group['id'];
                $lines = [
                    "[{$group['tag']}] {$group['name']} (id {$gid}) - dueño " . $app->nick((int) $group['owner_id']) . ' - ' . $g->memberCount($gid) . '/' . $g->maxMembers()
                        . ' miembros - fondo ' . Text::coins((int) $group['pool']) . ' - ' . $group['kills'] . ' kills - ' . ((int) $group['private'] === 1 ? 'privado' : 'público'),
                    "Descripción: {$group['description']} | Creado: " . date('d/m/Y H:i', (int) $group['created_at']) . " | Rondas del dueño: {$group['owner_rounds']}",
                ];
                foreach ($g->members($gid) as $m) {
                    $lines[] = "  {$m['nick']} - {$m['kills']} kills - {$m['rounds']} rondas - cuotas " . Text::coins((int) $m['fees_paid']) . ' - donó ' . Text::coins((int) $m['donated']);
                }
                $req = $g->requests($gid);
                if ($req !== []) {
                    $lines[] = 'Solicitudes: ' . implode(', ', array_column($req, 'nick'));
                }
                foreach ($g->ledger($gid, 10) as $l) {
                    $lines[] = '  ' . date('d/m H:i', (int) $l['created_at']) . " {$l['kind']} " . ($l['amount'] > 0 ? '+' : '') . Text::coins((int) $l['amount']) . ($l['nick'] !== null ? " ({$l['nick']})" : '') . ' => ' . Text::coins((int) $l['pool_after']);
                }
                return $lines;

            case 'lista':
            case 'list':
                $rows = $g->ranking(100);
                if ($rows === []) {
                    return ['No hay grupos.'];
                }
                $lines = [count($rows) . ' grupos.'];
                foreach ($rows as $row) {
                    $lines[] = "[{$row['tag']}] {$row['name']} - {$row['members']} miembros - dueño " . $app->nick((int) $row['owner_id']) . ' - fondo ' . Text::coins((int) $row['pool']) . ((int) $row['private'] === 1 ? ' - privado' : '');
                }
                return $lines;

            case 'renombrar':
            case 'nombre':
                $need(2);
                $group = $g->find($argv[0]);
                $name = $g->rename($group, $rest(1));
                $log(['grupo' => $group['id']]);
                self::toMembers($app, (int) $group['id'], "La administración le cambió el nombre al grupo: ahora es {green}{$name}{default}.");
                return ["[{$group['tag']}] {$group['name']} ahora se llama {$name}."];

            case 'tag':
                $need(2);
                $group = $g->find($argv[0]);
                $tag = $g->retag($group, $argv[1]);
                $log(['grupo' => $group['id']]);
                self::toMembers($app, (int) $group['id'], "La administración le cambió el tag al grupo: ahora es {green}[{$tag}]{default}.");
                return ["{$group['name']}: tag [{$group['tag']}] -> [{$tag}]."];

            case 'desc':
            case 'descripcion':
                $need(2);
                $group = $g->find($argv[0]);
                $d = $g->updateDescription($group, $rest(1));
                $log(['grupo' => $group['id']]);
                return ["Descripción de {$group['name']}: {$d}"];

            case 'privacidad':
            case 'privado':
            case 'publico':
                $value = $action === 'privacidad' ? Text::fold((string) ($argv[1] ?? '')) : $action;
                $need(1);
                if (!str_starts_with($value, 'pub') && !str_starts_with($value, 'priv')) {
                    throw new UserError('Uso: privacidad <tag> publico|privado');
                }
                $group = $g->find($argv[0]);
                $g->updatePrivate($group, str_starts_with($value, 'priv'));
                $log(['grupo' => $group['id']]);
                return ["{$group['name']} ahora es " . (str_starts_with($value, 'priv') ? 'privado.' : 'público.')];

            case 'dueno':
            case 'owner':
                $need(2);
                $group = $g->find($argv[0]);
                $user = $app->findUser($rest(1));
                $old = (int) $group['owner_id'];
                $g->setOwner($group, (int) $user['id']);
                $log(['grupo' => $group['id'], 'dueno' => $user['id']]);
                $app->notify((int) $user['id'], "La administración te hizo dueño/a de {green}[{$group['tag']}] {$group['name']}{default}.");
                $app->notify($old, "La administración le pasó {$group['name']} a {$user['nick']}. Seguís siendo miembro.");
                return ["{$user['nick']} es el nuevo dueño de [{$group['tag']}] {$group['name']}."];

            case 'agregar':
            case 'meter':
            case 'add':
                $need(2);
                $group = $g->find($argv[0]);
                $user = $app->findUser($rest(1));
                $g->addMember($group, (int) $user['id']);
                $log(['grupo' => $group['id'], 'usuario' => $user['id']]);
                $app->notify((int) $user['id'], "La administración te agregó a {green}[{$group['tag']}] {$group['name']}{default}.");
                return ["{$user['nick']} ahora está en [{$group['tag']}] {$group['name']}."];

            case 'expulsar':
            case 'sacar':
            case 'kick':
                $need(2);
                $group = $g->find($argv[0]);
                $user = $app->findUser($rest(1));
                $g->removeMember($group, (int) $user['id']);
                $log(['grupo' => $group['id'], 'usuario' => $user['id']]);
                $app->notify((int) $user['id'], "La administración te sacó de {$group['name']}.");
                return ["{$user['nick']} ya no está en [{$group['tag']}] {$group['name']}."];

            default:
                return array_merge(['Acciones de admin para grupos (el <tag> identifica al grupo):'], self::USAGE);
        }
    }

    private static function usageFor(string $action): string
    {
        foreach (self::USAGE as $u) {
            if (str_starts_with($u, $action . ' ')) {
                return substr($u, strlen($action) + 1);
            }
        }
        return '';
    }

    private static function toMembers(App $app, int $gid, string $text): void
    {
        foreach ($app->groups->memberIds($gid) as $m) {
            $app->notify($m, $text);
        }
    }
}
