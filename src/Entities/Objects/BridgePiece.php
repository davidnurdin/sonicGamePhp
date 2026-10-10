<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Falling bridge piece (object $2E, doObjectCode_platform_bridge), created by the bridge blocks of Bridge
 * (block behaviours 24-26, _5808 / _584b / _5883) under Sonic: the half block is removed from the floor
 * layout and this 16px piece takes its place. It waits 1-32 frames (random), then falls (sfx $20) with
 * a gravity of $20/256 px/frame² up to 4 px/frame, Sonic standing on it, and goes away 256px under the camera.
 */
class BridgePiece extends Mechanism
{
    private int $delay;
    private bool $falling = false;
    private float $speed = 0;
    private float $cameraY = 0;

    /** @param float $x left of the half block, $y top of the block (the plank is its third row of tiles) */
    public function __construct(Sdl $sdl, float $x, float $y)
    {
        parent::__construct($sdl, 0x2E, 'Falling bridge', $x, $y, 16, 32);
        $this->delay = mt_rand(0, 31) + 1;
        $this->tilesetName = RomSprites::SHEET;
    }

    public function setCameraY(float $cameraY): void
    {
        $this->cameraY = $cameraY;
    }

    protected function tick(array $player): void
    {
        if (!$this->falling) {
            if (--$this->delay > 0) return;
            $this->falling = true;
            $this->sounds[] = 0x20;
        }
        $this->speed = min(4, $this->speed + 0x20 / 256);
        $this->y += $this->speed;
        if ($this->y > $this->cameraY + 256) {
            $this->active = false;
            $this->visible = false;
        }
    }

    public function isFalling(): bool
    {
        return $this->falling;
    }

    /** Top of the plank, Sonic stands on it. */
    public function getTop(): float
    {
        return $this->y + 16;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        // sprite layout _8481: an empty row, then tiles $70 $72 (the VDP draws one line lower)
        $this->blit(RomSprites::BRIDGE_PLANK, $this->x, $this->y + 16 + 1, $cameraX, $cameraY);
    }
}
