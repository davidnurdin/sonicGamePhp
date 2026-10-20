<?php

namespace SonicGame\Level;

use Evenement\EventEmitter;
use SonicGame\AssetManager\AssetManager;
use SonicGame\Renderer\Sdl;
use SonicGame\Scene\TileSet;

class LevelManager extends EventEmitter
{
    /** The number of a level made in the site's editor (after the 35 of the game). */
    public const CUSTOM = 36;

    private array $levels = [];
    private int $currentLevelIndex = 0;

    public function __construct(private Sdl $sdl,private AssetManager $assetManager)
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
            $this->useCurrentTextures();
            $this->emit('levelChanged', [$this->currentLevelIndex+1]);
        }
    }

    /** Jumps to a level by its number (Scrap Brain transporters). */
    public function goToLevel(int $level): void
    {
        foreach ($this->levels as $index => $candidate) {
            if ($candidate->getLevel() === $level) {
                $this->currentLevelIndex = $index;
                $this->useCurrentTextures();
                $this->emit('levelChanged', [$level]);
                return;
            }
        }
    }

    /** Back to the first level (game over / new game). */
    public function firstLevel(): void
    {
        if ($this->currentLevelIndex !== 0) {
            $this->currentLevelIndex = 0;
            $this->useCurrentTextures();
            $this->emit('levelChanged', [1]);
        }
    }

    public function previousLevel(): void
    {
        if ($this->currentLevelIndex > 0) {
            $this->currentLevelIndex--;
            $this->useCurrentTextures();
            $this->emit('levelChanged', [$this->currentLevelIndex+1]);
        }
    }


    /** Only the blocks of the current level stay in memory: the others are freed, its own loaded now. */
    private function useCurrentTextures(): void
    {
        $group = 'tileset' . $this->convertLevelToTileSet($this->getCurrentLevel()->getLevel());
        $this->sdl->releaseGroupsExcept($group);
        $this->sdl->loadGroup($group);
    }

    /**
     * A level of the site's editor: $file (JSON, see Level::loadCustom) and its tiles next to it (tiles.png: 32px
     * blocks, 16 a row). It becomes level CUSTOM, played like the others. False if the files are not right.
     */
    public function loadCustom(string $file): bool
    {
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $png = dirname($file) . '/tiles.png';
        if (!is_array($data) || !isset($data['width'], $data['height'], $data['blocks'], $data['start']) || !is_file($png)) return false;
        // the textures are found from the assets folder
        $path = self::relativePath($this->assetManager->getAssetFolder(), realpath($png));
        $group = 'tileset' . $this->convertLevelToTileSet(self::CUSTOM);
        $name = 'tileset' . self::CUSTOM;
        $this->sdl->releaseTexture($name);
        $this->sdl->registerTexture($name, $path, $group);
        // the blocks in front of Sonic: the same picture (its transparent pixels stay transparent)
        $this->sdl->registerTexture($name . '-fg', $path, $group);
        $level = new Level(new TileSet($this->sdl), self::CUSTOM, $this->sdl, $this->assetManager);
        $level->loadCustom($data);
        foreach ($this->levels as $index => $candidate) {
            if ($candidate->getLevel() === self::CUSTOM) {
                $this->levels[$index] = $level;
                return true;
            }
        }
        $this->levels[] = $level;
        return true;
    }

    private static function relativePath(string $from, string $to): string
    {
        $from = explode('/', trim($from, '/'));
        $to = explode('/', trim($to, '/'));
        while ($from && $to && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        return str_repeat('../', count($from)) . implode('/', $to);
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
        // From level 20 the files follow the ROM level index ($13 has no header): 20 = $14 ... 35 = $23.
        // $14-$19 are the Scrap Brain act 2 rooms reached by the transporters (same art / blocks as act 2).
        $levelNTile[20] = 15 ; // $14: Scrap Brain Act 2 (Emerald Maze), from corridor
        $levelNTile[21] = 15 ; // $15: Scrap Brain Act 2 (Ballhog Area)
        $levelNTile[22] = 15 ; // $16: Scrap Brain Act 2, from transporter
        $levelNTile[23] = 15 ; // $17: Scrap Brain Act 2 (Emerald Maze), from transporter
        $levelNTile[24] = 15 ; // $18: Scrap Brain Act 2, from Emerald Maze
        $levelNTile[25] = 15 ; // $19: Scrap Brain Act 2, from Ballhog Area
        $levelNTile[26] = 26 ; // $1A: Sky Base interior
        $levelNTile[27] = 27 ; // $1B: Sky Base interior
        $levelNTile[self::CUSTOM] = 'custom'; // a level of the site's editor (loadCustom)

        // Bonus stages: tileset31 is the complete set decoded from the ROM (the ripped tileset30 stops at block 54,
        // which misses the ring background block 120 among others).
        for ($bonus = 28; $bonus <= 35; $bonus++) {
            $levelNTile[$bonus] = 31;
        }

        return $levelNTile[$level];
    }

    public function loadLevels()
    {
        for ($i = 1 ; $i <= 35 ; $i++) {
            $tileSet = new TileSet($this->sdl);
            $tileSetFile = 'tileset/levels/tileset' . $this->convertLevelToTileSet($i);
            // the textures of a level are loaded when it is played (useCurrentTextures), grouped by file
            $group = 'tileset' . $this->convertLevelToTileSet($i);
            $tileSet->loadTileSet('tileset' . $i, $tileSetFile . '.png', $group);
            // Labyrinth: fixed underwater palette below the water line (tools/waterpalette.py)
            foreach (['', '-fg'] as $layer) {
                if (file_exists($this->assetManager->getAssetFolder() . '/' . $tileSetFile . '-water' . $layer . '.png')) {
                    $this->sdl->registerTexture('tileset' . $i . $layer . '-water', $tileSetFile . '-water' . $layer . '.png', $group);
                }
            }
            // Palette-cycle steps generated from the ROM (water, waterfalls, lights...)
            for ($k = 0; file_exists($this->assetManager->getAssetFolder() . '/' . $tileSetFile . '-cycle' . $k . '.png'); $k++) {
                $tileSet->loadTileSet('tileset' . $i . '-cycle' . $k, $tileSetFile . '-cycle' . $k . '.png', $group);
            }
            // bonus stages: colour 14 white, the flash of the big bumpers (tools/bumperflash.py)
            if (file_exists($this->assetManager->getAssetFolder() . '/' . $tileSetFile . '-flash.png')) {
                $tileSet->loadTileSet('tileset' . $i . '-flash', $tileSetFile . '-flash.png', $group);
            }
            // Sky Base 1 lightning palettes (tools/lightning.py)
            $variants = ['', '-cycle0', '-cycle1', '-cycle2', '-cycle3', '-cycle4', '-cycle5', '-cycle6', '-cycle7'];
            foreach ([1, 2] as $light) {
                for ($k = 0; file_exists($this->assetManager->getAssetFolder() . '/' . $tileSetFile . '-light' . $light . '-cycle' . $k . '.png'); $k++) {
                    $tileSet->loadTileSet('tileset' . $i . '-light' . $light . '-cycle' . $k, $tileSetFile . '-light' . $light . '-cycle' . $k . '.png', $group);
                    $variants[] = '-light' . $light . '-cycle' . $k;
                }
            }
            // Foreground layers: priority blocks (solidity bit 7) drawn over the sprites, colour 0 left transparent.
            foreach ($variants as $variant) {
                if (file_exists($this->assetManager->getAssetFolder() . '/' . $tileSetFile . $variant . '-fg.png')) {
                    $tileSet->loadTileSet('tileset' . $i . $variant . '-fg', $tileSetFile . $variant . '-fg.png', $group);
                }
            }
            $level = new Level($tileSet,$i,$this->sdl,$this->assetManager);
            $level->readLevelPositionTilesAndMeta();

            // Initialize levels
            $this->levels[] = $level;
        }
        $this->useCurrentTextures();


        $debug = 1 ;

    }


}
