<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Switch (object $1D, doObjectCode_switch): X + 8, 10x17. Landing on it flips the level switch bit
 * ($D317) once with SFX $1A; it stays pressed while Sonic stands on it. Only walking onto it presses it
 * (a jump landing on it leaves it up, so jumping on the spot can't flip the doors back).
 */
class FloorSwitch extends Mechanism
{
    private const UP = [104, 57, 16, 15];
    private const DOWN = [128, 62, 16, 10];

    private bool $pressed = false;
    private float $bottom;

    public function __construct(Sdl $sdl, int $tileX, int $tileY, float $floorY)
    {
        parent::__construct($sdl, 0x1D, 'Switch', $tileX * 32 + 8, $floorY - 15, 16, 15);
        $this->bottom = $floorY;
    }

    protected function tick(array $player): void
    {
    }

    /** Top Sonic stands on. */
    public function getTop(): float
    {
        return $this->bottom - ($this->pressed ? 10 : 15);
    }

    /** Floor under the switch. */
    public function getBottom(): float
    {
        return $this->bottom;
    }

    /** Called every frame with "is Sonic standing on it": returns true when the switch gets pressed. */
    public function setPressed(bool $pressed): bool
    {
        $justPressed = $pressed && !$this->pressed;
        $this->pressed = $pressed;
        return $justPressed;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $sprite = $this->pressed ? self::DOWN : self::UP;
        $this->blit($sprite, $this->x, $this->bottom - $sprite[3], $cameraX, $cameraY);
    }
}
