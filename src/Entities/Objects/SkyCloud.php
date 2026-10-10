<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;
use SonicGame\Renderer\Window;

/**
 * Passing clouds (object $30, doObjectCode_meta_clouds, Sky Base): a 32x16 cloud (_aa63) flying left at 3 px a
 * frame, moved with the camera wave ($D2A1). Once 32 px past the left of the screen it comes back at
 * camera X + $1B4 + a random even offset (0-510). In views wider than the SMS screen both limits are pushed
 * out to the edges of the view, so that the clouds don't appear / vanish in sight.
 */
class SkyCloud extends Mechanism
{
    private array $camera = [0, 0];
    private float $wave = 0;

    public function __construct(Sdl $sdl, int $tileX, int $tileY)
    {
        parent::__construct($sdl, 0x30, 'Passing Clouds', $tileX * 32, $tileY * 32, 32, 16);
        $this->tilesetName = RomSprites::SHEET;
    }

    public function setCamera(array $camera, float $wave): void
    {
        $this->camera = $camera;
        $this->wave = $wave;
    }

    protected function tick(array $player): void
    {
        $half = max(128, Window::getInstance()->getWidth() / 2);
        $center = $this->camera[0] + 128;
        if ($this->x < $center - $half - 0x20) {
            $this->x = max($this->camera[0] + 0x1B4, $center + $half) + mt_rand(0, 255) * 2;
        }
        $this->x -= 3;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $this->blit(RomSprites::CLOUD, $this->x, $this->y + $this->wave + 1, $cameraX, $cameraY);
    }
}
