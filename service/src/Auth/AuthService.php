<?php

declare(strict_types=1);

namespace Claudia\Auth;

use Claudia\Clock;
use Claudia\Config;
use Claudia\Events;
use Claudia\Log;
use Claudia\Players\Session;
use Claudia\Players\Users;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Registro y login por nick + contraseña (bcrypt). Las contraseñas nunca se loguean.
 */
final class AuthService
{
    public function __construct(
        private readonly Users $users,
        private readonly Config $config,
        private readonly Events $events,
    ) {
    }

    public function isRegistered(string $nick): bool
    {
        return $this->users->findByNick($nick) !== null;
    }

    public function register(Session $s, string $password): void
    {
        if ($s->logged()) {
            throw new UserError('Ya estás logueado.', 'already_logged');
        }
        $this->validateNick($s->nick);
        $this->validatePassword($password);
        if ($this->isRegistered($s->nick)) {
            throw new UserError('Ese nick ya está registrado. Usá /login.', 'nick_taken');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $id = $this->users->create($s->nick, $hash, $s->ip, $s->authid);
        $s->registered = true;
        Log::info('Usuario registrado', ['nick' => $s->nick, 'id' => $id]);
        $this->completeLogin($s, $id, true);
    }

    public function login(Session $s, string $password): void
    {
        if ($s->logged()) {
            throw new UserError('Ya estás logueado.', 'already_logged');
        }
        $now = Clock::now();
        if ($s->lockUntil > $now) {
            throw new UserError('Demasiados intentos. Esperá ' . Text::duration($s->lockUntil - $now) . '.', 'locked');
        }
        $user = $this->users->findByNick($s->nick);
        if ($user === null) {
            throw new UserError('Ese nick no está registrado. Usá /registrar.', 'not_registered');
        }
        if (!password_verify($password, (string) $user['pass_hash'])) {
            $s->failedLogins++;
            $max = $this->config->int('service.auth.max_failed', 5);
            if ($s->failedLogins >= $max) {
                $s->failedLogins = 0;
                $s->lockUntil = $now + $this->config->int('service.auth.lock_seconds', 120);
                Log::warning('Login bloqueado por intentos fallidos', ['nick' => $s->nick, 'ip' => $s->ip]);
            }
            throw new UserError('Contraseña incorrecta.', 'bad_password');
        }
        if (password_needs_rehash((string) $user['pass_hash'], PASSWORD_BCRYPT)) {
            $this->users->setPassword((int) $user['id'], password_hash($password, PASSWORD_BCRYPT));
        }
        $this->completeLogin($s, (int) $user['id'], false);
    }

    /** Restaura una sesión sin contraseña (reanudación tras cambio de mapa). */
    public function resume(Session $s, int $userId): bool
    {
        $user = $this->users->find($userId);
        if ($user === null || mb_strtolower((string) $user['nick']) !== mb_strtolower($s->nick)) {
            return false;
        }
        $s->registered = true;
        $s->userId = $userId;
        $this->users->touch($userId, $s->ip, $s->authid);
        $this->events->emit('auth.resumed', $s);
        return true;
    }

    public function changePassword(Session $s, string $old, string $new): void
    {
        if (!$s->logged()) {
            throw new UserError('Primero logueate.', 'not_logged');
        }
        $user = $this->users->find((int) $s->userId);
        if ($user === null || !password_verify($old, (string) $user['pass_hash'])) {
            throw new UserError('La contraseña actual no coincide.', 'bad_password');
        }
        $this->validatePassword($new);
        $this->users->setPassword((int) $s->userId, password_hash($new, PASSWORD_BCRYPT));
    }

    public function logout(Session $s): void
    {
        if ($s->userId !== null) {
            $this->events->emit('auth.logout', $s);
        }
        $s->userId = null;
    }

    private function completeLogin(Session $s, int $userId, bool $isNew): void
    {
        $s->userId = $userId;
        $s->registered = true;
        $s->failedLogins = 0;
        $this->users->touch($userId, $s->ip, $s->authid);
        Log::info('Login', ['nick' => $s->nick, 'id' => $userId, 'ip' => $s->ip]);
        $this->events->emit('auth.login', $s, $isNew);
    }

    private function validateNick(string $nick): void
    {
        $clean = Text::fold(trim($nick));
        if ($clean === '' || mb_strlen($nick) < 2) {
            throw new UserError('Tu nick es muy corto para registrarlo.', 'bad_nick');
        }
        foreach ($this->config->array('service.auth.forbidden_nicks', ['player', 'unnamed']) as $bad) {
            if ($clean === Text::fold((string) $bad) || preg_match('/^\(\d+\)' . preg_quote(Text::fold((string) $bad), '/') . '$/', $clean)) {
                throw new UserError('No podés registrar ese nick, cambiátelo primero (name "TuNick").', 'bad_nick');
            }
        }
    }

    private function validatePassword(string $password): void
    {
        $min = $this->config->int('service.auth.min_password', 4);
        $max = $this->config->int('service.auth.max_password', 32);
        $len = mb_strlen($password);
        if ($len < $min || $len > $max) {
            throw new UserError("La contraseña tiene que tener entre {$min} y {$max} caracteres.", 'bad_password_format');
        }
        if (preg_match('/\s/', $password)) {
            throw new UserError('La contraseña no puede tener espacios.', 'bad_password_format');
        }
    }
}
