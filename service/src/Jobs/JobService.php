<?php

declare(strict_types=1);

namespace Claudia\Jobs;

use Claudia\Clock;
use Claudia\Config;
use Claudia\Db;
use Claudia\Economy\PromotionService;
use Claudia\Economy\Wallet;
use Claudia\Log;
use Claudia\Stats\StatsService;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Trabajos definidos en jobs.json. Cada trabajo tiene empleadores (con multiplicadores
 * propios) y niveles. El sueldo se cobra cada 24 h con /cobrar; el rendimiento es un
 * aleatorio ponderado por la actividad del jugador desde el último cobro y decide
 * bonus, ascensos y despidos.
 */
final class JobService
{
    /** @var callable():float generador aleatorio 0..1 (inyectable para tests) */
    private $rand;

    public function __construct(
        private readonly Config $config,
        private readonly Db $db,
        private readonly Wallet $wallet,
        private readonly PromotionService $promos,
        private readonly StatsService $stats,
    ) {
        $this->rand = static fn (): float => random_int(0, 1_000_000) / 1_000_000;
    }

    public function setRandom(callable $rand): void
    {
        $this->rand = $rand;
    }

    /** @return array<string, array> */
    public function jobs(): array
    {
        $out = [];
        foreach ($this->config->array('jobs.jobs') as $id => $job) {
            if (is_array($job) && ($job['enabled'] ?? true)) {
                $out[(string) $id] = $job;
            }
        }
        return $out;
    }

    public function job(string $id): array
    {
        $jobs = $this->jobs();
        $id = mb_strtolower($id);
        if (!isset($jobs[$id])) {
            throw new UserError('Ese trabajo no existe. Mirá la lista con /trabajos.');
        }
        return $jobs[$id];
    }

    public function employer(array $job, string $id): array
    {
        $id = mb_strtolower($id);
        $employers = (array) ($job['employers'] ?? []);
        if (!isset($employers[$id])) {
            throw new UserError('Ese empleador no existe. Opciones: ' . implode(', ', array_keys($employers)) . '.');
        }
        return $employers[$id];
    }

    /** @return array<string,mixed>|null */
    public function employment(int $userId): ?array
    {
        return $this->db->one('SELECT * FROM employment WHERE user_id = ?', [$userId]);
    }

    /** Resumen para bancos, perfil e IA. @return array{job:string, employer:string, level:int, salary:int, jobName:string, employerName:string, levelName:string}|null */
    public function info(int $userId): ?array
    {
        $e = $this->employment($userId);
        if ($e === null) {
            return null;
        }
        $jobs = $this->jobs();
        $job = $jobs[$e['job']] ?? null;
        if ($job === null) {
            return null;
        }
        $employer = $job['employers'][$e['employer']] ?? [];
        $level = $this->levelDef($job, (int) $e['level']);
        return [
            'job' => (string) $e['job'],
            'employer' => (string) $e['employer'],
            'level' => (int) $e['level'],
            'salary' => $this->salary($level, $employer),
            'jobName' => (string) ($job['name'] ?? $e['job']),
            'employerName' => (string) ($employer['name'] ?? $e['employer']),
            'levelName' => (string) ($level['name'] ?? ('Nivel ' . ((int) $e['level'] + 1))),
        ];
    }

    /** @return array{level:int, levelName:string} */
    public function apply(int $userId, string $jobId, string $employerId): array
    {
        if ($this->employment($userId) !== null) {
            throw new UserError('Ya tenés trabajo. Si querés cambiar, primero /renunciar.');
        }
        $jobId = mb_strtolower($jobId);
        $employerId = mb_strtolower($employerId);
        $job = $this->job($jobId);
        $employer = $this->employer($job, $employerId);

        $req = (array) ($job['requirements'] ?? []);
        $stats = $this->stats->get($userId);
        if (isset($req['min_playtime_hours']) && $stats['playtime'] < (float) $req['min_playtime_hours'] * 3600) {
            throw new UserError("Para {$job['name']} necesitás {$req['min_playtime_hours']} horas jugadas.");
        }
        if (isset($req['min_kills']) && $stats['kills'] < (int) $req['min_kills']) {
            throw new UserError("Para {$job['name']} necesitás {$req['min_kills']} kills.");
        }
        if (isset($req['min_coins']) && $this->wallet->balance($userId) < (int) $req['min_coins']) {
            throw new UserError("Para {$job['name']} necesitás tener " . Text::coins((int) $req['min_coins']) . ' URU Coins.');
        }

        $now = Clock::now();
        $last = $this->db->one('SELECT * FROM job_history WHERE user_id = ? AND job = ? ORDER BY id DESC LIMIT 1', [$userId, $jobId]);
        $level = 0;
        if ($last !== null) {
            $cool = (int) round($this->config->float('jobs.rehire_cooldown_hours', 24) * 3600);
            if ($last['reason'] === 'despido' && $now - (int) $last['created_at'] < $cool) {
                throw new UserError('Te echaron hace poco de ese trabajo. Volvé a intentar en ' . Text::duration($cool - ($now - (int) $last['created_at'])) . '.');
            }
            $penalty = $last['reason'] === 'despido'
                ? $this->config->int('jobs.rehire_level_penalty', 1)
                : $this->config->int('jobs.resign_level_penalty', 1);
            $level = max(0, (int) $last['level'] - $penalty);
        }
        $level = min($level, count((array) $job['levels']) - 1);
        $this->db->exec(
            'INSERT INTO employment(user_id, job, employer, level, hired_at, level_since, last_paid_at) VALUES(?, ?, ?, ?, ?, ?, NULL)',
            [$userId, $jobId, $employerId, $level, $now, $now]
        );
        Log::info('Contratado', ['user' => $userId, 'job' => $jobId, 'employer' => $employerId, 'level' => $level]);
        return ['level' => $level, 'levelName' => (string) ($this->levelDef($job, $level)['name'] ?? '')];
    }

    public function quit(int $userId): void
    {
        $e = $this->employment($userId);
        if ($e === null) {
            throw new UserError('No tenés trabajo.');
        }
        $this->leave($userId, $e, 'renuncia');
    }

    /** Segundos que faltan para poder cobrar (0 = ya puede). */
    public function secondsToClaim(int $userId): int
    {
        $e = $this->employment($userId);
        if ($e === null || $e['last_paid_at'] === null) {
            return 0;
        }
        $cool = (int) round($this->config->float('jobs.claim_cooldown_hours', 24) * 3600);
        return max(0, (int) $e['last_paid_at'] + $cool - Clock::now());
    }

    /**
     * Cobra el sueldo del día.
     * @return array{salary:int, bonus:int, performance:float, promoted:bool, fired:bool, levelName:string, jobName:string}
     */
    public function claim(int $userId): array
    {
        $e = $this->employment($userId);
        if ($e === null) {
            throw new UserError('No tenés trabajo. Mirá /trabajos.');
        }
        $wait = $this->secondsToClaim($userId);
        if ($wait > 0) {
            throw new UserError('Todavía no podés cobrar. Faltan ' . Text::duration($wait) . '.');
        }
        $job = $this->job((string) $e['job']);
        $employer = $this->employer($job, (string) $e['employer']);
        $levelIdx = (int) $e['level'];
        $level = $this->levelDef($job, $levelIdx);
        $now = Clock::now();

        $performance = $this->performance($e);
        $salary = $this->salary($level, $employer);

        $bonus = 0;
        $b = (array) ($level['bonus'] ?? []);
        $chance = (float) ($b['chance'] ?? 0) * (float) ($employer['bonus_multiplier'] ?? 1) * $this->promos->multiplier('bonus_chance');
        // Rendir bien sube la chance de bonus (hasta x1.5), rendir mal la baja.
        $chance *= 0.5 + $performance;
        if ($chance > 0 && ($this->rand)() < min(1.0, $chance)) {
            $min = (int) ($b['min'] ?? 0);
            $max = max($min, (int) ($b['max'] ?? $min));
            $bonus = (int) round(($min + ($max - $min) * ($this->rand)()) * (float) ($employer['bonus_multiplier'] ?? 1));
        }

        $promoted = false;
        $fired = false;
        $levels = (array) $job['levels'];
        $promote = (array) ($level['promote'] ?? []);
        $pmult = (float) ($employer['promotion_multiplier'] ?? 1);
        $daysAtLevel = ($now - (int) $e['level_since']) / 86400;
        if (
            $levelIdx < count($levels) - 1
            && $promote !== []
            && $daysAtLevel >= (float) ($promote['min_days'] ?? 1) / max(0.1, $pmult)
            && $performance >= (float) ($promote['min_performance'] ?? 0.6)
        ) {
            $promoted = true;
        }
        $fire = (array) ($job['fire'] ?? []);
        if (
            !$promoted
            && $performance < (float) ($fire['performance_below'] ?? 0.2)
            && ($this->rand)() < (float) ($fire['chance'] ?? 0.5) * (float) ($employer['fire_multiplier'] ?? 1)
        ) {
            $fired = true;
        }

        $jobName = (string) ($job['name'] ?? $e['job']);
        $this->db->transaction(function () use ($userId, $salary, $bonus, $promoted, $fired, $levelIdx, $now, $e, $jobName): void {
            $this->wallet->credit($userId, $salary, 'salary', $jobName);
            if ($bonus > 0) {
                $this->wallet->credit($userId, $bonus, 'bonus', $jobName);
            }
            if ($fired) {
                $this->leave($userId, $e, 'despido');
                return;
            }
            $this->db->exec(
                'UPDATE employment SET last_paid_at = ?, act_playtime = 0, act_kills = 0, act_messages = 0, level = ?, level_since = ? WHERE user_id = ?',
                [$now, $promoted ? $levelIdx + 1 : $levelIdx, $promoted ? $now : (int) $e['level_since'], $userId]
            );
        });

        $newLevel = $this->levelDef($job, $promoted ? $levelIdx + 1 : $levelIdx);
        return [
            'salary' => $salary,
            'bonus' => $bonus,
            'performance' => $performance,
            'promoted' => $promoted,
            'fired' => $fired,
            'levelName' => (string) ($newLevel['name'] ?? ''),
            'jobName' => $jobName,
        ];
    }

    /**
     * Rendimiento 0..1: mezcla la actividad desde el último cobro (tiempo jugado, kills,
     * mensajes, con los pesos de jobs.json) con un componente aleatorio.
     */
    public function performance(array $employment): float
    {
        $p = $this->config->array('jobs.performance');
        $w = (array) ($p['weights'] ?? []);
        $activity = ((int) $employment['act_playtime'] / 60) * (float) ($w['playtime_minutes'] ?? 1)
            + (int) $employment['act_kills'] * (float) ($w['kills'] ?? 1)
            + (int) $employment['act_messages'] * (float) ($w['messages'] ?? 0.2);
        $target = max(1.0, (float) ($p['target_activity'] ?? 120));
        $a = min(1.0, $activity / $target);
        $rw = min(1.0, max(0.0, (float) ($p['random_weight'] ?? 0.35)));
        return round((1 - $rw) * $a + $rw * ($this->rand)(), 3);
    }

    public function levelDef(array $job, int $level): array
    {
        $levels = array_values((array) ($job['levels'] ?? []));
        return $levels[max(0, min($level, count($levels) - 1))] ?? [];
    }

    public function salary(array $level, array $employer): int
    {
        return (int) round((int) ($level['salary'] ?? 0) * (float) ($employer['salary_multiplier'] ?? 1) * $this->promos->multiplier('salary'));
    }

    private function leave(int $userId, array $e, string $reason): void
    {
        $this->db->transaction(function () use ($userId, $e, $reason): void {
            $this->db->exec(
                'INSERT INTO job_history(user_id, job, employer, level, reason, created_at) VALUES(?, ?, ?, ?, ?, ?)',
                [$userId, $e['job'], $e['employer'], (int) $e['level'], $reason, Clock::now()]
            );
            $this->db->exec('DELETE FROM employment WHERE user_id = ?', [$userId]);
        });
        Log::info('Deja el trabajo', ['user' => $userId, 'job' => $e['job'], 'reason' => $reason]);
    }
}
