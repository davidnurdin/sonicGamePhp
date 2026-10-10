<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Rotating spiked ball (object $3D, doObjectCode_trap_spikeBall): turns around (X+8, Y+8) with a 64px
 * radius, 2 degrees per frame (180-entry table _898e). Hitbox 8x12 at (+4, +2).
 */
class SpikeBall extends Mechanism
{
    private const SPRITE = [216, 56, 16, 16];

    private float $centerX;
    private float $centerY;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x3D, 'Rotating spiked ball', $tileX * 32, $tileY * 32, 16, 16);
        $this->centerX = $tileX * 32 + 8;
        $this->centerY = $tileY * 32 + 8;
        $this->tick([]);
    }

    protected function tick(array $player): void
    {
        $angle = deg2rad(2 * ($this->frames % 180));
        $this->x = $this->centerX + round(64 * cos($angle));
        $this->y = $this->centerY + round(64 * sin($angle));
    }

    public function getHurtBox(): ?array
    {
        return self::box($this->x + 4, $this->y + 2, 8, 12);
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $this->blit(self::SPRITE, $this->x, $this->y, $cameraX, $cameraY);
    }
}
