<?php

declare(strict_types=1);

namespace Claudia\Reminders;

use Claudia\Ai\ChatService;
use Claudia\Clock;
use Claudia\Config;
use Claudia\Db;
use Claudia\Economy\Wallet;
use Claudia\Log;
use Claudia\Net\Out;
use Claudia\Players\SessionManager;
use Claudia\Players\Users;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Recordatorios personalizados y cumpleaños.
 * Un recordatorio vencido se entrega apenas el usuario esté conectado.
 */
final class ReminderService
{
    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly SessionManager $sessions,
        private readonly Users $users,
        private readonly Out $out,
        private readonly ChatService $chat,
        private readonly Wallet $wallet,
    ) {
    }

    public function timezone(): string
    {
        return $this->config->string('service.timezone', 'America/Montevideo');
    }

    /** @param string $raw "DD/MM" */
    public function setBirthday(int $userId, string $raw): string
    {
        if (!preg_match('/^(\d{1,2})[\/\-](\d{1,2})$/', trim($raw), $m) || !checkdate((int) $m[2], (int) $m[1], 2000)) {
            throw new UserError('Usá el formato DD/MM, por ejemplo /cumple 23/07.');
        }
        $mmdd = sprintf('%02d-%02d', (int) $m[2], (int) $m[1]);
        $this->users->setBirthday($userId, $mmdd);
        return sprintf('%02d/%02d', (int) $m[1], (int) $m[2]);
    }

    /**
     * @param list<string> $tokens
     * @return int timestamp de entrega
     */
    public function add(int $userId, array $tokens): int
    {
        $parsed = Text::parseWhen($tokens, Clock::now(), $this->timezone());
        if ($parsed === null) {
            throw new UserError('No entendí cuándo. Ejemplos: /recordar 30m sacar la ropa | /recordar 2h30m ... | /recordar 25/12 18:00 ...');
        }
        [$due, $used] = $parsed;
        $text = Text::sanitize(implode(' ', array_slice($tokens, $used)));
        if ($text === '') {
            throw new UserError('¿Y qué te recuerdo? Poné el texto después de la fecha.');
        }
        $maxLen = $this->config->int('reminders.max_text_length', 120);
        if (mb_strlen($text) > $maxLen) {
            throw new UserError("El recordatorio puede tener hasta {$maxLen} caracteres.");
        }
        $maxDays = $this->config->int('reminders.max_days_ahead', 366);
        if ($due - Clock::now() > $maxDays * 86400) {
            throw new UserError("Solo puedo recordarte cosas hasta {$maxDays} días adelante.");
        }
        $pending = (int) $this->db->value('SELECT COUNT(*) FROM reminders WHERE user_id = ? AND delivered = 0', [$userId]);
        $max = $this->config->int('reminders.max_pending', 10);
        if ($pending >= $max) {
            throw new UserError("Ya tenés {$max} recordatorios pendientes. Borrá alguno con /borrarrecordatorio.");
        }
        $this->db->exec('INSERT INTO reminders(user_id, text, due_at, created_at) VALUES(?, ?, ?, ?)', [$userId, $text, $due, Clock::now()]);
        return $due;
    }

    /** @return list<array{id:int, text:string, due_at:int}> */
    public function pending(int $userId): array
    {
        return $this->db->all('SELECT id, text, due_at FROM reminders WHERE user_id = ? AND delivered = 0 ORDER BY due_at', [$userId]);
    }

    /** Borra el recordatorio número $n (1-based, según /recordatorios). */
    public function delete(int $userId, int $n): string
    {
        $list = $this->pending($userId);
        if (!isset($list[$n - 1])) {
            throw new UserError('No tenés un recordatorio con ese número. Mirá /recordatorios.');
        }
        $this->db->exec('DELETE FROM reminders WHERE id = ?', [$list[$n - 1]['id']]);
        return (string) $list[$n - 1]['text'];
    }

    public function formatDate(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone($this->timezone()))->format('d/m H:i');
    }

    /** Scheduler: entrega recordatorios vencidos a usuarios conectados y revisa cumpleaños. */
    public function tick(): void
    {
        foreach ($this->sessions->logged() as $s) {
            $this->deliverFor((int) $s->userId);
        }
    }

    public function deliverFor(int $userId): void
    {
        $session = $this->sessions->byUser($userId);
        if ($session === null) {
            return;
        }
        $rows = $this->db->all('SELECT id, text, created_at FROM reminders WHERE user_id = ? AND delivered = 0 AND due_at <= ? ORDER BY due_at', [$userId, Clock::now()]);
        foreach ($rows as $r) {
            $this->db->exec('UPDATE reminders SET delivered = 1 WHERE id = ?', [$r['id']]);
            $this->out->chat($session->slot, '{green}Recordatorio:{default} ' . $r['text']);
            $this->out->sound($session->slot, $this->config->string('reminders.sound', 'claudia/recordatorio'));
        }
        $this->checkBirthday($userId);
    }

    public function checkBirthday(int $userId): void
    {
        $u = $this->users->find($userId);
        if ($u === null || ($u['birthday'] ?? null) === null) {
            return;
        }
        $today = (new \DateTimeImmutable('@' . Clock::now()))->setTimezone(new \DateTimeZone($this->timezone()));
        $year = (int) $today->format('Y');
        if ($today->format('m-d') !== $u['birthday'] || (int) ($u['last_birthday_year'] ?? 0) === $year) {
            return;
        }
        $this->db->exec('UPDATE users SET last_birthday_year = ? WHERE id = ?', [$year, $userId]);
        $name = ($u['apodo'] ?? '') !== '' ? (string) $u['apodo'] : (string) $u['nick'];
        $gift = $this->config->int('reminders.birthday_gift', 0);
        if ($gift > 0) {
            $this->wallet->credit($userId, $gift, 'birthday', 'regalo de cumpleaños');
        }
        Log::info('Cumpleaños', ['user' => $userId]);
        $this->chat->announce(
            "Hoy es el cumpleaños de {$name}. Saludalo en el chat general a tu manera, con tu personalidad."
                . ($gift > 0 ? ' Mencioná que le regalaste ' . Text::coins($gift) . ' URU Coins.' : ''),
            $userId,
            "¡Feliz cumple, {$name}! Que no se te suba a la cabeza." . ($gift > 0 ? ' Te tiré ' . Text::coins($gift) . ' URU Coins.' : '')
        );
    }
}
