<?php

declare(strict_types=1);

namespace Claudia\Groups;

use Claudia\Clock;
use Claudia\Config;
use Claudia\Db;
use Claudia\Economy\Wallet;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Grupos (clanes): creación, miembros, solicitudes, fondo común y ranking por kills.
 *
 * Economía: crear un grupo cuesta groups.price (sale de circulación). El fondo se llena solo con
 * coins de los miembros (cuotas y donaciones) y el impuesto quema una parte: nunca se crean coins.
 */
final class GroupService
{
    /** @var callable(int, string):void */
    private $notify;
    /** @var callable(int, string):void */
    private $tagChanged;

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly Wallet $wallet,
    ) {
        $this->notify = function (int $uid, string $msg): void {
        };
        $this->tagChanged = function (int $uid, string $tag): void {
        };
    }

    /** @param callable(int, string):void $fn avisa a un usuario (si está conectado) */
    public function setNotifier(callable $fn): void
    {
        $this->notify = $fn;
    }

    /** @param callable(int, string):void $fn el tag de chat de un usuario cambió ("" = sin grupo) */
    public function onTagChanged(callable $fn): void
    {
        $this->tagChanged = $fn;
    }

    public function price(): int
    {
        return $this->config->int('groups.price', 10000);
    }

    public function maxMembers(): int
    {
        return $this->config->int('groups.max_members', 16);
    }

    /* ------------------------------------------------------------------
     * Validaciones (las usa también el formulario de creación)
     * ---------------------------------------------------------------- */

    public function validateName(string $raw, ?int $exceptId = null): string
    {
        $name = Text::chatSafe(Text::sanitize($raw));
        $min = $this->config->int('groups.name_min_length', 3);
        $max = $this->config->int('groups.name_max_length', 24);
        if (mb_strlen($name) < $min || mb_strlen($name) > $max) {
            throw new UserError("El nombre tiene que tener entre {$min} y {$max} caracteres.");
        }
        if (!preg_match('/^[\p{L}\p{N} ._\-!]+$/u', $name)) {
            throw new UserError('El nombre solo puede tener letras, números, espacios y . _ - !');
        }
        $this->checkBlocked($name);
        $other = $this->byName($name);
        if ($other !== null && (int) $other['id'] !== $exceptId) {
            throw new UserError("Ya existe un grupo que se llama \"{$name}\".");
        }
        return $name;
    }

    public function validateTag(string $raw, ?int $exceptId = null): string
    {
        $tag = trim(Text::sanitize($raw), '[] ');
        $max = $this->config->int('groups.tag_max_length', 6);
        $extra = preg_quote($this->config->string('groups.tag_extra_chars', '-_.!*#$@+'), '/');
        if ($tag === '' || mb_strlen($tag) > $max) {
            throw new UserError("El tag tiene que tener entre 1 y {$max} caracteres.");
        }
        if (!preg_match('/^[A-Za-z0-9' . $extra . ']+$/', $tag)) {
            throw new UserError('El tag solo puede tener letras sin tilde, números y ' . $this->config->string('groups.tag_extra_chars', '-_.!*#$@+'));
        }
        $this->checkBlocked($tag);
        $other = $this->db->value('SELECT id FROM groups WHERE tag = ?', [$tag]);
        if ($other !== null && (int) $other !== $exceptId) {
            throw new UserError("El tag [{$tag}] ya lo usa otro grupo.");
        }
        return $tag;
    }

    public function validateDescription(string $raw): string
    {
        $d = Text::chatSafe(Text::sanitize($raw));
        $max = $this->config->int('groups.description_max_length', 100);
        if ($d === '' || mb_strlen($d) > $max) {
            throw new UserError("La descripción tiene que tener entre 1 y {$max} caracteres.");
        }
        $this->checkBlocked($d);
        return $d;
    }

    private function checkBlocked(string $text): void
    {
        foreach ($this->config->array('service.nickname.blocked_words') as $bad) {
            if ($bad !== '' && str_contains(Text::fold($text), Text::fold((string) $bad))) {
                throw new UserError('Eso no está permitido.');
            }
        }
    }

    /* ------------------------------------------------------------------
     * Consultas
     * ---------------------------------------------------------------- */

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        return $this->db->one('SELECT * FROM groups WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public function byName(string $name): ?array
    {
        $name = trim($name);
        return $this->db->one('SELECT * FROM groups WHERE name = ?', [$name])
            ?? $this->db->one('SELECT * FROM groups WHERE tag = ?', [trim($name, '[] ')]);
    }

    /** Busca por nombre o tag; lanza UserError si no existe. @return array<string,mixed> */
    public function find(string $name): array
    {
        $g = $this->byName($name);
        if ($g === null) {
            throw new UserError("No existe el grupo \"{$name}\". Mirá /grupos.");
        }
        return $g;
    }

    /** Grupo del usuario. @return array<string,mixed>|null */
    public function ofUser(int $uid): ?array
    {
        return $this->db->one('SELECT g.* FROM groups g JOIN group_members m ON m.group_id = g.id WHERE m.user_id = ?', [$uid]);
    }

    /** @return array<string,mixed> */
    public function mine(int $uid): array
    {
        $g = $this->ofUser($uid);
        if ($g === null) {
            throw new UserError('No estás en ningún grupo. Mirá /grupos o fundá uno con /creargrupo.');
        }
        return $g;
    }

    /** @return array<string,mixed> el grupo del que $uid es dueño */
    public function owned(int $uid): array
    {
        $g = $this->mine($uid);
        if ((int) $g['owner_id'] !== $uid) {
            throw new UserError('Eso lo puede hacer solo el dueño del grupo.');
        }
        return $g;
    }

    public function tagOf(int $uid): string
    {
        return (string) ($this->db->value('SELECT g.tag FROM groups g JOIN group_members m ON m.group_id = g.id WHERE m.user_id = ?', [$uid]) ?? '');
    }

    public function memberCount(int $gid): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM group_members WHERE group_id = ?', [$gid]);
    }

    /** @return list<array{user_id:int, nick:string, joined_at:int, rounds:int, kills:int, fees_paid:int, donated:int}> */
    public function members(int $gid): array
    {
        return $this->db->all(
            'SELECT m.user_id, u.nick, m.joined_at, m.rounds, m.kills, m.fees_paid, m.donated
             FROM group_members m JOIN users u ON u.id = m.user_id WHERE m.group_id = ? ORDER BY m.kills DESC, m.joined_at ASC',
            [$gid]
        );
    }

    /** @return list<int> */
    public function memberIds(int $gid): array
    {
        return array_map('intval', array_column($this->db->all('SELECT user_id FROM group_members WHERE group_id = ?', [$gid]), 'user_id'));
    }

    /** @return list<array<string,mixed>> grupos públicos con cantidad de miembros */
    public function listPublic(): array
    {
        return $this->db->all(
            'SELECT g.*, (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id) AS members
             FROM groups g WHERE g.private = 0 ORDER BY g.kills DESC, g.id ASC'
        );
    }

    /** @return list<array<string,mixed>> ranking por kills (todos los grupos) */
    public function ranking(int $limit = 10): array
    {
        return $this->db->all(
            'SELECT g.*, (SELECT COUNT(*) FROM group_members m WHERE m.group_id = g.id) AS members
             FROM groups g ORDER BY g.kills DESC, g.id ASC LIMIT ?',
            [$limit]
        );
    }

    public function position(int $gid): int
    {
        $g = $this->byId($gid);
        if ($g === null) {
            return 0;
        }
        return 1 + (int) $this->db->value('SELECT COUNT(*) FROM groups WHERE kills > ? OR (kills = ? AND id < ?)', [(int) $g['kills'], (int) $g['kills'], $gid]);
    }

    /** @return list<array{user_id:int, nick:string, created_at:int}> */
    public function requests(int $gid): array
    {
        return $this->db->all(
            'SELECT r.user_id, u.nick, r.created_at FROM group_requests r JOIN users u ON u.id = r.user_id WHERE r.group_id = ? ORDER BY r.created_at',
            [$gid]
        );
    }

    /** @return list<array{kind:string, amount:int, nick:?string, pool_after:int, note:?string, created_at:int}> */
    public function ledger(int $gid, int $limit = 15): array
    {
        return $this->db->all(
            'SELECT l.kind, l.amount, u.nick, l.pool_after, l.note, l.created_at FROM group_ledger l LEFT JOIN users u ON u.id = l.user_id
             WHERE l.group_id = ? ORDER BY l.id DESC LIMIT ?',
            [$gid, $limit]
        );
    }

    /* ------------------------------------------------------------------
     * Creación y membresía
     * ---------------------------------------------------------------- */

    /**
     * Crea el grupo. Si $charge es true cobra el precio al dueño (los admins crean gratis).
     * @return array<string,mixed> el grupo creado
     */
    public function create(int $ownerId, string $name, string $tag, string $description, bool $private, bool $charge = true): array
    {
        $group = $this->db->transaction(function () use ($ownerId, $name, $tag, $description, $private, $charge): array {
            if ($this->ofUser($ownerId) !== null) {
                throw new UserError($charge ? 'Ya estás en un grupo. Salí primero con /salirg.' : 'Ese jugador ya está en un grupo: tiene que salir primero.');
            }
            // Se revalida por si alguien lo tomó mientras se completaba el formulario.
            $name = $this->validateName($name);
            $tag = $this->validateTag($tag);
            if ($charge) {
                $this->wallet->debit($ownerId, $this->price(), 'group_create', "grupo {$name}");
            }
            $now = Clock::now();
            $gid = $this->db->insert(
                'INSERT INTO groups(name, tag, description, private, owner_id, created_at) VALUES(?, ?, ?, ?, ?, ?)',
                [$name, $tag, $description, $private ? 1 : 0, $ownerId, $now]
            );
            $this->insertMember($gid, $ownerId);
            return (array) $this->byId($gid);
        });
        ($this->tagChanged)($ownerId, (string) $group['tag']);
        return $group;
    }

    /**
     * /unirse: en un grupo público entra directo; en uno privado deja una solicitud.
     * @return array{0:string, 1:array<string,mixed>} ['joined'|'requested', grupo]
     */
    public function join(int $uid, string $name): array
    {
        $g = $this->find($name);
        $gid = (int) $g['id'];
        $current = $this->ofUser($uid);
        if ($current !== null) {
            throw new UserError((int) $current['id'] === $gid ? 'Ya estás en ese grupo.' : "Ya estás en {$current['name']}. Salí primero con /salirg.");
        }
        if ($this->memberCount($gid) >= $this->maxMembers()) {
            throw new UserError("{$g['name']} está lleno ({$this->maxMembers()} miembros).");
        }
        if ((int) $g['private'] === 1) {
            if ($this->db->value('SELECT 1 FROM group_requests WHERE group_id = ? AND user_id = ?', [$gid, $uid]) !== null) {
                throw new UserError("Ya le pediste entrar a {$g['name']}. Esperá que el dueño responda.");
            }
            $this->db->exec('INSERT INTO group_requests(group_id, user_id, created_at) VALUES(?, ?, ?)', [$gid, $uid, Clock::now()]);
            return ['requested', $g];
        }
        $this->db->transaction(fn () => $this->insertMember($gid, $uid));
        ($this->tagChanged)($uid, (string) $g['tag']);
        return ['joined', $g];
    }

    /** El dueño acepta una solicitud. @return array<string,mixed> el grupo */
    public function acceptRequest(int $ownerId, int $uid): array
    {
        $g = $this->owned($ownerId);
        $gid = (int) $g['id'];
        if ($this->db->value('SELECT 1 FROM group_requests WHERE group_id = ? AND user_id = ?', [$gid, $uid]) === null) {
            throw new UserError('Ese jugador no pidió entrar a tu grupo. Mirá /solicitudes.');
        }
        if ($this->ofUser($uid) !== null) {
            $this->db->exec('DELETE FROM group_requests WHERE user_id = ?', [$uid]);
            throw new UserError('Ese jugador ya entró a otro grupo.');
        }
        if ($this->memberCount($gid) >= $this->maxMembers()) {
            throw new UserError("Tu grupo está lleno ({$this->maxMembers()} miembros).");
        }
        $this->db->transaction(fn () => $this->insertMember($gid, $uid));
        ($this->tagChanged)($uid, (string) $g['tag']);
        return $g;
    }

    /** @return array<string,mixed> el grupo */
    public function rejectRequest(int $ownerId, int $uid): array
    {
        $g = $this->owned($ownerId);
        if ($this->db->exec('DELETE FROM group_requests WHERE group_id = ? AND user_id = ?', [(int) $g['id'], $uid]) === 0) {
            throw new UserError('Ese jugador no pidió entrar a tu grupo. Mirá /solicitudes.');
        }
        return $g;
    }

    private function insertMember(int $gid, int $uid): void
    {
        $this->db->exec('INSERT INTO group_members(user_id, group_id, joined_at) VALUES(?, ?, ?)', [$uid, $gid, Clock::now()]);
        // Las demás solicitudes de ese jugador ya no tienen sentido.
        $this->db->exec('DELETE FROM group_requests WHERE user_id = ?', [$uid]);
    }

    /** @return array<string,mixed> el grupo que dejó */
    public function leave(int $uid): array
    {
        $g = $this->mine($uid);
        if ((int) $g['owner_id'] === $uid) {
            throw new UserError('Sos el dueño: pasale el grupo a otro con /traspasarg <nick> o disolvelo con /disolver.');
        }
        $this->db->exec('DELETE FROM group_members WHERE user_id = ?', [$uid]);
        ($this->tagChanged)($uid, '');
        return $g;
    }

    /** @return array<string,mixed> el grupo */
    public function expel(int $ownerId, int $uid): array
    {
        $g = $this->owned($ownerId);
        if ($uid === $ownerId) {
            throw new UserError('No te podés echar a vos mismo. Usá /disolver.');
        }
        $this->removeMember($g, $uid);
        return $g;
    }

    /** Saca a un miembro (no al dueño). @param array<string,mixed> $g */
    public function removeMember(array $g, int $uid): void
    {
        if ($uid === (int) $g['owner_id']) {
            throw new UserError('No se puede sacar al dueño: primero hay que ponerle otro dueño al grupo.');
        }
        if ($this->db->exec('DELETE FROM group_members WHERE user_id = ? AND group_id = ?', [$uid, (int) $g['id']]) === 0) {
            throw new UserError('Ese jugador no está en el grupo.');
        }
        ($this->tagChanged)($uid, '');
    }

    /** Mete a un jugador en el grupo sin pedir permiso (admins). @param array<string,mixed> $g */
    public function addMember(array $g, int $uid): void
    {
        $current = $this->ofUser($uid);
        if ($current !== null) {
            throw new UserError((int) $current['id'] === (int) $g['id'] ? 'Ya está en ese grupo.' : "Ya está en {$current['name']}: tiene que salir primero.");
        }
        if ($this->memberCount((int) $g['id']) >= $this->maxMembers()) {
            throw new UserError("{$g['name']} está lleno ({$this->maxMembers()} miembros).");
        }
        $this->db->transaction(fn () => $this->insertMember((int) $g['id'], $uid));
        ($this->tagChanged)($uid, (string) $g['tag']);
    }

    /** @return array<string,mixed> el grupo */
    public function transferOwner(int $ownerId, int $uid): array
    {
        $g = $this->owned($ownerId);
        if ($uid === $ownerId) {
            throw new UserError('El grupo ya es tuyo.');
        }
        $member = $this->ofUser($uid);
        if ($member === null || (int) $member['id'] !== (int) $g['id']) {
            throw new UserError('Solo le podés pasar el grupo a un miembro.');
        }
        return $this->setOwner($g, $uid);
    }

    /**
     * Cambia el dueño. Si el nuevo dueño no es miembro, lo mete (admins).
     * @param array<string,mixed> $g
     * @return array<string,mixed> el grupo actualizado
     */
    public function setOwner(array $g, int $uid): array
    {
        $old = (int) $g['owner_id'];
        if ($uid === $old) {
            throw new UserError('Ese jugador ya es el dueño.');
        }
        $current = $this->ofUser($uid);
        if ($current === null) {
            $this->addMember($g, $uid);
        } elseif ((int) $current['id'] !== (int) $g['id']) {
            throw new UserError("Ya está en {$current['name']}: tiene que salir primero.");
        }
        $this->db->transaction(function () use ($g, $uid, $old): void {
            $this->db->exec('UPDATE groups SET owner_id = ?, owner_rounds = 0 WHERE id = ?', [$uid, (int) $g['id']]);
            // Los contadores de cuota arrancan de cero para los dos.
            $this->db->exec('UPDATE group_members SET rounds = 0 WHERE user_id IN (?, ?)', [$uid, $old]);
        });
        return (array) $this->byId((int) $g['id']);
    }

    /**
     * Disuelve el grupo: el fondo se reparte en partes iguales entre los miembros (el resto se pierde).
     * @return array{group:array<string,mixed>, members:list<int>, share:int}
     */
    public function dissolve(int $ownerId): array
    {
        return $this->dissolveGroup($this->owned($ownerId));
    }

    /**
     * Borra el grupo repartiendo el fondo entre los miembros (lo usan el dueño y los admins).
     * @param array<string,mixed> $g
     * @return array{group:array<string,mixed>, members:list<int>, share:int}
     */
    public function dissolveGroup(array $g): array
    {
        $gid = (int) $g['id'];
        $members = $this->memberIds($gid);
        $share = $members === [] ? 0 : intdiv((int) $g['pool'], count($members));
        $this->db->transaction(function () use ($gid, $members, $share, $g): void {
            if ($share > 0) {
                foreach ($members as $uid) {
                    $this->wallet->credit($uid, $share, 'group_payout', "reparto del fondo de {$g['name']}");
                }
            }
            $this->db->exec('DELETE FROM groups WHERE id = ?', [$gid]);
        });
        foreach ($members as $uid) {
            ($this->tagChanged)($uid, '');
        }
        return ['group' => $g, 'members' => $members, 'share' => $share];
    }

    public function setDescription(int $ownerId, string $raw): string
    {
        return $this->updateDescription($this->owned($ownerId), $raw);
    }

    /** @param array<string,mixed> $g */
    public function updateDescription(array $g, string $raw): string
    {
        $d = $this->validateDescription($raw);
        $this->db->exec('UPDATE groups SET description = ? WHERE id = ?', [$d, (int) $g['id']]);
        return $d;
    }

    public function setPrivate(int $ownerId, bool $private): void
    {
        $this->updatePrivate($this->owned($ownerId), $private);
    }

    /** @param array<string,mixed> $g */
    public function rename(array $g, string $raw): string
    {
        $name = $this->validateName($raw, (int) $g['id']);
        $this->db->exec('UPDATE groups SET name = ? WHERE id = ?', [$name, (int) $g['id']]);
        return $name;
    }

    /** Cambia el tag y se lo actualiza en el chat a todos los miembros. @param array<string,mixed> $g */
    public function retag(array $g, string $raw): string
    {
        $tag = $this->validateTag($raw, (int) $g['id']);
        $this->db->exec('UPDATE groups SET tag = ? WHERE id = ?', [$tag, (int) $g['id']]);
        foreach ($this->memberIds((int) $g['id']) as $uid) {
            ($this->tagChanged)($uid, $tag);
        }
        return $tag;
    }

    /** @param array<string,mixed> $g */
    public function updatePrivate(array $g, bool $private): void
    {
        $this->db->exec('UPDATE groups SET private = ? WHERE id = ?', [$private ? 1 : 0, (int) $g['id']]);
        if (!$private) {
            // Al hacerse público, las solicitudes pendientes quedan sin efecto: pueden entrar directo.
            $this->db->exec('DELETE FROM group_requests WHERE group_id = ?', [(int) $g['id']]);
        }
    }

    /* ------------------------------------------------------------------
     * Fondo común
     * ---------------------------------------------------------------- */

    /** @return int fondo después de donar */
    public function donate(int $uid, int $amount): int
    {
        if ($amount <= 0) {
            throw new UserError('Monto inválido.');
        }
        $g = $this->mine($uid);
        return $this->db->transaction(function () use ($g, $uid, $amount): int {
            $this->wallet->debit($uid, $amount, 'group_donation', "donación a {$g['name']}");
            $this->db->exec('UPDATE group_members SET donated = donated + ? WHERE user_id = ?', [$amount, $uid]);
            return $this->movePool((int) $g['id'], $amount, 'donacion', $uid, null);
        });
    }

    /** El dueño le paga a un miembro (o a sí mismo) desde el fondo. @return int fondo después */
    public function payFromPool(int $ownerId, int $uid, int $amount, string $note = ''): int
    {
        if ($amount <= 0) {
            throw new UserError('Monto inválido.');
        }
        $g = $this->owned($ownerId);
        $member = $this->ofUser($uid);
        if ($member === null || (int) $member['id'] !== (int) $g['id']) {
            throw new UserError('Solo le podés pagar a miembros del grupo.');
        }
        return $this->db->transaction(function () use ($g, $uid, $amount, $note): int {
            $pool = (int) $this->db->value('SELECT pool FROM groups WHERE id = ?', [(int) $g['id']]);
            if ($amount > $pool) {
                throw new UserError('En el fondo hay ' . Text::coins($pool) . ' nomás.');
            }
            $this->wallet->credit($uid, $amount, 'group_payout', "del fondo de {$g['name']}" . ($note !== '' ? ": {$note}" : ''));
            return $this->movePool((int) $g['id'], -$amount, 'pago', $uid, $note !== '' ? $note : null);
        });
    }

    private function movePool(int $gid, int $delta, string $kind, ?int $uid, ?string $note): int
    {
        $this->db->exec('UPDATE groups SET pool = pool + ? WHERE id = ?', [$delta, $gid]);
        $after = (int) $this->db->value('SELECT pool FROM groups WHERE id = ?', [$gid]);
        $this->db->exec(
            'INSERT INTO group_ledger(group_id, kind, amount, user_id, pool_after, note, created_at) VALUES(?, ?, ?, ?, ?, ?, ?)',
            [$gid, $kind, $delta, $uid, $after, $note, Clock::now()]
        );
        return $after;
    }

    /**
     * Actividad reportada por el plugin (cada lote de estadísticas): suma kills al ranking del grupo,
     * cobra la cuota cada N rondas a los miembros y aplica el impuesto cada M rondas del dueño.
     */
    public function onActivity(int $uid, int $kills, int $rounds): void
    {
        if ($kills <= 0 && $rounds <= 0) {
            return;
        }
        $g = $this->ofUser($uid);
        if ($g === null) {
            return;
        }
        $gid = (int) $g['id'];
        if ($kills > 0) {
            $this->db->exec('UPDATE groups SET kills = kills + ? WHERE id = ?', [$kills, $gid]);
            $this->db->exec('UPDATE group_members SET kills = kills + ? WHERE user_id = ?', [$kills, $uid]);
        }
        if ($rounds <= 0) {
            return;
        }
        if ((int) $g['owner_id'] === $uid) {
            $this->ownerRounds($g, $rounds);
        } else {
            $this->memberRounds($g, $uid, $rounds);
        }
    }

    /** @param array<string,mixed> $g */
    private function memberRounds(array $g, int $uid, int $rounds): void
    {
        $every = max(1, $this->config->int('groups.fee.rounds', 100));
        $fee = $this->config->int('groups.fee.amount', 25);
        $this->db->exec('UPDATE group_members SET rounds = rounds + ? WHERE user_id = ?', [$rounds, $uid]);
        $total = (int) $this->db->value('SELECT rounds FROM group_members WHERE user_id = ?', [$uid]);
        $due = intdiv($total, $every);
        if ($due <= 0) {
            return;
        }
        $this->db->exec('UPDATE group_members SET rounds = rounds - ? WHERE user_id = ?', [$due * $every, $uid]);
        for ($i = 0; $i < $due; $i++) {
            try {
                $this->db->transaction(function () use ($g, $uid, $fee): void {
                    $this->wallet->debit($uid, $fee, 'group_fee', "cuota de {$g['name']}");
                    $this->db->exec('UPDATE group_members SET fees_paid = fees_paid + ? WHERE user_id = ?', [$fee, $uid]);
                    $this->movePool((int) $g['id'], $fee, 'cuota', $uid, null);
                });
                ($this->notify)($uid, "Pagaste la cuota de {green}" . Text::coins($fee) . "{default} al fondo de {$g['name']} ({$every} rondas jugadas).");
            } catch (UserError) {
                $this->movePool((int) $g['id'], 0, 'cuota_impaga', $uid, 'sin saldo');
                ($this->notify)($uid, "No te alcanzó para la cuota de {$g['name']} (" . Text::coins($fee) . '). El dueño se va a enterar.');
                ($this->notify)((int) $g['owner_id'], 'Un miembro no pudo pagar la cuota del grupo. Mirá /fondo.');
            }
        }
    }

    /** @param array<string,mixed> $g */
    private function ownerRounds(array $g, int $rounds): void
    {
        $gid = (int) $g['id'];
        $every = max(1, $this->config->int('groups.tax.rounds', 300));
        $rate = $this->config->float('groups.tax.rate', 0.15);
        $this->db->exec('UPDATE groups SET owner_rounds = owner_rounds + ? WHERE id = ?', [$rounds, $gid]);
        $total = (int) $this->db->value('SELECT owner_rounds FROM groups WHERE id = ?', [$gid]);
        $due = intdiv($total, $every);
        if ($due <= 0) {
            return;
        }
        $this->db->exec('UPDATE groups SET owner_rounds = owner_rounds - ? WHERE id = ?', [$due * $every, $gid]);
        for ($i = 0; $i < $due; $i++) {
            $pool = (int) $this->db->value('SELECT pool FROM groups WHERE id = ?', [$gid]);
            $tax = (int) floor($pool * $rate);
            if ($tax <= 0) {
                continue;
            }
            $after = $this->movePool($gid, -$tax, 'impuesto', null, round($rate * 100) . '%');
            ($this->notify)((int) $g['owner_id'], "Impuesto al fondo de {$g['name']}: se fueron {green}" . Text::coins($tax) . '{default}. Quedan ' . Text::coins($after) . '.');
        }
    }
}
