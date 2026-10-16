<?php
declare(strict_types=1);

namespace M4W\Service;

use M4W\Core\Audit;
use M4W\Core\Settings;

/**
 * The tasks of the scheduled run (bin/cron.php), each on its own:
 *  - an error in one task is logged and the next tasks run all the same;
 *  - a task that stops the whole run (fatal error, memory, killed) is noticed at the next run,
 *    which starts again a minute later; after MAX_CRASHES in a row it is set aside for an
 *    hour ("parked"), logged and shown in Supervision, so that it no longer blocks the others.
 */
final class Jobs
{
    public const MAX_CRASHES = 3;
    public const PARK_SECONDS = 3600;

    /** @return array<string,array{crashes:int,parked_until:int,last_error:string,last_ok:int,error_at:int}> */
    public static function state(): array
    {
        $s = json_decode((string) Settings::get('system.jobs', ''), true);
        return is_array($s) ? $s : [];
    }

    private static function save(array $state): void
    {
        Settings::set('system.jobs', json_encode($state));
    }

    private static function entry(array $state, string $name): array
    {
        return ($state[$name] ?? []) + ['crashes' => 0, 'parked_until' => 0, 'last_error' => '', 'last_ok' => 0, 'error_at' => 0];
    }

    /**
     * At the start of a run: the task the previous run was in when it stopped counts one crash.
     * Returns the name of that task ('' if the previous run ended normally).
     */
    public static function recover(?int $now = null): string
    {
        $now ??= time();
        $running = (string) Settings::get('system.job_running', '');
        if ($running === '') {
            return '';
        }
        Settings::set('system.job_running', '');
        $state = self::state();
        $e = self::entry($state, $running);
        $e['crashes']++;
        $e['last_error'] = 'the scheduled run stopped during this task';
        $e['error_at'] = $now;
        error_log('[m4w] cron: stopped during "' . $running . '" (' . $e['crashes'] . ' time(s) in a row)');
        if ($e['crashes'] >= self::MAX_CRASHES) {
            $e['parked_until'] = $now + self::PARK_SECONDS;
            $e['crashes'] = 0;
            error_log('[m4w] cron: "' . $running . '" set aside for ' . (self::PARK_SECONDS / 60) . ' min');
            Audit::log('cron.parked', $running, ['minutes' => self::PARK_SECONDS / 60]);
        }
        $state[$running] = $e;
        self::save($state);
        return $running;
    }

    /**
     * Run one task. Returns its result, or null when it failed or is set aside.
     * @template T
     * @param callable():T $fn
     * @return T|null
     */
    public static function run(string $name, callable $fn, ?int $now = null): mixed
    {
        $now ??= time();
        $state = self::state();
        $e = self::entry($state, $name);
        if ($e['parked_until'] > $now) {
            return null;
        }
        Settings::set('system.job_running', $name);
        try {
            $result = $fn();
            $e['crashes'] = 0;
            $e['last_ok'] = time();
            $e['parked_until'] = 0;
        } catch (\Throwable $ex) {
            // An error is not a crash: logged, the run goes on with the next task.
            $result = null;
            $e['last_error'] = mb_substr($ex->getMessage(), 0, 300);
            $e['error_at'] = time();
            error_log('[m4w] cron task "' . $name . '": ' . $ex->getMessage());
        } finally {
            Settings::set('system.job_running', '');
        }
        $state = self::state();
        $state[$name] = $e;
        self::save($state);
        return $result;
    }

    /** Tasks set aside now, for Supervision. @return array<string,array> */
    public static function parked(?int $now = null): array
    {
        $now ??= time();
        return array_filter(self::state(), static fn($e) => (int) ($e['parked_until'] ?? 0) > $now);
    }

    /** Tasks that failed in the last hour (with no success since). @return array<string,array> */
    public static function failing(?int $now = null): array
    {
        $now ??= time();
        return array_filter(self::state(), static fn($e) => (int) ($e['error_at'] ?? 0) > $now - 3600 && (int) ($e['error_at'] ?? 0) > (int) ($e['last_ok'] ?? 0));
    }
}
