<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

class Projectile extends GameObject
{
    private float $life = 3.0;
    /** Seconds before the scenery stops it (shots fired from inside a cannon block). */
    public float $wallGrace = 0;
    /** Bullet $0D (_6ac1): goes through the scenery, its two frames alternate every frame (_6b70). */
    public ?array $frames2 = null;
    private float $age = 0;

    public function __construct(Sdl $sdl, float $centerX, float $centerY, float $vx, float $vy, private bool $gravity,
                                ?array $frame = null, ?string $sheet = null)
    {
        parent::__construct($sdl, -1, 'Projectile', $centerX - 4, $centerY - 5, 8, 10);
        $this->vx = $vx;
        $this->vy = $vy;
        if ($sheet !== null) {
            // own sprite (Unidus ball): goes on until it leaves the screen area
            $this->tilesetName = $sheet;
            $this->life = 9.0;
        }
        $this->animations = ['idle' => ['flags' => [], 'coords' => self::frames([$frame ?? [160, 84, 8, 10]])]];
        $this->setAnimation('idle');
    }

    public function update(float $deltaTime): void
    {
        if (!$this->active) return;
        if ($this->gravity) {
            $this->vy += 700 * $deltaTime;
        }
        $this->x += $this->vx * $deltaTime;
        $this->y += $this->vy * $deltaTime;
        $this->life -= $deltaTime;
        $this->age += $deltaTime;
        // shots stop against the scenery
        $this->wallGrace -= $deltaTime;
        if ($this->wallGrace <= 0 && $this->level && $this->level->isSolidAt((int)round($this->x + $this->width / 2), (int)round($this->y + $this->height / 2))) {
            $this->life = 0;
        }
        if ($this->frames2 !== null) {
            $frame = $this->frames2[(int)floor($this->age * 60) % 2];
            $this->animations['idle']['coords'] = self::frames([$frame]);
        }
        if ($this->life <= 0) {
            $this->active = false;
            $this->visible = false;
        }
    }
}
