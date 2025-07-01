<?php

namespace SonicGame\Scene;

use Evenement\EventEmitter;
use SonicGame\Entities\Player;
use SonicGame\Level\Level;
use SonicGame\Level\LevelManager;
use SonicGame\Renderer\Sdl;

class Scene extends EventEmitter
{

    private int $debugMode = 0;
    private ?Level $currentLevel = null ;
    private array $collisionTiles = [];

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

    /**
     * Écoute l'événement collisionTile du CollisionSystem
     */
    public function listenToCollisionEvents($collisionSystem)
    {
        $collisionSystem->on('collisionTile', function($data) {
            $this->handleCollisionTile($data);
        });
    }

    /**
     * Gère l'événement collisionTile
     */
    private function handleCollisionTile($data)
    {
        $tileKey = $data['tileX'] . ',' . $data['tileY'];
        
        // Stocke les informations de collision pour debug
        $this->collisionTiles[$tileKey] = [
            'tileX' => $data['tileX'],
            'tileY' => $data['tileY'],
            'tileValue' => $data['tileValue'],
            'timestamp' => microtime(true)
        ];

        // Debug: affiche les informations de collision
        // echo "Scene: Collision tile détectée à ({$data['tileX']}, {$data['tileY']}) - Tile value: {$data['tileValue']}\n";
        
        // Émet un événement pour informer d'autres composants
        // $this->emit('collisionTileRendered', $data);
    }

    /**
     * Récupère les tiles de collision pour debug
     */
    public function getCollisionTiles(): array
    {
        return $this->collisionTiles;
    }

    /**
     * Nettoie les anciennes tiles de collision (plus de 1 seconde)
     */
    public function cleanupOldCollisionTiles()
    {
        $currentTime = microtime(true);
        $this->collisionTiles = array_filter($this->collisionTiles, function($tile) use ($currentTime) {
            return ($currentTime - $tile['timestamp']) < 1.0; // Garde seulement 1 seconde
        });
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
        $destRect->y = $player->getY() - $this->camera->getY() ; // + 16;
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
        $this->drawDebugTiles($fontTab, $this->debugMode);
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
                }
                elseif ($debugType == 4)
                {
                    // Mode 4 : Coordonnées logiques des tiles (X/Y)
                    // Afficher X et Y séparément, en limitant à un chiffre chacun pour la lisibilité
                    $charX = substr((string)$x, -1); // Dernier chiffre de X
                    $charY = substr((string)$y, -1); // Dernier chiffre de Y
                    $char = $charX . $charY;
                }
                else {

                    
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

                
                // En mode debug 2, afficher les zones de collision
                if ($debugType == 3) {

                    if ($colision = $level->getTileColisionAt($x, $y))
                    {
                        \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 0, 255, 255, 128); // Semi-transparent
                            
                        // Calculer les zones de collision et les remplir

                        $this->fillCollisionZones($level->getTileColisionAt($x, $y) , $dstRect->x, $dstRect->y);
                        
                        // $this->drawCollisionPixelRand($colision, $dstRect->x, $dstRect->y);

                        // Remettre la couleur jaune pour les borders
                        \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 255, 255, 0, 255);
                    }


                }

                
            }
        }

        // En mode debug 2, dessiner les rectangles multicolors autour des tiles en collision
        if ($debugType == 2) {
            $this->drawCollisionTilesMulticolor();
        }
    }

    /**
     * Dessine des rectangles multicolors autour des tiles en collision
     */
    private function drawCollisionTilesMulticolor()
    {
        $cameraX = $this->camera->getX();
        $cameraY = $this->camera->getY();
        $tileSize = 32;

        foreach ($this->collisionTiles as $tileKey => $tileData) {
            $tileX = $tileData['tileX'];
            $tileY = $tileData['tileY'];
            
            // Calculer la position à l'écran
            $screenX = $tileX * $tileSize - $cameraX;
            $screenY = $tileY * $tileSize - $cameraY;
            
            // Vérifier si la tile est visible à l'écran
            if ($screenX >= -$tileSize && $screenX <= $this->sdl->getWindow()->getWidth() &&
                $screenY >= -$tileSize && $screenY <= $this->sdl->getWindow()->getHeight()) {
                
                // Créer un rectangle multicolor (arc-en-ciel)
                $this->drawRainbowBorder($screenX, $screenY, $tileSize);
            }
        }
    }

    /**
     * Dessine une bordure arc-en-ciel autour d'une tile
     */
    private function drawRainbowBorder($x, $y, $size)
    {
        $colors = [
            [255, 0, 0],    // Rouge
            [255, 127, 0],  // Orange
            [255, 255, 0],  // Jaune
            [0, 255, 0],    // Vert
            [0, 0, 255],    // Bleu
            [75, 0, 130],   // Indigo
            [148, 0, 211]   // Violet
        ];
        
        
        shuffle($colors);
        
        $colorIndex = 0;
        $borderWidth = 2;
        
        // Dessiner les 4 côtés avec des couleurs différentes
        for ($i = 0; $i < 4; $i++) {
            $color = $colors[$colorIndex % count($colors)];
            \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), $color[0], $color[1], $color[2], 255);
            
            $rect = new \SDL_Rect;
            
            switch ($i) {
                case 0: // Haut
                    $rect->x = $x;
                    $rect->y = $y;
                    $rect->w = $size;
                    $rect->h = $borderWidth;
                    break;
                case 1: // Droite
                    $rect->x = $x + $size - $borderWidth;
                    $rect->y = $y;
                    $rect->w = $borderWidth;
                    $rect->h = $size;
                    break;
                case 2: // Bas
                    $rect->x = $x;
                    $rect->y = $y + $size - $borderWidth;
                    $rect->w = $size;
                    $rect->h = $borderWidth;
                    break;
                case 3: // Gauche
                    $rect->x = $x;
                    $rect->y = $y;
                    $rect->w = $borderWidth;
                    $rect->h = $size;
                    break;
            }
            
            \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $rect);
            $colorIndex++;
        }
    }

    private function drawCollisionPixelRand($tileColisionData, $tileX, $tileY)
    {
        // Parcourir les 32x32 pixels de la tile
        for ($y = 0; $y < 32; $y++) {
            for ($x = 0; $x < 32; $x++) {
                // Vérifier si rand(1,10) == 5 (10% de chance)
                if (rand(1, 20) == 5) {
                    // Vérifier si le pixel est dans une zone de collision
                    if (isset($tileColisionData[$y][$x]) && $tileColisionData[$y][$x] == 1) {
                        // Dessiner un pixel à cette position
                        $pixelX = $tileX + $x;
                        $pixelY = $tileY + $y;
                        
                        // Utiliser SDL_RenderDrawPoint pour dessiner un pixel
                        \SDL_RenderDrawPoint(
                            $this->sdl->getRenderer()->getRenderer(),
                            $pixelX,
                            $pixelY
                        );
                    }
                }
            }
        }
    }

    private function fillCollisionZones($tileColisionData, $tileX, $tileY)
    {
        // Algorithme simple : détecter les lignes horizontales de collision
        for ($y = 0; $y < 32; $y++) {
            $startX = -1;
            $endX = -1;
            
            // Trouver le début et la fin de chaque ligne de collision
            for ($x = 0; $x < 32; $x++) {
                if (isset($tileColisionData[$y][$x]) && $tileColisionData[$y][$x] == 1) {
                    if ($startX == -1) {
                        $startX = $x;
                    }
                    $endX = $x;
                } else {
                    // Si on trouve un pixel sans collision, dessiner le rectangle précédent
                    if ($startX != -1 && $endX != -1) {
                        $rect = new \SDL_Rect;
                        $rect->x = $tileX + $startX;
                        $rect->y = $tileY + $y;
                        $rect->w = $endX - $startX + 1;
                        $rect->h = 1;
                        \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $rect);
                        $startX = -1;
                        $endX = -1;
                    }
                }
            }
            
            // Dessiner le dernier rectangle de la ligne si nécessaire
            if ($startX != -1 && $endX != -1) {
                $rect = new \SDL_Rect;
                $rect->x = $tileX + $startX;
                $rect->y = $tileY + $y;
                $rect->w = $endX - $startX + 1;
                $rect->h = 1;
                \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $rect);
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
