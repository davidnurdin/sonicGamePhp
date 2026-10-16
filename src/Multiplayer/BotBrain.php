<?php

namespace SonicGame\Multiplayer;

use SonicGame\Entities\Player;
use SonicGame\Level\Level;

/**
 * Joypad of a computer Sonic, decided every frame from the map around it (Level::isSolidAt)
 * and a target given by Versus (the sign, the flag, a ring, an opponent):
 *  - left / right towards the target;
 *  - jump in front of a wall, a pit, a target above it, when it has been pushing without moving, or to
 *    attack an opponent close to it that can be hurt (chase); an opponent jumping / rolling at it: it backs off
 *    (two attacking Sonics only bounce off each other);
 *  - down (roll) when it runs fast at an opponent;
 *  - stuck (no closer to the target for a while): a higher jump, then it asks to be put back on the way.
 * The skill (0-1) makes it pause now and then, so that the human can win.
 */
class BotBrain
{
    private float $pause = 0;
    private float $blocked = 0;
    private float $jumpCooldown = 0;
    private float $bestDistance = INF;
    private float $noProgress = 0;

    public function __construct(private float $skill)
    {
    }

    public function reset(): void
    {
        $this->pause = 0;
        $this->blocked = 0;
        $this->jumpCooldown = 0;
        $this->bestDistance = INF;
        $this->noProgress = 0;
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
        [$tx, $ty] = $target;
        $dx = $tx - $x;
        $grounded = $p->isGrounded();
        $hurt = $p->getState() === 'hurt';

        $this->jumpCooldown = max(0, $this->jumpCooldown - $deltaTime);
        $dir = abs($dx) > 10 ? ($dx > 0 ? 1 : -1) : 0;
        // an opponent attacking close by: step back until it lands
        $backOff = $chase && $targetAttacking && abs($dx) < 110 && abs($ty - $y) < 80;
        if ($backOff) $dir = $dx > 0 ? -1 : 1;

        // now and then a short hesitation (less often for a good bot)
        if ($this->pause > 0) {
            $this->pause -= $deltaTime;
            $dir = 0;
        } elseif (mt_rand() / mt_getrandmax() < (1 - $this->skill) * 0.5 * $deltaTime) {
            $this->pause = 0.25 + mt_rand(0, 40) / 100;
        }

        // progress towards the target
        $distance = hypot($dx, $ty - $y);
        if ($distance < $this->bestDistance - 16) {
            $this->bestDistance = $distance;
            $this->noProgress = 0;
        } else {
            $this->noProgress += $deltaTime;
        }

        $jump = false;
        $super = false;
        if ($grounded && !$hurt && $this->jumpCooldown <= 0) {
            $aheadX = (int)round($x + $dir * 22);
            // a wall at the height of the body (the feet may be on a slope going up)
            $wall = $dir !== 0 && ($level->isSolidAt($aheadX, (int)$y + 6) || $level->isSolidAt($aheadX, (int)$y + 14));
            // nothing under the feet a little ahead: a pit (only worth jumping when running)
            $pit = $dir !== 0 && abs($vx) > 60 && !self::groundBelow($level, (int)round($x + $dir * 44), (int)$y + 31, 96);
            // the target is higher, not far
            $above = $ty < $y - 40 && abs($dx) < 160;
            // pushing without moving
            $this->blocked = ($dir !== 0 && abs($vx) < 15) ? $this->blocked + $deltaTime : 0;
            $attack = $chase && !$targetAttacking && abs($dx) < 80 && abs($ty - $y) < 64;
            if ($backOff) $above = false;
            if ($wall || $pit || $above || $this->blocked > 0.3 || $attack) {
                $jump = true;
                $this->blocked = 0;
            } elseif (!$chase && mt_rand() / mt_getrandmax() < 0.15 * $deltaTime) {
                $jump = true;   // a hop once in a while
            }
            if ($jump && $this->noProgress > 4) {
                $super = true;  // stuck: the higher jump of a spring
            }
            if ($jump) $this->jumpCooldown = 0.35;
        }

        $down = $chase && !$targetAttacking && $grounded && abs($vx) > 220 && abs($dx) < 220 && !$jump;

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
