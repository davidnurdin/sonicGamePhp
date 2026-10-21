<?php

namespace SonicGame\Save;

/**
 * The replay of a game: an act played alone, a match against the computer or online. Every Sonic's position and
 * sprite frame 30 times a second, and the blocks that changed (rings taken, bridges), so that ReplayPlayer can
 * show it again in the same level. Written when the act / the match is over (var/replays/last.json.gz); the web
 * page sends it to the player's account on the site (history, "Revoir").
 *
 * Format (JSON, gzip): {v, kind: solo|versus|online, level, mode, match (online: the same id for every player of
 * the match), date, follow (the Sonic of this player), fps, entities: [{name, tex, rgb}], frames: [[per entity:
 * [x, y, sx, sy, sw, sh, flags (1 flipped, 2 ROM sheet)] or 0]], tiles: [[frame, x, y, value]], result: [[name, score]],
 * site (level LevelManager::CUSTOM: the level of the site, {id, lt, name}, downloaded again to watch it)}.
 */
final class ReplayRecorder
{
    public const VERSION = 1;
    public const FPS = 30;
    /** At most 15 minutes (a long act): then it is not kept. */
    private const MAX_FRAMES = 15 * 60 * self::FPS;

    private ?array $meta = null;
    private array $frames = [];
    private array $tiles = [];
    private float $clock = 0;

    public function __construct(private string $file)
    {
    }

    /** @param list<array{0: string, 1: string, 2: array}> $entities [name, texture, rgb] */
    public function begin(string $kind, int $level, string $mode, array $entities, int $follow, ?string $match = null, ?array $site = null): void
    {
        $this->meta = ['v' => self::VERSION, 'kind' => $kind, 'level' => $level, 'mode' => $mode, 'match' => $match, 'site' => $site,
            'date' => time(), 'follow' => $follow, 'fps' => self::FPS,
            'entities' => array_map(fn(array $e) => ['name' => $e[0], 'tex' => $e[1], 'rgb' => array_values($e[2])], $entities)];
        $this->frames = [];
        $this->tiles = [];
        $this->clock = 0;
    }

    public function isRecording(?string $kind = null, ?int $level = null): bool
    {
        return $this->meta !== null && ($kind === null || $this->meta['kind'] === $kind)
            && ($level === null || $this->meta['level'] === $level);
    }

    /**
     * $deltaTime of play went by; $states: per entity [x, y, [sx, sy, sw, sh, flip, rom]] or null (not drawn).
     * A frame is kept every 1/30 s.
     */
    public function sample(float $deltaTime, array $states): void
    {
        if ($this->meta === null) return;
        $this->clock += $deltaTime;
        $due = (int)floor($this->clock * self::FPS) + 1;
        if (count($this->frames) >= $due) return;
        if (count($this->frames) >= self::MAX_FRAMES) {
            $this->cancel();
            return;
        }
        $frame = [];
        foreach ($states as $state) {
            if ($state === null) {
                $frame[] = 0;
                continue;
            }
            [$x, $y, $f] = $state;
            $frame[] = [(int)round($x), (int)round($y), (int)$f[0], (int)$f[1], (int)$f[2], (int)$f[3],
                (!empty($f[4]) ? 1 : 0) | (!empty($f[5]) ? 2 : 0)];
        }
        // a missed frame (slow machine): the same again
        while (count($this->frames) < $due) $this->frames[] = $frame;
    }

    /** A block of the level changed (rings taken...). */
    public function tile(int $x, int $y, int $value): void
    {
        if ($this->meta !== null) $this->tiles[] = [count($this->frames), $x, $y, $value];
    }

    /**
     * Over: written for the page, which sends it to the site. $result: [[name, score text]] by rank.
     * Returns the file, or null (nothing recorded).
     */
    public function finish(array $result = []): ?string
    {
        if ($this->meta === null || count($this->frames) < self::FPS) {
            $this->cancel();
            return null;
        }
        $data = $this->meta + ['frames' => $this->frames, 'tiles' => $this->tiles, 'result' => $result,
            'duration' => round(count($this->frames) / self::FPS, 1)];
        $this->cancel();
        @mkdir(dirname($this->file), 0777, true);
        return @file_put_contents($this->file, gzencode(json_encode($data), 6)) !== false ? $this->file : null;
    }

    public function cancel(): void
    {
        $this->meta = null;
        $this->frames = [];
        $this->tiles = [];
    }
}
