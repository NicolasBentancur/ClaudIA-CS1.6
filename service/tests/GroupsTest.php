<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Clock;
use Claudia\Players\Role;
use Claudia\Players\Session;
use Claudia\Tests\Support\AppTestCase;

final class GroupsTest extends AppTestCase
{
    /** Crea un grupo completando el formulario por chat. */
    private function createGroup(Session $owner, string $name = 'Los Pibes', string $tag = 'PIB', bool $private = false): array
    {
        $this->app->wallet->credit((int) $owner->userId, 10000, 'admin');
        $this->cmd($owner, 'creargrupo');
        foreach ([$name, $tag, 'Grupo de prueba', $private ? 'privado' : 'publico', 'si'] as $answer) {
            $this->app->flows->input($owner, $answer);
        }
        $g = $this->app->groups->ofUser((int) $owner->userId);
        $this->assertNotNull($g, implode("\n", $this->chats((int) $owner->slot)));
        return $g;
    }

    /** @return list<array> eventos chat.tag para un slot */
    private function tags(int $slot): array
    {
        return array_values(array_map(fn ($e) => $e['data']['tag'], array_filter($this->events, fn ($e) => $e['type'] === 'chat.tag' && $e['data']['slot'] === $slot)));
    }

    public function testCreationFlowChargesAndSetsTag(): void
    {
        $ana = $this->player(1, 'Ana');
        $this->cmd($ana, 'creargrupo');
        $this->assertStringContainsString('cuesta', $this->lastChat(1));

        $this->app->wallet->credit((int) $ana->userId, 12000, 'admin');
        $this->events = [];
        $this->cmd($ana, 'creargrupo');
        $capture = array_values(array_filter($this->events, fn ($e) => $e['type'] === 'input.capture'));
        $this->assertTrue($capture[0]['data']['on']);

        $this->app->flows->input($ana, 'x');   // nombre muy corto
        $this->assertStringContainsString('entre 3 y 24', $this->lastChat(1));
        $this->app->flows->input($ana, 'Los Pibes');
        $this->app->flows->input($ana, 'DEMASIADOLARGO');
        $this->assertStringContainsString('entre 1 y 6', $this->lastChat(1));
        $this->app->flows->input($ana, 'PIB');
        $this->app->flows->input($ana, 'Los mejores del barrio');
        $this->app->flows->input($ana, 'quizas');
        $this->assertStringContainsString('publico', $this->lastChat(1));
        $this->app->flows->input($ana, 'publico');
        $this->assertStringContainsString('¿Lo creo?', $this->lastChat(1));
        $this->app->flows->input($ana, 'si');

        $g = $this->app->groups->ofUser((int) $ana->userId);
        $this->assertSame('Los Pibes', $g['name']);
        $this->assertSame(2000, $this->app->wallet->balance((int) $ana->userId));
        $this->assertSame(['PIB'], $this->tags(1));
        $this->assertStringContainsString('fundó el grupo', $this->lastChat(0));
        $capture = array_values(array_filter($this->events, fn ($e) => $e['type'] === 'input.capture'));
        $this->assertFalse(end($capture)['data']['on']);

        // Nombre y tag únicos.
        $beto = $this->player(2, 'Beto');
        $this->app->wallet->credit((int) $beto->userId, 10000, 'admin');
        $this->cmd($beto, 'creargrupo');
        $this->app->flows->input($beto, 'los pibes');
        $this->assertStringContainsString('Ya existe', $this->lastChat(2));
        $this->app->flows->input($beto, 'Otro Grupo');
        $this->app->flows->input($beto, 'pib');
        $this->assertStringContainsString('ya lo usa', $this->lastChat(2));
        $this->app->flows->input($beto, 'cancelar');
        $this->assertStringContainsString('Cancelaste', $this->lastChat(2));
        $this->assertSame(10000, $this->app->wallet->balance((int) $beto->userId));
    }

    public function testFlowTimesOut(): void
    {
        $ana = $this->player(1, 'Ana');
        $this->app->wallet->credit((int) $ana->userId, 10000, 'admin');
        $this->cmd($ana, 'creargrupo');
        Clock::advance(500);
        $this->app->tick(Clock::now());
        $this->assertStringContainsString('venció', $this->lastChat(1));
        $this->assertFalse($this->app->flows->has($ana));
    }

    public function testPublicAndPrivateMembership(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $caro = $this->player(3, 'Caro');
        $this->createGroup($ana, 'Los Pibes', 'PIB', true);

        $this->cmd($beto, 'unirse Los Pibes');
        $this->assertStringContainsString('privado', $this->lastChat(2));
        $this->assertStringContainsString('quiere entrar', $this->lastChat(1));
        $this->assertNull($this->app->groups->ofUser((int) $beto->userId));

        $this->cmd($caro, 'unirse PIB');   // también por tag
        $this->cmd($ana, 'rechazarg Caro');
        $this->assertStringContainsString('rechazada', $this->lastChat(3));

        $this->events = [];
        $this->cmd($ana, 'aceptarg Beto');
        $this->assertSame('Los Pibes', $this->app->groups->ofUser((int) $beto->userId)['name']);
        $this->assertSame(['PIB'], $this->tags(2));

        // Solo el dueño administra.
        $this->cmd($beto, 'expulsarg Ana');
        $this->assertStringContainsString('solo el dueño', $this->lastChat(2));

        $this->events = [];
        $this->cmd($ana, 'expulsarg Beto');
        $this->assertNull($this->app->groups->ofUser((int) $beto->userId));
        $this->assertSame([''], $this->tags(2));

        // Público: entra directo; salir borra el tag.
        $this->cmd($ana, 'editarg privacidad publico');
        $this->cmd($caro, 'unirse Los Pibes');
        $this->assertNotNull($this->app->groups->ofUser((int) $caro->userId));
        $this->events = [];
        $this->cmd($caro, 'salirg');
        $this->assertSame([''], $this->tags(3));

        // El dueño no puede irse sin traspasar.
        $this->cmd($ana, 'salirg');
        $this->assertStringContainsString('traspasarg', $this->lastChat(1));
    }

    public function testMemberLimit(): void
    {
        $owner = $this->player(1, 'Dueno');
        $this->createGroup($owner);
        for ($i = 2; $i <= 16; $i++) {
            $p = $this->player($i, "Miembro{$i}");
            $this->cmd($p, 'unirse Los Pibes');
        }
        $this->assertSame(16, $this->app->groups->memberCount((int) $this->app->groups->ofUser((int) $owner->userId)['id']));
        $extra = $this->player(17, 'Extra');
        $this->cmd($extra, 'unirse Los Pibes');
        $this->assertStringContainsString('lleno', $this->lastChat(17));
    }

    public function testPoolFeesTaxAndRanking(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $pobre = $this->player(3, 'Pobre');
        $g = $this->createGroup($ana);
        $gid = (int) $g['id'];
        $this->app->wallet->credit((int) $beto->userId, 1000, 'admin');
        $this->cmd($beto, 'unirse Los Pibes');
        $this->cmd($pobre, 'unirse Los Pibes');

        // Donación.
        $this->cmd($beto, 'donar 500');
        $this->assertSame(500, $this->app->wallet->balance((int) $beto->userId));
        $this->assertSame(500, (int) $this->app->groups->byId($gid)['pool']);

        // Cuota cada 100 rondas (lotes de estadísticas del plugin), con kills al ranking.
        $this->app->groups->onActivity((int) $beto->userId, 3, 60);
        $this->assertSame(500, (int) $this->app->groups->byId($gid)['pool']);
        $this->app->groups->onActivity((int) $beto->userId, 2, 45);
        $this->assertSame(525, (int) $this->app->groups->byId($gid)['pool']);
        $this->assertSame(475, $this->app->wallet->balance((int) $beto->userId));
        $this->assertSame(5, (int) $this->app->groups->byId($gid)['kills']);

        // Sin saldo no paga y se avisa al dueño (no se crean coins).
        $this->app->groups->onActivity((int) $pobre->userId, 0, 100);
        $this->assertSame(525, (int) $this->app->groups->byId($gid)['pool']);
        $this->assertStringContainsString('no pudo pagar', $this->lastChat(1));

        // El dueño no paga cuota; cada 300 rondas suyas se quema el 15 % del fondo.
        $this->app->groups->onActivity((int) $ana->userId, 1, 299);
        $this->assertSame(525, (int) $this->app->groups->byId($gid)['pool']);
        $this->app->groups->onActivity((int) $ana->userId, 0, 1);
        $this->assertSame(447, (int) $this->app->groups->byId($gid)['pool']);  // 525 - floor(78.75)

        // El dueño paga desde el fondo a un miembro.
        $this->cmd($ana, 'fondo dar Beto 100');
        $this->assertSame(575, $this->app->wallet->balance((int) $beto->userId));
        $this->assertSame(347, (int) $this->app->groups->byId($gid)['pool']);
        $this->cmd($beto, 'fondo dar Beto 100');
        $this->assertStringContainsString('solo el dueño', $this->lastChat(2));

        $kinds = array_column($this->app->groups->ledger($gid), 'kind');
        $this->assertSame(['pago', 'impuesto', 'cuota_impaga', 'cuota', 'donacion'], $kinds);

        // Ranking cosmético por kills.
        $other = $this->player(4, 'Otro');
        $this->createGroup($other, 'Rivales', 'RIV');
        $this->app->groups->onActivity((int) $other->userId, 10, 0);
        $this->assertSame('Rivales', $this->app->groups->ranking()[0]['name']);
        $this->assertSame(2, $this->app->groups->position($gid));
    }

    public function testStatsBatchFeedsGroups(): void
    {
        $ana = $this->player(1, 'Ana');
        $g = $this->createGroup($ana);
        $this->app->stats->add((int) $ana->userId, ['kills' => 4]);
        $this->app->groups->onActivity((int) $ana->userId, 4, 1);
        $this->assertSame(4, (int) $this->app->groups->byId((int) $g['id'])['kills']);
    }

    public function testDissolveSplitsPool(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $g = $this->createGroup($ana);
        $this->cmd($beto, 'unirse Los Pibes');
        $this->app->wallet->credit((int) $beto->userId, 301, 'admin');
        $this->cmd($beto, 'donar 301');

        $this->cmd($ana, 'disolver');
        $this->assertStringContainsString('/disolver si', $this->lastChat(1));
        $this->assertNotNull($this->app->groups->byId((int) $g['id']));

        $this->events = [];
        $this->cmd($ana, 'disolver si');
        $this->assertNull($this->app->groups->byId((int) $g['id']));
        $this->assertSame(150, $this->app->wallet->balance((int) $beto->userId));
        $this->assertSame(150, $this->app->wallet->balance((int) $ana->userId));
        $this->assertSame([''], $this->tags(1));
        $this->assertSame([''], $this->tags(2));
    }

    public function testTransferOwnershipAndGroupChat(): void
    {
        $ana = $this->player(1, 'Ana');
        $beto = $this->player(2, 'Beto');
        $caro = $this->player(3, 'Caro');
        $this->createGroup($ana);
        $this->cmd($beto, 'unirse Los Pibes');
        $this->cmd($ana, 'traspasarg Beto');
        $this->assertSame((int) $beto->userId, (int) $this->app->groups->ofUser((int) $ana->userId)['owner_id']);
        $this->cmd($ana, 'salirg');
        $this->assertNull($this->app->groups->ofUser((int) $ana->userId));

        $this->cmd($caro, 'unirse Los Pibes');
        $this->events = [];
        $this->cmd($beto, 'g hola equipo');
        $this->assertStringContainsString('hola equipo', $this->lastChat(3));
        $this->assertSame([], $this->chats(1));
    }

    public function testLoginSendsTag(): void
    {
        $ana = $this->player(1, 'Ana');
        $this->createGroup($ana);
        $this->app->auth->logout($ana);
        $this->events = [];
        $this->app->auth->login($ana, 'secreto1');
        $this->assertSame(['PIB'], $this->tags(1));
    }

    private function admin(Session $s, string $args, int $role = Role::STAFF): void
    {
        $s->role = $role;
        $this->app->commands->dispatch($s, 'admingrupo', $args);
    }

    public function testAdminCreatesAndDeletesGroups(): void
    {
        $admin = $this->player(1, 'Admin');
        $ana = $this->player(2, 'Ana');
        $beto = $this->player(3, 'Beto');

        // Sin flags de admin no se puede.
        $this->cmd($ana, 'admingrupo crear Ana PIB Los Pibes');
        $this->assertStringContainsString('No tenés permiso', $this->lastChat(2));

        // Un admin común no puede crear ni borrar (hace falta staff), pero sí ver.
        $this->admin($admin, 'crear Ana PIB Los Pibes', Role::ADMIN);
        $this->assertStringContainsString('hace falta ser staff', $this->lastChat(1));

        // Crear gratis con dueño asignado (no se cobra ni se crean coins).
        $this->events = [];
        $this->admin($admin, 'crear Ana PIB Los Pibes');
        $g = $this->app->groups->ofUser((int) $ana->userId);
        $this->assertSame('Los Pibes', $g['name']);
        $this->assertSame((int) $ana->userId, (int) $g['owner_id']);
        $this->assertSame(0, $this->app->wallet->balance((int) $ana->userId));
        $this->assertSame(['PIB'], $this->tags(2));
        $this->assertStringContainsString('te hizo dueño', $this->lastChat(2));

        // Validaciones: dueño que ya está en un grupo, tag repetido.
        $this->admin($admin, 'crear Ana OTRO Otro Grupo');
        $this->assertStringContainsString('ya está en un grupo', $this->lastChat(1));
        $this->admin($admin, 'crear Beto pib Otro Grupo');
        $this->assertStringContainsString('ya lo usa', $this->lastChat(1));

        // Gestión: agregar, renombrar, cambiar tag, dueño, expulsar.
        $this->admin($admin, 'agregar PIB Beto');
        $this->assertSame((int) $g['id'], (int) $this->app->groups->ofUser((int) $beto->userId)['id']);
        $this->admin($admin, 'renombrar PIB Los Pibes Nuevos');
        $this->assertSame('Los Pibes Nuevos', $this->app->groups->byId((int) $g['id'])['name']);
        $this->events = [];
        $this->admin($admin, 'tag PIB NUE');
        $this->assertSame(['NUE'], $this->tags(2));
        $this->assertSame(['NUE'], $this->tags(3));
        $this->admin($admin, 'dueño NUE Beto');
        $this->assertSame((int) $beto->userId, (int) $this->app->groups->byId((int) $g['id'])['owner_id']);
        $this->admin($admin, 'expulsar NUE Beto');
        $this->assertStringContainsString('dueño', $this->lastChat(1));
        $this->admin($admin, 'expulsar NUE Ana');
        $this->assertNull($this->app->groups->ofUser((int) $ana->userId));
        $this->admin($admin, 'privacidad NUE privado');
        $this->assertSame(1, (int) $this->app->groups->byId((int) $g['id'])['private']);

        // Borrar reparte el fondo entre los miembros.
        $this->app->wallet->credit((int) $beto->userId, 100, 'admin');
        $this->cmd($beto, 'donar 100');
        $this->events = [];
        $this->admin($admin, 'borrar Los Pibes Nuevos');
        $this->assertNull($this->app->groups->byId((int) $g['id']));
        $this->assertSame(100, $this->app->wallet->balance((int) $beto->userId));
        $this->assertSame([''], $this->tags(3));
        $this->assertStringContainsString('eliminó el grupo', $this->lastChat(3));
    }

    public function testAdminConsoleLines(): void
    {
        $ana = $this->player(1, 'Ana');
        $this->createGroup($ana);
        $lines = \Claudia\Commands\GroupAdminCommands::run($this->app, 'consola', 'info PIB');
        $this->assertStringContainsString('[PIB] Los Pibes', $lines[0]);
        $this->assertStringContainsString('Ana', implode("\n", $lines));
        $help = \Claudia\Commands\GroupAdminCommands::run($this->app, 'consola', 'ayuda');
        $this->assertGreaterThan(5, count($help));
        $list = \Claudia\Commands\GroupAdminCommands::run($this->app, 'consola', 'lista');
        $this->assertSame('1 grupos.', $list[0]);
    }

    public function testShop(): void
    {
        $ana = $this->player(1, 'Ana');
        $this->cmd($ana, 'tienda');
        $this->assertStringContainsString('anillo', $this->lastChat(1));
        $this->cmd($ana, 'comprar anillo');
        $this->assertStringContainsString('No te alcanza', $this->lastChat(1));
        $this->app->wallet->credit((int) $ana->userId, 10000, 'admin');
        for ($i = 0; $i < 4; $i++) {
            $this->cmd($ana, 'comprar anillo');
        }
        $this->assertSame(3, $this->app->shop->count((int) $ana->userId, 'anillo'));
        $this->assertStringContainsString('no podés tener más', $this->lastChat(1));
        $this->cmd($ana, 'comprar grupo');
        $this->assertTrue($this->app->flows->has($ana) || str_contains($this->lastChat(1), 'cuesta'));
    }
}
