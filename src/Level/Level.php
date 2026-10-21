<?php

namespace SonicGame\Level;

use SonicGame\AssetManager\AssetManager;
use SonicGame\Entities\GameObjectManager;
use SonicGame\Renderer\Sdl;
use SonicGame\Scene\TileSet;

class Level
{
    private int $version = 0;
    /** getTileTopAt() by block. */
    private array $tileTops = [];
    private array $tilemap;
    private int $mapWidth;
    private int $mapHeight;
    private int $currentPositionSoniceYinTile;
    private int $currentPositionSoniceXinTile;
    private array $tilesColision = [];
    private array $tilesColisionWay = [];
    private array $solidity = [];
    private static array $tileColData = [];
    private array $objects = [];
    private int $solidityIndex = 0;
    private array $meta = [];
    private array $originalTilemap = [];

    public function __construct(private TileSet $tileSet,private int $level,Sdl $sdl,private AssetManager $assetManager)
    {
        $this->setTileSet($sdl->getTextureSizes('tileset' . $level));
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
        // loadObjectLayout: Sonic is loaded like any object, at (SX * 32, SY * 32)
        $this->currentPositionSoniceYinTile = $levelMeta['SY'] ;
        $this->mapHeight = $levelMeta['FH'] ;
        $this->mapWidth = $levelMeta['FW'] ;
        $this->solidityIndex = (int)($levelMeta['SP'] ?? 0);
        $this->meta = $levelMeta;

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
        $this->originalTilemap = $tilemap;
        $this->readTileColision();
        $this->readObjects();
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
        
        $this->applySolidity(file_get_contents($colisionFile));
    }

    /** One collision byte a block (level<N>.solidity): the shapes of tileCol.data.php and their sides. */
    private function applySolidity(string $solidity): void
    {
        $this->solidity = str_split($solidity);
        $this->tileTops = [];

        $tilesColision = [];
        $tilesColisionWay = [];

        foreach ($this->solidity as $key => $value)
        {
            // Bit 7 = block drawn in front of sprites; the collision shape is the low 6 bits (the game does `and $3F`).
            $value = ord($value) & 0x3F;
            if (isset(self::$tileColData[$value])) {
                $tilesColision[$key] = self::$tileColData[$value]['value'];
                $way = self::$tileColData[$value]['colisionWay'];
                // ROM collision tables of _32e2 ($41EC moving up, $448A moving down): $16 and $18-$28 stop
                // Sonic from below (ceilings), $18-$26 are ceilings only, never stood on (Sky Base 3 slanted ceilings)
                $way['top'] = $value === 0x16 || ($value >= 0x18 && $value <= 0x28);
                $way['floor'] = !($value >= 0x18 && $value <= 0x26);
                $tilesColisionWay[$key] = $way;
            }
        }

        $this->tilesColision = $tilesColision;
        $this->tilesColisionWay = $tilesColisionWay;
    }

    /**
     * A level made in the site's editor (LevelManager::loadCustom): {width, height, blocks (base64, one byte a
     * block), start [x, y], objects [[type, x, y]], solidity [byte a block], behaviour [byte a block], music,
     * sp (the zone its tiles come from, 0-6), masks {block: collision drawn in the editor, see applyMask}}.
     */
    public function loadCustom(array $data): void
    {
        $this->mapWidth = (int)$data['width'];
        $this->mapHeight = (int)$data['height'];
        [$this->currentPositionSoniceXinTile, $this->currentPositionSoniceYinTile] = array_map('intval', $data['start']);
        // the zone of its tiles (the platforms' look...); the blocks' behaviours come with the level
        $this->solidityIndex = (int)($data['sp'] ?? 0);
        $this->behaviours = array_map('intval', $data['behaviour'] ?? []);
        // the HUD with rings (SR bit 2), 10 minutes at most (TL bit 5), the camera inside the map
        $this->meta = ['FW' => $this->mapWidth, 'FH' => $this->mapHeight, 'SX' => $this->currentPositionSoniceXinTile,
            'SY' => $this->currentPositionSoniceYinTile, 'SP' => $this->solidityIndex, 'SR' => 4, 'TL' => 32, 'MU' => (int)($data['music'] ?? 0)];
        $blocks = (string)base64_decode((string)$data['blocks']);
        $tilemap = [];
        for ($y = 0; $y < $this->mapHeight; $y++) {
            for ($x = 0; $x < $this->mapWidth; $x++) {
                $tilemap[$y][$x] = ord($blocks[$x + $y * $this->mapWidth] ?? "\0");
            }
        }
        $this->tilemap = $this->originalTilemap = $tilemap;
        $this->version++;
        $this->applySolidity(implode('', array_map(fn($v) => chr((int)$v & 0xFF), $data['solidity'] ?? [])));
        foreach ($data['masks'] ?? [] as $block => $mask) {
            if (is_array($mask)) $this->applyMask((int)$block, $mask);
        }
        $this->objects = array_map(fn(array $o) => ['type' => (int)$o[0], 'typeName' => '', 'x' => (int)$o[1], 'y' => (int)$o[2]],
            $data['objects'] ?? []);
    }

    /**
     * A collision drawn in the site's tile editor, instead of the block's shape: r = 32 rows of 8 hex digits (bit 31 =
     * the left pixel), w = 1 Sonic stands on it, 2 its sides are walls, 4 it stops Sonic from below (a ceiling).
     */
    private function applyMask(int $block, array $mask): void
    {
        $rows = $mask['r'] ?? null;
        if (!is_array($rows) || count($rows) !== 32) return;
        $pixels = [];
        foreach (array_values($rows) as $y => $hex) {
            $hex = is_string($hex) && preg_match('/^[0-9a-f]{8}$/i', $hex) ? $hex : '00000000';
            // a hex digit at a time: ints are 32 bits in the browser (hexdec('ffffffff') is a float there)
            for ($x = 0; $x < 32; $x++) $pixels[$y][$x] = (hexdec($hex[$x >> 2]) >> (3 - ($x & 3))) & 1;
        }
        $w = (int)($mask['w'] ?? 1);
        $this->tilesColision[$block] = $pixels;
        $this->tilesColisionWay[$block] = ['bottom' => ($w & 1) !== 0, 'floor' => ($w & 1) !== 0,
            'left' => ($w & 2) !== 0, 'right' => ($w & 2) !== 0, 'top' => ($w & 4) !== 0];
        unset($this->tileTops[$block]);
    }

    /** null: the ROM table of the zone (blockBehaviour.data.php, by SP); a level of the site: its own. */
    private ?array $behaviours = null;
    private static array $romBehaviours = [];

    /** @return array<int, int> block => behaviour (springs, spikes, conveyors... see CollisionSystem::B_*) */
    public function getBlockBehaviours(): array
    {
        if ($this->behaviours !== null) return $this->behaviours;
        self::$romBehaviours = self::$romBehaviours ?: require __DIR__ . '/../../assets/levels/blockBehaviour.data.php';

        return self::$romBehaviours[$this->solidityIndex] ?? [];
    }

    private function readObjects(): void
    {
        $objectsFile = $this->assetManager->getAssetFolder() . '/levels/level' . $this->level . '.objects.php';
        if (file_exists($objectsFile)) {
            $this->objects = require $objectsFile;
        } else {
            $this->objects = [];
        }
    }

    public function getObjects(): array
    {
        return $this->objects;
    }

    public function getObjectsByType(int $type): array
    {
        return array_filter($this->objects, fn($obj) => $obj['type'] === $type);
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

    public function isSolidAt(int $x, int $y): bool
    {
        if ($x < 0 || $y < 0) return false;
        $col = $this->getTileColisionAt(intdiv($x, 32), intdiv($y, 32));
        return !empty($col[$y % 32][$x % 32]);
    }

    public function findSurfaceY(int $x, int $fromY, int $maxDistance): ?int
    {
        if ($this->isSolidAt($x, $fromY)) {
            for ($y = $fromY - 1; $y >= $fromY - $maxDistance; $y--) {
                if (!$this->isSolidAt($x, $y)) return $y + 1;
            }
            return null;
        }
        for ($y = $fromY + 1; $y <= $fromY + $maxDistance; $y++) {
            if ($this->isSolidAt($x, $y)) return $y;
        }
        return null;
    }

    public function getMeta(string $key, int $default = 0): int
    {
        return (int)($this->meta[$key] ?? $default);
    }

    public function getSolidityIndex(): int
    {
        return $this->solidityIndex;
    }

    /** Online match (VersusNetwork): told of every block that changes, fn(int $x, int $y, int $value). */
    public ?\Closure $tileListener = null;
    /** The replay being recorded (ReplayRecorder::tile), fn(int $x, int $y, int $value). */
    public ?\Closure $tileRecorder = null;

    public function setTile(int $x, int $y, int $value): void
    {
        if (isset($this->tilemap[$y][$x])) {
            if ($this->tileListener !== null && $this->tilemap[$y][$x] !== $value) ($this->tileListener)($x, $y, $value);
            if ($this->tileRecorder !== null && $this->tilemap[$y][$x] !== $value) ($this->tileRecorder)($x, $y, $value);
            // the picture of the block changes (rings taken / back: the same empty block, drawn under the rings)
            if (self::drawnBlock($this->tilemap[$y][$x]) !== self::drawnBlock($value)) $this->version++;
            $this->tilemap[$y][$x] = $value;
        }
    }

    public function resetTilemap(): void
    {
        $this->tilemap = $this->originalTilemap;
        $this->version++;
    }

    /** Block drawn for a map value: ring blocks are drawn empty (Scene draws the rings over them). */
    public static function drawnBlock(int $block): int
    {
        return GameObjectManager::isRingBlock($block) ? GameObjectManager::RING_BLOCK_EMPTY : $block;
    }

    /** Changes each time the picture of a block of the map changes (Scene keeps the drawn blocks). */
    public function getVersion(): int
    {
        return $this->version;
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

    /** First solid row of column $pixelX of the block at ($x, $y) (null: nothing solid), kept per block. */
    public function getTileTopAt(int $x, int $y, int $pixelX): ?int
    {
        $tileValue = $this->getTile($x, $y);
        if ($tileValue === null) return null;
        if (!isset($this->tileTops[$tileValue])) {
            $tops = array_fill(0, 32, null);
            foreach ($this->getTilesColision()[$tileValue] ?? [] as $row => $pixels) {
                foreach ($pixels as $px => $solid) {
                    if ($solid == 1 && $px >= 0 && $px < 32 && ($tops[$px] === null || $row < $tops[$px])) $tops[$px] = $row;
                }
            }
            $this->tileTops[$tileValue] = $tops;
        }
        return $this->tileTops[$tileValue][$pixelX] ?? null;
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
    public function isForegroundBlock(int $tileValue): bool
    {
        return isset($this->solidity[$tileValue]) && (ord($this->solidity[$tileValue]) & 0x80) !== 0;
    }

    public function getSolidity(): array
    {
        return $this->solidity;
    }


}
