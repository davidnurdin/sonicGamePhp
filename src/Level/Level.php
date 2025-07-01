<?php

namespace SonicGame\Level;

use SonicGame\AssetManager\AssetManager;
use SonicGame\Renderer\Sdl;
use SonicGame\Scene\TileSet;

class Level
{
    private array $tilemap;
    private int $mapWidth;
    private int $mapHeight;
    private int $currentPositionSoniceYinTile;
    private int $currentPositionSoniceXinTile;
    private array $tilesColision = [];
    private array $tilesColisionWay = [];
    private array $solidity = [];
    private static array $tileColData = [];

    public function __construct(private TileSet $tileSet,private int $level,Sdl $sdl,private AssetManager $assetManager)
    {
        $this->setTileSet($sdl->getTextures('tileset' . $level));
        // Chargement unique de tileCol.data.php
        if (empty(self::$tileColData)) {
            self::$tileColData = require __DIR__ . '/../../assets/levels/tileCol.data.php';
        }
    }

    public function setLevel(int $level)
    {
        $this->level = $level;
    }

    public function setTileSet(mixed $textureTileset)
    {
        $this->tileSet->generateTiles($textureTileset);
    }

    public function getLevel()
    {
        return $this->level;
    }

    public function getTileSet()
    {
        return $this->tileSet ;
    }

    public function readLevelPositionTilesAndMeta()
    {
        $baseFile = $this->assetManager->getAssetFolder() . '/levels/' . 'level' . $this->level ;
        $level = file_get_contents($baseFile . '.bin');
        $levelMeta = eval('return ' . file_get_contents($baseFile .  '.meta') . ';' );

        $this->currentPositionSoniceXinTile = $levelMeta['SX'] ;
        $this->currentPositionSoniceYinTile = $levelMeta['SY'] - 2 ; // TODO : enlever je démarre expres plus haut
        $this->mapHeight = $levelMeta['FH'] ;
        $this->mapWidth = $levelMeta['FW'] ;

        $tilemap = [];

        for ($x = 0 ; $x < $this->mapWidth ; $x++)
        {
            for ($y = 0 ; $y < $this->mapHeight ; $y++)
            {
                if (isset($level[$x + $y * $this->mapWidth]))
                    $tilemap[$y][$x] = ord($level[$x + $y * $this->mapWidth]);
            }
        }

        $this->tilemap = $tilemap;
        // Lecture de la map de collision
        $this->readTileColision();
    }

    private function readTileColision()
    {
        $baseFile = $this->assetManager->getAssetFolder() . '/levels/' . 'level' . $this->level ;
        $colisionFile = $baseFile . '.solidity';
        
        if (!file_exists($colisionFile)) {
            $this->tilesColision = [];
            $this->tilesColisionWay = [];
            $this->solidity = [];
            return;
        }
        
        $solidity = file_get_contents($colisionFile);
        $this->solidity = str_split($solidity);

        $tilesColision = [];
        $tilesColisionWay = [];

        foreach ($this->solidity as $key => $value)
        {
            $value = ord($value);
            if (isset(self::$tileColData[$value])) {
                $tilesColision[$key] = self::$tileColData[$value]['value'];
                $tilesColisionWay[$key] = self::$tileColData[$value]['colisionWay'];
            }
        }

        $this->tilesColision = $tilesColision;
        $this->tilesColisionWay = $tilesColisionWay;
    }

    public function getTilemap()
    {
        return $this->tilemap;
    }

    public function getCurrentPositionSoniceXinTile()
    {
        return $this->currentPositionSoniceXinTile;
    }

    public function getCurrentPositionSoniceYinTile()
    {
        return $this->currentPositionSoniceYinTile;
    }

    public function getMapWidth()
    {
        return $this->mapWidth;
    }

    public function getMapHeight()
    {
        return $this->mapHeight;
    }

    public function getTile(int $x, int $y)
    {
        if (isset($this->tilemap[$y][$x]) === false) {
            return null;
        }

        return $this->tilemap[$y][$x];
    }

    /**
     * Retourne le tableau des valeurs de collision (0/1 par pixel de la tuile 32x32)
     * @return array
     */
    public function getTilesColision(): array
    {
        return $this->tilesColision;
    }


    public function getTileColisionAt(int $x, int $y): ?array
    {
        $tileValue = $this->getTile($x, $y);
        
        if ($tileValue === null) {
            return null;
        }
        
        $tilesColision = $this->getTilesColision();
        
        if (!isset($tilesColision[$tileValue])) {
            return null;
        }
        
        if (!isset($tilesColision[$tileValue])) {
            return null;
        }
        
        $tileColisionData = $tilesColision[$tileValue];
        return $tileColisionData;
    }

    public function getTileColisionWayAt(int $x, int $y): ?array
    {
        $tileValue = $this->getTile($x, $y);
        
        if ($tileValue === null) {
            return null;
        }
        
        $tilesColisionWay = $this->getTilesColisionWay();
        
        if (!isset($tilesColisionWay[$tileValue])) {
            return null;
        }
        
        $tileColisionWayData = $tilesColisionWay[$tileValue];
        return $tileColisionWayData;
    }


    /**
     * Retourne le tableau des directions de collision (top/bottom/left/right)
     * @return array
     */
    public function getTilesColisionWay(): array
    {
        return $this->tilesColisionWay;
    }

    /**
     * Retourne le tableau des données brutes de solidité (données binaires du fichier .solidity)
     * @return array
     */
    public function getSolidity(): array
    {
        return $this->solidity;
    }

}
