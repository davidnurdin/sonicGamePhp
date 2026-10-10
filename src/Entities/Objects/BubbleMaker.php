<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

/**
 * Bubble maker (object $41, doObjectCode_powerUp_bubbles): every 128 frames, 5 times out of 8 (_8ec2),
 * releases a bubble at a random 0-15px offset; 1 in 4 is a big breathable one (_9257).
 * A tiny bubble wobbles over the vent (offsets _8eb6, next step every 8 frames).
 */
class BubbleMaker extends GameObject
{
    private const SPAWN = [1, 0, 1, 1, 0, 1, 0, 1];
    private const VENT = [[8, 5], [8, 4], [7, 3], [6, 2], [7, 1], [6, 0]];
    private const FRAME = 1 / 60;

    private int $frames = 0;
    private int $step = 0;
    private float $clock = 0;
    private float $startX;
    private float $startY;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x41, 'Bubble Maker', $tileX * 32, $tileY * 32, 8, 16);
        $this->animations = ['idle' => ['flags' => [], 'coords' => self::frames([[160, 32, 8, 16]])]];
        $this->setAnimation('idle');
        $this->startX = $this->x;
        $this->startY = $this->y;
    }

    /** @return Bubble[] the bubbles released this tick */
    public function think(float $deltaTime): array
    {
        $bubbles = [];
        $this->clock += $deltaTime;
        while ($this->clock >= self::FRAME) {
            $this->clock -= self::FRAME;
            if (($this->frames & 0x7F) === 0 && self::SPAWN[mt_rand(0, 7)]) {
                $bubbles[] = new Bubble($this->sdl, $this->startX + mt_rand(0, 15), $this->startY + mt_rand(0, 15), mt_rand(0, 3) === 0);
            }
            $this->frames++;
            if ($this->frames % 8 === 0) {
                $this->step = ($this->step + 1) % 6;
            }
        }
        [$dx, $dy] = self::VENT[$this->step];
        $this->x = $this->startX + $dx;
        $this->y = $this->startY + $dy;
        return $bubbles;
    }
}
