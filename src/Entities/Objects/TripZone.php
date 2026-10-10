<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Green Hill act 2 trip zone (object $4B, doObjectCode_meta_trip): invisible 64x64 area. Sonic running
 * into it on the ground is thrown like when hurt (flags6 bit 6) but keeps his rings: Y speed -5px/frame,
 * X speed +3px/frame, controls locked until he lands, SFX $11.
 */
class TripZone extends Mechanism
{
    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x4B, 'Trip zone', $tileX * 32, $tileY * 32, 0x40, 0x40);
        $this->visible = false;
    }

    protected function tick(array $player): void
    {
    }

    public function render(float $cameraX, float $cameraY): void
    {
    }
}
