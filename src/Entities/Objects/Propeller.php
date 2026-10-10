<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Sky Base propeller (object $31, doObjectCode_trap_propeller): X + 15, Y - 6, spins with a new frame every
 * frame (_ab01: two sprites 24px apart, or one in the middle) and hurts Sonic (5x20).
 */
class Propeller extends Mechanism
{
    private const FRAMES = [[[0, 0x1C], [0x18, 0x3C]], [[0, 0x1E], [0x18, 0x3E]], [[0, 0x38], [0x18, 0x3A]], [[8, 0x1A]]];

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x31, 'Propeller', $tileX * 32 + 15, $tileY * 32 - 6, 8, 40);
        $this->tilesetName = RomSprites::SHEET;
    }

    protected function tick(array $player): void
    {
    }

    public function getHurtBox(): ?array
    {
        return self::box($this->x + 2, $this->y + 2, 5, 0x14 + 16);
    }

    public function render(float $cameraX, float $cameraY): void
    {
        foreach (self::FRAMES[$this->frames & 3] as [$dy, $tile]) {
            $this->blit(RomSprites::skyTile($tile), $this->x, $this->y + $dy + 1, $cameraX, $cameraY);
        }
    }
}
