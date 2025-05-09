<?php

namespace SonicGame\Level;

use Evenement\EventEmitter;
use SonicGame\Renderer\Sdl;
use SonicGame\Scene\TileSet;

class LevelManager extends EventEmitter
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
            if ($this->currentLevelIndex == 20)
                $this->currentLevelIndex++;

            $this->emit('levelChanged', [$this->currentLevelIndex+1]);
        }
    }

    public function previousLevel(): void
    {
        if ($this->currentLevelIndex > 0) {
            $this->currentLevelIndex--;

            if ($this->currentLevelIndex == 20)
                $this->currentLevelIndex--;

            $this->emit('levelChanged', [$this->currentLevelIndex+1]);
        }
    }


    public function convertLevelToTileSet($level)
    {
        $levelNTile[1] = 1 ; $levelNTile[2] = 2 ; $levelNTile[3] = 3 ; // green hill zone
        $levelNTile[4] = 4 ; $levelNTile[5] = 5 ; $levelNTile[6] = 6 ; // bridge
        $levelNTile[7] = 7 ; $levelNTile[8] = 8 ; $levelNTile[9] = 9 ; // jungle
        $levelNTile[10] = 10 ; $levelNTile[11] = 11 ; $levelNTile[12] = 12 ; // labyrinth // tileset13 ??
        $levelNTile[13] = 14 ; $levelNTile[14] = 15 ; $levelNTile[15] = 16 ; // Scrap Brain Act 1
        $levelNTile[16] = 18 ; $levelNTile[17] = 19 ; // Sky Base
        $levelNTile[18] = 26 ; // Skybase Boss
        $levelNTile[19] = 1 ; // $155A4–A5	18: End Sequence
//        $levelNTile[20] = 0 ; // unused level !
        $levelNTile[21] = 14 ; // 20: Scrap Brain Act 2 (Emerald Maze), from corridor
        $levelNTile[22] = 14 ; //	21: Scrap Brain Act 2 (Ballhog Area)
        $levelNTile[23] = 14 ; // 22: Scrap Brain Act 2, from transporter
        $levelNTile[24] = 14 ; // 23: Scrap Brain Act 2 (Emerald Maze), from transporter
        $levelNTile[25] = 14 ; // 24: Scrap Brain Act 2, from Emerald Maze
        $levelNTile[26] = 26 ; //25: Scrap Brain Act 2, from Ballhog Area
        $levelNTile[27] = 27 ; // semble ok

        $levelNTile[28] = 30 ; // BONUS !
        $levelNTile[29] = 30 ; //25: Scrap Brain Act 2, from Ballhog Area
        $levelNTile[30] = 30 ; $levelNTile[31] = 30 ; $levelNTile[32] = 30 ;$levelNTile[33] = 30 ;$levelNTile[34] = 30 ; $levelNTile[35] = 30 ; // bonus

        return $levelNTile[$level];
    }

    public function loadLevels()
    {
        for ($i = 1 ; $i < 30 ; $i++) {
            if ($i == 20)
                continue ;
            $tileSet = new TileSet($this->sdl);
            $tileSet->loadTileSet('tileset' . $i, 'tileset/levels/tileset' . $this->convertLevelToTileSet($i) . '.png');
            $level = new Level($tileSet,$i,$this->sdl);
            $level->readLevelPositionTilesAndMeta();

            // Initialize levels
            $this->levels[] = $level;
        }


        $debug = 1 ;

    }


}
