<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

/**
 * Small sprite from tileset-eggman.png spawned by the bosses: bombs, shots, homing mines, freed animals.
 * Speeds in px/s, accelerations in px/s² (the ROM values are converted with x60 / x3600).
 */
class BossPart extends GameObject
{
    // tileset-eggman.png rects
    public const BOMB = [[14, 158, 14, 16], [29, 158, 14, 16]];
    public const BOMB_EXPLODE = [[46, 158, 16, 16], [63, 158, 12, 16], [46, 158, 16, 16]];
    public const SHOT = [[136, 164, 6, 6], [143, 163, 8, 8]];
    public const MINE = [[173, 161, 15, 8], [210, 162, 15, 8]];
    public const BIRD = [[261, 113, 11, 15], [273, 112, 11, 16]];
    public const RABBIT = [[307, 135, 14, 16], [322, 135, 14, 16]];

    public bool $harmful = true;
    public float $gravity = 0;
    public float $maxFall = 1e9;
    public ?float $life = null;
    /** Jungle bomb: explodes this many seconds after being dropped. */
    public ?float $fuse = null;
    /** Labyrinth mine: steers toward the player (accel px/s², max px/s). */
    public ?array $homing = null;
    /**
     * Sky Base boss shot (object $47, _bcdf): still for $C8 frames, then steers toward Sonic: X ±$0C/256 px/frame²
     * ($18 when turning back), Y ±$0A ($05 when turning back), without a speed limit. Seconds left before it moves.
     */
    public ?float $electricWait = null;
    /** Freed animal: hops along the floor. */
    public bool $hopper = false;
    public bool $landOnFloor = false;
    /** X slowdown in px/s² (Jungle bomb) */
    public float $friction = 0;
    // $3F90: X speed (/256 px/frame) given every frame to an object standing on a block of this solidity
    // $3FF0: how far over the floor of a block of this solidity an object is still put on it
    private const FLOOR_TOLERANCE = [0, 8, 8, 8, 8, 6, 6, 6, 6, 6, 6, 3, 3, 3, 3, 3, 3, 8, 3, 3, 3, 3, 3, 3, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 3, 3, 4, 4, 3, 3, 3, 3, 0];
    private const SLOPE_PUSH = [0, 28, 28, -28, -28, 18, 18, 18, -18, -18, -18, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 18, -18, 0, 0, 0, 0];
    private bool $exploding = false;
    /** frames shown once the fuse has burnt (default: the eggman sheet explosion) */
    public ?array $explodeFrames = null;
    /** frames used while moving right (Labyrinth torpedo), instead of flipping the left ones */
    public ?array $rightFrames = null;
    /** True during the frame the bomb blows up (for the sound). */
    public bool $justExploded = false;

    public function __construct(Sdl $sdl, float $centerX, float $centerY, array $frames, float $frameDuration = 0.1)
    {
        $w = $frames[0][2];
        $h = $frames[0][3];
        parent::__construct($sdl, -1, 'BossPart', $centerX - $w / 2, $centerY - $h / 2, $w, $h);
        $this->tilesetName = 'eggman';
        $this->frameDuration = $frameDuration;
        $this->animations = ['idle' => ['flags' => [], 'coords' => self::frames($frames)]];
        $this->setAnimation('idle');
    }

    /** Rects of the BossSprites frames "{prefix}{start}"... */
    public static function frames2(string $prefix, int $count, int $start = 0): array
    {
        $rects = [];
        for ($k = $start; $k < $start + $count; $k++) {
            $rects[] = BossSprites::FRAMES[$prefix . $k];
        }
        return $rects;
    }

    public function setLevelAndReturn(?\SonicGame\Level\Level $level): self
    {
        if ($level) $this->setLevel($level);
        return $this;
    }

    public function withVelocity(float $vx, float $vy): self
    {
        $this->vx = $vx;
        $this->vy = $vy;
        return $this;
    }

    public function think(float $deltaTime, float $playerX, float $playerY): void
    {
        $this->justExploded = false;
        if (!$this->active) return;

        if ($this->homing !== null) {
            [$accel, $max, $dir] = $this->homing;
            $this->vx = max(-$max, min($max, $this->vx + $dir * $accel * $deltaTime));
            $dy = $playerY - ($this->y + $this->height / 2);
            $this->vy = max(-$max, min($max, $this->vy + ($dy > 0 ? 1 : -1) * $accel * $deltaTime));
            if ($this->rightFrames !== null) {
                $this->animations['right'] ??= ['flags' => [], 'coords' => self::frames($this->rightFrames)];
                $this->setAnimation($this->vx > 0 ? 'right' : 'idle');
            } else {
                $this->flipX = $this->vx > 0;
            }
        }

        if ($this->electricWait !== null) {
            $this->electricWait -= $deltaTime;
            if ($this->electricWait <= 0) {
                $cx = $this->x + $this->width / 2;
                $cy = $this->y + $this->height / 2;
                $ax = $playerX >= $cx ? ($this->vx < 0 ? 0x18 : 0x0C) : ($this->vx >= 0 ? -0x18 : -0x0C);
                $ay = $playerY >= $cy ? ($this->vy < 0 ? 0x05 : 0x0A) : ($this->vy >= 0 ? -0x05 : -0x0A);
                $this->vx += $ax / 256 * 3600 * $deltaTime;
                $this->vy += $ay / 256 * 3600 * $deltaTime;
            }
        }

        if ($this->exploding) {
            // _8218: the X speed is cleared when it blows up
            $this->vx = 0;
        }
        if (!$this->exploding) {
            $this->vy = min($this->maxFall, $this->vy + $this->gravity * $deltaTime);
            $this->x += $this->vx * $deltaTime;
            $this->y += $this->vy * $deltaTime;
        }

        if (($this->landOnFloor || $this->hopper) && $this->vy >= 0 && $this->level) {
            $feetX = (int)($this->x + $this->width / 2);
            $floor = $this->level->findSurfaceY($feetX, (int)($this->y + $this->height - 8), 24);
            $solidity = 0;
            if ($floor !== null) {
                $tile = $this->level->getTile(intdiv($feetX, 32), intdiv((int)$floor, 32));
                $solidity = $tile !== null ? ord($this->level->getSolidity()[$tile] ?? "\0") & 0x3F : 0;
            }
            // _32e2: an object within the tolerance of the block solidity ($3FF0) over the floor is put on it,
            // so it keeps to a slope going down
            $tolerance = $this->landOnFloor ? (self::FLOOR_TOLERANCE[$solidity] ?? 0) : 0;
            if ($floor !== null && $this->y + $this->height + $tolerance >= $floor) {
                $this->y = $floor - $this->height;
                $this->vy = $this->hopper ? -180 : 0;
                if ($this->landOnFloor && !$this->exploding) {
                    // and gets the push of the block solidity ($3F90) every frame: the Jungle bombs roll on the vine
                    $this->vx += (self::SLOPE_PUSH[$solidity] ?? 0) / 256 * 3600 * $deltaTime;
                }
            }
        }
        if ($this->friction > 0 && !$this->exploding && $this->vx != 0) {
            // _8218: the bomb's X speed goes back toward 0 by 2/256 px/frame²
            $f = $this->friction * $deltaTime;
            $this->vx = $this->vx > 0 ? max(0, $this->vx - $f) : min(0, $this->vx + $f);
        }

        if ($this->fuse !== null && !$this->exploding) {
            $this->fuse -= $deltaTime;
            if ($this->fuse <= 0) {
                $this->exploding = true;
                $this->justExploded = true;
                $this->animations['boom'] = ['flags' => [], 'coords' => self::frames($this->explodeFrames ?? self::BOMB_EXPLODE)];
                $this->frameDuration = 0.2;
                $this->setAnimation('boom');
                $this->life = 0.6;
            }
        }

        $this->updateSprite($deltaTime);
        if ($this->life !== null) {
            $this->life -= $deltaTime;
            if ($this->life <= 0) {
                $this->active = false;
                $this->visible = false;
            }
        }
    }

    public function update(float $deltaTime): void
    {
    }
}
