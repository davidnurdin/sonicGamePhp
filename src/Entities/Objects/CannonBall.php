<?php

namespace SonicGame\Entities\Objects;

use SonicGame\Renderer\Sdl;

/** Sky Base cannon ball (object $34, doObjectCode_trap_cannonBall): 2.5px/frame to the right, 12x12, hurts Sonic. */
class CannonBall extends Projectile
{
    public function __construct(Sdl $sdl, float $x, float $y)
    {
        parent::__construct($sdl, $x + 8, $y + 8, 0x280 / 256 * 60, 0, false, RomSprites::skyTile(0x02), RomSprites::SHEET);
        $this->x = $x + 2;
        $this->y = $y + 2;
        $this->width = 12;
        $this->height = 12;
        $this->wallGrace = 99;   // flies over the scenery, removed once past the screen
    }

    public function render(float $cameraX, float $cameraY): void
    {
        $renderer = $this->sdl->getRenderer()->getRenderer();
        $texture = $this->getTexture()['texture'];
        foreach ([0x02, 0x04] as $i => $tile) {
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = RomSprites::skyTile($tile);
            $dst = new \SDL_Rect;
            [$dst->x, $dst->y, $dst->w, $dst->h] = [(int)round($this->x - 2 + 8 * $i - $cameraX), (int)round($this->y - 2 + 1 - $cameraY), 8, 16];
            \SDL_RenderCopy($renderer, $texture, $src, $dst);
        }
    }
}
