<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;
use SonicGame\SoundManager\SoundManager;

/**
 * Spear (object $3E, doObjectCode_trap_spear), X + 12. On a 256-frame cycle shared by every spear:
 * frames 0-7 sink, 8-127 hidden at the bottom, 128-135 rise (SFX $1D), 136-255 fully out.
 * Each of the 9 positions (_8bce) lists the tip / shaft sprites and the 1px wide hitbox.
 */
class Spear extends Mechanism
{
    private const TIP = [256, 32, 8, 16];
    private const SHAFT = [272, 32, 8, 16];
    // [[tip offset], [shaft offsets...], hitbox top]
    private const POSITIONS = [
        [0x00, [0x10, 0x20], 0x01], [0x04, [0x14, 0x20], 0x02], [0x08, [0x18, 0x20], 0x06],
        [0x0C, [0x1C, 0x20], 0x0A], [0x10, [0x20], 0x0E], [0x14, [0x20], 0x12],
        [0x18, [0x20], 0x16], [0x1C, [0x20], 0x1A], [0x20, [], 0x1E],
    ];

    private int $position = 8;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x3E, 'Spear', $tileX * 32 + 12, $tileY * 32, 8, 48);
    }

    protected function tick(array $player): void
    {
        $f = $this->frames & 0xFF;
        $e = $f & 0x3F;
        $this->position = match ($f >> 6) {
            0 => $e < 8 ? $e : 8,
            1 => 8,
            2 => $e < 8 ? 7 - $e : 0,
            default => 0,
        };
        if ($f === 0x80) {
            $this->sounds[] = SoundManager::SFX_SPEAR;
        }
    }

    public function getHurtBox(): ?array
    {
        $top = self::POSITIONS[$this->position][2];
        return self::box($this->x + 3, $this->y + $top, 3, 0x30 - $top);
    }

    public function render(float $cameraX, float $cameraY): void
    {
        [$tip, $shafts] = self::POSITIONS[$this->position];
        foreach ($shafts as $offset) {
            $this->blit(self::SHAFT, $this->x, $this->y + $offset, $cameraX, $cameraY);
        }
        $this->blit(self::TIP, $this->x, $this->y + $tip, $cameraX, $cameraY);
    }
}
