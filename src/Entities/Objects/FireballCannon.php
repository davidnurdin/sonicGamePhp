<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;
use SonicGame\SoundManager\SoundManager;

/**
 * Scrap Brain fireballs ($14 right / $15 left, _9be8 / _9c70): fly at 1.5px/frame for $64 frames
 * (hitbox 6x8 at +4 in Y), stay hidden until frame $F0, then start again from their spawn point (SFX $18).
 */
class FireballCannon extends Mechanism
{
    private const RIGHT = [200, 80, 16, 16];
    private const LEFT = [176, 80, 16, 16];

    private float $startX;
    private int $counter = 0;
    private float $speed;

    public function __construct(Sdl $sdl, int $type, string $typeName, int $tileX, int $tileY)
    {
        parent::__construct($sdl, $type, $typeName, $tileX * 32, $tileY * 32, 16, 16);
        $this->startX = $this->x;
        $this->speed = $type === 0x14 ? 1.5 : -1.5;
        $this->sounds[] = SoundManager::SFX_FIREBALL;
    }

    protected function tick(array $player): void
    {
        if ($this->counter < 0x64) {
            $this->x += $this->speed;
        }
        if (++$this->counter >= 0xF0) {
            $this->counter = 0;
            $this->x = $this->startX;
            $this->sounds[] = SoundManager::SFX_FIREBALL;
        }
    }

    public function getHurtBox(): ?array
    {
        return $this->counter < 0x64 ? self::box($this->x, $this->y + 4, 6, 8) : null;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        if ($this->counter >= 0x64) return;
        $this->blit($this->speed > 0 ? self::RIGHT : self::LEFT, $this->x, $this->y, $cameraX, $cameraY);
    }
}
