<?php

namespace SonicGame\Scene;

use Evenement\EventEmitter;
use SonicGame\Entities\GameObjectManager;
use SonicGame\Entities\Objects\Flower;
use SonicGame\Entities\Player;
use SonicGame\Level\Level;
use SonicGame\Level\LevelManager;
use SonicGame\Renderer\Sdl;
use SonicGame\SoundManager\SoundManager;

class Scene extends EventEmitter
{

    private int $debugMode = 0;
    private ?Level $currentLevel = null ;
    private array $collisionTiles = [];
    private ?GameObjectManager $objectManager = null;
    private ?HUD $hud = null;

    public function __construct(private Camera $camera, private Sdl $sdl,private Player $player)
    {
        $this->camera->setScene($this);
        $this->camera->stickTo($this->player);
        $this->hud = new HUD($sdl);
    }

    public function setObjectManager(GameObjectManager $objectManager): void
    {
        $this->objectManager = $objectManager;
    }

    public function getPlayer()
    {
        return $this->player;
    }

    public function getCurrentLevel()
    {
         return $this->currentLevel;
    }

    /**
     * Écoute l'événement collisionTile du CollisionSystem
     */
    public function listenToCollisionEvents($collisionSystem)
    {
        $collisionSystem->on('collisionTile', function($data) {
            $this->handleCollisionTile($data);
        });
    }

    /**
     * Gère l'événement collisionTile
     */
    private function handleCollisionTile($data)
    {
        $tileKey = $data['tileX'] . ',' . $data['tileY'];
        
        // Stocke les informations de collision pour debug
        $this->collisionTiles[$tileKey] = [
            'tileX' => $data['tileX'],
            'tileY' => $data['tileY'],
            'tileValue' => $data['tileValue'],
            'timestamp' => microtime(true)
        ];

        // Debug: affiche les informations de collision
        // echo "Scene: Collision tile détectée à ({$data['tileX']}, {$data['tileY']}) - Tile value: {$data['tileValue']}\n";
        
        // Émet un événement pour informer d'autres composants
        // $this->emit('collisionTileRendered', $data);
    }

    /**
     * Récupère les tiles de collision pour debug
     */
    public function getCollisionTiles(): array
    {
        return $this->collisionTiles;
    }

    /**
     * Nettoie les anciennes tiles de collision (plus de 1 seconde)
     */
    public function cleanupOldCollisionTiles()
    {
        $currentTime = microtime(true);
        $this->collisionTiles = array_filter($this->collisionTiles, function($tile) use ($currentTime) {
            return ($currentTime - $tile['timestamp']) < 1.0; // Garde seulement 1 seconde
        });
    }

    public function drawScene($font, Level $level)
    {
        if ($this->currentLevel === null)
        {
            // init level
            $this->currentLevel = &$level ;
            // init player
            // the act starts again from the checkpoint after a death
            [$startX, $startY] = $this->objectManager?->getCheckpoint() ?? [$level->getCurrentPositionSoniceXinTile() * 32, $level->getCurrentPositionSoniceYinTile() * 32];
            $this->getPlayer()->setXY($startX, $startY);
            // init camera
            $this->camera->startLevel($level);
			$this->camera->noSmooth = true;
            $this->camera->stickTo($this->player);
			$this->camera->update();

        }

        $this->drawWorld($font, $level, $this->player);

        // Labyrinth: below the water line everything uses the underwater palette
        $waterLine = $this->objectManager?->getWaterLine();
        if ($waterLine !== null && $this->debugMode != 1) {
            $screenLine = (int)round($waterLine - $this->camera->getY());
            if ($screenLine < $this->sdl->getWindow()->getHeight()) {
                $this->drawUnderwater($font, $level, max(0, $screenLine));
            }
        }

        if ($this->debugMode == 0) {
            $this->maskOutsideLevel();
        }

        // Afficher le HUD en dernier (par-dessus tout)
        if ($this->hud && $this->objectManager) {
            $this->hud->draw($this->objectManager, $level);
            $this->drawAirCountdown();
        }

    }

    private function drawWorld($font, Level $level, Player $player): void
    {
        // Ne pas afficher les tiles normales en mode debug 1
        if ($this->debugMode != 1) {
            $this->drawTiles($level);
        }

        // Afficher le debug en premier (en arrière-plan)
        if ($this->debugMode) {
            $this->drawDebug($font);
        }

        // Afficher les game objects (enemies, monitors, etc.)
        $this->drawGameObjects();

        // Afficher le joueur par-dessus
        $this->drawPlayer($player);

        // Blocs prioritaires (arbres, piliers...) devant les sprites, comme le VDP de la SMS
        if ($this->debugMode != 1) {
            $this->drawForeground($level);
        }
    }

    /** Black outside of the area the original screen could show (Camera::getVisibleArea). */
    private function maskOutsideLevel(): void
    {
        [$left, $right, $top, $bottom] = $this->camera->getVisibleArea();
        $w = $this->sdl->getWindow()->getWidth();
        $h = $this->sdl->getWindow()->getHeight();
        $x0 = (int)round($left - $this->camera->getX());
        $x1 = (int)round($right - $this->camera->getX());
        $y0 = (int)round($top - $this->camera->getY());
        $y1 = (int)round($bottom - $this->camera->getY());
        $renderer = $this->sdl->getRenderer()->getRenderer();
        \SDL_SetRenderDrawColor($renderer, 0, 0, 0, 255);
        foreach ([[0, 0, $x0, $h], [$x1, 0, $w - $x1, $h], [0, 0, $w, $y0], [0, $y1, $w, $h - $y1]] as [$x, $y, $rw, $rh]) {
            if ($rw <= 0 || $rh <= 0) continue;
            $rect = new \SDL_Rect;
            $rect->x = $x;
            $rect->y = $y;
            $rect->w = $rw;
            $rect->h = $rh;
            \SDL_RenderFillRect($renderer, $rect);
        }
    }

    /** @var resource|null render target of the underwater pass */
    private $waterTexture = null;
    private bool $underwaterPass = false;
    private int $lastLightningPhase = 0;
    private array $waterSize = [0, 0];
    private const WATER_WAVE = [152, 56, 16, 7];
    // _8e16: X of the surface sprites, 4 of them per frame
    private const WATER_WAVE_X = [0x00, 0x40, 0x80, 0xC0, 0x10, 0x50, 0x90, 0xD0, 0x20, 0x60, 0xA0, 0xE0, 0x30, 0x70, 0xB0, 0xF0];

    /**
     * The original reloads the palette with S1_UnderwaterPalette on a line interrupt at the water line:
     * the world is drawn a second time with the "-water" textures (tools/waterpalette.py) into another
     * target and only the part below the line is copied over. The surface sprites follow (object $40).
     */
    private function drawUnderwater($font, Level $level, int $screenLine): void
    {
        $renderer = $this->sdl->getRenderer()->getRenderer();
        $winW = $this->sdl->getWindow()->getWidth();
        $winH = $this->sdl->getWindow()->getHeight();
        if ($this->waterTexture !== null && $this->waterSize !== [$winW, $winH]) {
            \SDL_DestroyTexture($this->waterTexture);   // the view size changed (zoom)
            $this->waterTexture = null;
        }
        $this->waterTexture ??= \SDL_CreateTexture($renderer, \SDL_PIXELFORMAT_RGBA8888, \SDL_TEXTUREACCESS_TARGET, $winW, $winH);
        $this->waterSize = [$winW, $winH];

        \SDL_SetRenderTarget($renderer, $this->waterTexture);
        $this->sdl->getRenderer()->clear();
        $this->sdl->setTextureVariant('-water');
        $this->underwaterPass = true;
        $this->drawWorld($font, $level, $this->player);
        $this->underwaterPass = false;
        $this->sdl->setTextureVariant('');
        \SDL_SetRenderTarget($renderer, $this->sdl->getRenderer()->getRenderTexture());

        $rect = new \SDL_Rect;
        $rect->x = 0;
        $rect->y = $screenLine;
        $rect->w = $winW;
        $rect->h = $winH - $screenLine;
        \SDL_RenderCopy($renderer, $this->waterTexture, $rect, $rect);

        // surface sprites, scrolling 1px per frame over a 16px period
        $frames = $this->objectManager->getWaterFrames();
        $y = (int)round($this->objectManager->getWaterLine() - $this->camera->getY()) - 3;
        if ($y < -8) return;
        $texture = $this->sdl->getTextures('objects')[0]['texture'];
        $src = new \SDL_Rect;
        [$src->x, $src->y, $src->w, $src->h] = self::WATER_WAVE;
        for ($base = 0; $base < $winW; $base += 256) {
            foreach (self::WATER_WAVE_X as $x) {
                $dst = new \SDL_Rect;
                $dst->x = $base + $x + ($frames & 0x0F);
                $dst->y = $y;
                $dst->w = $src->w;
                $dst->h = $src->h;
                \SDL_RenderCopy($renderer, $texture, $src, $dst);
            }
        }
    }

    /** Drowning countdown over Sonic (_5009: Sonic X + 8, Y - 20, shown every other frame). */
    private function drawAirCountdown(): void
    {
        $digit = $this->objectManager->getAirCountdown();
        if ($digit === null || ((int)(microtime(true) * 30) & 1)) return;
        $src = new \SDL_Rect;
        [$src->x, $src->y, $src->w, $src->h] = [544 + 8 * $digit, 107, 8, 13];
        $dst = new \SDL_Rect;
        $dst->x = (int)round($this->player->getX() - $this->camera->getX() + 8);
        $dst->y = (int)round($this->player->getY() - $this->camera->getY() - 20);
        $dst->w = 8;
        $dst->h = 13;
        \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures('objects')[0]['texture'], $src, $dst);
    }

    public function drawPlayer(Player $player)
    {
        if ($this->objectManager && $this->objectManager->isPlayerHidden()) return;
        if ($this->objectManager && $this->objectManager->isPlayerHurt()) {
            $blink = (int)(microtime(true) * 10) % 2;
            if ($blink === 0) return;
        }

        $destRect = new \SDL_Rect;
        // the frames of the sheet are 32px wide with Sonic's 24px sprite 4px in: the sprite is drawn over the
        // 24px box of the original (the collision rect), so that he touches the walls without entering them
        $destRect->x = $player->getX() - 4 - $this->camera->getX();
        $destRect->y = $player->getY() - $this->camera->getY() ; // + 16;
        $destRect->w = 32;
        $destRect->h = 32;

        // thrown up by a spring / a balance (animation $13): frame $26 of the ROM art; water slide (animation $11)
        $spring = $player->getSpringFrame();
        $slide = $player->getSlideFrame();
        if ($slide !== null && !($this->objectManager && $this->objectManager->hasShield() && (int)floor(microtime(true) * 60) % 2 === 0)) {
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = \SonicGame\Entities\Objects\RomSprites::SONIC_SLIDE[$slide];
            $dst = new \SDL_Rect;
            [$dst->x, $dst->y, $dst->w, $dst->h] = [$destRect->x + 4, $destRect->y, 24, 32];
            \SDL_RenderCopyEx($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures(\SonicGame\Entities\Objects\RomSprites::SHEET)[0]['texture'],
                $src, $dst, 0, null, $player->getFacing() === 'left' ? \SDL_FLIP_HORIZONTAL : \SDL_FLIP_NONE);
            if ($this->objectManager && $this->objectManager->hasInvincibility()) {
                $this->drawInvincibilityStars($destRect->x + 16, $destRect->y + 16);
            }
            return;
        }
        if ($spring !== null && !($this->objectManager && $this->objectManager->hasShield() && (int)floor(microtime(true) * 60) % 2 === 0)) {
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = \SonicGame\Entities\Objects\RomSprites::SONIC_SPRING;
            $dst = new \SDL_Rect;
            [$dst->x, $dst->y, $dst->w, $dst->h] = [$destRect->x + 4, $destRect->y, 24, 32];
            \SDL_RenderCopyEx($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures(\SonicGame\Entities\Objects\RomSprites::SHEET)[0]['texture'],
                $src, $dst, 0, null, $player->getFacing() === 'left' ? \SDL_FLIP_HORIZONTAL : \SDL_FLIP_NONE);
            if ($this->objectManager && $this->objectManager->hasInvincibility()) {
                $this->drawInvincibilityStars($destRect->x + 16, $destRect->y + 16);
            }
            return;
        }

        // waiting (animation $0D): frames of the ROM art
        $wait = $player->getWaitFrame();
        if ($wait !== null && !($this->objectManager && $this->objectManager->hasShield() && (int)floor(microtime(true) * 60) % 2 === 0)) {
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = \SonicGame\Entities\Objects\RomSprites::SONIC_WAIT[$wait];
            $dst = new \SDL_Rect;
            [$dst->x, $dst->y, $dst->w, $dst->h] = [$destRect->x + 4, $destRect->y, 24, 32];
            \SDL_RenderCopyEx($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures(\SonicGame\Entities\Objects\RomSprites::SHEET)[0]['texture'],
                $src, $dst, 0, null, $player->getFacing() === 'left' ? \SDL_FLIP_HORIZONTAL : \SDL_FLIP_NONE);
            return;
        }
        // shield (_5206): every other frame Sonic's sprite is replaced by the ring of frame $18
        if ($this->objectManager && $this->objectManager->hasShield() && (int)floor(microtime(true) * 60) % 2 === 0) {
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = \SonicGame\Entities\Objects\RomSprites::SHIELD;
            $dst = new \SDL_Rect;
            [$dst->x, $dst->y, $dst->w, $dst->h] = [$destRect->x + 4, $destRect->y, 24, 32];
            \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures(\SonicGame\Entities\Objects\RomSprites::SHEET)[0]['texture'], $src, $dst);
        } else {
            $player->draw($destRect);
        }

        if ($this->objectManager && $this->objectManager->hasInvincibility()) {
            $this->drawInvincibilityStars($destRect->x + 16, $destRect->y + 16);
        }
    }

    private function drawInvincibilityStars(int $centerX, int $centerY): void
    {
        $t = microtime(true);
        $src = new \SDL_Rect;
        $src->x = ((int)($t * 12) % 2) ? 560 : 608;
        $src->y = 72;
        $src->w = 16;
        $src->h = 16;
        for ($i = 0; $i < 4; $i++) {
            $angle = $t * 5 + $i * M_PI / 2;
            $dst = new \SDL_Rect;
            $dst->x = (int)round($centerX + cos($angle) * 18 - 8);
            $dst->y = (int)round($centerY + sin($angle) * 18 - 8);
            $dst->w = 16;
            $dst->h = 16;
            \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures('objects')[0]['texture'], $src, $dst);
        }
    }

    public function getCamera()
    {
        return $this->camera;
    }


    public function drawTiles(Level $level)
    {
	//	return ;
        $tileSet = $level->getTileSet();
        /** @var Level $level */

        // On limite l'affichage à la taille de la fenêtre

        $mapHeight = $level->getMapHeight();
        $mapWidth = $level->getMapWidth();


        $cameraX = $this->camera->getX();
        $cameraY = $this->camera->getY();
        $tileSize = 32;

        $winW = $this->sdl->getWindow()->getWidth();
        $winH = $this->sdl->getWindow()->getHeight();

//        $maxWidth = floor($this->sdl->getWindow()->getWidth()/$tileSet->getWidth()) - 1 ;
//        $maxHeight = floor($this->sdl->getWindow()->getHeight()/$tileSet->getHeight()) -1 ;

        // the camera can be outside of the map (level narrower than the view): screen position = world - camera
        $startCol = max(0, (int)floor($cameraX / $tileSize));
        $endCol = min($mapWidth, (int)ceil(($cameraX + $winW) / $tileSize));
        $offsetX = $startCol * $tileSize - $cameraX;

        $startRow = max(0, (int)floor($cameraY / $tileSize));
        $endRow = min($mapHeight, (int)ceil(($cameraY + $winH) / $tileSize));
        $offsetY = $startRow * $tileSize - $cameraY;

        $texturesTileSet = $this->sdl->getTextures($this->currentTilesetTextureName($level));

		$previewMore = 2 ;

        $maxWidth = floor(4096 / 32) * 32; // = 4064
        $ringOverlays = [];
        $this->foregroundBlocks = [];
        for ($y = $startRow; $y < $endRow + $previewMore; $y++) {
            for ($x = $startCol; $x < $endCol + $previewMore; $x++) {
                $tileValue = $level->getTile($x, $y);
                if ($tileValue === null)
                    continue;

                // Ring blocks: draw the ring-free background block, the animated rings are overlaid afterwards.
                if (GameObjectManager::isRingBlock($tileValue)) {
                    $ringOverlays[] = [($x - $startCol) * $tileSize + $offsetX, ($y - $startRow) * $tileSize + $offsetY, $tileValue];
                    $tileValue = GameObjectManager::RING_BLOCK_EMPTY;
                } elseif ($level->isForegroundBlock($tileValue)) {
                    $this->foregroundBlocks[] = [($x - $startCol) * $tileSize + $offsetX, ($y - $startRow) * $tileSize + $offsetY, $tileValue];
                }

                /** @var TileSet $tileSet */
                $tileRect = $tileSet->getTile($tileValue);

                if ($tileRect) {
                    $dstRect = new \SDL_Rect;
                    $dstRect->x = ($x - $startCol) * $tileSize + $offsetX;
                    // $dstRect->y = $y * $tileSize + (int)$cameraY;
                    $dstRect->y = ($y - $startRow) * $tileSize + $offsetY;
                    $dstRect->w = $tileSize;
                    $dstRect->h = $tileSize;

                    // found $textureTileset

                    $indexTexture = (int)floor($tileValue*$tileSize / $maxWidth);
                    if ($indexTexture > 0)
                    {
                        $debug = 1;
                    }
                    $textureTileSet = $texturesTileSet[$indexTexture]['texture'];


                    // with api native sdl
                    \SDL_RenderCopyEx(
                        $this->sdl->getRenderer()->getRenderer(),
                        $textureTileSet,
                        $tileRect,
                        $dstRect,
                        0,
                        null,
                        \SDL_FLIP_NONE
                    );
                }
            }
        }



        $this->drawRingOverlays($ringOverlays);
    }

    // Ring rotation frames (tileset-objects.png), 16x16 cells centred on each frame; the original shows each for 10 frames at 60Hz.
    private const RING_FRAMES = [[536, 72], [520, 72], [504, 72], [488, 72], [472, 72], [456, 72]];

    private function drawRingOverlays(array $overlays): void
    {
        if (empty($overlays)) return;
        $texture = $this->sdl->getTextures('objects')[0]['texture'];
        $frame = self::RING_FRAMES[(int)floor(microtime(true) * 6) % 6];
        $src = new \SDL_Rect;
        $src->x = $frame[0];
        $src->y = $frame[1];
        $src->w = 16;
        $src->h = 16;
        $renderer = $this->sdl->getRenderer()->getRenderer();

        foreach ($overlays as [$sx, $sy, $block]) {
            foreach ([1 => 0, 2 => 16] as $bit => $offsetX) {
                if (!($block & $bit)) continue;
                $dst = new \SDL_Rect;
                $dst->x = $sx + $offsetX;
                $dst->y = $sy + 8;
                $dst->w = 16;
                $dst->h = 16;
                \SDL_RenderCopy($renderer, $texture, $src, $dst);
            }
        }
    }

    /** Blocks collected by drawTiles() whose solidity has the priority bit: redrawn over the sprites. */
    private array $foregroundBlocks = [];

    private function drawForeground(Level $level): void
    {
        if (empty($this->foregroundBlocks)) return;
        $name = $this->currentTilesetTextureName($level) . '-fg';
        if (!$this->sdl->hasTexture($name)) {
            $name = 'tileset' . $level->getLevel() . '-fg';
            if (!$this->sdl->hasTexture($name)) return;
        }
        $textures = $this->sdl->getTextures($name);
        $tileSet = $level->getTileSet();
        $maxWidth = floor(4096 / 32) * 32;
        $renderer = $this->sdl->getRenderer()->getRenderer();
        foreach ($this->foregroundBlocks as [$sx, $sy, $block]) {
            $src = $tileSet->getTile($block);
            $texture = $textures[(int)floor($block * 32 / $maxWidth)]['texture'] ?? null;
            if (!$src || !$texture) continue;
            $dst = new \SDL_Rect;
            $dst->x = $sx;
            $dst->y = $sy;
            $dst->w = 32;
            $dst->h = 32;
            \SDL_RenderCopy($renderer, $texture, $src, $dst);
        }
    }

    /** Colour cycling like the original: step every CS frames (60Hz) through CC palettes. */
    private function currentTilesetTextureName(Level $level): string
    {
        $base = 'tileset' . $level->getLevel();
        // no palette cycling under water, the underwater palette is fixed
        if ($this->underwaterPass && $this->sdl->hasTexture($base . '-water')) {
            return $base;
        }
        if ($this->objectManager && $this->objectManager->isPaletteFlashWhite() && $this->sdl->hasTexture($base . '-flash')) {
            return $base . '-flash';
        }
        $count = $level->getMeta('CC');
        $speed = max(1, $level->getMeta('CS'));
        // TL bit 1, Sky Base 1 lightning (_1f49): 171-frame loop, flash palettes from frame 100 and 130
        // (thunder at 130), every palette cycling 4 steps at speed 2
        if (($level->getMeta('TL') & 0x02) && $this->sdl->hasTexture($base . '-light1-cycle0')) {
            $frame = (int)floor(microtime(true) * 60);
            $phase = $frame % 171;
            $light = $phase >= 130 ? 2 : ($phase >= 100 ? 1 : 0);
            if ($light === 2 && $this->lastLightningPhase < 130) {
                $this->emit('sfx', [SoundManager::SFX_ELECTRIC]);
            }
            $this->lastLightningPhase = $phase;
            $step = intdiv($frame, 2) % 4;
            return $light ? $base . '-light' . $light . '-cycle' . $step : $base . '-cycle' . $step;
        }
        if ($count <= 1 || !$this->sdl->hasTexture($base . '-cycle0')) {
            return $base;
        }
        $step = (int)floor(microtime(true) * 60 / $speed) % $count;
        return $base . '-cycle' . $step;
    }

    public function setDebugMode(int $debugMode)
    {
        $this->debugMode = $debugMode;
    }

    private function drawDebug($fontTab)
    {
        $this->drawDebugTiles($fontTab, $this->debugMode);
    }

    private function drawDebugTiles($fontTab, $debugType = 1)
    {
        if (!$this->currentLevel) {
            return;
        }

        $level = $this->currentLevel;
        $cameraX = $this->camera->getX();
        $cameraY = $this->camera->getY();
        $tileSize = 32;

        $winW = $this->sdl->getWindow()->getWidth();
        $winH = $this->sdl->getWindow()->getHeight();

        $startCol = (int)floor($cameraX / $tileSize);
        $endCol = (int)ceil(($winW/$tileSize) + ($cameraX/$tileSize));
        $offsetX = -(int)($cameraX % $tileSize);

        $startRow = (int)floor($cameraY / $tileSize);
        $endRow = (int)ceil(($winH/$tileSize) + ($cameraY/$tileSize));
        $offsetY = -(int)($cameraY % $tileSize);

        $startRow = max(0, $startRow);
        $endRow = min($level->getMapHeight(), $endRow);

        $startCol = max(0, $startCol);
        $endCol = min($level->getMapWidth(), $endCol);

        $previewMore = 2;

        // Définir la couleur jaune pour les borders
        \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 255, 255, 0, 255);

        // Afficher les valeurs des tiles
        for ($y = $startRow; $y < $endRow + $previewMore; $y++) {
            for ($x = $startCol; $x < $endCol + $previewMore; $x++) {
                $tileValue = $level->getTile($x, $y);
                if ($tileValue === null) {
                    continue;
                }

                // Calculer l'index de la tile
                $tileIndex = $x + $y * $level->getMapWidth();

                // Choisir la valeur à afficher selon le type de debug
                if ($debugType == 1) {
                    // Mode 1 : Valeurs des tiles
                    $char = strtoupper(dechex($tileValue));
                    if (strlen($char) == 1) {
                        $char = '0' . $char;
                    }
                }
                elseif ($debugType == 4)
                {
                    // Mode 4 : Coordonnées logiques des tiles (X/Y)
                    // Afficher X et Y séparément, en limitant à un chiffre chacun pour la lisibilité
                    $charX = substr((string)$x, -1); // Dernier chiffre de X
                    $charY = substr((string)$y, -1); // Dernier chiffre de Y
                    $char = $charX . $charY;
                }
                else {

                    
                    // Mode 2 : Valeurs de collision
                    $solidity = $level->getSolidity();

                    if (isset($solidity[$tileValue])) {
                        $colisionIndexTile = ord($solidity[$tileValue]);
                        

                        $char = strtoupper(dechex($colisionIndexTile));
                        if (strlen($char) == 1) {
                            $char = '0' . $char;
                        }
                    } else {
                        $char = '00'; // Pas de collision
                    }
                }

                $dstRect = new \SDL_Rect;
                $dstRect->x = ($x - $startCol) * $tileSize + $offsetX;
                $dstRect->y = ($y - $startRow) * $tileSize + $offsetY;
                $dstRect->w = $tileSize;
                $dstRect->h = $tileSize;

                // Dessiner le border jaune autour de la tile (seulement le contour)
                $x1 = $dstRect->x;
                $y1 = $dstRect->y;
                $x2 = $dstRect->x + $dstRect->w - 1;
                $y2 = $dstRect->y + $dstRect->h - 1;
                
                // Rectangle jaune plein (border)
                $borderRect = new \SDL_Rect;
                $borderRect->x = $x1;
                $borderRect->y = $y1;
                $borderRect->w = $dstRect->w;
                $borderRect->h = $dstRect->h;
                \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 255, 255, 0, 255); // Jaune
                \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $borderRect);
                
                // Rectangle noir plus petit à l'intérieur
                $innerRect = new \SDL_Rect;
                $innerRect->x = $x1 + 2;
                $innerRect->y = $y1 + 2;
                $innerRect->w = $dstRect->w - 4;
                $innerRect->h = $dstRect->h - 4;
                \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 0, 0, 0, 255); // Noir
                \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $innerRect);
                
                // Remettre la couleur jaune pour le texte
                \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 255, 255, 0, 255);

                // Afficher les 2 caractères hexadécimaux séparément
                if (isset($fontTab[substr($char, 0, 1)]) && isset($fontTab[substr($char, 1, 1)])) {
                    // Premier caractère (gauche)
                    $dstRectFont = new \SDL_Rect;
                    $dstRectFont->x = $dstRect->x + 4;
                    $dstRectFont->y = $dstRect->y + 4;
                    $dstRectFont->w = 12;
                    $dstRectFont->h = 24;
                    
                    \SDL_RenderCopyEx(
                        $this->sdl->getRenderer()->getRenderer(),
                        $fontTab[substr($char, 0, 1)],
                        new \SDL_Rect(0, 0, 32, 32),
                        $dstRectFont,
                        0,
                        null,
                        \SDL_FLIP_NONE
                    );
                    
                    // Deuxième caractère (droite)
                    $dstRectFont2 = new \SDL_Rect;
                    $dstRectFont2->x = $dstRect->x + 16;
                    $dstRectFont2->y = $dstRect->y + 4;
                    $dstRectFont2->w = 12;
                    $dstRectFont2->h = 24;
                    
                    \SDL_RenderCopyEx(
                        $this->sdl->getRenderer()->getRenderer(),
                        $fontTab[substr($char, 1, 1)],
                        new \SDL_Rect(0, 0, 32, 32),
                        $dstRectFont2,
                        0,
                        null,
                        \SDL_FLIP_NONE
                    );
                }

                
                // En mode debug 2, afficher les zones de collision
                if ($debugType == 3) {

                    if ($colision = $level->getTileColisionAt($x, $y))
                    {
                        \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 0, 255, 255, 128); // Semi-transparent
                            
                        // Calculer les zones de collision et les remplir

                        $this->fillCollisionZones($level->getTileColisionAt($x, $y) , $dstRect->x, $dstRect->y);
                        
                        // $this->drawCollisionPixelRand($colision, $dstRect->x, $dstRect->y);

                        // Remettre la couleur jaune pour les borders
                        \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), 255, 255, 0, 255);
                    }


                }

                
            }
        }

        // En mode debug 2, dessiner les rectangles multicolors autour des tiles en collision
        if ($debugType == 2) {
            $this->drawCollisionTilesMulticolor();
        }
    }

    /**
     * Dessine des rectangles multicolors autour des tiles en collision
     */
    private function drawCollisionTilesMulticolor()
    {
        $cameraX = $this->camera->getX();
        $cameraY = $this->camera->getY();
        $tileSize = 32;

        foreach ($this->collisionTiles as $tileKey => $tileData) {
            $tileX = $tileData['tileX'];
            $tileY = $tileData['tileY'];
            
            // Calculer la position à l'écran
            $screenX = $tileX * $tileSize - $cameraX;
            $screenY = $tileY * $tileSize - $cameraY;
            
            // Vérifier si la tile est visible à l'écran
            if ($screenX >= -$tileSize && $screenX <= $this->sdl->getWindow()->getWidth() &&
                $screenY >= -$tileSize && $screenY <= $this->sdl->getWindow()->getHeight()) {
                
                // Créer un rectangle multicolor (arc-en-ciel)
                $this->drawRainbowBorder($screenX, $screenY, $tileSize);
            }
        }
    }

    /**
     * Dessine une bordure arc-en-ciel autour d'une tile
     */
    private function drawRainbowBorder($x, $y, $size)
    {
        $colors = [
            [255, 0, 0],    // Rouge
            [255, 127, 0],  // Orange
            [255, 255, 0],  // Jaune
            [0, 255, 0],    // Vert
            [0, 0, 255],    // Bleu
            [75, 0, 130],   // Indigo
            [148, 0, 211]   // Violet
        ];
        
        
        shuffle($colors);
        
        $colorIndex = 0;
        $borderWidth = 2;
        
        // Dessiner les 4 côtés avec des couleurs différentes
        for ($i = 0; $i < 4; $i++) {
            $color = $colors[$colorIndex % count($colors)];
            \SDL_SetRenderDrawColor($this->sdl->getRenderer()->getRenderer(), $color[0], $color[1], $color[2], 255);
            
            $rect = new \SDL_Rect;
            
            switch ($i) {
                case 0: // Haut
                    $rect->x = $x;
                    $rect->y = $y;
                    $rect->w = $size;
                    $rect->h = $borderWidth;
                    break;
                case 1: // Droite
                    $rect->x = $x + $size - $borderWidth;
                    $rect->y = $y;
                    $rect->w = $borderWidth;
                    $rect->h = $size;
                    break;
                case 2: // Bas
                    $rect->x = $x;
                    $rect->y = $y + $size - $borderWidth;
                    $rect->w = $size;
                    $rect->h = $borderWidth;
                    break;
                case 3: // Gauche
                    $rect->x = $x;
                    $rect->y = $y;
                    $rect->w = $borderWidth;
                    $rect->h = $size;
                    break;
            }
            
            \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $rect);
            $colorIndex++;
        }
    }

    private function drawCollisionPixelRand($tileColisionData, $tileX, $tileY)
    {
        // Parcourir les 32x32 pixels de la tile
        for ($y = 0; $y < 32; $y++) {
            for ($x = 0; $x < 32; $x++) {
                // Vérifier si rand(1,10) == 5 (10% de chance)
                if (rand(1, 20) == 5) {
                    // Vérifier si le pixel est dans une zone de collision
                    if (isset($tileColisionData[$y][$x]) && $tileColisionData[$y][$x] == 1) {
                        // Dessiner un pixel à cette position
                        $pixelX = $tileX + $x;
                        $pixelY = $tileY + $y;
                        
                        // Utiliser SDL_RenderDrawPoint pour dessiner un pixel
                        \SDL_RenderDrawPoint(
                            $this->sdl->getRenderer()->getRenderer(),
                            $pixelX,
                            $pixelY
                        );
                    }
                }
            }
        }
    }

    private function fillCollisionZones($tileColisionData, $tileX, $tileY)
    {
        // Algorithme simple : détecter les lignes horizontales de collision
        for ($y = 0; $y < 32; $y++) {
            $startX = -1;
            $endX = -1;
            
            // Trouver le début et la fin de chaque ligne de collision
            for ($x = 0; $x < 32; $x++) {
                if (isset($tileColisionData[$y][$x]) && $tileColisionData[$y][$x] == 1) {
                    if ($startX == -1) {
                        $startX = $x;
                    }
                    $endX = $x;
                } else {
                    // Si on trouve un pixel sans collision, dessiner le rectangle précédent
                    if ($startX != -1 && $endX != -1) {
                        $rect = new \SDL_Rect;
                        $rect->x = $tileX + $startX;
                        $rect->y = $tileY + $y;
                        $rect->w = $endX - $startX + 1;
                        $rect->h = 1;
                        \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $rect);
                        $startX = -1;
                        $endX = -1;
                    }
                }
            }
            
            // Dessiner le dernier rectangle de la ligne si nécessaire
            if ($startX != -1 && $endX != -1) {
                $rect = new \SDL_Rect;
                $rect->x = $tileX + $startX;
                $rect->y = $tileY + $y;
                $rect->w = $endX - $startX + 1;
                $rect->h = 1;
                \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $rect);
            }
        }
    }

    private function drawGameObjects(): void
    {
        if (!$this->objectManager || !$this->currentLevel) return;

        $cameraX = $this->camera->getX();
        $cameraY = $this->camera->getY();
        $winW = $this->sdl->getWindow()->getWidth();
        $winH = $this->sdl->getWindow()->getHeight();

        $visibleObjects = $this->objectManager->getVisibleObjects($cameraX, $cameraY, $winW, $winH);

        // flowers are background tiles in the original: behind every sprite
        usort($visibleObjects, fn($a, $b) => ($b instanceof Flower) <=> ($a instanceof Flower));
        foreach ($visibleObjects as $obj) {
            $obj->render($cameraX, $cameraY);
        }
    }

    public function setPlayer(Player $player)
    {
        $this->player = $player;
    }

    public function resetLevel()
    {
        $this->currentLevel = null;
    }
}
