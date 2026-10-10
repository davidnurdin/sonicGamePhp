<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;
use SonicGame\SoundManager\SoundManager;

/**
 * Electric spheres (object $1A, doObjectCode_trap_electric): X + 24, Y + 16, 48x16 area on a 160-frame
 * cycle: off until $46, the spark flickers until $64 (warning), then sparks + spheres are live and
 * hurt until $A0 (SFX $13 at $64).
 */
class ElectricSphere extends Mechanism
{
    private const SPHERE = [168, 104, 16, 16];
    private const SPARK = [144, 104, 16, 16];

    private int $counter = 0;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x1A, 'Electric sphere', $tileX * 32 + 24, $tileY * 32 + 16, 48, 16);
    }

    protected function tick(array $player): void
    {
        $this->counter = ($this->counter + 1) % 0xA0;
        if ($this->counter === 0x64) {
            $this->sounds[] = SoundManager::SFX_ELECTRIC;
        }
    }

    public function getHurtBox(): ?array
    {
        return $this->counter >= 0x64 ? self::box($this->x, $this->y, 48, 16) : null;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        if ($this->counter < 0x46) return;
        if ($this->counter < 0x64) {
            // warning: the spark blinks (layout frames 2 / 3 alternate every frame)
            if ($this->frames & 1) {
                $this->blit(self::SPARK, $this->x + 16, $this->y, $cameraX, $cameraY);
            }
            return;
        }
        $this->blit(self::SPHERE, $this->x, $this->y, $cameraX, $cameraY);
        $this->blit(self::SPARK, $this->x + 16, $this->y, $cameraX, $cameraY);
        $this->blit(self::SPHERE, $this->x + 32, $this->y, $cameraX, $cameraY);
    }
}
