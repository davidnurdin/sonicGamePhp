<?php

namespace SonicGame\Level;

use Evenement\EventEmitter;
use SonicGame\AssetManager\AssetManager;
use SonicGame\Renderer\Sdl;
use SonicGame\Save\Options;
use SonicGame\Scene\Screens;
use SonicGame\Scene\TileSet;

class LevelManager extends EventEmitter
{
    /** The number of a level made in the site's editor (after the 35 of the game). */
    public const CUSTOM = 36;

    /**
     * The sheets of sprites a level of the site may draw again (Game::run loads them; tileset/sprites/tileset-<name>.png),
     * with their colour key. The level's own ones replace them while it is played.
     */
    public const SPRITE_SHEETS = [
        'sonic' => ['r' => 0, 'g' => 72, 'b' => 0],
        'enemies' => ['r' => 170, 'g' => 0, 'b' => 170],
        'objects' => ['r' => 170, 'g' => 0, 'b' => 170],
        'rom' => ['r' => 170, 'g' => 0, 'b' => 170],
        'eggman' => ['r' => 0, 'g' => 170, 'b' => 255],
        'boss' => ['r' => 170, 'g' => 0, 'b' => 170],
    ];
    /** The underwater and boss-hit variants of the sheets (files next to them): the level's sprites stand for them too. */
    private const SPRITE_VARIANTS = ['-water', '-flash'];

    private array $levels = [];
    private int $currentLevelIndex = 0;
    /** @var array<string, string> sheet => its picture for the level CUSTOM (path from the assets folder) */
    private array $customSprites = [];
    /** @var array<string, string> sheet => the level's picture drawn now instead of the game's */
    private array $spritesShown = [];
    /** The level's pictures were written again (another level of the site): load them again. */
    private bool $spritesChanged = false;
    /** The level CUSTOM's own music (music.wav), and its sound effects: the game's SFX index => its WAV. */
    private ?string $customMusic = null;
    /** @var array<int, string> */
    private array $customSfx = [];
    /** Up by one each time the level CUSTOM is loaded (its files may have been written again). */
    private int $customVersion = 0;
    /** The id on the site of the level CUSTOM loaded (null: none, or not from the site). */
    private ?int $customId = null;
    /** The level CUSTOM as the matches and the replays name it: {id, lt (its key), name}, or null. */
    private ?array $customSite = null;
    /** Who made the level CUSTOM on the site (his name there), or null. */
    private ?string $customAuthor = null;

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
        $this->useCurrentSprites();
    }

    /** The music of the level played, if it is a level of the site with its own (music.wav), or null. */
    public function getCustomMusic(): ?string
    {
        return $this->getCurrentLevel()->getLevel() === self::CUSTOM ? $this->customMusic : null;
    }

    /** @return array<int, string> the sound effects of the level played instead of the game's (a level of the site) */
    public function getCustomSfx(): array
    {
        return $this->getCurrentLevel()->getLevel() === self::CUSTOM ? $this->customSfx : [];
    }

    /** The id on the site of the level of the site loaded (multiplayer: is it the one of the match?), or null. */
    /** The level CUSTOM loaded, from the site: {id, lt, name} (Options::validSite), or null. */
    public function getCustomSite(): ?array
    {
        return $this->customSite;
    }

    private ?string $customContentVersion = null;
    /** @var array{name: string, time: float, ghost: ?string}|null the best time of the level of the site loaded */
    private ?array $customRecord = null;

    /** What the best times and the ghosts of the level of the site loaded are set on (the site's Level::getContentVersion). */
    public function getCustomContentVersion(): ?string
    {
        return $this->customContentVersion;
    }

    /** @return array{name: string, time: float, ghost: ?string}|null its record: who, the time, its replay file (or null) */
    public function getCustomRecord(): ?array
    {
        return $this->customRecord;
    }

    public function getCustomAuthor(): ?string
    {
        return $this->customAuthor;
    }

    public function getCustomId(): ?int
    {
        return $this->customId;
    }

    /** Changes each time a level of the site is loaded: its music and sounds must be read again. */
    public function getCustomVersion(): int
    {
        return $this->customVersion;
    }

    /** The level CUSTOM's own sprites while it is played, the game's ones otherwise. */
    private function useCurrentSprites(): void
    {
        $wanted = $this->getCurrentLevel()->getLevel() === self::CUSTOM ? $this->customSprites : [];
        foreach (self::SPRITE_SHEETS as $sheet => $key) {
            $path = $wanted[$sheet] ?? null;
            $shown = $this->spritesShown[$sheet] ?? null;
            if ($path === $shown && ($path === null || !$this->spritesChanged)) continue;
            $original = 'tileset/sprites/tileset-' . $sheet . '.png';
            try {
                $this->sdl->replaceTexture($sheet, $path ?? $original, $key);
                foreach (self::SPRITE_VARIANTS as $variant) {
                    $file = substr($original, 0, -4) . $variant . '.png';
                    if (!is_file($this->assetManager->getAssetFolder() . '/' . $file)) continue;
                    // the level's picture stands for the variant (no underwater palette of its own)
                    $this->sdl->replaceTexture($sheet . $variant, $path ?? $file, $key);
                }
                if ($path === null) unset($this->spritesShown[$sheet]); else $this->spritesShown[$sheet] = $path;
            } catch (\RuntimeException $e) {
                // a picture that does not load: the game's one
                error_log('[sprites] ' . $sheet . ': ' . $e->getMessage());
                if ($path !== null) {
                    $this->sdl->replaceTexture($sheet, $original, $key);
                    unset($this->spritesShown[$sheet]);
                }
            }
        }
        $this->spritesChanged = false;
    }

    /** The zones of the site's editor (the "zone" of a level made with one as it is) => their first level. */
    private const ZONES = ['green-hill' => 1, 'bridge' => 4, 'jungle' => 7, 'labyrinth' => 10, 'scrap-brain' => 13, 'sky-base' => 16, 'bonus' => 28];
    /** The textures of the level CUSTOM now registered (after its name). */
    private array $customVariants = [];

    /**
     * Registers the pictures of the zone of level $zone as $name: the plain one, its colour cycling, the lightning
     * (Sky Base), the flash (bonus), under water (Labyrinth), each with its front layer.
     */
    private function registerZoneTextures(string $name, int $zone, string $group): void
    {
        $file = 'tileset/levels/tileset' . $this->convertLevelToTileSet($zone);
        $folder = $this->assetManager->getAssetFolder() . '/' . $file;
        // [the file's suffix, the texture's] (under water the front layer is "-fg-water", its file "-water-fg")
        $layers = [];
        foreach (array_merge(['', '-flash'], self::suffixes($folder, '-cycle'), self::suffixes($folder, '-light1-cycle'),
            self::suffixes($folder, '-light2-cycle')) as $variant) {
            $layers[] = [$variant, $variant];
            $layers[] = [$variant . '-fg', $variant . '-fg'];
        }
        $layers[] = ['-water', '-water'];
        $layers[] = ['-water-fg', '-fg-water'];
        $this->customVariants = [];
        foreach ($layers as [$fileSuffix, $textureSuffix]) {
            if (!file_exists($folder . $fileSuffix . '.png')) continue;
            $this->sdl->registerTexture($name . $textureSuffix, $file . $fileSuffix . '.png', $group);
            $this->customVariants[] = $textureSuffix;
        }
    }

    /** "-cycle0", "-cycle1"... while "<$folder><$prefix><k>.png" exists. */
    private static function suffixes(string $folder, string $prefix): array
    {
        $out = [];
        for ($k = 0; file_exists($folder . $prefix . $k . '.png'); $k++) $out[] = $prefix . $k;
        return $out;
    }

    /**
     * The other pictures of a level of the site made by the site from its own (TileVariants.php of the site):
     * tiles<variant>.png next to it, each with its front layer (the same picture).
     */
    private function registerCustomVariants(string $name, string $folder, array $variants, string $group): void
    {
        foreach ($variants as $variant) {
            if (!is_string($variant) || !preg_match('/^-(cycle\d{1,2}|light[12]-cycle\d{1,2}|water|flash)$/', $variant)) continue;
            $png = $folder . '/tiles' . $variant . '.png';
            if (!is_file($png)) continue;
            $path = self::relativePath($this->assetManager->getAssetFolder(), realpath($png));
            $this->sdl->registerTexture($name . $variant, $path, $group);
            $front = $variant === '-water' ? '-fg-water' : $variant . '-fg';
            $this->sdl->registerTexture($name . $front, $path, $group);
            array_push($this->customVariants, $variant, $front);
        }
    }

    /**
     * A level of the site's editor: $file (JSON, see Level::loadCustom) and its tiles next to it (tiles.png: 32px
     * blocks, 16 a row), and its own sprites if it has some ("sprites": the sheets whose sprite-<sheet>.png is there).
     * It becomes level CUSTOM, played like the others. False if the files are not right.
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
        $zone = self::ZONES[$data['zone'] ?? ''] ?? null;
        foreach ($this->customVariants as $variant) $this->sdl->releaseTexture($name . $variant);
        $this->customVariants = ['', '-fg'];
        if ($zone !== null) {
            // a zone of the game as it is: its own pictures, colour cycling (water, waterfalls...) and front layers
            $this->registerZoneTextures($name, $zone, $group);
        } else {
            $this->sdl->registerTexture($name, $path, $group);
            // the blocks in front of Sonic: the same picture (its transparent pixels stay transparent)
            $this->sdl->registerTexture($name . '-fg', $path, $group);
            $this->registerCustomVariants($name, dirname($file), is_array($data['tile_variants'] ?? null) ? $data['tile_variants'] : [], $group);
        }
        $level = new Level(new TileSet($this->sdl), self::CUSTOM, $this->sdl, $this->assetManager);
        $level->loadCustom($data);
        if ($zone !== null) {
            $act = $this->levels[$zone - 1];
            $level->useColourCycling($act->getMeta('CC'), $act->getMeta('CS'), ($act->getMeta('TL') & 0x02) !== 0);
        } elseif (is_array($data['cycle'] ?? null)) {
            $level->useColourCycling((int)($data['cycle']['count'] ?? 0), (int)($data['cycle']['speed'] ?? 0), !empty($data['cycle']['lightning']));
        }
        // its own sprites (sprite-<sheet>.png next to it, the size of the game's sheet), used from its start
        $this->customSprites = [];
        foreach (is_array($data['sprites'] ?? null) ? $data['sprites'] : [] as $sheet) {
            if (!is_string($sheet) || !isset(self::SPRITE_SHEETS[$sheet])) continue;
            $picture = dirname($file) . '/sprite-' . $sheet . '.png';
            $size = is_file($picture) ? @getimagesize($picture) : false;
            $original = @getimagesize($this->assetManager->getAssetFolder() . '/tileset/sprites/tileset-' . $sheet . '.png');
            if ($size === false || $original === false || $size[0] !== $original[0] || $size[1] !== $original[1]) {
                error_log('[sprites] ' . $sheet . ': not the size of the game\'s sheet');
                continue;
            }
            $this->customSprites[$sheet] = self::relativePath($this->assetManager->getAssetFolder(), realpath($picture));
        }
        $this->spritesChanged = true;
        // its own music and sound effects (music.wav, sfx-<index>.wav next to it)
        $folder = dirname($file);
        $this->customMusic = !empty($data['custom_music']) && is_file($folder . '/music.wav') ? $folder . '/music.wav' : null;
        $this->customSfx = [];
        foreach (is_array($data['custom_sfx'] ?? null) ? $data['custom_sfx'] : [] as $index) {
            if (is_int($index) && is_file($folder . '/sfx-' . $index . '.wav')) $this->customSfx[$index] = $folder . '/sfx-' . $index . '.wav';
        }
        $this->customVersion++;
        $this->customId = is_int($data['id'] ?? null) ? $data['id'] : null;
        $author = Screens::plain((string)($data['author'] ?? ''), 8);
        $this->customAuthor = $author !== '' ? $author : null;
        $this->customSite = Options::validSite(['id' => $this->customId, 'lt' => (string)($data['lt'] ?? ''),
            'name' => Screens::plain($data['name'] ?? '', 20)]);
        // what its best times are set on, its best time (the first record) and its replay (record.json.gz next to it: its ghost)
        $this->customContentVersion = is_string($data['version'] ?? null) && preg_match('/^[0-9a-f]{16}$/', $data['version']) ? $data['version'] : null;
        $first = is_array($data['records'][0] ?? null) ? $data['records'][0] : null;
        $this->customRecord = $first !== null && is_numeric($first['time'] ?? null) ? ['name' => Screens::plain($first['name'] ?? '', 8),
            'time' => (float)$first['time'], 'ghost' => !empty($data['record_ghost']) && is_file($folder . '/record.json.gz') ? $folder . '/record.json.gz' : null] : null;
        foreach ($this->levels as $index => $candidate) {
            if ($candidate->getLevel() === self::CUSTOM) {
                $this->levels[$index] = $level;
                // played again (a level of the site after another one): no level change will load them
                if ($index === $this->currentLevelIndex) $this->useCurrentSprites();
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
