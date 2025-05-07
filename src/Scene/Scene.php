<?php

namespace SonicGame\Scene;

use SonicGame\Entities\Player;
use SonicGame\Renderer\Sdl;

class Scene
{

    public function __construct(private Camera $camera,private TileSet $tileSet)
    {

    }

    public function drawPlayer(Player $player,Sdl $sdl)
    {

        $destRect = new \SDL_Rect;
        $destRect->x = $player->getX();
        $destRect->y = $player->getY();
        $destRect->w = 64;
        $destRect->h = 64;

        // with api native sdl
        \SDL_RenderCopyEx(
            $sdl->getRenderer()->getRenderer(),
            $sdl->getTextures('sonic'),
            null,
            $destRect,
            0,
            null,
            \SDL_FLIP_NONE
        );

    }

    public function getCamera()
    {
        return $this->camera;
    }

    public function drawBackground(Sdl $sdl)
    {

        $srcRect = new \SDL_Rect;
        $srcRect->x = $this->camera->getX();
        $srcRect->y = $this->camera->getY();
        $srcRect->w = 300;
        $srcRect->h = 300;


        $destRect = new \SDL_Rect;
        $destRect->x = 0;
        $destRect->y = 0;
        $destRect->w = 1640;
        $destRect->h = 1480;

        // with api native sdl
        \SDL_RenderCopyEx(
            $sdl->getRenderer()->getRenderer(),
            $sdl->getTextures('background'),
            $srcRect,
            $destRect,
            0,
            null,
            \SDL_FLIP_NONE
        );
    }
}
