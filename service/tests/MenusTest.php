<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Commands\EconomyCommands;
use Claudia\Players\Role;
use Claudia\Players\Session;
use Claudia\Tests\Support\AppTestCase;

final class MenusTest extends AppTestCase
{
    /** @return array{slot:int, id:int, title:string, items:list<string>, page:int} */
    private function lastMenu(int $slot): array
    {
        foreach (array_reverse($this->events) as $e) {
            if ($e['type'] === 'menu' && $e['data']['slot'] === $slot) {
                return $e['data'];
            }
        }
        $this->fail("No se mostró ningún menú al slot {$slot}");
    }

    /** @return list<string> textos del último menú sin códigos de color */
    private function labels(int $slot): array
    {
        return array_map(fn ($l) => (string) preg_replace('/\\\\[wydr]/', '', $l), $this->lastMenu($slot)['items']);
    }

    /** Elige la opción del último menú que contiene $text. */
    private function choose(Session $s, string $text): void
    {
        $menu = $this->lastMenu($s->slot);
        foreach ($this->labels($s->slot) as $i => $label) {
            if (str_contains($label, $text)) {
                $this->app->menus->select($s, $menu['id'], $i, 0);
                return;
            }
        }
        $this->fail("No hay opción \"{$text}\" en: " . implode(' | ', $this->labels($s->slot)));
    }

    private function openMenu(Session $s): void
    {
        $this->cmd($s, 'menu');
    }

    public function testMainMenuDependsOnRole(): void
    {
        $ana = $this->player(1, 'Ana');
        $this->openMenu($ana);
        $this->assertStringContainsString('Claudia', $this->lastMenu(1)['title']);
        $this->assertNotContains('Administración', $this->labels(1));

        $ana->role = Role::ADMIN;
        $this->openMenu($ana);
        $this->assertContains('Administración', $this->labels(1));
    }

    public function testAdminMenuOptionsGrowWithRole(): void
    {
        $p = $this->player(1, 'Jefe');
        $options = [];
        foreach ([Role::ADMIN, Role::STAFF, Role::OWNER] as $role) {
            $p->role = $role;
            $this->cmd($p, 'admin');
            $options[$role] = $this->labels(1);
        }
        $this->assertContains('Jugadores', $options[Role::ADMIN]);
        $this->assertContains('Anuncio de Claudia', $options[Role::ADMIN]);
        $this->assertNotContains('Silenciar a Claudia (IA)', $options[Role::ADMIN]);
        $this->assertContains('Silenciar a Claudia (IA)', $options[Role::STAFF]);
        $this->assertNotContains('Recargar configuración', $options[Role::STAFF]);
        $this->assertContains('Recargar configuración', $options[Role::OWNER]);
        $this->assertContains('Estado del servicio', $options[Role::OWNER]);
        $this->assertLessThan(count($options[Role::STAFF]), count($options[Role::ADMIN]));
        $this->assertLessThan(count($options[Role::OWNER]), count($options[Role::STAFF]));

        // Un jugador común no puede abrirlo.
        $p->role = Role::USER;
        $this->cmd($p, 'admin');
        $this->assertStringContainsString('No tenés permiso', $this->lastChat(1));
    }

    public function testNavigationPromptAndTransfer(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->app->wallet->credit((int) $ana->userId, 1000, 'admin');

        $this->openMenu($ana);
        $this->choose($ana, 'Economía');
        $this->assertStringContainsString('Economía', $this->lastMenu(1)['title']);
        $this->choose($ana, 'Transferir');
        $this->choose($ana, 'Beto');
        $prompt = array_values(array_filter($this->events, fn ($e) => $e['type'] === 'prompt'));
        $this->assertStringContainsString('Monto para Beto', end($prompt)['data']['label']);

        $this->app->menus->input($ana, '300');
        $this->assertSame(300, $this->app->wallet->balance((int) $beto->userId));
        // Después de transferir vuelve al menú de economía.
        $this->assertStringContainsString('Economía', $this->lastMenu(1)['title']);

        // Volver lleva al principal.
        $this->choose($ana, 'Volver');
        $this->assertStringContainsString('Menú', $this->lastMenu(1)['title']);

        // Texto vacío cancela.
        $this->choose($ana, 'Economía');
        $this->choose($ana, 'Transferir');
        $this->choose($ana, 'Beto');
        $this->app->menus->input($ana, '');
        $this->assertStringContainsString('Cancelado.', $this->lastChat(1));
        $this->assertSame(300, $this->app->wallet->balance((int) $beto->userId));
    }

    public function testDisabledItemExplains(): void
    {
        $ana = $this->player(1, 'Ana');
        $this->openMenu($ana);
        $this->choose($ana, 'Economía');
        $this->choose($ana, 'Pagar deuda');
        $this->assertStringContainsString('No debés nada', implode("\n", $this->chats(1)));
        $this->assertStringContainsString('Economía', $this->lastMenu(1)['title']);
    }

    public function testFamilyMenuAcceptsProposal(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $this->cmd($ana, 'pareja Beto');
        $this->openMenu($beto);
        $this->assertStringContainsString('(propuesta)', implode(' ', $this->labels(2)));
        $this->choose($beto, 'Pareja y familia');
        $this->choose($beto, 'Aceptar ser pareja de Ana');
        $this->assertSame((int) $ana->userId, $this->app->family->partnerOf((int) $beto->userId));
        $this->assertContains('Besar a Ana', $this->labels(2));
    }

    public function testStaffCoinLimitAndTargetRank(): void
    {
        $staff = $this->player(1, 'Staff');
        $owner = $this->player(2, 'Owner');
        $ana = $this->player(3, 'Ana');
        $staff->role = Role::STAFF;
        $owner->role = Role::OWNER;

        $this->cmd($staff, 'admin');
        $this->choose($staff, 'Jugadores');
        $this->choose($staff, 'Ana');
        $this->choose($staff, 'Dar coins');
        $this->app->menus->input($staff, '50000');
        $this->assertStringContainsString('tope', $this->lastChat(1));
        $this->assertSame(0, $this->app->wallet->balance((int) $ana->userId));

        $this->choose($staff, 'Dar coins');
        $this->app->menus->input($staff, '5000');
        $this->assertSame(5000, $this->app->wallet->balance((int) $ana->userId));

        // No puede quitarle a alguien de rango superior conectado.
        $this->app->wallet->credit((int) $owner->userId, 100, 'admin');
        $this->assertStringContainsString('rango', $this->tryCall(fn () => $this->app->admin->coins(Role::STAFF, 'Staff', (array) $this->app->users->find((int) $owner->userId), 50, false)));

        // El owner no tiene tope; un admin no puede dar coins (ni por consola).
        $this->assertStringContainsString('Diste', $this->app->admin->coins(Role::OWNER, 'Owner', (array) $this->app->users->find((int) $ana->userId), 100000, true));
        $this->assertStringContainsString('hace falta ser staff', $this->tryCall(fn () => EconomyCommands::adminCoins($this->app, 0, 'Admin', 'Ana', '10', true, Role::ADMIN)));
    }

    public function testOwnerActions(): void
    {
        $owner = $this->player(1, 'Owner');
        $ana = $this->player(2, 'Ana');
        $owner->role = Role::OWNER;

        // Contraseña temporal: la vieja deja de andar y la nueva sí.
        $temp = $this->app->admin->resetPassword(Role::OWNER, 'Owner', (array) $this->app->users->find((int) $ana->userId));
        $this->app->auth->logout($ana);
        $this->app->auth->login($ana, $temp);
        $this->assertTrue($ana->logged());

        // Silenciar la IA.
        $this->assertFalse($this->app->admin->toggleAi(Role::STAFF, 'Owner'));
        $this->assertFalse($this->app->config->bool('ai.enabled', true));
        $this->assertStringContainsString('hace falta ser owner', $this->tryCall(fn () => $this->app->admin->reloadConfig(Role::STAFF, 'x')));
        $this->assertStringContainsString('recargada', $this->app->admin->reloadConfig(Role::OWNER, 'Owner'));

        $status = $this->app->admin->status(Role::OWNER);
        $this->assertStringContainsString('Usuarios: 2', $status[2]);
    }

    public function testStaffCreatesGroupFromMenu(): void
    {
        $staff = $this->player(1, 'Staff');
        $ana = $this->player(2, 'Ana');
        $staff->role = Role::STAFF;
        $this->cmd($staff, 'admin');
        $this->choose($staff, 'Grupos');
        $this->choose($staff, 'Crear un grupo');
        $this->choose($staff, 'Ana');
        $this->app->menus->input($staff, 'PIB');
        $this->app->menus->input($staff, 'Los Pibes');
        $g = $this->app->groups->ofUser((int) $ana->userId);
        $this->assertSame('Los Pibes', $g['name']);
        $this->assertStringContainsString('Grupos', $this->lastMenu(1)['title']);

        // Un admin ve los grupos pero no la opción de crear ni eliminar.
        $staff->role = Role::ADMIN;
        $this->cmd($staff, 'admin');
        $this->choose($staff, 'Grupos');
        $this->assertNotContains('Crear un grupo', $this->labels(1));
        $this->choose($staff, 'Los Pibes');
        $this->assertNotContains('Eliminar el grupo', $this->labels(1));
        $this->assertContains('Renombrar', $this->labels(1));
    }

    private function tryCall(callable $fn): string
    {
        try {
            $fn();
        } catch (\Claudia\UserError $e) {
            return $e->getMessage();
        }
        return '';
    }
}
