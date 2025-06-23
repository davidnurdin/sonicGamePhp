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
			$this->camera->noSmooth = true;
            $this->camera->stickTo($this->player);
			$this->camera->update();

        }

        $player = $this->player;
        // draw the scene
        // Ne pas afficher les tiles normales en mode debug 1
        if ($this->debugMode != 1) {
            $this->drawTiles($level);
        }

        // Afficher le debug en premier (en arrière-plan)
        if ($this->debugMode) {
            $this->drawDebug($font);
        }

        // Afficher le joueur en dernier (par-dessus tout)
        $this->drawPlayer($player);

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
	//	return ;
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
        $endCol =  (int)ceil(($winW/$tileSize) + ($cameraX/$tileSize));
        $offsetX = -(int)($cameraX % $tileSize);

        $startRow = (int)floor($cameraY / $tileSize);
        $endRow = (int)ceil(($winH/$tileSize) + ($cameraY/$tileSize));
        $offsetY = -(int)($cameraY % $tileSize);

        $startRow = max(0, $startRow);
        $endRow = min($mapHeight, $endRow);

        $startCol = max(0, $startCol);
        $endCol = min($mapWidth, $endCol);

        $texturesTileSet = $this->sdl->getTextures('tileset' . $level->getLevel()) ;

		$previewMore = 2 ;

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
        if ($this->debugMode == 1) {
            // Afficher les valeurs des tiles au lieu des sprites
            $this->drawDebugTiles($fontTab, 1);
        } elseif ($this->debugMode == 2) {
            // Afficher les valeurs de collision
            $this->drawDebugTiles($fontTab, 2);
        } else {
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

    private function drawDebugTiles($fontTab, $debugType = 1)
    {
        if (!$this->currentLevel) {
            return;
        }

        $level = $this->currentLevel;
        $cameraX = $this->camera->getX();
        $cameraY = $this->camera->getY();
        $tileSize = 32;

        $winW = $this->sdl->getWindow()->getWidth();
        $winH = $this->sdl->getWindow()->getHeight();

        $startCol = (int)floor($cameraX / $tileSize);
        $endCol = (int)ceil(($winW/$tileSize) + ($cameraX/$tileSize));
        $offsetX = -(int)($cameraX % $tileSize);

        $startRow = (int)floor($cameraY / $tileSize);
        $endRow = (int)ceil(($winH/$tileSize) + ($cameraY/$tileSize));
        $offsetY = -(int)($cameraY % $tileSize);

        $startRow = max(0, $startRow);
        $endRow = min($level->getMapHeight(), $endRow);

        $startCol = max(0, $startCol);
        $endCol = min($level->getMapWidth(), $endCol);

        $previewMore = 2;

        // Définir la couleur jaune pour les borders
        \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 255, 255, 0, 255);

        // Afficher les valeurs des tiles
        for ($y = $startRow; $y < $endRow + $previewMore; $y++) {
            for ($x = $startCol; $x < $endCol + $previewMore; $x++) {
                $tileValue = $level->getTile($x, $y);
                if ($tileValue === null) {
                    continue;
                }

                // Calculer l'index de la tile
                $tileIndex = $x + $y * $level->getMapWidth();

                // Choisir la valeur à afficher selon le type de debug
                if ($debugType == 1) {
                    // Mode 1 : Valeurs des tiles
                    $char = strtoupper(dechex($tileValue));
                    if (strlen($char) == 1) {
                        $char = '0' . $char;
                    }
                } else {
                    // Mode 2 : Valeurs de collision
                    $solidity = $level->getSolidity();

                    if (isset($solidity[$tileValue])) {
                        $colisionIndexTile = ord($solidity[$tileValue]);
                        

                        $char = strtoupper(dechex($colisionIndexTile));
                        if (strlen($char) == 1) {
                            $char = '0' . $char;
                        }
                    } else {
                        $char = '00'; // Pas de collision
                    }
                }

                $dstRect = new \SDL_Rect;
                $dstRect->x = ($x - $startCol) * $tileSize + $offsetX;
                $dstRect->y = ($y - $startRow) * $tileSize + $offsetY;
                $dstRect->w = $tileSize;
                $dstRect->h = $tileSize;

                // Dessiner le border jaune autour de la tile (seulement le contour)
                $x1 = $dstRect->x;
                $y1 = $dstRect->y;
                $x2 = $dstRect->x + $dstRect->w - 1;
                $y2 = $dstRect->y + $dstRect->h - 1;
                
                // Rectangle jaune plein (border)
                $borderRect = new \SDL_Rect;
                $borderRect->x = $x1;
                $borderRect->y = $y1;
                $borderRect->w = $dstRect->w;
                $borderRect->h = $dstRect->h;
                \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 255, 255, 0, 255); // Jaune
                \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $borderRect);
                
                // Rectangle noir plus petit à l'intérieur
                $innerRect = new \SDL_Rect;
                $innerRect->x = $x1 + 2;
                $innerRect->y = $y1 + 2;
                $innerRect->w = $dstRect->w - 4;
                $innerRect->h = $dstRect->h - 4;
                \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 0, 0, 0, 255); // Noir
                \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $innerRect);
                
                // Remettre la couleur jaune pour le texte
                \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 255, 255, 0, 255);

                // Afficher les 2 caractères hexadécimaux séparément
                if (isset($fontTab[substr($char, 0, 1)]) && isset($fontTab[substr($char, 1, 1)])) {
                    // Premier caractère (gauche)
                    $dstRectFont = new \SDL_Rect;
                    $dstRectFont->x = $dstRect->x + 4;
                    $dstRectFont->y = $dstRect->y + 4;
                    $dstRectFont->w = 12;
                    $dstRectFont->h = 24;
                    
                    \SDL_RenderCopyEx(
                        $this->sdl->getRenderer()->getRenderer(),
                        $fontTab[substr($char, 0, 1)],
                        new \SDL_Rect(0, 0, 32, 32),
                        $dstRectFont,
                        0,
                        null,
                        \SDL_FLIP_NONE
                    );
                    
                    // Deuxième caractère (droite)
                    $dstRectFont2 = new \SDL_Rect;
                    $dstRectFont2->x = $dstRect->x + 16;
                    $dstRectFont2->y = $dstRect->y + 4;
                    $dstRectFont2->w = 12;
                    $dstRectFont2->h = 24;
                    
                    \SDL_RenderCopyEx(
                        $this->sdl->getRenderer()->getRenderer(),
                        $fontTab[substr($char, 1, 1)],
                        new \SDL_Rect(0, 0, 32, 32),
                        $dstRectFont2,
                        0,
                        null,
                        \SDL_FLIP_NONE
                    );
                }

                // En mode debug 2, afficher les pixels de collision aléatoires
                if ($debugType == 2) {
                    $tilesColision = $level->getTilesColision();
                    
                    if (isset($tilesColision[$tileValue])) {
                        
                        $tileColisionData = $tilesColision[$tileValue];
                        
                        // Définir la couleur bleu cyan pétant pour les points de collision
                        \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 0, 255, 255, 255);
                        
                        // Parcourir les pixels de la tile (32x32)
                        for ($xTile = 0; $xTile < 32; $xTile++) {
                            for ($yTile = 0; $yTile < 32; $yTile++) {
                                // Vérifier si ce pixel a une collision (1 = collision)
                                if (isset($tileColisionData[$yTile][$xTile]) && $tileColisionData[$yTile][$xTile] == 1) {
                                    // Afficher aléatoirement pour éviter de surcharger l'écran
                                    if (rand(1, 10) == 2) {
                                        $screenX = $dstRect->x + $xTile;
                                        $screenY = $dstRect->y + $yTile;
                                        \SDL_RenderDrawPoint($this->sdl->getRenderer()->getRenderer(), $screenX, $screenY);
                                    }
                                }
                            }
                        }
                        
                        // Remettre la couleur jaune pour les borders
                        \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 255, 255, 0, 255);
                    }
                }
            }
        }
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
