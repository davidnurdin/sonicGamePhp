<?php

namespace SonicGame\Scene;

use SonicGame\Entities\Player;
use SonicGame\Renderer\Sdl;

class Scene
{

    private int $debugMode = 0 ;

    public function __construct(private Camera $camera,private Level $level)
    {

    }

    public function drawScene($sdl,$player,$font)
    {
        // draw the scene
//        $this->drawBackground($sdl);
        $this->drawTiles($sdl);
        $this->drawPlayer($player,$sdl);

        if ($this->debugMode) {
            $this->drawDebug($sdl,$font);
        }

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


    public function drawTiles(Sdl $sdl)
    {

        // TODO : lire les data du level ! + utilisé une taille qui dépend de notre fenetre en cours
        $tileSet = $this->level->getTileSet();

        for ($cell = 0; $cell < 30; $cell++) {
            for ($row = 0; $row < 30; $row++) {
                $tile = $tileSet->getTile($row % 3);
                $destRect = new \SDL_Rect;
                $destRect->x = $cell * $tileSet->getWidth() - $this->camera->getX();
                $destRect->y = $row * $tileSet->getHeight() - $this->camera->getY();
                $destRect->w = $tileSet->getWidth();
                $destRect->h = $tileSet->getHeight();

                // with api native sdl
                \SDL_RenderCopyEx(
                    $sdl->getRenderer()->getRenderer(),
                    $sdl->getTextures('tileset' . $this->level->getLevel()),
                    $tile,
                    $destRect,
                    0,
                    null,
                    \SDL_FLIP_NONE
                );
            }
        }



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

    public function setDebugMode(int $debugMode)
    {
        $this->debugMode = $debugMode;
    }

    private function drawDebug($sdl,$fontTab)
    {
        if ($this->debugMode == 1)
            $char = 'F' ;
        else
            $char = '0' ;

        $srcRectFont = new \SDL_Rect;
        $srcRectFont->x = 0;
        $srcRectFont->y = 0;
        $srcRectFont->w = 32;
        $srcRectFont->h = 32;

        $dstRectFont = new \SDL_Rect;
        $dstRectFont->x = 0;
        $dstRectFont->y = 0;
        $dstRectFont->w = 320;
        $dstRectFont->h = 320;

        dump('Debug mode is enabled : ' . $this->debugMode);
        \SDL_RenderCopyEx($sdl->getRenderer()->getRenderer(),
            $fontTab[substr($char, 0, 1)], $srcRectFont,$dstRectFont, 0, null, 0);

    }
}
