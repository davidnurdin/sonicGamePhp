<?php

namespace SonicGame\Scene;

class Level
{
    public function __construct(private TileSet $tileSet,private Camera $camera)
    {

    }
}
