<?php

namespace SonicGame\Level;

use SonicGame\Scene\TileSet;

class Level
{
    private int $level = 1 ;
    public function __construct(private TileSet $tileSet)
    {


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

}
