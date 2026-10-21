<?php

namespace SonicGame\Save;

/**
 * Ghost of the fastest run of each level. While an act is played, Sonic's position and sprite frame are
 * recorded every 1/60 s of level time; when the act is completed faster than the saved run (or with no run
 * saved yet), it becomes the ghost: var/ghosts/level{N}.json.gz = {time, frames: [[x, y, sx, sy, sw, sh, flip]]}.
 * A run restarted from a checkpoint after a death is not kept (the ghost would jump). A level of the site has a
 * key of its own ("site<id>-<version>", Game::ghostKey), and the replay of its record on the site (setRecord) runs
 * instead of the player's own run when it is faster.
 */
class GhostRecorder
{
    private int|string|null $level = null;
    private array $frames = [];
    private bool $valid = false;
    private float $lastTime = -1;
    /** @var array<int, ?array> best run by level, loaded on demand */
    private array $best = [];

    public function __construct(private string $dir)
    {
    }

    /** New attempt of the level (start, or full restart: valid; restart from a checkpoint: not kept). */
    public function begin(int|string $level, bool $valid = true): void
    {
        $this->level = $level;
        $this->frames = [];
        $this->valid = $valid;
        $this->lastTime = -1;
    }

    public function record(int|string $level, float $time, float $x, float $y, array $frame): void
    {
        // the timer went back to 0 (restart) or another level: a new attempt
        if ($level !== $this->level || $time < $this->lastTime) $this->begin($level);
        $this->lastTime = $time;
        $index = (int)floor($time * 60);
        $entry = [(int)round($x), (int)round($y), ...$frame];
        for ($i = count($this->frames); $i <= $index; $i++) {
            $this->frames[$i] = $entry;
        }
    }

    public function invalidate(): void
    {
        $this->valid = false;
    }

    /** The act is complete in $time seconds: kept when it is the fastest. */
    public function finish(int|string $level, float $time): void
    {
        if (!$this->valid || $level !== $this->level || !$this->frames) return;
        $best = $this->load($level);
        if ($best !== null && $best['time'] <= $time) return;
        $this->best[$level] = ['time' => $time, 'frames' => $this->frames];
        @mkdir($this->dir, 0777, true);
        @file_put_contents($this->file($level), gzencode(json_encode($this->best[$level])));
        $this->valid = false;
    }

    /** Title menu > options > erase: every saved run is deleted. */
    public function clearAll(): void
    {
        foreach (glob($this->dir . '/level*.json.gz') ?: [] as $file) {
            @unlink($file);
        }
        $this->best = [];
        $this->valid = false;
    }

    /** Frame of the best run at $time: [x, y, sx, sy, sw, sh, flip], or null (none, or the run is over). */
    public function frameAt(int|string $level, float $time): ?array
    {
        $best = $this->fastest($level);
        if ($best === null) return null;
        return $best['frames'][(int)floor($time * 60)] ?? null;
    }

    public function bestTime(int|string $level): ?float
    {
        return $this->fastest($level)['time'] ?? null;
    }

    /** @var array<string, ?array> the record of a level of the site (its replay), by key; null: not readable */
    private array $records = [];

    /**
     * The record of the level of the site $key: its replay ($file, ReplayRecorder's format: Sonic 30 times a second)
     * done in $time, read once.
     */
    public function setRecord(string $key, string $file, float $time): void
    {
        if (array_key_exists($key, $this->records)) return;
        $this->records[$key] = null;
        $data = is_file($file) ? json_decode((string)@gzdecode((string)file_get_contents($file)), true) : null;
        if (!is_array($data) || !is_array($data['frames'] ?? null)) return;
        $follow = (int)($data['follow'] ?? 0);
        $step = 60 / max(1, (int)($data['fps'] ?? 30));
        $frames = [];
        $last = null;
        foreach ($data['frames'] as $i => $row) {
            $f = is_array($row) ? ($row[$follow] ?? null) : null;
            // the ghost has Sonic's sheet only: a pose of the ROM's sheet (flags bit 1) keeps the one before
            if (is_array($f) && count($f) >= 7 && !((int)$f[6] & 2)) $last = [(int)$f[0], (int)$f[1], (int)$f[2], (int)$f[3], (int)$f[4], (int)$f[5], (int)$f[6] & 1];
            elseif (is_array($f) && $last !== null) $last = [(int)$f[0], (int)$f[1], ...array_slice($last, 2)];
            for ($k = (int)round($i * $step); $k < (int)round(($i + 1) * $step); $k++) $frames[$k] = $last;
        }
        if ($frames) $this->records[$key] = ['time' => $time, 'frames' => $frames];
    }

    /** The faster of the player's best run and the record of the level. */
    private function fastest(int|string $level): ?array
    {
        $own = $this->load($level);
        $record = is_string($level) ? ($this->records[$level] ?? null) : null;
        if ($record === null) return $own;
        return $own === null || $record['time'] < $own['time'] ? $record : $own;
    }

    private function load(int|string $level): ?array
    {
        if (!array_key_exists($level, $this->best)) {
            $data = is_file($this->file($level)) ? json_decode((string)gzdecode((string)file_get_contents($this->file($level))), true) : null;
            $this->best[$level] = is_array($data) && isset($data['time'], $data['frames']) ? $data : null;
        }
        return $this->best[$level];
    }

    private function file(int|string $level): string
    {
        return $this->dir . '/level' . $level . '.json.gz';
    }
}
