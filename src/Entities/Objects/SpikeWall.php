<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/**
 * Moving spiked wall (object $39, _b398, Sky Base): a column of spikes (_b45b, plus the row drawn 16 px above
 * by _3581) that hurts Sonic (box $0202, $0C x $2E). While Sonic is at its height (his top between Y - $20 and
 * Y + $2C) it creeps right at 1/2 px a frame, up to X $580; it goes back to its start once it is $40 px above
 * the screen.
 */
class SpikeWall extends Mechanism
{
    private float $startX;
    private array $camera = [0, 0];

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x39, 'Spiked wall', $tileX * 32, $tileY * 32, 12, 0x2E);
        $this->tilesetName = RomSprites::SHEET;
        $this->startX = $this->x;
    }

    public function setCamera(array $camera): void
    {
        $this->camera = $camera;
    }

    protected function tick(array $player): void
    {
        if ($this->x >= 0x580) return;
        if ($this->y + 0x40 < $this->camera[1]) {
            $this->x = $this->startX;
        }
        $sonicY = self::sonicY($player);
        if ($sonicY > $this->y - 0x20 && $sonicY <= $this->y + 0x2C) {
            $this->x += 0.5;
        }
    }

    public function getHurtBox(): ?array
    {
        return self::box($this->x + 2, $this->y + 2, 0x0C - 2, 0x2E - 2);
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $this->blit(RomSprites::SPIKE_WALL, $this->x, $this->y - 16 + 1, $cameraX, $cameraY);
    }
}
