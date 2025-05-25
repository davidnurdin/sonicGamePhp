<?php

namespace SonicGame\Scene;

use SonicGame\Entities\Player;
use SonicGame\Level\Level;
use SonicGame\Level\LevelManager;
use SonicGame\Renderer\Sdl;

class Scene
{

    private int $debugMode = 0;
    private ?Level $currentLevel = null ;

    public function __construct(private Camera $camera, private Sdl $sdl,private Player $player)
    {
        $this->camera->setScene($this);
        $this->camera->stickTo($this->player);
    }

    public function getPlayer()
    {
        return $this->player;
    }

    public function getCurrentLevel()
    {
        return $this->currentLevel;
    }
    public function drawScene($font, Level $level)
    {
        if ($this->currentLevel === null)
        {
            // init level
            $this->currentLevel = &$level ;
            // init player
            $this->getPlayer()->setXY($level->getCurrentPositionSoniceXinTile()*32,$level->getCurrentPositionSoniceYinTile()*32);
            // init camera
            $this->camera->stickTo($this->player,true);
        }

        $player = $this->player;
        // draw the scene
        $this->drawTiles($level);
        $this->drawPlayer($player);

        if ($this->debugMode) {
            $this->drawDebug($font);
        }

    }

    public function drawPlayer(Player $player)
    {

        $destRect = new \SDL_Rect;
        $destRect->x = $player->getX() - $this->camera->getX();
        $destRect->y = $player->getY() - $this->camera->getY() + 16;
        $destRect->w = 32;
        $destRect->h = 32;

		$player->draw($destRect);



    }

    public function getCamera()
    {
        return $this->camera;
    }


    public function drawTiles(Level $level)
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

        $texturesTileSet = $this->sdl->getTextures('tileset' . $level->getLevel()) ;

		$previewMore = 10 ;

        $maxWidth = floor(4096 / 32) * 32; // = 4064
        for ($y = $startRow; $y < $endRow + $previewMore; $y++) {
            for ($x = $startCol; $x < $endCol + $previewMore; $x++) {
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

                    // found $textureTileset

                    $indexTexture = (int)floor($tileValue*$tileSize / $maxWidth);
                    if ($indexTexture > 0)
                    {
                        $debug = 1;
                    }
                    $textureTileSet = $texturesTileSet[$indexTexture]['texture'];


                    // with api native sdl
                    \SDL_RenderCopyEx(
                        $this->sdl->getRenderer()->getRenderer(),
                        $textureTileSet,
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

    public function setPlayer(Player $player)
    {
        $this->player = $player;
    }

    public function resetLevel()
    {
        $this->currentLevel = null;
    }
}
