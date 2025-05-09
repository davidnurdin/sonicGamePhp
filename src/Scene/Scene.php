<?php

namespace SonicGame\Scene;

use SonicGame\Entities\Player;
use SonicGame\Level\Level;
use SonicGame\Renderer\Sdl;

class Scene
{

    private int $debugMode = 0;

    public function __construct(private Camera $camera, private Sdl $sdl)
    {

    }

    public function drawScene($player, $font, $level)
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
        $destRect->w = 32;
        $destRect->h = 32;

        $srcRect = new \SDL_Rect;
        $srcRect->x = 3;
        $srcRect->y = 3;
        $srcRect->w = 23;
        $srcRect->h = 32;

        // with api native sdl
        \SDL_RenderCopyEx(
            $this->sdl->getRenderer()->getRenderer(),
            $this->sdl->getTextures('sonic'),
            $srcRect,
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
        $tileSet = $level->getTileSet();
        /** @var Level $level */

        // On limite l'affichage à la taille de la fenêtre

        $mapHeight = $level->getMapHeight();
        $mapWidth = $level->getMapWidth();


        $cameraX = $this->camera->getX();
        $cameraY = $this->camera->getY();
        $tileSize = 32;

        $winW = $this->sdl->getWindow()->getWidth();
        $winH = $this->sdl->getWindow()->getHeight();

//        $maxWidth = floor($this->sdl->getWindow()->getWidth()/$tileSet->getWidth()) - 1 ;
//        $maxHeight = floor($this->sdl->getWindow()->getHeight()/$tileSet->getHeight()) -1 ;

        $startCol = (int)floor($cameraX / $tileSize);
        $endCol = (int)ceil(($cameraX + $winW) / $tileSize);
        $offsetX = -(int)($cameraX % $tileSize);

        $startRow = (int)floor($cameraY / $tileSize);
        $endRow = (int)ceil(($cameraY + $winH) / $tileSize);
        $offsetY = -(int)($cameraY % $tileSize);

        $startRow = max(0, $startRow);
        $endRow = min($mapHeight, $endRow);

        $startCol = max(0, $startCol);
        $endCol = min($mapWidth, $endCol);


        for ($y = $startRow; $y < $endRow; $y++) {
            for ($x = $startCol; $x < $endCol; $x++) {
                $tileValue = $level->getTile($x, $y);
                if ($tileValue === null)
                    continue;

                /** @var TileSet $tileSet */
                $tileRect = $tileSet->getTile($tileValue);

                if ($tileRect) {
                    $dstRect = new \SDL_Rect;
                    $dstRect->x = ($x - $startCol) * $tileSize + $offsetX;
                    // $dstRect->y = $y * $tileSize + (int)$cameraY;
                    $dstRect->y = ($y - $startRow) * $tileSize + $offsetY;
                    $dstRect->w = $tileSize;
                    $dstRect->h = $tileSize;


                    // with api native sdl
                    \SDL_RenderCopyEx(
                        $this->sdl->getRenderer()->getRenderer(),
                        $this->sdl->getTextures('tileset' . $level->getLevel()),
                        $tileRect,
                        $dstRect,
                        0,
                        null,
                        \SDL_FLIP_NONE
                    );
                }
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
            $char = 'F';
        else
            $char = '0';

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
            $fontTab[substr($char, 0, 1)], $srcRectFont, $dstRectFont, 0, null, 0);

    }
}
