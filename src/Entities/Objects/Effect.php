<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

class Effect extends GameObject
{
    public const PUFF = [[168, 136, 16, 16], [192, 136, 16, 16]];
    public const SPARKLE = [[560, 72, 16, 16], [608, 72, 16, 16], [560, 72, 16, 16], [608, 72, 16, 16]];

    private float $remaining;
    private float $gravity = 0;

    public function __construct(Sdl $sdl, float $centerX, float $centerY, array $frames, float $frameDuration, ?float $lifetime = null)
    {
        parent::__construct($sdl, -1, 'Effect', $centerX - 8, $centerY - 8, 16, 16);
        $this->animations = ['idle' => ['flags' => [], 'coords' => self::frames($frames)]];
        $this->frameDuration = $frameDuration;
        $this->loop = $lifetime !== null;
        $this->remaining = $lifetime ?? $frameDuration * count($frames);
        $this->setAnimation('idle');
    }

    public function withMotion(float $vx, float $vy, float $gravity): self
    {
        $this->vx = $vx;
        $this->vy = $vy;
        $this->gravity = $gravity;
        return $this;
    }

    public function update(float $deltaTime): void
    {
        if (!$this->active) return;
        $this->vy += $this->gravity * $deltaTime;
        $this->x += $this->vx * $deltaTime;
        $this->y += $this->vy * $deltaTime;
        $this->updateSprite($deltaTime);
        $this->remaining -= $deltaTime;
        if ($this->remaining <= 0) {
            $this->active = false;
            $this->visible = false;
        }
    }
}
