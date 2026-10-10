<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

/**
 * Green Hill flower (object $50, doObjectCode_flower): not a sprite, it rewrites 2x2 background tiles at
 * (X, Y) and (X, Y+16) (_7b5d / _7b85, tools/flowers.py). The flower stands up for 240 frames, then
 * rests for 256 frames; drawn as background, behind every sprite.
 */
class Flower extends GameObject
{
    private const FRAME = 1 / 60;
    private const LOW = [0, 0, 16, 32];
    private const HIGH = [16, 0, 16, 32];
    // _7b85: [shown 2x2 sets, duration of the next state in 2-frame steps]; the counter starts at $32
    private const STATES = [[false, 8], [false, 0x78], [true, 8], [false, 0x78]];

    private int $phase = 0;
    private int $counter = 0x32;
    private int $frames = 0;
    private float $clock = 0;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x50, 'Flower', $tileX * 32, $tileY * 32, 16, 32);
        $this->tilesetName = 'flower';
        $this->animations = [
            'low' => ['flags' => [], 'coords' => self::frames([self::LOW])],
            'high' => ['flags' => [], 'coords' => self::frames([self::HIGH])],
        ];
        $this->setAnimation('low');
    }

    public function update(float $deltaTime): void
    {
        $this->clock += $deltaTime;
        while ($this->clock >= self::FRAME) {
            $this->clock -= self::FRAME;
            // the counter only runs on even frames
            if ((++$this->frames & 1) === 0 && --$this->counter === 0) {
                $this->counter = self::STATES[$this->phase][1];
                $this->phase = ($this->phase + 1) & 3;
                $this->setAnimation(self::STATES[$this->phase][0] ? 'high' : 'low');
            }
        }
    }
}
