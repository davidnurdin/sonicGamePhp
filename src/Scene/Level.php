<?php

namespace SonicGame\Scene;

class Level
{
    private int $level = 1 ;
    public function __construct(private TileSet $tileSet)
    {

        // generate the tiles

    }

    public function setLevel(int $level)
    {
        $this->level = $level;
    }

    public function setLevelName(string $name)
    {
    }

    public function setLevelDescription(string $description)
    {
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
