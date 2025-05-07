<?php

namespace SonicGame\Scene;

class TileSet
{
    private array $tiles = [];

    public function __construct(private int $tileWidth = 32,private int $tileHeight = 32)
    {
    }

}
