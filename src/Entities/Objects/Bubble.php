<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Entities\GameObject;
use SonicGame\Renderer\Sdl;

/**
 * Bubbles released by a bubble maker (Labyrinth):
 * - small bubble (object $42, _8eca): rises at $60/256 px/frame, pure decoration;
 * - big bubble (object $20, _96f8): rises at $68/256 px/frame, 10x12, Sonic breathes it (air counter reset).
 * Both drift by +/-$20/256 px/frame (direction picked at random every 16 frames) and disappear at the surface.
 */
class Bubble extends GameObject
{
    private const SMALL = [160, 32, 8, 16];
    private const BIG = [176, 32, 16, 16];
    private const FRAME = 1 / 60;

    private int $frames = 0;
    private float $clock = 0;
    private int $popTimer = 0;

    public function __construct(Sdl $sdl, float $x, float $y, private bool $big)
    {
        parent::__construct($sdl, $big ? 0x20 : 0x42, 'Bubble', $x, $y, $big ? 16 : 8, 16);
        $this->animations = [
            'idle' => ['flags' => [], 'coords' => self::frames([$big ? self::BIG : self::SMALL])],
            'pop' => ['flags' => [], 'coords' => self::frames([self::SMALL])],
        ];
        $this->setAnimation('idle');
    }

    public function isBreathable(): bool
    {
        return $this->big && $this->popTimer === 0 && $this->active;
    }

    /** Breathed by Sonic: shows the small "pop" sprite for 16 frames then vanishes. */
    public function pop(): void
    {
        $this->popTimer = 16;
        $this->setAnimation('pop');
    }

    /** Hitbox of the big bubble (10x12). */
    public function intersects(array $rect, int $shrink = 0): bool
    {
        $x = $this->x + 3;
        $y = $this->y + 2;
        return $x < $rect['x'] + $rect['width'] && $x + 10 > $rect['x']
            && $y < $rect['y'] + $rect['height'] && $y + 12 > $rect['y'];
    }

    public function think(float $deltaTime, ?float $waterLine): void
    {
        $this->clock += $deltaTime;
        while ($this->clock >= self::FRAME && $this->active) {
            $this->clock -= self::FRAME;
            if ($this->popTimer > 0) {
                if (--$this->popTimer === 0) {
                    $this->active = false;
                }
                continue;
            }
            if ($this->frames % 16 === 0) {
                $this->vx = (mt_rand(0, 63) < 32 ? 0x20 : -0x20) / 256;
            }
            $this->frames++;
            $this->x += $this->vx;
            $this->y -= ($this->big ? 0x68 : 0x60) / 256;
            if ($waterLine !== null && $this->y <= $waterLine) {
                $this->active = false;
            }
        }
    }
}
