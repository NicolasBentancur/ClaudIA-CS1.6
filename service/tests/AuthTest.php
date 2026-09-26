<?php

declare(strict_types=1);

namespace Claudia\Tests;

use Claudia\Clock;
use Claudia\Players\Session;
use Claudia\Tests\Support\AppTestCase;
use Claudia\UserError;

final class AuthTest extends AppTestCase
{
    /** Ana conectada y logueada desde 10.0.0.1, con el #userid 7 del motor. */
    private function ana(): Session
    {
        $s = $this->app->sessions->join(1, 'Ana', '10.0.0.1', 'STEAM_0:0:1', 7);
        if ($this->app->auth->isRegistered('Ana')) {
            $this->app->auth->login($s, 'clave123');
        } else {
            $this->app->auth->register($s, 'clave123');
        }
        return $s;
    }

    public function testRegisterAndLogin(): void
    {
        $s = $this->player(1, 'Ana', 'clave123');
        $this->assertTrue($s->logged());
        $this->app->sessions->leave(1);

        Clock::advance(3600); // la reanudación ya expiró
        $s2 = $this->app->sessions->join(2, 'Ana', '10.0.0.9', '');
        $this->assertNull($this->app->sessions->takeResume('Ana', '10.0.0.9'));
        $this->app->auth->login($s2, 'clave123');
        $this->assertTrue($s2->logged());
    }

    public function testWrongPasswordAndLock(): void
    {
        $this->player(1, 'Ana', 'clave123');
        $this->app->sessions->leave(1);
        Clock::advance(3600);
        $s = $this->app->sessions->join(1, 'Ana', '10.0.0.1', '');
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->app->auth->login($s, 'mal');
            } catch (UserError $e) {
                $this->assertSame('bad_password', $e->errorCode);
            }
        }
        try {
            $this->app->auth->login($s, 'clave123');
            $this->fail('Debería estar bloqueado');
        } catch (UserError $e) {
            $this->assertSame('locked', $e->errorCode);
        }
        Clock::advance(121);
        $this->app->auth->login($s, 'clave123');
        $this->assertTrue($s->logged());
    }

    public function testNickIsCaseInsensitiveAndUnique(): void
    {
        $this->player(1, 'Ana');
        $s = $this->app->sessions->join(2, 'ANA', '10.0.0.2', '');
        $this->expectException(UserError::class);
        $this->app->auth->register($s, 'otra1234');
    }

    public function testDefaultNicksCannotRegister(): void
    {
        $s = $this->app->sessions->join(1, 'Player', '10.0.0.1', '');
        $this->expectException(UserError::class);
        $this->app->auth->register($s, 'clave123');
    }

    public function testSessionResumesAfterMapChangeFromSameIp(): void
    {
        $s = $this->player(1, 'Ana');
        $uid = $s->userId;
        $this->app->sessions->leaveAll(); // el plugin se reconecta en el cambio de mapa
        Clock::advance(20);
        $this->assertSame($uid, $this->app->sessions->takeResume('Ana', '10.0.0.1'));
        // Otra IP no reanuda (la sesión tiene que estar logueada para dejar algo que reanudar).
        $s2 = $this->app->sessions->join(1, 'Ana', '10.0.0.1', '');
        $this->app->auth->login($s2, 'secreto1');
        $this->app->sessions->leaveAll();
        $this->assertNull($this->app->sessions->takeResume('Ana', '99.9.9.9'));
    }

    public function testMapChangeResumesOnlyTheSameConnection(): void
    {
        $uid = $this->ana()->userId;
        $this->app->sessions->leaveAll();   // cambio de mapa: vuelve la misma conexión
        $this->assertSame($uid, $this->app->sessions->takeResume('Ana', '10.0.0.1', 7));

        // Ana se va y entra otra conexión con su nick desde la misma IP (cíber, NAT): no reanuda.
        $this->ana();
        $this->app->sessions->leave(1);
        $this->assertNull($this->app->sessions->takeResume('Ana', '10.0.0.1', 8));
    }

    public function testWasLoggedAfterRestartNeedsTheLastLoginIp(): void
    {
        $this->ana();
        $this->app->sessions->leave(1);

        // El servicio se reinició y el plugin avisa que ya estaba logueado. Alguien que se puso el
        // nick Ana con el servicio caído, desde otra IP, no entra.
        $other = $this->app->sessions->join(2, 'Ana', '10.0.0.2', '', 9);
        $this->assertFalse($this->app->auth->resumeAfterRestart($other));
        $this->assertFalse($other->logged());

        $same = $this->app->sessions->join(1, 'Ana', '10.0.0.1', 'STEAM_0:0:1', 7);
        $this->assertTrue($this->app->auth->resumeAfterRestart($same));
        $this->assertTrue($same->logged());
    }

    public function testCommandsRequireLogin(): void
    {
        $s = $this->app->sessions->join(1, 'Visitante', '10.0.0.1', '');
        $this->cmd($s, 'saldo');
        $this->assertStringContainsString('loguearte', $this->lastChat(1));
        $this->cmd($s, 'ruleta');
        $this->assertStringContainsString('loguearte', $this->lastChat(1));
    }
}
