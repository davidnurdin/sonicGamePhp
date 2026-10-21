<?php

namespace SonicGame\Multiplayer;

use SonicGame\Level\Level;

/**
 * The way through a map for the autoplay (F7): BotBrain only runs at its target, which is not enough in a maze
 * (Green Hill 2, Labyrinth...). An A* on the 32 px blocks where Sonic can stand: a block that is not a wall, with
 * a slope in it or something solid under it (the collisions of a map are its surfaces only: the inside of the
 * ground is "empty", hence never a block of air alone). From one of them: walk to the next one (one block up or
 * down: a slope), fall from an edge, or jump (at most JUMP blocks up; no such way: higher, a spring or the warp).
 * BotBrain gets the block a few steps further on that way; no progress on it for a while: Sonic is put further
 * on it.
 */
class BotRoute
{
    private const STEPS_AHEAD = 3;
    /** Closer than that to the target: straight at it (an opponent, the capsule, the boss). */
    private const DIRECT = 160;
    private const REPLAN = 0.5;
    private const STUCK = 8.0;
    /** A* gives up after that many blocks (a target nobody can reach: the closest block seen). */
    private const MAX_NODES = 20000;
    /** Blocks a jump goes up; the way without one: a spring. */
    private const JUMP = 3;
    private const SPRING = 7;
    /** Block behaviours of the springs that throw up (CollisionSystem::B_SPRING_*) => blocks they throw Sonic up. */
    private const SPRINGS_UP = [4 => 16, 9 => 16, 18 => 11, 19 => 16, 20 => 22];

    /** tile value => its solid pixels; for one map (its object id). */
    private array $solid = [];
    private int $mapOf = 0;
    /** [[col, row], ...] from Sonic's block. */
    private array $path = [];
    private float $replan = 0;
    private ?int $goal = null;
    private float $best = INF;
    private float $stuck = 0;

    public function reset(): void
    {
        $this->path = [];
        $this->replan = 0;
        $this->best = INF;
        $this->stuck = 0;
    }

    /**
     * @param array{0: float, 1: float} $target where to go (world pixels)
     * @return array{target: array{0: float, 1: float}, direct: bool, warp: ?array{0: float, 1: float}} the point
     *   to give BotBrain; warp: where to put Sonic (top left of his box) when he is stuck
     */
    public function next(Level $level, float $x, float $y, array $target, float $deltaTime): array
    {
        if ($this->mapOf !== spl_object_id($level)) {
            $this->mapOf = spl_object_id($level);
            $this->solid = [];
            $this->goal = null;
            $this->reset();
        }
        $cx = $x + 12;
        $cy = $y + 16;
        if (hypot($target[0] - $cx, $target[1] - $cy) < self::DIRECT) {
            $this->stuck = 0;
            $this->best = INF;
            return ['target' => $target, 'direct' => true, 'warp' => null];
        }

        $width = (int)$level->getMapWidth();
        $height = (int)$level->getMapHeight();
        $start = $this->standNear($level, max(0, min($width - 1, intdiv((int)$cx, 32))), max(0, min($height - 1, intdiv((int)$cy, 32))));
        $goal = $this->goalBlock($level, $target, $width, $height);
        $this->replan -= $deltaTime;
        // a goal moved far (not the row of a jump): a new way, the progress counts again from there
        $moved = $this->goal === null || abs($goal % $width - $this->goal % $width) > 2
            || abs(intdiv($goal, $width) - intdiv($this->goal, $width)) > 4;
        if ($moved) $this->best = INF;
        if ($this->replan <= 0 || $moved || !in_array($start, array_slice($this->path, 0, 3), true)) {
            $this->goal = $goal;
            $this->path = $this->search($level, $start, $goal, $width, $height, self::JUMP);
            if (end($this->path) !== [$goal % $width, intdiv($goal, $width)]) {
                $this->path = $this->search($level, $start, $goal, $width, $height, self::SPRING);
            }
            $this->replan = self::REPLAN;
        }
        // from Sonic's block on
        $at = array_search($start, $this->path, true);
        if ($at !== false) $this->path = array_slice($this->path, $at);

        $remaining = count($this->path);
        if ($remaining < $this->best - 0.5) {
            $this->best = $remaining;
            $this->stuck = 0;
        } else {
            $this->stuck += $deltaTime;
        }
        if ($this->stuck > self::STUCK && $remaining > 1) {
            $this->stuck = 0;
            $this->best = INF;
            $this->replan = 0;
            [$col, $row] = $this->path[min(8, $remaining - 1)];
            $px = $col * 32 + 16;
            $ground = $level->findSurfaceY($px, $row * 32, 64) ?? $row * 32 + 32;
            return ['target' => $target, 'direct' => false, 'warp' => [$px - 12, $ground - 32]];
        }

        [$col, $row] = $this->path[min(self::STEPS_AHEAD, max(0, $remaining - 1))] ?? $start;
        return ['target' => [$col * 32 + 16, $row * 32], 'direct' => false, 'warp' => null];
    }

    /**
     * Sonic's block: on a steep slope his centre is in the block under the surface (empty: the inside of the
     * ground), so the nearest block to stand in around it; none (high in the air): his own, he falls from it.
     */
    private function standNear(Level $level, int $col, int $row): array
    {
        foreach ([[0, 0], [0, -1], [0, 1], [-1, 0], [1, 0], [-1, -1], [1, -1]] as [$dc, $dr]) {
            if ($this->canStand($level, $col + $dc, $row + $dr)) return [$col + $dc, $row + $dr];
        }
        return [$col, $row];
    }

    /** The block of the target, kept inside the map: the nearest one of its column (or next to it) to stand in. */
    private function goalBlock(Level $level, array $target, int $width, int $height): int
    {
        $col = max(0, min($width - 1, intdiv(max(0, (int)$target[0]), 32)));
        $row = max(0, min($height - 1, intdiv(max(0, (int)$target[1]), 32)));
        foreach ([0, -1, 1, -2, 2] as $dc) {
            $c = $col + $dc;
            if ($c < 0 || $c >= $width) continue;
            for ($d = 0; $d < $height; $d++) {
                foreach ([$row + $d, $row - $d] as $r) {
                    if ($r >= 0 && $r < $height && $this->canStand($level, $c, $r)) return $r * $width + $c;
                }
            }
        }
        return $row * $width + $col;
    }

    /** A* from $start to the block $goal (row * width + col); unreachable: to the closest block seen. */
    private function search(Level $level, array $start, int $goal, int $width, int $height, int $jump): array
    {
        $gx = $goal % $width;
        $gy = intdiv($goal, $width);
        $first = $start[1] * $width + $start[0];
        $cost = [$first => 0];
        $from = [];
        $done = [];
        $queue = new \SplPriorityQueue();
        $queue->insert($first, 0);
        $closest = $first;
        $closestH = INF;
        $nodes = 0;
        while (!$queue->isEmpty() && $nodes++ < self::MAX_NODES) {
            $cell = $queue->extract();
            if (isset($done[$cell])) continue;
            $done[$cell] = true;
            $col = $cell % $width;
            $row = intdiv($cell, $width);
            $h = abs($gx - $col) + abs($gy - $row);
            if ($h < $closestH) {
                $closestH = $h;
                $closest = $cell;
            }
            if ($cell === $goal) break;
            foreach ($this->moves($level, $col, $row, $height, $jump) as [$c, $r, $step]) {
                $next = $r * $width + $c;
                $g = $cost[$cell] + $step;
                if (isset($cost[$next]) && $cost[$next] <= $g) continue;
                $cost[$next] = $g;
                $from[$next] = $cell;
                $queue->insert($next, -($g + abs($gx - $c) + abs($gy - $r)));
            }
        }
        $path = [];
        for ($cell = $closest; $cell !== null; $cell = $from[$cell] ?? null) {
            $path[] = [$cell % $width, intdiv($cell, $width)];
        }
        return array_reverse($path);
    }

    /** @return list<array{0: int, 1: int, 2: float}> blocks to stand in from ($col, $row), and what it costs */
    private function moves(Level $level, int $col, int $row, int $height, int $jump): array
    {
        // in the air (Sonic jumping, falling: his first block): where he lands
        if (!$this->canStand($level, $col, $row)) {
            $land = $this->fall($level, $col, $row, $height);
            return $land === null || $land === $row ? [] : [[$col, $land, ($land - $row) * 0.5]];
        }
        $moves = [];
        foreach ([-1, 1] as $dx) {
            $c = $col + $dx;
            $walked = false;
            foreach ([0, -1, 1] as $dy) {
                if ($this->canStand($level, $c, $row + $dy)) {
                    $moves[] = [$c, $row + $dy, 1];
                    $walked = true;
                    break;
                }
            }
            if (!$walked && !$this->isWall($level, $c, $row)) {
                // an edge: down to where he lands
                $land = $this->fall($level, $c, $row, $height);
                if ($land !== null) $moves[] = [$c, $land, 1 + ($land - $row) * 0.3];
            }
            // over a pit or an enemy: a jump to the same height, or lower
            for ($far = 2; $far <= 4; $far++) {
                foreach ([0, 1] as $dy) {
                    if ($this->canStand($level, $col + $dx * $far, $row + $dy)) $moves[] = [$col + $dx * $far, $row + $dy, 2 + $far * 0.5];
                }
            }
        }
        // up onto a ledge: a jump, a spring under his feet (cheap), beyond them a spring somewhere (dear)
        $spring = $this->springUp($level, $col, $row);
        for ($dy = 1; $dy <= max($jump, $spring); $dy++) {
            if ($this->isWall($level, $col, $row - $dy)) break;
            for ($dx = -3; $dx <= 3; $dx++) {
                if ($this->canStand($level, $col + $dx, $row - $dy)) {
                    $moves[] = [$col + $dx, $row - $dy, 2 + abs($dx) * 0.5 + ($dy <= $spring ? $dy * 0.3 : $dy * ($dy > self::JUMP ? 3 : 1))];
                }
            }
        }
        return $moves;
    }

    /** Blocks a spring under Sonic's feet in ($col, $row) throws him up, 0 without one. */
    private function springUp(Level $level, int $col, int $row): int
    {
        $behaviours = $level->getBlockBehaviours();
        foreach ([$row, $row + 1] as $r) {
            $tile = $level->getTile($col, $r);
            if ($tile !== null && isset(self::SPRINGS_UP[$behaviours[$tile] ?? 0])) return self::SPRINGS_UP[$behaviours[$tile]];
        }
        return 0;
    }

    /** Falling down column $col from $row: the block he lands in, or null (the bottom of the map: a pit). */
    private function fall(Level $level, int $col, int $row, int $height): ?int
    {
        for ($r = $row; $r < $height; $r++) {
            if ($this->isWall($level, $col, $r)) return $r > $row && $this->canStand($level, $col, $r - 1) ? $r - 1 : null;
            if ($this->canStand($level, $col, $r)) return $r;
        }
        return null;
    }

    /** Sonic can be in it: not a wall, a slope in it or something solid right under it. */
    private function canStand(Level $level, int $col, int $row): bool
    {
        $here = $this->solidOf($level, $col, $row);
        if ($here < 0 || $here > 0.6 * 1024) return false;
        return $here > 0 || $this->solidOf($level, $col, $row + 1) > 0;
    }

    /** Mostly solid (or outside the map). */
    private function isWall(Level $level, int $col, int $row): bool
    {
        $solid = $this->solidOf($level, $col, $row);
        return $solid < 0 || $solid > 0.6 * 1024;
    }

    /** Solid pixels of a block, -1 outside the map. */
    private function solidOf(Level $level, int $col, int $row): int
    {
        if ($col < 0 || $row < 0 || $col >= $level->getMapWidth() || $row >= $level->getMapHeight()) return -1;
        $tile = $level->getTile($col, $row);
        if ($tile === null) return -1;
        if (!isset($this->solid[$tile])) {
            $solid = 0;
            foreach ($level->getTilesColision()[$tile] ?? [] as $pixels) {
                foreach ($pixels as $pixel) {
                    if (!empty($pixel)) $solid++;
                }
            }
            $this->solid[$tile] = $solid;
        }
        return $this->solid[$tile];
    }
}
