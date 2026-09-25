<?php

declare(strict_types=1);

namespace Claudia\Social;

use Claudia\Clock;
use Claudia\Config;
use Claudia\Db;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Parejas, casamientos, familias y adopciones.
 *
 *   pareja  --(/casarse)-->  casados  --(/adoptar)-->  hijos (máx. social.max_children por familia)
 *
 * Una familia nace con cada casamiento (padres = la pareja). Si se divorcian, siguen siendo los
 * padres de sus hijos, pero solo una pareja casada puede adoptar. Cada jugador es hijo de una
 * sola familia; el apellido es de la familia, así que lo ven los padres y todos los hijos.
 */
final class FamilyService
{
    public function __construct(private readonly Config $config, private readonly Db $db)
    {
    }

    /* ------------------------------------------------------------------
     * Parejas
     * ---------------------------------------------------------------- */

    /** Relación activa del usuario, con "partner" = el otro. @return array<string,mixed>|null */
    public function relationship(int $uid): ?array
    {
        $r = $this->db->one('SELECT * FROM relationships WHERE ended_at IS NULL AND (a_id = ? OR b_id = ?) ORDER BY id DESC LIMIT 1', [$uid, $uid]);
        if ($r === null) {
            return null;
        }
        $r['partner'] = (int) $r['a_id'] === $uid ? (int) $r['b_id'] : (int) $r['a_id'];
        return $r;
    }

    public function partnerOf(int $uid): ?int
    {
        return $this->relationship($uid)['partner'] ?? null;
    }

    public function isMarried(int $uid): bool
    {
        return ($this->relationship($uid)['status'] ?? '') === 'casados';
    }

    /** Verifica que $a y $b puedan ser pareja (lanza UserError si no). */
    public function checkCanDate(int $a, int $b): void
    {
        if ($a === $b) {
            throw new UserError('No podés ser tu propia pareja (aunque te quieras mucho).');
        }
        if ($this->relationship($a) !== null) {
            throw new UserError('Ya tenés pareja. Primero cortá con /terminar.');
        }
        if ($this->relationship($b) !== null) {
            throw new UserError('Esa persona ya tiene pareja.');
        }
        if ($this->closeRelatives($a, $b)) {
            throw new UserError('Son familia directa, eso no va.');
        }
    }

    public function startRelationship(int $a, int $b): int
    {
        return $this->db->transaction(function () use ($a, $b): int {
            $this->checkCanDate($a, $b);
            return $this->db->insert('INSERT INTO relationships(a_id, b_id, status, started_at) VALUES(?, ?, ?, ?)', [$a, $b, 'pareja', Clock::now()]);
        });
    }

    /**
     * Termina la relación activa (si estaban casados es un divorcio: la familia queda, pero ya no pueden adoptar).
     * @return array{partner:int, married:bool, since:int}
     */
    public function endRelationship(int $uid, string $reason = 'terminó'): array
    {
        $r = $this->relationship($uid);
        if ($r === null) {
            throw new UserError('No tenés pareja.');
        }
        $now = Clock::now();
        $married = $r['status'] === 'casados';
        $this->db->transaction(function () use ($r, $now, $married, $reason): void {
            $this->db->exec('UPDATE relationships SET ended_at = ?, end_reason = ? WHERE id = ?', [$now, $reason, (int) $r['id']]);
            foreach ([[(int) $r['a_id'], (int) $r['b_id']], [(int) $r['b_id'], (int) $r['a_id']]] as [$user, $ex]) {
                $this->db->exec(
                    'INSERT INTO ex_partners(user_id, ex_id, started_at, ended_at, was_married, reason) VALUES(?, ?, ?, ?, ?, ?)',
                    [$user, $ex, (int) $r['started_at'], $now, $married ? 1 : 0, $reason]
                );
                $this->trimExes($user);
            }
        });
        return ['partner' => (int) $r['partner'], 'married' => $married, 'since' => (int) $r['started_at']];
    }

    private function trimExes(int $uid): void
    {
        $keep = max(1, $this->config->int('social.max_ex', 5));
        $this->db->exec(
            'DELETE FROM ex_partners WHERE user_id = ? AND id NOT IN (SELECT id FROM ex_partners WHERE user_id = ? ORDER BY ended_at DESC, id DESC LIMIT ?)',
            [$uid, $uid, $keep]
        );
    }

    /** @return list<array{ex_id:int, nick:string, started_at:int, ended_at:int, was_married:int, reason:?string}> */
    public function exes(int $uid): array
    {
        return $this->db->all(
            'SELECT e.ex_id, u.nick, e.started_at, e.ended_at, e.was_married, e.reason FROM ex_partners e JOIN users u ON u.id = e.ex_id
             WHERE e.user_id = ? ORDER BY e.ended_at DESC, e.id DESC',
            [$uid]
        );
    }

    /** Suma un beso si son pareja. Devuelve el total de besos de la pareja (o null si no lo son). */
    public function kiss(int $a, int $b): ?int
    {
        $r = $this->relationship($a);
        if ($r === null || $r['partner'] !== $b) {
            return null;
        }
        $this->db->exec('UPDATE relationships SET kisses = kisses + 1 WHERE id = ?', [(int) $r['id']]);
        return (int) $r['kisses'] + 1;
    }

    /* ------------------------------------------------------------------
     * Casamiento y familias
     * ---------------------------------------------------------------- */

    /** Verifica que $uid pueda pedirle casamiento a su pareja. @return int la pareja */
    public function checkCanMarry(int $uid): int
    {
        $r = $this->relationship($uid);
        if ($r === null) {
            throw new UserError('Primero necesitás pareja: /pareja <nick>.');
        }
        if ($r['status'] === 'casados') {
            throw new UserError('Ya están casados.');
        }
        return (int) $r['partner'];
    }

    /** Casa a la pareja y crea su familia. @return int id de la familia */
    public function marry(int $a, int $b): int
    {
        return $this->db->transaction(function () use ($a, $b): int {
            $r = $this->relationship($a);
            if ($r === null || $r['partner'] !== $b || $r['status'] !== 'pareja') {
                throw new UserError('Ya no son pareja.');
            }
            $now = Clock::now();
            $this->db->exec('UPDATE relationships SET status = ?, married_at = ? WHERE id = ?', ['casados', $now, (int) $r['id']]);
            return $this->db->insert(
                'INSERT INTO families(parent_a, parent_b, relationship_id, created_at) VALUES(?, ?, ?, ?)',
                [(int) $r['a_id'], (int) $r['b_id'], (int) $r['id'], $now]
            );
        });
    }

    /** Familia del matrimonio actual del usuario. @return array<string,mixed>|null */
    public function currentFamily(int $uid): ?array
    {
        $r = $this->relationship($uid);
        if ($r === null || $r['status'] !== 'casados') {
            return null;
        }
        return $this->db->one('SELECT * FROM families WHERE relationship_id = ?', [(int) $r['id']]);
    }

    /** Familia donde el usuario es hijo. @return array<string,mixed>|null */
    public function childFamily(int $uid): ?array
    {
        return $this->db->one('SELECT f.* FROM families f JOIN family_children c ON c.family_id = f.id WHERE c.user_id = ?', [$uid]);
    }

    /** Familias donde el usuario es padre o madre (la más nueva primero). @return list<array<string,mixed>> */
    public function parentFamilies(int $uid): array
    {
        return $this->db->all('SELECT * FROM families WHERE parent_a = ? OR parent_b = ? ORDER BY id DESC', [$uid, $uid]);
    }

    /** @return list<int> */
    public function childrenOfFamily(int $familyId): array
    {
        return array_map('intval', array_column($this->db->all('SELECT user_id FROM family_children WHERE family_id = ? ORDER BY adopted_at', [$familyId]), 'user_id'));
    }

    /**
     * Apellido que "lleva" el usuario: el de la familia donde es hijo; si no, el de su familia
     * como padre/madre (la del matrimonio actual o la más nueva).
     */
    public function surnameOf(int $uid): ?string
    {
        $child = $this->childFamily($uid);
        if ($child !== null && ($child['surname'] ?? '') !== '') {
            return (string) $child['surname'];
        }
        $current = $this->currentFamily($uid);
        if ($current !== null) {
            return ($current['surname'] ?? '') !== '' ? (string) $current['surname'] : null;
        }
        foreach ($this->parentFamilies($uid) as $f) {
            if (($f['surname'] ?? '') !== '') {
                return (string) $f['surname'];
            }
        }
        return null;
    }

    /** /apellido: lo pone uno de los padres en la familia del matrimonio actual. @return array{family:array<string,mixed>, children:list<int>, surname:?string} */
    public function setSurname(int $uid, ?string $raw): array
    {
        $f = $this->currentFamily($uid);
        if ($f === null) {
            throw new UserError('El apellido es de la familia: primero tenés que estar casado/a (/casarse).');
        }
        $surname = null;
        if ($raw !== null) {
            $surname = Text::chatSafe(Text::sanitize($raw));
            $max = $this->config->int('social.surname_max_length', 20);
            if ($surname === '' || mb_strlen($surname) > $max) {
                throw new UserError("El apellido tiene que tener entre 1 y {$max} caracteres.");
            }
            if (!preg_match('/^[\p{L}\' \-]+$/u', $surname)) {
                throw new UserError('El apellido solo puede tener letras, espacios, guiones y apóstrofes.');
            }
            foreach ($this->config->array('service.nickname.blocked_words') as $bad) {
                if ($bad !== '' && str_contains(Text::fold($surname), Text::fold((string) $bad))) {
                    throw new UserError('Ese apellido no está permitido.');
                }
            }
        }
        $this->db->exec('UPDATE families SET surname = ? WHERE id = ?', [$surname, (int) $f['id']]);
        $f['surname'] = $surname;
        return ['family' => $f, 'children' => $this->childrenOfFamily((int) $f['id']), 'surname' => $surname];
    }

    /* ------------------------------------------------------------------
     * Adopción
     * ---------------------------------------------------------------- */

    /** Verifica que $parent pueda adoptar a $child. @return array<string,mixed> la familia */
    public function checkCanAdopt(int $parent, int $child): array
    {
        $f = $this->currentFamily($parent);
        if ($f === null) {
            throw new UserError('Solo las parejas casadas pueden adoptar (/casarse).');
        }
        $pa = (int) $f['parent_a'];
        $pb = (int) $f['parent_b'];
        if ($child === $pa || $child === $pb) {
            throw new UserError('No podés adoptar a tu propia pareja, che.');
        }
        $max = $this->config->int('social.max_children', 4);
        if (count($this->childrenOfFamily((int) $f['id'])) >= $max) {
            throw new UserError("Tu familia ya tiene {$max} hijos, que es el máximo.");
        }
        if ($this->childFamily($child) !== null) {
            throw new UserError('Esa persona ya tiene familia. Primero se tiene que /emancipar.');
        }
        if (in_array($child, $this->ancestors($pa), true) || in_array($child, $this->ancestors($pb), true)) {
            throw new UserError('No podés adoptar a alguien que es tu antepasado (o de tu pareja).');
        }
        if ($this->partnerOf($child) === $pa || $this->partnerOf($child) === $pb) {
            throw new UserError('Esa persona es pareja de alguien de la familia.');
        }
        return $f;
    }

    /** @return array<string,mixed> la familia */
    public function adopt(int $parent, int $child): array
    {
        return $this->db->transaction(function () use ($parent, $child): array {
            $f = $this->checkCanAdopt($parent, $child);
            $this->db->exec('INSERT INTO family_children(user_id, family_id, adopted_at) VALUES(?, ?, ?)', [$child, (int) $f['id'], Clock::now()]);
            return $f;
        });
    }

    /** El hijo se va de su familia. @return array<string,mixed> la familia que deja */
    public function emancipate(int $uid): array
    {
        $f = $this->childFamily($uid);
        if ($f === null) {
            throw new UserError('No sos hijo/a de ninguna familia.');
        }
        $this->db->exec('DELETE FROM family_children WHERE user_id = ?', [$uid]);
        return $f;
    }

    /** Un padre/madre saca a un hijo de la familia. @return array<string,mixed> la familia */
    public function disown(int $parent, int $child): array
    {
        $f = $this->childFamily($child);
        if ($f === null || ((int) $f['parent_a'] !== $parent && (int) $f['parent_b'] !== $parent)) {
            throw new UserError('Esa persona no es hijo/a tuyo.');
        }
        $this->db->exec('DELETE FROM family_children WHERE user_id = ?', [$child]);
        return $f;
    }

    /* ------------------------------------------------------------------
     * Árbol genealógico
     * ---------------------------------------------------------------- */

    /** @return list<int> padres (la familia donde es hijo) */
    public function parentsOf(int $uid): array
    {
        $f = $this->childFamily($uid);
        return $f === null ? [] : [(int) $f['parent_a'], (int) $f['parent_b']];
    }

    /** @return list<int> hijos de todas sus familias */
    public function childrenOf(int $uid): array
    {
        $out = [];
        foreach ($this->parentFamilies($uid) as $f) {
            array_push($out, ...$this->childrenOfFamily((int) $f['id']));
        }
        return array_values(array_unique($out));
    }

    /** @return list<int> hermanos (incluye medio hermanos: otros hijos de cualquiera de sus padres) */
    public function siblingsOf(int $uid): array
    {
        $out = [];
        foreach ($this->parentsOf($uid) as $p) {
            array_push($out, ...$this->childrenOf($p));
        }
        return array_values(array_diff(array_unique($out), [$uid]));
    }

    /** @return list<int> */
    public function grandparentsOf(int $uid): array
    {
        $out = [];
        foreach ($this->parentsOf($uid) as $p) {
            array_push($out, ...$this->parentsOf($p));
        }
        return array_values(array_unique($out));
    }

    /** @return list<int> tíos (hermanos de los padres) */
    public function unclesOf(int $uid): array
    {
        $parents = $this->parentsOf($uid);
        $out = [];
        foreach ($parents as $p) {
            array_push($out, ...$this->siblingsOf($p));
        }
        return array_values(array_diff(array_unique($out), $parents));
    }

    /** @return list<int> primos (hijos de los tíos) */
    public function cousinsOf(int $uid): array
    {
        $out = [];
        foreach ($this->unclesOf($uid) as $u) {
            array_push($out, ...$this->childrenOf($u));
        }
        $excluded = array_merge([$uid], $this->siblingsOf($uid));
        return array_values(array_diff(array_unique($out), $excluded));
    }

    /** @return list<int> todos los antepasados (padres, abuelos, ...) */
    public function ancestors(int $uid, int $depth = 12): array
    {
        $out = [];
        $level = [$uid];
        for ($i = 0; $i < $depth && $level !== []; $i++) {
            $next = [];
            foreach ($level as $u) {
                foreach ($this->parentsOf($u) as $p) {
                    if (!in_array($p, $out, true) && $p !== $uid) {
                        $out[] = $p;
                        $next[] = $p;
                    }
                }
            }
            $level = $next;
        }
        return $out;
    }

    /** Familia directa: antepasado/descendiente o hermanos. */
    public function closeRelatives(int $a, int $b): bool
    {
        return in_array($b, $this->ancestors($a), true)
            || in_array($a, $this->ancestors($b), true)
            || in_array($b, $this->siblingsOf($a), true);
    }

    /**
     * Todo lo que muestra /familia.
     * @return array{surname:?string, partner:?int, status:?string, parents:list<int>, children:list<int>, siblings:list<int>, grandparents:list<int>, uncles:list<int>, cousins:list<int>}
     */
    public function tree(int $uid): array
    {
        $r = $this->relationship($uid);
        return [
            'surname' => $this->surnameOf($uid),
            'partner' => $r['partner'] ?? null,
            'status' => $r['status'] ?? null,
            'parents' => $this->parentsOf($uid),
            'children' => $this->childrenOf($uid),
            'siblings' => $this->siblingsOf($uid),
            'grandparents' => $this->grandparentsOf($uid),
            'uncles' => $this->unclesOf($uid),
            'cousins' => $this->cousinsOf($uid),
        ];
    }

    /**
     * Familias ordenadas por tamaño (padres + hijos).
     * @return list<array{id:int, surname:?string, parent_a:int, parent_b:int, children:int, size:int, married:bool}>
     */
    public function familiesBySize(int $limit = 15): array
    {
        $rows = $this->db->all(
            'SELECT f.id, f.surname, f.parent_a, f.parent_b,
                    (SELECT COUNT(*) FROM family_children c WHERE c.family_id = f.id) AS children,
                    (SELECT COUNT(*) FROM relationships r WHERE r.id = f.relationship_id AND r.ended_at IS NULL) AS married
             FROM families f ORDER BY children DESC, f.id ASC LIMIT ?',
            [$limit]
        );
        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'surname' => $r['surname'] !== null ? (string) $r['surname'] : null,
            'parent_a' => (int) $r['parent_a'],
            'parent_b' => (int) $r['parent_b'],
            'children' => (int) $r['children'],
            'size' => 2 + (int) $r['children'],
            'married' => (int) $r['married'] > 0,
        ], $rows);
    }
}
