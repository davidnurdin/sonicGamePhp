<?php

namespace SonicGame\Multiplayer;

use SonicGame\Entities\Player;
use SonicGame\Level\Level;

/**
 * Joypad of a computer Sonic, from the map around it (Level::isSolidAt) and a target given by Versus (the sign,
 * the flag, a ring, an opponent). It plays like a person, not like a machine:
 *  - it sees the target with a reaction time (it decides again every REACT seconds, on where the target was);
 *  - it runs, slopes included, and jumps only for a real wall, a pit, a target above it, or after pushing
 *    without moving for a while;
 *  - it attacks (a jump, or a roll when it runs fast) when close to an opponent that can be hurt, now and then,
 *    then waits a moment on the ground: it can be hit back;
 *  - an opponent jumping / rolling at it: it backs off, if it notices (two attacking Sonics only bounce);
 *  - it hesitates now and then;
 *  - stuck (no closer to the target for a while): a higher jump, then it asks to be put back on the way.
 * The difficulty (0 easy, 1 medium, 2 hard) sets all of that.
 */
class BotBrain
{
    public const DIFFICULTIES = ['FACILE', 'MOYEN', 'DUR'];

    /**
     * react: seconds between two decisions; pause: hesitations per second; attack: chance to attack when it
     * could; rest: seconds on the ground after an attack [min, max]; dodge: chance to see an attack coming.
     */
    private const LEVELS = [
        ['react' => 0.32, 'pause' => 0.45, 'attack' => 0.35, 'rest' => [1.6, 2.8], 'dodge' => 0.25],
        ['react' => 0.2, 'pause' => 0.25, 'attack' => 0.55, 'rest' => [1.0, 2.0], 'dodge' => 0.5],
        ['react' => 0.12, 'pause' => 0.08, 'attack' => 0.8, 'rest' => [0.6, 1.3], 'dodge' => 0.8],
    ];

    private array $level;
    private float $pause = 0;
    private float $blocked = 0;
    private float $jumpCooldown = 0;
    private float $attackRest = 0;
    private float $bestDistance = INF;
    private float $noProgress = 0;
    /** Until the next decision: seconds left, the direction chosen, where it saw the target, backing off. */
    private float $decide = 0;
    private int $dir = 0;
    private array $seen = [0, 0];
    private bool $backOff = false;
    private bool $wantAttack = false;

    /** @param int $difficulty 0 easy, 1 medium, 2 hard */
    public function __construct(int $difficulty)
    {
        $this->level = self::LEVELS[max(0, min(2, $difficulty))];
    }

    public function reset(): void
    {
        $this->pause = 0;
        $this->blocked = 0;
        $this->jumpCooldown = 0;
        $this->attackRest = 0;
        $this->bestDistance = INF;
        $this->noProgress = 0;
        $this->decide = 0;
    }

    private static function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    /**
     * @param array{0: float, 1: float} $target point to reach (world pixels)
     * @param bool $chase the target is an opponent: jump / roll into it when close
     * @param bool $targetAttacking that opponent is jumping / rolling
     * @return array{dir: int, jump: bool, super: bool, down: bool, warp: bool}
     */
    public function think(Player $p, Level $level, array $target, float $deltaTime, bool $chase, bool $targetAttacking = false): array
    {
        $x = $p->getX() + 12;           // centre of the 24px box
        $y = $p->getY();
        [$vx] = $p->getVelocity();
        $grounded = $p->isGrounded();
        $hurt = $p->getState() === 'hurt';

        $this->jumpCooldown = max(0, $this->jumpCooldown - $deltaTime);
        $this->attackRest = max(0, $this->attackRest - $deltaTime);

        // a decision every REACT seconds (a little random), on what it saw then
        $this->decide -= $deltaTime;
        if ($this->decide <= 0) {
            $this->decide = $this->level['react'] * (0.7 + mt_rand(0, 60) / 100);
            $this->seen = $target;
            $dx = $target[0] - $x;
            $dy = $target[1] - $y;
            $this->dir = abs($dx) > 10 ? ($dx > 0 ? 1 : -1) : 0;
            // an opponent attacking close by: step back until it lands (if it notices)
            $this->backOff = $chase && $targetAttacking && abs($dx) < 120 && abs($dy) < 80 && self::chance($this->level['dodge']);
            if ($this->backOff) $this->dir = $dx > 0 ? -1 : 1;
            // close enough to an opponent that can be hurt: attack, or not this time
            $this->wantAttack = $chase && !$targetAttacking && $this->attackRest <= 0
                && abs($dx) < 96 && abs($dy) < 56 && self::chance($this->level['attack']);
            // now and then a short hesitation
            if ($this->pause <= 0 && self::chance($this->level['pause'] * $this->level['react'])) {
                $this->pause = 0.2 + mt_rand(0, 40) / 100;
            }
        }
        [$tx, $ty] = $this->seen;
        $dx = $tx - $x;
        $dir = $this->dir;
        if ($this->pause > 0) {
            $this->pause -= $deltaTime;
            $dir = 0;
        }

        // progress towards the target
        $distance = hypot($dx, $ty - $y);
        if ($distance < 48) {
            $this->noProgress = 0;   // there (a defender at its base): not stuck
        } elseif ($distance < $this->bestDistance - 16) {
            $this->bestDistance = $distance;
            $this->noProgress = 0;
        } else {
            $this->noProgress += $deltaTime;
        }

        $jump = false;
        $down = false;
        $this->blocked = ($grounded && $this->dir !== 0 && abs($vx) < 15) ? $this->blocked + $deltaTime : 0;
        if ($grounded && !$hurt && $this->jumpCooldown <= 0 && $dir !== 0) {
            // a wall: solid ahead at the height of the head and of the chest (a slope going up is not:
            // at 16 px it rises at most 16 px above the feet)
            $aheadX = (int)round($x + $dir * 16);
            $wall = $level->isSolidAt($aheadX, (int)$y + 4) && $level->isSolidAt($aheadX, (int)$y + 12);
            // nothing under the feet a little ahead: a pit (only worth jumping when running)
            $pit = abs($vx) > 60 && !self::groundBelow($level, (int)round($x + $dir * 44), (int)$y + 31, 96);
            // the target is on a ledge above, not far
            $above = !$chase && !$this->backOff && $ty < $y - 48 && abs($dx) < 120;
            if ($wall || $pit || $above || $this->blocked > 0.45) {
                $jump = true;
                $this->blocked = 0;
            }
        }
        if ($this->wantAttack && $grounded && !$hurt && $this->jumpCooldown <= 0) {
            $this->wantAttack = false;
            $this->attackRest = $this->level['rest'][0] + mt_rand(0, 100) / 100 * ($this->level['rest'][1] - $this->level['rest'][0]);
            // fast and on the same ground: a roll into it; otherwise a jump
            if (abs($vx) > 220 && abs($ty - $y) < 24 && !$jump) {
                $down = true;
            } else {
                $jump = true;
            }
        }
        $super = $jump && $this->noProgress > 4;   // stuck: the higher jump of a spring
        if ($jump) $this->jumpCooldown = 0.4;

        $warp = $this->noProgress > 9;
        if ($warp) {
            $this->noProgress = 0;
            $this->bestDistance = INF;
        }

        return ['dir' => $dir, 'jump' => $jump, 'super' => $super, 'down' => $down, 'warp' => $warp];
    }

    /** Something solid within $depth pixels below ($x, $y), probed every 8 pixels (cheaper than findSurfaceY). */
    private static function groundBelow(Level $level, int $x, int $y, int $depth): bool
    {
        for ($dy = 0; $dy <= $depth; $dy += 8) {
            if ($level->isSolidAt($x, $y + $dy)) return true;
        }
        return false;
    }
}
