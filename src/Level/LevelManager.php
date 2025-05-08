<?php

namespace SonicGame\Level;

use SonicGame\Renderer\Sdl;
use SonicGame\Scene\TileSet;

class LevelManager
{
    private array $levels = [];
    private int $currentLevelIndex = 0;

    public function __construct(private Sdl $sdl)
    {

    }

    public function getCurrentLevel(): Level
    {
        return $this->levels[$this->currentLevelIndex];
    }

    public function nextLevel(): void
    {
        if ($this->currentLevelIndex < count($this->levels) - 1) {
            $this->currentLevelIndex++;
        }
    }

    public function previousLevel(): void
    {
        if ($this->currentLevelIndex > 0) {
            $this->currentLevelIndex--;
        }
    }


    public function loadLevels()
    {
        for ($i = 1 ; $i < 30 ; $i++) {
            $tileSet = new TileSet();
            $level = new Level($tileSet);
            $this->sdl->loadTexture('tileset' . $i, 'tileset/levels/tileset' . $i . '.png');
            $level->setLevel($i);
            $level->setTileSet($this->sdl->getTextures('tileset' . $i));

            // Initialize levels
            $this->levels[] = $level;
        }


        $debug = 1 ;

    }


}
