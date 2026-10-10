<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Scrap Brain act 2 transporter (object $13, _9b75): invisible 30x96 trigger. Its block position is looked
 * up in _9bd9 to get the level to load ($D2D3), then the act ends at once (no tally) and that level starts.
 */
class WarpZone extends Mechanism
{
    // [block X, block Y] => level index of the ROM ($D2D3), which is also our level number from $14 on
    private const TARGETS = ['125,26' => 0x15, '125,1' => 0x14, '1,60' => 0x18, '1,2' => 0x19, '20,15' => 0x1A];

    private ?int $target;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x13, 'Warp door', $tileX * 32, $tileY * 32, 0x1E, 0x60);
        $this->target = self::TARGETS["$tileX,$tileY"] ?? null;
        $this->visible = false;
    }

    protected function tick(array $player): void
    {
    }

    /** Our level number to load when Sonic enters it, null if the ROM table has no entry for it. */
    public function getTargetLevel(): ?int
    {
        return $this->target;
    }
}
