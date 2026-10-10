<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

class SwingingPlatform extends Platform
{
    private const CHAIN_LINK = ['x' => 272, 'y' => 8, 'w' => 8, 'h' => 8];
    private const CHAIN_LENGTH = 72.0;
    private const MAX_ANGLE = 1.1;

    private float $time = 0;
    private float $angle = 0;
    private float $pivotX;
    private float $pivotY;

    public function __construct(Sdl $sdl, int $tileX, int $tileY, array $sprite)
    {
        parent::__construct($sdl, 0x09, 'Swinging platform', $tileX, $tileY, $sprite);
        // The pivot ball is drawn by the level block at this position (GH block 108).
        $this->pivotX = $tileX * 32 + 16;
        $this->pivotY = $tileY * 32 + 16;
        $this->place();
    }

    private function place(): void
    {
        $this->x = $this->pivotX + sin($this->angle) * self::CHAIN_LENGTH - $this->width / 2;
        $this->y = $this->pivotY + cos($this->angle) * self::CHAIN_LENGTH;
    }

    public function update(float $deltaTime): void
    {
        if (!$this->active) return;
        $oldX = $this->x;
        $oldY = $this->y;
        $this->time += $deltaTime;
        $this->angle = sin($this->time * 1.6) * self::MAX_ANGLE;
        $this->place();
        $this->deltaX = $this->x - $oldX;
        $this->deltaY = $this->y - $oldY;
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $texture = $this->getTexture()['texture'];
        $src = new \SDL_Rect;
        $src->x = self::CHAIN_LINK['x'];
        $src->y = self::CHAIN_LINK['y'];
        $src->w = self::CHAIN_LINK['w'];
        $src->h = self::CHAIN_LINK['h'];
        for ($i = 1; $i <= 4; $i++) {
            $d = self::CHAIN_LENGTH * $i / 5;
            $dst = new \SDL_Rect;
            $dst->x = (int)round($this->pivotX + sin($this->angle) * $d - 4 - $cameraX);
            $dst->y = (int)round($this->pivotY + cos($this->angle) * $d - 4 - $cameraY);
            $dst->w = 8;
            $dst->h = 8;
            \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $texture, $src, $dst);
        }
        parent::render($cameraX, $cameraY);
    }
}
