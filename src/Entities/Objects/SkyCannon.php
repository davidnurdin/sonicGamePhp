<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Sky Base cannon (object $33, doObjectCode_trap_cannon): drawn by the scenery, X - 4. Its 8-bit counter starts
 * at random; at $64 it fires a cannon ball $34 at (X+4, Y+16), SFX $1C, and shows its blast for 24 frames
 * (_ae04, 8 frames each).
 */
class SkyCannon extends Mechanism
{
    private const BLAST = [[0x74, 0x76], [0x78, 0x7A], [0x7C, 0x7E]];

    private int $counter;
    private int $blast = 0;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x33, 'Cannon', $tileX * 32 - 4, $tileY * 32, 16, 32);
        $this->tilesetName = RomSprites::SHEET;
        $this->counter = mt_rand(0, 255);
    }

    protected function tick(array $player): void
    {
        if ($this->counter === 0x64) {
            $this->spawns[] = new CannonBall($this->sdl, $this->x + 4, $this->y + 16);
            $this->sounds[] = 0x1C;
            $this->blast = 0x18;
        }
        if ($this->blast > 0) $this->blast--;
        $this->counter = ($this->counter + 1) & 0xFF;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        if ($this->blast <= 0) return;
        $tiles = self::BLAST[min(2, intdiv(0x18 - $this->blast, 8))];
        foreach ($tiles as $i => $tile) {
            $this->blit(RomSprites::skyTile($tile), $this->x + 8 * $i, $this->y + 16 + 1, $cameraX, $cameraY);
        }
    }
}
