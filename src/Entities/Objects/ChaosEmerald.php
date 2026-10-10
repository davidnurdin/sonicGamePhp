<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

class ChaosEmerald extends GameObject
{
    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x06, 'Chaos Emerald', 0, 0, 14, 16);
        $this->animations = ['idle' => ['flags' => [], 'coords' => self::frames([[585, 72, 14, 16]])]];
        $this->setAnimation('idle');
        $this->placeOnSpawn($tileX, $tileY, 16);
    }

    public function collect(): void
    {
        $this->active = false;
        $this->visible = false;
    }
}
