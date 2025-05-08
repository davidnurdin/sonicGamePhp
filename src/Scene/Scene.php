<?php

namespace SonicGame\Scene;

use SonicGame\Entities\Player;
use SonicGame\Level\Level;
use SonicGame\Renderer\Sdl;

class Scene
{

    private int $debugMode = 0 ;

    public function __construct(private Camera $camera,private Sdl $sdl)
    {

    }

    public function drawScene($player,$font,$level)
    {
        // draw the scene
//        $this->drawBackground($sdl);
        $this->drawTiles($level);
        $this->drawPlayer($player);

        if ($this->debugMode) {
            $this->drawDebug($font);
        }

    }

    public function drawPlayer(Player $player)
    {

        $destRect = new \SDL_Rect;
        $destRect->x = $player->getX();
        $destRect->y = $player->getY();
        $destRect->w = 64;
        $destRect->h = 64;

        // with api native sdl
        \SDL_RenderCopyEx(
            $this->sdl->getRenderer()->getRenderer(),
            $this->sdl->getTextures('sonic'),
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


    public function drawTiles($level)
    {
        // TODO : lire les data du level ! + utilisé une taille qui dépend de notre fenetre en cours
        $tileSet = $level->getTileSet();
        /** @var Level $level */
        for ($cell = 0; $cell < $level->getMapWidth(); $cell++) {
            for ($row = 0; $row < $level->getMapHeight(); $row++) {

                $tileValue = $level->getTile($cell,$row);
                /** @var TileSet $tileSet */
                $tileRect = $tileSet->getTile($tileValue);

                $destRect = new \SDL_Rect;
                $destRect->x = $cell * $tileSet->getWidth() - $this->camera->getX();
                $destRect->y = $row * $tileSet->getHeight() - $this->camera->getY();
                $destRect->w = $tileSet->getWidth();
                $destRect->h = $tileSet->getHeight();

                // with api native sdl
                \SDL_RenderCopyEx(
                    $this->sdl->getRenderer()->getRenderer(),
                    $this->sdl->getTextures('tileset' . $level->getLevel()),
                    $tileRect,
                    $destRect,
                    0,
                    null,
                    \SDL_FLIP_NONE
                );
            }
        }



    }

    public function setDebugMode(int $debugMode)
    {
        $this->debugMode = $debugMode;
    }

    private function drawDebug($fontTab)
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
        \SDL_RenderCopyEx($this->sdl->getRenderer()->getRenderer(),
            $fontTab[substr($char, 0, 1)], $srcRectFont,$dstRectFont, 0, null, 0);

    }
}
