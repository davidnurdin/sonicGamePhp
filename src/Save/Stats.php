<?php

namespace SonicGame\Save;

/**
 * The player's statistics, totals kept in var/stats.json (in the browser: IndexedDB, like the other saves).
 * The web page sends what they gained to the site (sonic.mimichat.fr, page Statistiques) when the player came
 * from his account there: the game only counts.
 */
final class Stats
{
    /** Counters: seconds played (solo / online match / match against the computer), then numbers. */
    public const KEYS = ['time_solo', 'time_online', 'time_versus', 'jumps', 'rings', 'enemies', 'springs', 'deaths', 'acts',
        'emeralds', 'matches_online', 'wins_online', 'matches_versus', 'wins_versus'];

    private array $values;
    private bool $dirty = false;
    private float $lastSave = 0;

    public function __construct(private string $file)
    {
        $saved = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $this->values = array_fill_keys(self::KEYS, 0);
        if (is_array($saved)) {
            foreach (self::KEYS as $key) $this->values[$key] = max(0, (float)($saved[$key] ?? 0));
        }
    }

    public function add(string $key, float $amount = 1): void
    {
        if ($amount <= 0 || !isset($this->values[$key])) return;
        $this->values[$key] += $amount;
        $this->dirty = true;
    }

    public function get(string $key): float
    {
        return $this->values[$key] ?? 0;
    }

    /** Written at most every 10 seconds ($now: right away, at the end of a match or an act). */
    public function save(bool $now = false): void
    {
        if (!$this->dirty || (!$now && microtime(true) - $this->lastSave < 10)) return;
        $this->lastSave = microtime(true);
        $this->dirty = false;
        $values = $this->values;
        foreach (['time_solo', 'time_online', 'time_versus'] as $key) $values[$key] = round($values[$key], 1);
        @mkdir(dirname($this->file), 0777, true);
        @file_put_contents($this->file, json_encode($values));
    }
}
