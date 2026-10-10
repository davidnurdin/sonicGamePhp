<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;
use SonicGame\SoundManager\SoundManager;

/**
 * Flame thrower (object $16, doObjectCode_trap_flameThrower): X + 12, Y + 18, random start in its
 * 256-frame cycle. Phase = counter >> 4: phases 7-15 throw a column of 4 flame sprites (layouts _9d7a..)
 * whose hitbox (6 wide, from Y + 2) is _9d6a high. SFX $17 when the counter reaches $70.
 */
class FlameThrower extends Mechanism
{
    private const BIG = [144, 80, 8, 16];
    private const SMALL = [160, 80, 8, 16];
    private const HEIGHT = [0, 0, 0, 0, 0, 0, 0, 0x1B, 0x1F, 0x22, 0x25, 0x25, 0x25, 0x22, 0x1F, 0x1B];
    // Y offsets of [big flame, small, small, small] for the 4 sub-frames (counter & 3) of each layout
    private const LAYOUTS = [
        'A' => [[0x15, 0x0E, 0x07, 0x00], [0x17, 0x10, 0x09, 0x02], [0x19, 0x12, 0x0B, 0x04], [0x1B, 0x14, 0x0D, 0x06]],
        'B' => [[0x0C, 0x08, 0x04, 0x00], [0x0E, 0x0A, 0x06, 0x02], [0x10, 0x0C, 0x08, 0x04], [0x11, 0x0E, 0x0A, 0x06]],
        'C' => [[0x0F, 0x0A, 0x05, 0x00], [0x11, 0x0C, 0x07, 0x02], [0x13, 0x0E, 0x09, 0x04], [0x15, 0x10, 0x0B, 0x06]],
        'D' => [[0x12, 0x0C, 0x06, 0x00], [0x14, 0x0E, 0x08, 0x02], [0x16, 0x10, 0x0A, 0x04], [0x18, 0x12, 0x0C, 0x06]],
    ];
    private const PHASES = [7 => 'B', 8 => 'C', 9 => 'D', 10 => 'A', 11 => 'A', 12 => 'A', 13 => 'D', 14 => 'C', 15 => 'B'];

    private int $counter;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x16, 'Flame Thrower', $tileX * 32 + 12, $tileY * 32 + 18, 8, 48);
        $this->counter = mt_rand(0, 255);
    }

    protected function tick(array $player): void
    {
        $this->counter = ($this->counter + 1) & 0xFF;
        if ($this->counter === 0x70) {
            $this->sounds[] = SoundManager::SFX_FLAME;
        }
    }

    public function getHurtBox(): ?array
    {
        $height = self::HEIGHT[$this->counter >> 4];
        return $height ? self::box($this->x + 1, $this->y + 2, 6, $height) : null;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $layout = self::PHASES[$this->counter >> 4] ?? null;
        if ($layout === null) return;
        foreach (self::LAYOUTS[$layout][$this->counter & 3] as $i => $offset) {
            $this->blit($i === 0 ? self::BIG : self::SMALL, $this->x, $this->y + $offset, $cameraX, $cameraY);
        }
    }
}
