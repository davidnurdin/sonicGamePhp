<?php

namespace SonicGame\Level;

use SonicGame\Renderer\Sdl;
use SonicGame\Scene\TileSet;

class Level
{
    private array $tilemap;
    private int $mapWidth;
    private int $mapHeight;
    private int $currentPositionSoniceYinTile;
    private int $currentPositionSoniceXinTile;

    public function __construct(private TileSet $tileSet,private int $level,Sdl $sdl)
    {
        $this->setTileSet($sdl->getTextures('tileset' . $level));
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

        $baseFile =__DIR__ . '/../'.'Resources/levels/' . 'level' . $this->level ;
        $level = file_get_contents($baseFile . '.bin');
        $levelMeta = eval('return ' . file_get_contents($baseFile .  '.meta') . ';' );

        $this->currentPositionSoniceXinTile = $levelMeta['SX'] ;
        $this->currentPositionSoniceYinTile = $levelMeta['SY'] ;
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



}
