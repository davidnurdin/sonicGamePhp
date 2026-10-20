<?php

namespace SonicGame\Entities;

use Evenement\EventEmitter;
use SonicGame\Entities\Objects\Badnik;
use SonicGame\Entities\Objects\ActivityWindow;
use SonicGame\Entities\Objects\Boss;
use SonicGame\Entities\Objects\Bubble;
use SonicGame\Entities\Objects\BubbleMaker;
use SonicGame\Entities\Objects\Door;
use SonicGame\Entities\Objects\ElectricBeam;
use SonicGame\Entities\Objects\FinalShip;
use SonicGame\Entities\Objects\Flipper;
use SonicGame\Entities\Objects\MovingBumper;
use SonicGame\Entities\Objects\SkyCloud;
use SonicGame\Entities\Objects\SpikeWall;
use SonicGame\Entities\Objects\ElectricSphere;
use SonicGame\Entities\Objects\FireballCannon;
use SonicGame\Entities\Objects\FireballHead;
use SonicGame\Entities\Objects\FlameThrower;
use SonicGame\Entities\Objects\FloorSwitch;
use SonicGame\Entities\Objects\Flower;
use SonicGame\Entities\Objects\Mechanism;
use SonicGame\Entities\Objects\BridgePiece;
use SonicGame\Entities\Objects\Seesaw;
use SonicGame\Entities\Objects\SkyCannon;
use SonicGame\Entities\Objects\SkyTurret;
use SonicGame\Entities\Objects\Propeller;
use SonicGame\Entities\Objects\Spear;
use SonicGame\Entities\Objects\SpikeBall;
use SonicGame\Entities\Objects\TripZone;
use SonicGame\Entities\Objects\Water;
use SonicGame\Entities\Objects\WarpZone;
use SonicGame\Entities\Objects\BossPart;
use SonicGame\Entities\Objects\Capsule;
use SonicGame\Entities\Objects\ChaosEmerald;
use SonicGame\Entities\Objects\Effect;
use SonicGame\Entities\Objects\EndSign;
use SonicGame\Entities\Objects\Monitor;
use SonicGame\Entities\Objects\Platform;
use SonicGame\Entities\Objects\Projectile;
use SonicGame\Entities\Objects\RomSprites;
use SonicGame\Entities\Objects\SwingingPlatform;
use SonicGame\Level\Level;
use SonicGame\Renderer\Sdl;
use SonicGame\SoundManager\SoundManager;

class GameObjectManager extends EventEmitter
{
    // Ring blocks: (block & 0x7F) in 0x79..0x7B, bit 0 = left ring, bit 1 = right ring; 0x78 is the empty background.
    public const RING_BLOCK_EMPTY = 0x78;
    private const RING_POP = [[537, 72, 14, 16], [522, 72, 11, 16], [508, 72, 8, 16], [494, 72, 4, 16], [476, 72, 8, 16], [459, 72, 11, 16]];

    /** @var GameObject[] */
    private array $objects = [];
    /** @var GameObject[] */
    private array $transient = [];
    private ?Level $level = null;
    private int $currentLevel = 0;

    private int $rings = 0;
    private int $score = 0;
    private int $lives = 3;
    // continues ($D284): one per act finished after taking a continue monitor (flags9 bit 3, _1726)
    private int $continues = 0;
    private bool $continuePending = false;
    private int $emeralds = 0;
    private float $levelTime = 0;

    private bool $hurt = false;
    private float $hurtTimer = 0;
    private bool $shield = false;
    private float $invincibleTimer = 0;
    private float $speedShoesTimer = 0;

    private bool $dead = false;
    private float $deadTimer = 0;
    // _543c: death fall in ROM px/frame, frames since the death, $D287 (decremented every other frame)
    private float $deadSpeed = 0;
    private int $deadFrames = 0;
    private int $deadLength = 0;
    private bool $drowned = false;
    private ?array $checkpoint = null;

    private ?EndSign $sign = null;
    /** x of the end sign of the act (kept when the sign itself is left out, see $noEnemies). */
    private ?float $endSignX = null;
    /** Multiplayer: the act without badniks and without end sign (Versus has its own finish line). */
    public bool $noEnemies = false;
    private float $signDoneTimer = 0;
    private bool $levelComplete = false;
    private ?Platform $standingOn = null;
    private ?Seesaw $onSeesaw = null;
    private float $wave = 0;
    private ?Boss $boss = null;
    private ?Capsule $capsule = null;
    private bool $onCapsule = false;
    private bool $bossMusic = false;
    private bool $signStopped = false;
    private string $signEffect = 'none';
    private bool $specialStagePending = false;
    /** Button 2 held (the flippers of the special stages). */
    private bool $buttonHeld = false;

    // Labyrinth water (object $40) and air counter ($D29B, 60Hz frames spent underwater)
    private ?Water $water = null;
    private bool $underwater = false;
    private int $airFrames = 0;
    private float $airClock = 0;
    // Level switch bit ($D317), flipped by the switch $1D, read by the switch doors $1E
    private bool $switchFlag = false;
    private ?FloorSwitch $onSwitch = null;
    /** Sonic walked onto the switch he stands on (landing on it from a jump does not press it). */
    private bool $switchWalked = false;
    /** $D317+: one switch bit per level, kept for the whole game (getLevelBitFlag), so a room keeps its doors open. */
    private array $switchFlags = [];
    // end of the game (level 19): object $53, then Sonic walks back (6 emeralds) and the emerald $54 rises
    private ?FinalShip $finalShip = null;
    private string $finalPhase = '';
    private int $finalFrames = 0;
    private float $finalClock = 0;
    private ?Effect $finalGlow = null;
    private ?ChaosEmerald $finalEmerald = null;
    /** Counters of the special bonus of the final tally ($D280-$D283). */
    private array $stats = ['oneUps' => 0, 'specialEntered' => 0, 'specialCleared' => 0, 'fellInPit' => false];
    /** Original camera position (see Camera::getRomCamera), for the object activity windows. */
    private array $romCamera = [0, 0];
    /** Our real view: top-left x, y, width, height. */
    private ?array $view = null;

    public function __construct(private Sdl $sdl)
    {
    }

    public function loadLevel(Level $level, bool $keepCheckpoint = false): void
    {
        $this->level = $level;
        $this->currentLevel = $level->getLevel();
        if (!$keepCheckpoint || !$this->keepTilemap) $level->resetTilemap();
        $this->objects = [];
        $this->transient = [];
        $this->sign = null;
        $this->signDoneTimer = 0;
        $this->levelComplete = false;
        $this->standingOn = null;
        $this->boss = null;
        $this->capsule = null;
        $this->onCapsule = false;
        $this->bossMusic = false;
        $this->signStopped = false;
        $this->signRunDone = false;
        $this->water = null;
        $this->underwater = false;
        $this->airFrames = 0;
        $this->switchFlag = $this->switchFlags[$level->getLevel()] ?? false;
        $this->onSwitch = null;
        $this->switchWalked = false;
        $this->finalShip = null;
        $this->finalPhase = '';
        $this->finalGlow = null;
        $this->finalEmerald = null;
        $this->levelTime = 0;
        // cheat: every act (and every restart) with 50 rings
        $this->rings = $this->cheat('rings') ? 50 : 0;
        if (!$keepCheckpoint) {
            $this->checkpoint = null;
        }

        $platformSprite = match ($level->getSolidityIndex()) {
            0 => Platform::SPRITE_GREEN_HILL,
            3, 5, 7 => Platform::SPRITE_SKY,
            default => Platform::SPRITE_LOG,
        };

        foreach ($level->getObjects() as $o) {
            $type = $o['type'];
            $tx = $o['x'];
            $ty = $o['y'];
            $obj = null;

            if (Monitor::supports($type)) {
                $obj = new Monitor($this->sdl, $type, $o['typeName'], $tx, $ty);
            } elseif (Badnik::supports($type)) {
                if ($this->noEnemies) continue;
                $obj = new Badnik($this->sdl, $type, $o['typeName'], $tx, $ty);
            } elseif (Boss::supports($type)) {
                $obj = $this->boss = new Boss($this->sdl, $type, $o['typeName'], $tx, $ty);
            } elseif ($type === 0x25) {
                $obj = $this->capsule = new Capsule($this->sdl, $tx, $ty);
            } elseif ($type === 0x40) {
                $this->water = new Water($ty * 32);
            } else {
                $obj = match ($type) {
                    // multiplayer in a special stage: no emerald to take
                    0x06 => $this->noEnemies ? null : new ChaosEmerald($this->sdl, $tx, $ty),
                    0x07 => $this->sign = new EndSign($this->sdl, $tx, $ty),
                    0x09 => new SwingingPlatform($this->sdl, $tx, $ty, $platformSprite),
                    0x0B, 0x0C, 0x0F, 0x27, 0x28, 0x29, 0x38, 0x3B => new Platform($this->sdl, $type, $o['typeName'], $tx, $ty,
                        self::romPlatformSprite($type, $level->getSolidityIndex()), RomSprites::SHEET),
                    0x45 => new Platform($this->sdl, $type, $o['typeName'], $tx, $ty, $platformSprite),
                    0x50 => new Flower($this->sdl, $tx, $ty),
                    0x4E => new Seesaw($this->sdl, $tx, $ty),
                    0x4B => new TripZone($this->sdl, $tx, $ty),
                    0x46 => new ElectricBeam($this->sdl, $tx, $ty),
                    0x53 => $this->finalShip = new FinalShip($this->sdl, $tx, $ty),
                    // Sky Base
                    0x3A, 0x37 => new SkyTurret($this->sdl, $type, $tx, $ty),
                    0x33 => new SkyCannon($this->sdl, $tx, $ty),
                    0x31 => new Propeller($this->sdl, $tx, $ty),
                    0x30 => new SkyCloud($this->sdl, $tx, $ty),
                    // special stages
                    0x21 => new MovingBumper($this->sdl, $tx, $ty),
                    0x4C => new Flipper($this->sdl, $tx, $ty),
                    0x39 => new SpikeWall($this->sdl, $tx, $ty),
                    // Labyrinth
                    0x41 => new BubbleMaker($this->sdl, $tx, $ty),
                    0x3D => new SpikeBall($this->sdl, $tx, $ty),
                    0x3E => new Spear($this->sdl, $tx, $ty),
                    0x3F => new FireballHead($this->sdl, $tx, $ty, (int)$level->getTile($tx, $ty)),
                    // Scrap Brain
                    0x13 => new WarpZone($this->sdl, $tx, $ty),
                    0x14, 0x15 => new FireballCannon($this->sdl, $type, $o['typeName'], $tx, $ty),
                    0x16 => new FlameThrower($this->sdl, $tx, $ty),
                    0x17, 0x18, 0x19, 0x1E => (new Door($this->sdl, $type, $o['typeName'], $tx, $ty, $level->getTile($tx, $ty - 1)))
                        ->settle($this->switchFlag),
                    0x1A => new ElectricSphere($this->sdl, $tx, $ty),
                    0x1D => new FloorSwitch($this->sdl, $tx, $ty, $level->findSurfaceY($tx * 32 + 16, $ty * 32 + 16, 48) ?? $ty * 32 + 32),
                    default => null,
                };
            }

            if ($obj instanceof EndSign) {
                $this->endSignX = $obj->getPosX();
                if ($this->noEnemies) {
                    $this->sign = null;
                    continue;
                }
            }
            if ($obj !== null) {
                $obj->setLevel($level);
                $this->objects[] = $obj;
            }
        }
        // multiplayer: a respawn doesn't bring back the monitors and badniks gone; a new match does
        if (!$keepCheckpoint || !$this->keepTilemap) $this->goneObjects = [];
        foreach (array_keys($this->goneObjects) as $id) {
            $obj = $this->objects[$id] ?? null;
            if ($obj instanceof Monitor) $obj->breakMonitor();
            if ($obj instanceof Badnik) $obj->destroy();
        }
    }

    /** Sprite of a platform in its ROM routine, which depends on the level solidity (tools/romsprites.py). */
    private static function romPlatformSprite(int $type, int $solidity): array
    {
        return match ($type) {
            0x0B => RomSprites::GH_PLATFORM,
            0x0C => $solidity === 0 ? RomSprites::GH_PLATFORM : RomSprites::LOG_PLATFORM,
            0x0F => match ($solidity) { 0 => RomSprites::GH_PLATFORM, 1 => RomSprites::BRIDGE_PLATFORM, default => RomSprites::LOG_PLATFORM },
            0x27 => RomSprites::LOG_VERTICAL,
            0x28 => RomSprites::LOG_PLATFORM,
            0x29 => RomSprites::LOG_ROLL[0],
            0x38 => RomSprites::SKY_PLATFORM,
            0x3B => $solidity === 1 ? RomSprites::BRIDGE_PLATFORM : RomSprites::SKY_PLATFORM,
        };
    }

    /** Ground provided by solid objects (platforms, monitors) for the collision system. */
    public function findObjectGround(array $rect, float $vy, string $playerState): ?array
    {
        if ($this->dead || $vy < 0) return null;
        $attacking = $playerState === 'jump' || $playerState === 'roll';
        $best = null;

        foreach ($this->objects as $obj) {
            if (!$obj->isActive()) continue;
            if ($obj instanceof Platform) {
                if (!$obj->isPlayerOnTop($rect, $vy)) continue;
                $top = $obj->getPosY();
                if ($best === null || $top < $best['top']) {
                    $best = ['top' => $top, 'dx' => $obj->getDeltaX(), 'object' => $obj];
                }
            } elseif ($obj instanceof FloorSwitch) {
                $feet = $rect['y'] + $rect['height'];
                $top = $obj->getTop();
                $overlapX = $rect['x'] + $rect['width'] > $obj->getPosX() + 3 && $rect['x'] < $obj->getPosX() + 13;
                // _LABEL_3956_11 + _7CC1: touching it while not going up puts Sonic on top, so he steps onto it walking
                if ($overlapX && $feet >= $top - 2 && $feet <= $obj->getBottom() + 2 && ($best === null || $top < $best['top'])) {
                    $best = ['top' => $top, 'dx' => 0, 'object' => $obj];
                }
            } elseif ($obj instanceof BridgePiece) {
                // _LABEL_7CC1_12: Sonic is put on the plank and follows it down
                $feet = $rect['y'] + $rect['height'];
                $top = $obj->getTop();
                $overlapX = $rect['x'] + $rect['width'] > $obj->getPosX() + 2 && $rect['x'] < $obj->getPosX() + 14;
                if ($overlapX && $feet >= $top - 6 && $feet <= $top + 10 && ($best === null || $top < $best['top'])) {
                    $best = ['top' => $top, 'dx' => 0, 'object' => $obj, 'force' => $obj->isFalling()];
                }
            } elseif ($obj instanceof Seesaw) {
                $feet = $rect['y'] + $rect['height'];
                foreach ($obj->getPads() as [$x, $top, $w, $pad]) {
                    $overlapX = $rect['x'] + $rect['width'] > $x + 2 && $rect['x'] < $x + $w - 2;
                    if ($overlapX && $feet >= $top - 4 && $feet <= $top + 12 && ($best === null || $top < $best['top'])) {
                        $best = ['top' => $top, 'dx' => 0, 'object' => $obj, 'pad' => $pad];
                    }
                }
            } elseif ($obj instanceof Monitor && !$attacking) {
                $feet = $rect['y'] + $rect['height'];
                $top = $obj->getPosY();
                $overlapX = $rect['x'] + $rect['width'] > $obj->getPosX() + 2 && $rect['x'] < $obj->getPosX() + $obj->getWidth() - 2;
                if ($overlapX && $feet >= $top - 2 && $feet <= $top + 10 && ($best === null || $top < $best['top'])) {
                    $best = ['top' => $top, 'dx' => 0, 'object' => $obj];
                }
            }
        }

        $this->onCapsule = false;
        if ($this->capsule && !$this->capsule->isFinished()) {
            $c = $this->capsule;
            $feet = $rect['y'] + $rect['height'];
            $centerX = $rect['x'] + $rect['width'] / 2;
            $top = $c->getTopAt($centerX);
            if ($top !== null && $feet >= $top - 4 && $feet <= $top + 10 && ($best === null || $top < $best['top'])) {
                $best = ['top' => $top, 'dx' => 0, 'object' => $c];
                $this->onCapsule = $c->isButtonAt($centerX);
            }
        }

        if ($best === null) {
            $this->standingOn = null;
            $this->onSwitch = null;
            $this->onSeesaw = null;
            return null;
        }
        $this->standingOn = $best['object'] instanceof Platform ? $best['object'] : null;
        $this->onSeesaw = $best['object'] instanceof Seesaw ? $best['object'] : null;
        $this->onSeesaw?->stand($best['pad'], $vy);
        $onSwitch = $best['object'] instanceof FloorSwitch ? $best['object'] : null;
        if ($onSwitch !== null && $onSwitch !== $this->onSwitch) {
            $this->switchWalked = $playerState !== 'jump' && $playerState !== 'hurt';
        }
        $this->onSwitch = $onSwitch;
        $dx = $best['dx'];
        if ($this->standingOn) {
            $dx = $this->standingOn->takeDeltaX();
            $this->standingOn->touch();
            // $29 floating log: Sonic is held at its centre while it rolls
            $pin = $this->standingOn->getPinX();
            if ($pin !== null) {
                $dx = $pin - ($rect['x'] + $rect['width'] / 2);
            }
        }
        return ['y' => $best['top'] - $rect['height'], 'dx' => $dx, 'force' => $best['force'] ?? false];
    }

    public function update(float $deltaTime, Player $player, Level $level): void
    {
        if ($this->level !== $level || $this->currentLevel !== $level->getLevel()) {
            $this->loadLevel($level);
        }
        $player->setBallOnly($this->currentLevel >= 28 && $this->currentLevel <= 35);

        if ($this->dead) {
            $this->updateDeath($deltaTime, $player);
            return;
        }

        if (!$this->levelComplete) {
            $this->levelTime += $deltaTime;
            // _3a03: special stages count down and end at 0:00, the acts kill Sonic at 10:00
            $limit = $this->getTimeLimit();
            if ($limit !== null && $this->levelTime >= $limit) {
                $this->levelTime = $limit;
                $this->completeAct(true);
            } elseif ($limit === null && $this->levelTime >= 600 && ($level->getMeta('TL') & 0x20)) {
                $this->killPlayer($player);
            }
        }
        $this->updateTimers($deltaTime, $player);

        $this->updateTeleport($deltaTime, $player, $level);
        $this->updateBridge($player, $level);
        $this->updatePaletteFlash($deltaTime);
        $this->updateFinal($deltaTime, $player);

        $rect = $player->getCollisionRect();
        $vel = $player->getVelocity();
        $state = $player->getState();
        $attacking = $state === 'jump' || $state === 'roll' || $this->invincibleTimer > 0;

        $this->collectRings($rect, $level);

        foreach ($this->objects as $obj) {
            if (!$obj->isActive()) continue;

            if ($obj instanceof Badnik) {
                $shots = $obj->think($deltaTime, $rect['x'] + $rect['width'] / 2, $rect['y'] + $rect['height'] / 2);
                // the original only runs the objects inside their activity window around the camera:
                // no shot from a badnik that far from Sonic
                if ($shots && $this->isShootingObject($obj)) {
                    foreach ($shots as $s) {
                        $shot = new Projectile($this->sdl, $s[0], $s[1], $s[2], $s[3], $s[4], $s[5] ?? null, $s[6] ?? null);
                        $shot->setLevel($level);
                        $this->transient[] = $shot;
                    }
                    $this->sfx($obj->pendingSound ?? SoundManager::SFX_SHOT);
                }
                $obj->pendingSound = null;
                foreach ($obj->getHazards() as $box) {
                    if (Mechanism::overlaps($box, $rect)) $this->hurtPlayer($player);
                }
                if ($obj->isVisible() && $obj->intersects($rect, 2)) {
                    // Yadrin: the spikes on its back hurt even a jumping Sonic (only invincibility gets through)
                    $onSpikes = $obj->getObjectType() === 0x2D && $this->invincibleTimer <= 0
                        && $vel[1] >= 0 && $rect['y'] + $rect['height'] <= $obj->getPosY() + 16;
                    if ($attacking && !$onSpikes && !$obj->isInvulnerable()) {
                        $this->destroyBadnik($obj, $player);
                    } else {
                        $this->hurtPlayer($player);
                    }
                }
                continue;
            }

            if ($obj instanceof Mechanism) {
                $this->handleMechanism($obj, $deltaTime, $player, $rect);
                continue;
            }
            if ($obj instanceof BubbleMaker) {
                array_push($this->transient, ...$obj->think($deltaTime));
                continue;
            }
            if ($obj instanceof Platform && $obj->isFloating() && abs($obj->getPosX() - $rect['x']) > 320) {
                $obj->resetPosition();
            }
            if ($obj instanceof Platform) {
                $obj->setPlayerSpeed($vel[0]);
                $obj->setWave($this->wave);
                $wasFalling = $obj->isFalling();
            }

            if ($obj instanceof Boss) {
                $this->handleBoss($obj, $deltaTime, $player, $rect, $attacking);
                continue;
            }
            if ($obj instanceof Capsule) {
                $this->handleCapsule($obj, $deltaTime, $player, $rect);
                continue;
            }

            $obj->update($deltaTime);
            if ($obj instanceof Platform && !$wasFalling && $obj->isFalling()) $this->tellObject($obj, 'fall');

            if ($obj instanceof Monitor) {
                // _5deb: only a jumping or rolling Sonic breaks it (invincibility doesn't), walking he is stopped
                $this->handleMonitor($obj, $player, $rect, $vel, $state === 'jump' || $state === 'roll');
            } elseif ($obj instanceof ChaosEmerald && $obj->intersects($rect)) {
                $obj->collect();
                $this->emeralds++;
                $this->emit('music', ['emerald', false]);
                $this->score += 1000;
                $this->emit('chaosEmeraldCollected', [$this->emeralds]);
            } elseif ($obj instanceof EndSign) {
                $this->handleSign($obj, $rect, $player);
            }
        }

        foreach ($this->transient as $t) {
            if ($t instanceof Bubble) {
                $t->think($deltaTime, $this->water?->getLine());
                if ($t->isBreathable() && $this->underwater && $t->intersects($rect)) {
                    $this->breathe($t, $player);
                }
                continue;
            }
            if ($t instanceof BossPart) {
                $t->think($deltaTime, $rect['x'] + $rect['width'] / 2, $rect['y'] + $rect['height'] / 2);
                if ($t->justExploded) {
                    $this->sfx(SoundManager::SFX_EXPLOSION);
                }
                if ($t->harmful && $t->isActive() && $t->intersects($rect, 2)) {
                    $this->hurtPlayer($player);
                }
                continue;
            }
            $t->update($deltaTime);
            if ($t instanceof Projectile && $t->isActive() && $t->intersects($rect, 1)) {
                $this->hurtPlayer($player);
                $t->setActive(false);
            }
        }
        $this->transient = array_values(array_filter($this->transient, fn($t) => $t->isActive()));

        $this->updateWater($deltaTime, $player, $level);
    }

    /**
     * Big bumpers of the bonus stages (_57be, _1f06): $D2B1 = 4, decremented every other frame, background
     * colour 14 is white while it is odd: 2 frames white, 2 normal, 2 white, 2 normal.
     */
    private int $paletteFlash = 0;
    private float $paletteFlashClock = 0;

    public function flashPalette(): void
    {
        if ($this->paletteFlash === 0) $this->paletteFlash = 8;
    }

    public function isPaletteFlashWhite(): bool
    {
        return in_array($this->paletteFlash, [8, 7, 4, 3], true);
    }

    private function updatePaletteFlash(float $deltaTime): void
    {
        if ($this->paletteFlash === 0) {
            $this->paletteFlashClock = 0;
            return;
        }
        $this->paletteFlashClock += $deltaTime;
        while ($this->paletteFlash > 0 && $this->paletteFlashClock >= 1 / 60) {
            $this->paletteFlashClock -= 1 / 60;
            $this->paletteFlash--;
        }
    }

    public function setButtonHeld(bool $held): void
    {
        $this->buttonHeld = $held;
    }

    /** Camera wave offset of the levels with the scroll flag bit 6 ($D2A1), followed by the flying platforms. */
    public function setWave(float $wave): void
    {
        $this->wave = $wave;
    }

    public function setRomCamera(array $camera): void
    {
        $this->romCamera = $camera;
    }

    public function setView(float $x, float $y, int $width, int $height): void
    {
        $this->view = [$x, $y, $width, $height];
    }

    /**
     * Shots (badniks, Sky Base turrets and cannons): the original fires once the object is inside its activity
     * window, i.e. about as soon as it is on the 256x192 screen. With our wider view the same window is taken
     * around what is really shown, so a cannon on screen fires instead of waiting for Sonic to be close.
     */
    public function isShootingObject(GameObject $obj): bool
    {
        return $this->isActiveObject($obj) || ($this->view !== null
            && ActivityWindow::containsView($obj->getObjectType(), $obj->getPosX(), $obj->getPosY(), $this->view));
    }

    /** Inside the activity window of its type around the original camera (_31e6). */
    public function isActiveObject(GameObject $obj): bool
    {
        return ActivityWindow::contains($obj->getObjectType(), $obj->getPosX(), $obj->getPosY(), $this->romCamera);
    }

    /** Traps and Scrap Brain mechanisms: frame logic, sounds, hurting areas and walls (doors). */
    private function handleMechanism(Mechanism $obj, float $deltaTime, Player $player, array $rect): void
    {
        if ($obj instanceof Door) {
            $obj->setSwitchFlag($this->switchFlag);
        }
        if ($obj instanceof SkyCloud) {
            $obj->setCamera($this->romCamera, $this->wave);
        }
        if ($obj instanceof BridgePiece) {
            $obj->setCameraY($this->romCamera[1]);
        }
        if ($obj instanceof SpikeWall) {
            // only runs inside its activity window
            if (!$this->isActiveObject($obj)) return;
            $obj->setCamera($this->romCamera);
        }
        if ($obj instanceof ElectricBeam) {
            $obj->setBoss($this->boss !== null && !$this->boss->isDefeated() && !$this->dead, $this->boss?->getHits() ?? 0);
        }
        if ($obj instanceof Flipper) {
            [$vx, $vy] = $player->getVelocity();
            $obj->setButton($this->buttonHeld && !$this->dead);
            $obj->think($deltaTime, $rect + ['vx' => $vx / 60, 'vy' => $vy / 60]);
            if ($obj->push !== null && !$this->dead) {
                // put on the surface of the flipper, speeds in px/frame
                [$top, $pvx, $pvy] = $obj->push;
                $player->setY($player->getY() + $top - ($rect['y'] + $rect['height'] - 32));
                $player->setVelocity($pvx * 60, $pvy * 60);
                $obj->push = null;
            }
        } else {
            $obj->think($deltaTime, $rect);
        }
        if ($obj instanceof MovingBumper && $obj->takeBounce() && !$this->dead) {
            [$vx, $vy] = $player->getVelocity();
            $player->setVelocity($vx, -$vy);
        }
        // bullets / cannon balls: only fired inside the activity window, like the badnik shots
        foreach ($obj->spawns as $spawn) {
            if ($this->isShootingObject($obj)) {
                $spawn->setLevel($this->level);
                $this->transient[] = $spawn;
            }
        }
        $obj->spawns = [];
        foreach ($obj->sounds as $sfx) {
            // only audible when running in the original (activity window around the camera)
            if ($this->isShootingObject($obj)) {
                $this->sfx($sfx);
            }
        }
        $obj->sounds = [];

        if ($obj instanceof FinalShip) {
            $this->handleFinalShip($obj, $player);
        }
        if ($obj instanceof Seesaw && ($launch = $obj->takeLaunch()) !== null && $this->onSeesaw === $obj) {
            // the falling weight throws Sonic up
            $player->setGrounded(false);
            $player->setVelocity($player->getVelocity()[0], -$launch);
            $player->setState('jump');
            $player->setSprung();
            $this->onSeesaw = null;
        }
        if ($obj instanceof TripZone && $player->isGrounded() && $player->getState() !== 'hurt' && !$this->dead
            && Mechanism::overlaps($obj->getBoundingBox(), $rect)) {
            $player->setGrounded(false);
            $player->setAcceleration(0, 0);
            // Y speed -5 px/frame (vertical scale Player::V_SCALE), X speed +3 px/frame
            $player->setVelocity(3 * 60, -5 * Player::V_SCALE);
            $player->setState('hurt');
            $this->sfx(SoundManager::SFX_HURT);
        }
        if ($obj instanceof FloorSwitch && $obj->setPressed($this->onSwitch === $obj && $this->switchWalked)) {
            $this->switchFlag = !$this->switchFlag;
            $this->switchFlags[$this->currentLevel] = $this->switchFlag;
            $this->sfx(SoundManager::SFX_SWITCH);
            $this->tellObject($obj, $this->switchFlag ? 'on' : 'off');
        }
        if ($obj instanceof WarpZone && $obj->getTargetLevel() !== null && !$this->levelComplete
            && Mechanism::overlaps($obj->getBoundingBox(), $rect)) {
            $this->levelComplete = true;
            $this->emit('warp', [$obj->getTargetLevel()]);
            return;
        }
        if (Mechanism::overlaps($obj->getHurtBox(), $rect)) {
            $this->hurtPlayer($player);
        }
        $wall = $obj->getSolidBox();
        if (Mechanism::overlaps($wall, $rect)) {
            // put Sonic back on the side he came from and stop him (doObjectCode_door)
            $fromLeft = $rect['x'] + $rect['width'] / 2 < $wall['x'] + $wall['width'] / 2;
            $vx = $player->getVelocity()[0];
            // going away from it: let him go (Sonic put back at door X + 5 still touches its 4px wall)
            if (($fromLeft && $vx < 0) || (!$fromLeft && $vx > 0)) return;
            if ($obj instanceof Door) {
                // the original puts Sonic's object at door X - 20 / X + 5
                $player->setX($fromLeft ? $obj->getPosX() - 20 : $obj->getPosX() + 5);
            } else {
                $player->setX($fromLeft ? $wall['x'] - $rect['width'] : $wall['x'] + $wall['width']);
            }
            $player->setVelocity(0, $player->getVelocity()[1]);
        }
    }

    /**
     * Labyrinth water: Sonic is underwater once his top is below the line ($D2DC). The air counter ($D29B,
     * _5009) runs except in Labyrinth act 3; from $300 a 2-1-0 countdown beeps (SFX $1A every 16/8/2 frames)
     * and at $600 Sonic drowns. Leaving the water or a big bubble resets it.
     */
    private function updateWater(float $deltaTime, Player $player, Level $level): void
    {
        if ($this->water === null) return;
        $this->water->update($deltaTime);
        $rect = $player->getCollisionRect();
        $this->underwater = !$this->dead && $rect['y'] > $this->water->getLine();
        if (!$this->underwater || $level->getLevel() === 12 || $this->levelComplete || $this->godMode) {
            $this->airFrames = 0;
            $this->airClock = 0;
            return;
        }
        $this->airClock += $deltaTime;
        while ($this->airClock >= 1 / 60 && !$this->dead) {
            $this->airClock -= 1 / 60;
            $this->airFrames++;
            $digit = $this->getAirCountdown();
            if ($digit === null) continue;
            if ($this->airFrames >= 0x600) {
                // drowned: death music, Sonic sinks, a few bubbles escape
                for ($i = 0; $i < 4; $i++) {
                    $this->transient[] = new Bubble($this->sdl, $rect['x'] + mt_rand(0, 15), $rect['y'] + mt_rand(0, 15), false);
                }
                $this->killPlayer($player, true);
                return;
            }
            if (($this->airFrames & [0x01, 0x07, 0x0F][$digit]) === 0) {
                $this->sfx(SoundManager::SFX_SWITCH);
            }
        }
    }

    private function breathe(Bubble $bubble, Player $player): void
    {
        $bubble->pop();
        $this->airFrames = 0;
        [$vx] = $player->getVelocity();
        $player->setVelocity($vx, 0);
        $player->breathe();
        $this->sfx(SoundManager::SFX_BUBBLE);
    }

    /** Digit shown over Sonic before he drowns (2, 1, 0), null when there is enough air. */
    public function getAirCountdown(): ?int
    {
        if ($this->airFrames < 0x300) return null;
        return max(0, 5 - ($this->airFrames >> 8));
    }

    public function getWaterLine(): ?float
    {
        return $this->water?->getLine();
    }

    public function getWaterFrames(): int
    {
        return $this->water?->getFrames() ?? 0;
    }

    private function handleBoss(Boss $boss, float $deltaTime, Player $player, array $rect, bool $attacking): void
    {
        [$vx, $vy] = $player->getVelocity();
        $boss->viewTop = $this->view[1] ?? -INF;
        $boss->romCamera = $this->romCamera;
        $events = $boss->think($deltaTime, $rect, $vx, $attacking);
        if ($boss->isStarted() && !$this->bossMusic) {
            $this->bossMusic = true;
            $this->emit('music', ['boss', true]);
        }
        foreach ($events as $event) {
            switch ($event[0]) {
                case 'sfx':
                    $this->sfx($event[1]);
                    break;
                case 'music':
                    $this->emit('music', [$event[1], $event[1] !== 'actcomplete']);
                    break;
                case 'levelMusic':
                    $this->emit('levelMusic', []);
                    break;
                case 'part':
                    $event[1]->setLevel($this->level);
                    $this->transient[] = $event[1];
                    break;
                case 'hurt':
                    $this->hurtPlayer($player);
                    break;
                case 'hit':
                    $this->sfx(SoundManager::SFX_EXPLOSION);
                    // `_77be`: Sonic's vertical speed is reversed and his horizontal speed cleared
                    $player->setVelocity(0, $vy > 0 ? -$vy : min($vy, -240));
                    break;
                case 'knockback':
                    $player->setVelocity($event[1], $vy);
                    break;
                case 'explosion':
                    $this->transient[] = new Effect($this->sdl, $event[1], $event[2], Effect::PUFF, 0.12);
                    $this->sfx(SoundManager::SFX_EXPLOSION);
                    break;
                case 'carry':
                    $player->setXY($event[1], $event[2] - 32);
                    $player->setVelocity(0, 0);
                    $player->setGrounded(true);
                    break;
                case 'complete':
                    $this->completeAct();
                    break;
            }
        }
    }

    private function handleCapsule(Capsule $capsule, float $deltaTime, Player $player, array $rect): void
    {
        $bossDone = $this->boss === null || $this->boss->isDefeated() || $this->boss->isGone();
        if ($this->onCapsule && $player->isGrounded()) {
            $capsule->press();
            if ($bossDone && !$capsule->isOpened()) {
                $capsule->open();
                $this->sfx(SoundManager::SFX_SWITCH);
            }
        } elseif (!$capsule->isOpened()) {
            $capsule->release();
        }
        // the sides are solid
        if (!$capsule->isFinished() && $capsule->intersects($rect) && $rect['y'] + $rect['height'] > $capsule->getTop() + 0x15) {
            $center = $capsule->getPosX() + $capsule->getWidth() / 2;
            $offset = $player->getX() - $rect['x'];
            $newX = $rect['x'] + $rect['width'] / 2 < $center
                ? $capsule->getPosX() - $rect['width']
                : $capsule->getPosX() + $capsule->getWidth();
            $player->setXY($newX + $offset, $player->getY());
            [, $vy] = $player->getVelocity();
            $player->setVelocity(0, $vy);
        }
        foreach ($capsule->think($deltaTime) as $event) {
            match ($event[0]) {
                'part' => $this->transient[] = $event[1]->setLevelAndReturn($this->level),
                'explosion' => $this->explosion($event[1], $event[2]),
                'music' => $this->emit('music', [$event[1], false]),
                'complete' => $this->completeAct(),
            };
        }
    }

    private function explosion(float $x, float $y): void
    {
        $this->transient[] = new Effect($this->sdl, $x, $y, Effect::PUFF, 0.12);
        $this->sfx(SoundManager::SFX_EXPLOSION);
    }

    private function sfx(int $index): void
    {
        $this->emit('sfx', [$index, false]);
    }

    public function isInvincible(): bool
    {
        return $this->invincibleTimer > 0;
    }

    public function isBossFight(): bool
    {
        return $this->boss !== null && $this->bossMusic && !$this->boss->isGone() && !$this->boss->hasFled();
    }

    /**
     * Teleporter blocks (block behaviour 11, _55eb): with Sonic's centre in the middle 16px of one of the blocks
     * of _5643, he is held for $50 frames (spinning, then the beam of animation $0E for the last $14 frames),
     * then moved to the target block, sent to another level ($D2D3) or the act ends ($FF, Sky Base 3).
     */
    private const TELEPORTS = [
        [0x34, 0x3C, 0x34, 0x2F, 0], [0x19, 0x3A, 0x19, 0x04, 0], [0x0E, 0x3A, 0, 0, 0x16],
        [0x1B, 0x32, 0, 0, 0x17], [0x2F, 0x0C, 0, 0, 0xFF],
    ];
    private ?array $teleport = null;
    private float $teleportClock = 0;

    private static function blockBehaviourAt(Level $level, int $bx, int $by): int
    {
        $block = $level->getTile($bx, $by);
        return $block === null ? 0 : ($level->getBlockBehaviours()[$block] ?? 0);
    }

    /**
     * Bridge blocks (behaviours 24-26 of blocks $88-$8A, _5808 / _584b / _5883), looked at under Sonic's centre
     * (X + $0C, Y + $10): the half block under him becomes a falling bridge piece ($2E, _5893) and the block
     * loses that half ($88 -> $89 right half left / $8A left half left, the other half -> 0). $88 only does it
     * with Sonic on the ground.
     */
    private function updateBridge(Player $player, Level $level): void
    {
        if ($this->dead) return;
        // Sonic's object is 24px wide at the engine position
        $cx = (int)floor($player->getX() + 0x0C);
        $cy = (int)floor($player->getY() + 0x10);
        $bx = intdiv($cx, 32);
        $by = intdiv($cy, 32);
        $behaviour = self::blockBehaviourAt($level, $bx, $by);
        if ($behaviour < 24 || $behaviour > 26) return;
        if ($behaviour === 24 && !$player->isGrounded()) return;
        $right = ($cx & 31) >= 16;
        if (($behaviour === 25 && !$right) || ($behaviour === 26 && $right)) return;
        $block = $level->getTile($bx, $by);
        $piece = new BridgePiece($this->sdl, $bx * 32 + ($right ? 16 : 0), $by * 32);
        $piece->setLevel($level);
        $this->objects[] = $piece;
        if ($right) {
            $level->setTile($bx, $by, $block === 0x89 ? 0 : 0x8A);
        } else {
            $level->setTile($bx, $by, $block === 0x8A ? 0 : 0x89);
        }
    }

    private function updateTeleport(float $deltaTime, Player $player, Level $level): void
    {
        $rect = $player->getCollisionRect();
        $centerX = $player->getX() + 0x0C;
        if ($this->teleport === null) {
            if (!$player->isGrounded() || $this->levelComplete) return;
            $inMiddle = ((int)$centerX & 31) >= 8 && ((int)$centerX & 31) < 0x18;
            $bx = (int)floor($centerX / 32);
            $by = (int)floor(($rect['y'] + $rect['height'] - 16) / 32);
            // only looked at by the handler of block behaviour 11
            if (self::blockBehaviourAt($level, $bx, $by) !== 11) return;
            foreach (self::TELEPORTS as [$x, $y, $tx, $ty, $flag]) {
                if ($inMiddle && $x === $bx && $y === $by) {
                    $this->teleport = ['frames' => 0x50, 'x' => $player->getX(), 'y' => $player->getY(), 'to' => [$tx, $ty, $flag]];
                    $player->setTeleporting(true);
                    $this->teleportClock = 0;
                    $this->sfx(SoundManager::SFX_ROLL);
                    break;
                }
            }
            return;
        }

        // held in place
        $player->setXY($this->teleport['x'], $this->teleport['y']);
        $player->setVelocity(0, 0);
        $this->teleportClock += $deltaTime;
        while ($this->teleport !== null && $this->teleportClock >= 1 / 60) {
            $this->teleportClock -= 1 / 60;
            $frames = --$this->teleport['frames'];
            if ($frames === 0x13) {
                // animation $0E: frames $19-$21 two frames each, the first one four, then held on $21
                $beam = new Effect($this->sdl, $player->getX() + 12, $player->getY() + 24,
                    [RomSprites::TELEPORT_BEAM[0], ...RomSprites::TELEPORT_BEAM], 2 / 60);
                $beam->setTileset(RomSprites::SHEET);
                $this->transient[] = $beam;
            }
            if ($frames > 0) continue;
            [$tx, $ty, $flag] = $this->teleport['to'];
            $this->teleport = null;
            $player->setTeleporting(false);
            if ($flag === 0) {
                // X = block * 32 + 8, Y = block * 32 (Sonic's 24x32 object)
                $player->setXY($tx * 32 + 8, $ty * 32);
            } elseif ($flag === 0xFF) {
                // _5117: bit 2 of $D20D, the screen fades out and the next level starts without a tally (_1fa9)
                $this->levelComplete = true;
                $this->emit('teleportEnd', []);
            } else {
                $this->levelComplete = true;
                $this->emit('warp', [$flag]);
            }
        }
    }

    /** Sonic is inside the teleport beam (or not there yet / glowing at the end of the game): not drawn. */
    public function isPlayerHidden(): bool
    {
        if ($this->finalShip !== null && (!$this->finalShip->isSonicShown() || $this->finalPhase === 'appear'
                || $this->finalPhase === 'emerald')) {
            return true;
        }
        if ($this->boss?->hidesPlayer()) return true;
        return $this->teleport !== null && $this->teleport['frames'] < 0x14;
    }

    private function handleFinalShip(FinalShip $ship, Player $player): void
    {
        foreach ($ship->events as $event) {
            switch ($event[0]) {
                case 'appear':
                    // Sonic's object at (ship + $3C, ship - $40), coming out of the teleport beam (animation $16)
                    // and held there until its frame $12 (_5193)
                    $player->setXY($event[1], $event[2]);
                    $player->setVelocity(0, 0);
                    $player->setState('jump');
                    $this->finalPhase = 'appear';
                    $this->finalFrames = 0;
                    $frames = [];
                    foreach ([8, 7, 6, 5, 4, 3, 2, 1, 0] as $f) {
                        $frames[] = RomSprites::TELEPORT_BEAM[$f];
                    }
                    $glow = new Effect($this->sdl, $event[1] + 12, $event[2] + 16, $frames, 4 / 60);
                    $glow->setTileset(RomSprites::SHEET);
                    $this->transient[] = $glow;
                    break;
                case 'bounce':
                    // Sonic's Y speed reversed
                    [$vx, $vy] = $player->getVelocity();
                    $player->setVelocity($vx, -abs($vy));
                    break;
                case 'explosion':
                    $this->explosion($event[1], $event[2]);
                    break;
                case 'done':
                    // 6 emeralds: Sonic walks back left to X $1B60 and the emerald rises ($529c); otherwise the
                    // level ends $20 frames later
                    $this->finalPhase = $this->emeralds >= 6 ? 'walk' : 'end';
                    $this->finalFrames = 0;
                    break;
            }
        }
        $ship->events = [];
    }

    /** Frame logic of the end of the game around object $53 (60Hz). */
    private function updateFinal(float $deltaTime, Player $player): void
    {
        if ($this->finalShip === null) return;
        if (!$this->finalShip->isSonicShown()) {
            // Sonic's object is not there yet ($FF): hold him
            $player->setVelocity(0, 0);
            $player->setAcceleration(0, 0);
        }
        $this->finalClock += $deltaTime;
        while ($this->finalClock >= 1 / 60) {
            $this->finalClock -= 1 / 60;
            $this->finalFrames++;
            switch ($this->finalPhase) {
                case 'appear':
                    $player->setVelocity(0, 0);
                    if ($this->finalFrames >= 36) {
                        // _5193: at the end of the beam the rolling bit is set, Sonic falls onto Robotnik as a ball
                        $this->finalPhase = 'fall';
                        $player->setGrounded(false);
                        $player->setState('jump');
                    }
                    break;
                case 'walk':
                    $romX = $player->getX();
                    if ($romX >= 0x1B60) {
                        $player->move('left');
                    } elseif ($player->isGrounded() && abs($player->getVelocity()[0]) < 1) {
                        $player->setVelocity(0, 0);
                        // Sonic glows (animation $18) and the emerald $54 appears at Sonic + (2, $0E)
                        $this->finalPhase = 'emerald';
                        $this->finalFrames = 0;
                        $glow = new Effect($this->sdl, $player->getX() + 12, $player->getY() + 16, [RomSprites::TELEPORT_BEAM[1]], 1, 9999);
                        $glow->setTileset(RomSprites::SHEET);
                        $this->transient[] = $this->finalGlow = $glow;
                        $this->finalEmerald = new ChaosEmerald($this->sdl, 0, 0);
                        $this->finalEmerald->setXY($player->getX() + 2, $player->getY() + 0x0E);
                    }
                    break;
                case 'emerald':
                    // _529c: Sonic animation $18 (frame $1A) for $50 frames, $17 (frame $19) for $8C, then $19 ($1B)
                    if ($this->finalFrames === 0x50 || $this->finalFrames === 0x50 + 0x8C) {
                        $this->finalGlow->setActive(false);
                        $glow = new Effect($this->sdl, $player->getX() + 12, $player->getY() + 16,
                            [RomSprites::TELEPORT_BEAM[$this->finalFrames === 0x50 ? 0 : 2]], 1, 9999);
                        $glow->setTileset(RomSprites::SHEET);
                        $this->transient[] = $this->finalGlow = $glow;
                    }
                    // $50 frames, then $64, then it rises 1/2 px a frame until it is above the screen
                    if ($this->finalFrames > 0x50 + 0x64) {
                        $this->finalEmerald->setXY($this->finalEmerald->getPosX(), $this->finalEmerald->getPosY() - 0.5);
                    }
                    if ($this->finalEmerald->getPosY() + 0x20 < $this->romCamera[1]) {
                        // $D289 = 1
                        $this->finalPhase = 'end';
                        $this->finalFrames = 0x1F;
                    }
                    break;
                case 'end':
                    // $D289 = $20 frames before the level ends
                    if ($this->finalFrames >= 0x20 && !$this->levelComplete) {
                        $this->finalPhase = 'over';
                        $this->levelComplete = true;
                        $this->emit('gameEnd', []);
                    }
                    break;
            }
        }
    }

    /** The emerald of the end of the game, drawn every other frame. */
    public function getFinalEmerald(): ?ChaosEmerald
    {
        return $this->finalPhase === 'emerald' ? $this->finalEmerald : null;
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function resetStats(): void
    {
        $this->stats = ['oneUps' => 0, 'specialEntered' => 0, 'specialCleared' => 0, 'fellInPit' => false];
    }

    /** Sonic fell below the screen ($D283, the special bonus of the end is lost). */
    public function playerFellInPit(Player $player): void
    {
        if ($this->godMode) {
            // invincible: thrown back up out of the pit
            $player->setGrounded(false);
            $player->setVelocity($player->getVelocity()[0], -12 * 80);
            $player->setState('jump');
            return;
        }
        if (!$this->dead) $this->stats['fellInPit'] = true;
        $this->killPlayer($player);
    }

    /** New game: lives, score, emeralds and the counters of the special bonus. */
    /** Cheats of the title menu (Options::CHEATS => on), set by Game for each new game; none in the demo. */
    private array $cheats = [];

    public function setCheats(array $cheats): void
    {
        $this->cheats = $cheats;
    }

    private function cheat(string $name): bool
    {
        return !empty($this->cheats[$name]);
    }

    /** Debug / cheat: invincible on or off (F5 toggles it). */
    public function setGodMode(bool $on): void
    {
        $this->godMode = $on;
    }

    /** A new game starts: cheats "6 emeralds" and "50 rings" (the first act may be loaded already). */
    public function startGameCheats(): void
    {
        if ($this->cheat('emeralds')) $this->emeralds = 6;
        if ($this->cheat('rings')) $this->rings = max(50, $this->rings);
    }

    /** At least one cheat is on: the game does not count for the ghosts and the high scores. */
    public function hasCheats(): bool
    {
        return in_array(true, $this->cheats, true);
    }

    public function resetGame(): void
    {
        $this->lives = 3;
        $this->continues = 0;
        $this->continuePending = false;
        $this->score = 0;
        $this->emeralds = 0;
        $this->switchFlags = [];
        $this->checkpoint = null;
        $this->resetStats();
    }

    private function completeAct(bool $timedOut = false): void
    {
        if ($this->levelComplete) return;
        $this->levelComplete = true;
        if ($this->continuePending) {
            $this->continuePending = false;
            $this->continues++;
        }
        // _155e: special stages played ($D281) and finished before the time ran out ($D282)
        if ($this->currentLevel >= 28 && $this->currentLevel <= 35) {
            $this->stats['specialEntered']++;
            if (!$timedOut) $this->stats['specialCleared']++;
        }
        $this->emit('levelComplete', [$this->currentLevel]);
    }

    private bool $signRunDone = false;

    /**
     * After the sign stopped (flags6 bit 1, main loop): the joypad is forced to the right until Sonic is at
     * level left (the camera) + $112, i.e. out of the screen, then he is stopped. Returns whether right is held.
     */
    public function signRun(Player $player): bool
    {
        if (!$this->signStopped || $this->sign === null || $this->signRunDone || $this->dead) return false;
        $romX = $player->getX();
        if ($romX >= $this->sign->getPosX() - 0x70 + 0x112) {
            $this->signRunDone = true;
            $player->setVelocity(0, 0);
            $player->setAcceleration(0, 0);
            return false;
        }
        return true;
    }

    /** Sonic runs off the screen after the sign: the level limits don't hold him. */
    public function isSignRun(): bool
    {
        return $this->signStopped;
    }

    /** SMS camera position to lock on (boss arena, the ship of the end), or null. */
    public function getCameraLock(): ?array
    {
        if ($this->finalShip !== null && ($x = $this->finalShip->getCameraX($this->romCamera[0])) !== null) {
            return [$x, $this->romCamera[1]];
        }
        // the end sign: the right limit of the camera becomes sign X - $70, the sign ends up in the middle
        if ($this->sign !== null && $this->sign->isPassed()) {
            return [$this->sign->getPosX() - 0x70, $this->romCamera[1]];
        }
        return $this->boss?->getCameraLock();
    }

    /**
     * doObjectCode_boss_endSign: while the sign is processed (its activity window), RAM_LEVEL_LEFT = the camera X
     * every frame (the screen can't go back left) and RAM_LEVEL_RIGHT = sign X - $70. [left, right] or null.
     */
    public function getSignLimits(): ?array
    {
        if ($this->sign === null || !$this->isActiveObject($this->sign)) return null;
        return [(int)$this->romCamera[0], (int)$this->sign->getPosX() - 0x70];
    }

    public function getPlayerBounds(): ?array
    {
        return $this->boss?->getPlayerBounds();
    }

    public function isCutscene(): bool
    {
        // the end of the game: the object writes $FF to the joypad every frame
        if ($this->finalShip !== null) return true;
        // the sign has stopped: the joypad is forced (see signRun())
        if ($this->signStopped) return true;
        // teleporter (_5117): Sonic's code skips the joypad while $D28A counts down
        if ($this->teleport !== null) return true;
        return $this->boss !== null && $this->boss->isCutscene();
    }

    private function updateTimers(float $deltaTime, Player $player): void
    {
        if ($this->hurt) {
            $this->hurtTimer -= $deltaTime;
            if ($this->hurtTimer <= 0) $this->hurt = false;
        }
        if ($this->invincibleTimer > 0) {
            $this->invincibleTimer -= $deltaTime;
            if ($this->invincibleTimer <= 0) {
                $this->emit('levelMusic', []);
            }
        }
        if ($this->speedShoesTimer > 0) {
            $this->speedShoesTimer -= $deltaTime;
            if ($this->speedShoesTimer <= 0) $player->setSpeedBoost(false);
        }
        // cheats: speed shoes and shield for good
        if ($this->cheat('turbo') && !$this->dead) $player->setSpeedBoost(true);
        if ($this->cheat('shield') && !$this->dead && !$this->hurt) $this->shield = true;
        if ($this->sign && $this->sign->isFinished() && !$this->levelComplete) {
            if (!$this->signStopped) {
                $this->signStopped = true;
                $this->emit('stopSfx', [SoundManager::SFX_SIGN_SPIN]);
                $this->emit('music', ['actcomplete', false]);
                $this->applySignEffect();
            }
            $this->signDoneTimer += $deltaTime;
            // $D289 = $A0 frames before the tally
            if ($this->signDoneTimer >= 0xA0 / 60) {
                $this->completeAct();
            }
        }
    }

    private function collectRings(array $rect, Level $level): void
    {
        $minTX = (int)floor($rect['x'] / 32);
        $maxTX = (int)floor(($rect['x'] + $rect['width'] - 1) / 32);
        $minTY = (int)floor($rect['y'] / 32);
        $maxTY = (int)floor(($rect['y'] + $rect['height'] - 1) / 32);

        for ($ty = $minTY; $ty <= $maxTY; $ty++) {
            for ($tx = $minTX; $tx <= $maxTX; $tx++) {
                $block = $level->getTile($tx, $ty);
                if ($block === null || !self::isRingBlock($block)) continue;

                foreach ([1 => 0, 2 => 16] as $bit => $offsetX) {
                    if (!($block & $bit)) continue;
                    $rx = $tx * 32 + $offsetX;
                    $ry = $ty * 32 + 8;
                    if ($rect['x'] < $rx + 16 && $rect['x'] + $rect['width'] > $rx && $rect['y'] < $ry + 16 && $rect['y'] + $rect['height'] > $ry) {
                        $block ^= $bit;
                        $level->setTile($tx, $ty, $block);
                        $this->addRings(1);
                        $this->sfx(SoundManager::SFX_RING);
                        $this->transient[] = new Effect($this->sdl, $rx + 8, $ry + 8, Effect::SPARKLE, 0.06);
                    }
                }
            }
        }
    }

    public static function isRingBlock(int $block): bool
    {
        $b = $block & 0x7F;
        return $b >= 0x79 && $b <= 0x7B;
    }

    /** Multiplayer (Versus): the rings are a score, no life at 100 and no wrap around. */
    public bool $ringWrap = true;

    /** Multiplayer (rings mode): rings taken from a rival. */
    public function gainRings(int $count): void
    {
        $this->addRings($count);
    }

    /** Multiplayer (rings mode): Sonic loses all his rings (they go to the others); returns how many. */
    public function takeRings(): int
    {
        $rings = $this->rings;
        $this->rings = 0;
        return $rings;
    }

    /** Multiplayer: the map is not loaded again when Sonic dies (the rings taken stay taken). */
    public bool $keepTilemap = false;
    /**
     * Online match: told of what Sonic does to the objects, fn(int $id, string $what) (applyObject): 'gone' (a
     * monitor broken, a badnik destroyed), 'fall' (a platform stood on starts falling / floating), 'on' / 'off'
     * (a floor switch: the doors of the act).
     */
    public ?\Closure $objectListener = null;
    /** Multiplayer: ids of the monitors and badniks gone, still gone after a respawn (like the rings). */
    private array $goneObjects = [];

    /** A monitor or a badnik gone here: kept gone, and told to the online match. */
    private function objectGone(GameObject $obj): void
    {
        $id = array_search($obj, $this->objects, true);
        if ($id === false) return;
        $this->goneObjects[$id] = true;
        if ($this->objectListener !== null) ($this->objectListener)($id, 'gone');
    }

    private function tellObject(GameObject $obj, string $what): void
    {
        if ($this->objectListener === null) return;
        $id = array_search($obj, $this->objects, true);
        if ($id !== false) ($this->objectListener)($id, $what);
    }

    /** Online, anti-cheat: what the monitor $id gives, if it is still there (null: not a monitor, or broken). */
    public function monitorItem(int $id): ?string
    {
        $obj = $this->objects[$id] ?? null;
        return $obj instanceof Monitor && $obj->isActive() ? $obj->getItemType() : null;
    }

    /** Online: what a player did to the object $id on another machine (objectListener; nothing given here). */
    public function applyObject(int $id, string $what): void
    {
        $obj = $this->objects[$id] ?? null;
        if ($obj instanceof Platform && $what === 'fall') {
            $obj->startFalling();
            return;
        }
        if ($obj instanceof FloorSwitch && ($what === 'on' || $what === 'off')) {
            if ($this->switchFlag !== ($what === 'on')) {
                $this->switchFlag = $what === 'on';
                $this->switchFlags[$this->currentLevel] = $this->switchFlag;
                if ($this->isShootingObject($obj)) $this->sfx(SoundManager::SFX_SWITCH);
            }
            return;
        }
        if ($what !== 'gone' || !($obj instanceof Monitor || $obj instanceof Badnik) || !$obj->isActive()) return;
        $this->goneObjects[$id] = true;
        $obj instanceof Monitor ? $obj->breakMonitor() : $obj->destroy();
        $box = $obj->getBoundingBox();
        $this->transient[] = new Effect($this->sdl, $box['x'] + $box['width'] / 2, $box['y'] + $box['height'] / 2, Effect::PUFF, 0.1);
        if ($this->isShootingObject($obj)) $this->sfx(SoundManager::SFX_EXPLOSION);
    }

    /**
     * Online: a bridge block changed on another machine ($88 whole, $89 / $8A one half, 0): the halves gone
     * fall here too.
     */
    public function collapseBridge(int $bx, int $by, int $value): void
    {
        $halves = fn(int $block) => match ($block) { 0x88 => [0, 16], 0x89 => [16], 0x8A => [0], default => [] };
        $level = $this->level;
        $block = $level?->getTile($bx, $by);
        if ($block === null || $block < 0x88 || $block > 0x8A) return;
        foreach (array_diff($halves($block), $halves($value)) as $offset) {
            $piece = new BridgePiece($this->sdl, $bx * 32 + $offset, $by * 32);
            $piece->setLevel($level);
            $this->objects[] = $piece;
        }
        $level->setTile($bx, $by, $value);
    }

    private function addRings(int $count): void
    {
        $this->rings += $count;
        $this->score += 10 * $count;
        // The original keeps a 2-digit counter: reaching 100 gives a life and wraps around.
        while ($this->ringWrap && $this->rings >= 100) {
            $this->rings -= 100;
            $this->lives++;
            $this->emit('extraLife', []);
        }
    }

    private function handleMonitor(Monitor $monitor, Player $player, array $rect, array $vel, bool $attacking): void
    {
        if (!$monitor->intersects($rect)) return;

        // _5deb: rolling upwards from under it (Sonic over the monitor), it jumps and Sonic is sent down
        $centerX = $rect['x'] + $rect['width'] / 2;
        if ($attacking && $vel[1] < 0 && $centerX >= $monitor->getPosX() - 6 && $centerX <= $monitor->getPosX() + $monitor->getWidth() + 6
            && $rect['y'] > $monitor->getPosY()) {
            $monitor->bump();
            $player->setVelocity($vel[0], 4 * Player::V_SCALE);
            return;
        }

        if ($attacking) {
            $item = $monitor->breakMonitor();
            $this->objectGone($monitor);
            $this->sfx(SoundManager::SFX_EXPLOSION);
            $this->transient[] = new Effect($this->sdl, $monitor->getPosX() + 11, $monitor->getPosY() + 12, Effect::PUFF, 0.08);
            $this->applyItem($item, $player, $monitor);
            $this->score += 10;
            if ($vel[1] > 0) {
                $player->setVelocity($vel[0], -abs($vel[1]) * 0.8);
            }
            return;
        }

        // Solid sides: push Sonic out horizontally.
        $feet = $rect['y'] + $rect['height'];
        if ($feet <= $monitor->getPosY() + 10) return;
        $playerCenter = $rect['x'] + $rect['width'] / 2;
        $monitorCenter = $monitor->getPosX() + $monitor->getWidth() / 2;
        if ($playerCenter < $monitorCenter) {
            $player->setX($monitor->getPosX() - $rect['width']);
            if ($vel[0] > 0) $player->setVelocity(0, $vel[1]);
        } else {
            $player->setX($monitor->getPosX() + $monitor->getWidth());
            if ($vel[0] < 0) $player->setVelocity(0, $vel[1]);
        }
    }

    private function applyItem(string $item, Player $player, Monitor $monitor): void
    {
        switch ($item) {
            case 'ring':
                $this->addRings(10);
                $this->sfx(SoundManager::SFX_RING);
                break;
            case 'life':
                $this->lives++;
                // $D280: 1-ups from the monitors outside the special stages
                if ($this->currentLevel < 28 || $this->currentLevel > 35) $this->stats['oneUps']++;
                $this->emit('extraLife', []);
                break;
            case 'shield':
                $this->shield = true;
                break;
            case 'invincibility':
                $this->invincibleTimer = 20.0;
                $this->emit('music', ['invincibility', true]);
                break;
            case 'speed':
                // $D411 = $F0, decremented every 4 frames (_4fec): 960 frames
                $this->speedShoesTimer = 0xF0 * 4 / 60;
                $player->setSpeedBoost(true);
                $this->sfx(SoundManager::SFX_RING);
                break;
            case 'checkpoint':
                $this->checkpoint = [$monitor->getPosX(), $monitor->getPosY() + $monitor->getHeight() - 32];
                break;
            case 'continue':
                $this->continuePending = true;
                $this->emit('continueCollected', []);
                break;
        }
    }

    private function destroyBadnik(Badnik $badnik, Player $player): void
    {
        $badnik->destroy();
        $this->objectGone($badnik);
        $this->sfx(SoundManager::SFX_EXPLOSION);
        $this->score += 100;
        $box = $badnik->getBoundingBox();
        $this->transient[] = new Effect($this->sdl, $box['x'] + $box['width'] / 2, $box['y'] + $box['height'] / 2, Effect::PUFF, 0.1);
        [$vx, $vy] = $player->getVelocity();
        if (!$player->isGrounded() && $vy > 0) {
            $player->setVelocity($vx, -max(300, $vy));
        }
    }

    /**
     * doObjectCode_boss_endSign: final face ($D288) from the level (ROM index) and the BCD ring counter.
     * Bonus stages: Eggman. Zones 1-4 with 50 rings or more: "!" (special stage next, _203f). Otherwise rings
     * multiple of 10 (or a zone 5-6 level with index & $0F = 0): ring (+10 rings, _39ac); tens digit equal to
     * (level & 3) + 1: Sonic (extra life); else Eggman.
     */
    private function signResult(): array
    {
        $index = $this->currentLevel <= 19 ? $this->currentLevel - 1 : $this->currentLevel;
        $rings = $this->rings;
        if ($index >= 0x1C) return [EndSign::FACE_EGGMAN, 'none'];
        if ($index < 0x0C) {
            if ($rings >= 50) return [EndSign::FACE_EXCLAMATION, 'special'];
            if ($rings !== 40 && $rings % 10 === 0) return [EndSign::FACE_RING, 'rings'];
        } elseif (($index & 0x0F) === 0) {
            return [EndSign::FACE_RING, 'rings'];
        }
        if (($index & 3) + 1 === intdiv($rings, 10)) return [EndSign::FACE_SONIC, 'life'];
        return [EndSign::FACE_EGGMAN, 'none'];
    }

    /** _2023: what the final face of the sign gives. */
    private function applySignEffect(): void
    {
        switch ($this->signEffect) {
            case 'life':
                $this->lives++;
                $this->emit('extraLife', []);
                break;
            case 'rings':
                $this->addRings(10);
                $this->sfx(SoundManager::SFX_RING);
                break;
            case 'special':
                $this->specialStagePending = true;
                $this->sfx(SoundManager::SFX_BUMPER);
                break;
            default:
                $this->sfx(0x0E);
        }
        $this->signEffect = 'none';
    }

    /** The sign showed "!": the next special stage is played after this act (taken once). */
    public function takeSpecialStage(): bool
    {
        $pending = $this->specialStagePending;
        $this->specialStagePending = false;
        return $pending;
    }

    private function handleSign(EndSign $sign, array $rect, Player $player): void
    {
        $sign->watchSonic($rect['x'], $player->isGrounded());
        // The ROM (doObjectCode_boss_endSign) needs a real collision with Sonic, not just passing its X.
        $top = $sign->getPosY();
        if ($rect['y'] + $rect['height'] < $top || $rect['y'] > $top + $sign->getHeight()) return;
        if ($sign->checkPassed($rect['x'] + $rect['width'] / 2, $player->getVelocity()[0])) {
            [$face, $this->signEffect] = $this->signResult();
            $sign->setFinalFace($face);
            $this->emit('sfx', [SoundManager::SFX_SIGN_SPIN, true]);
            $this->emit('signPassed', []);
        }
    }

    /** Debug (F5): nothing hurts or kills Sonic. */
    private bool $godMode = false;

    public function toggleGodMode(): bool
    {
        return $this->godMode = !$this->godMode;
    }

    public function isGodMode(): bool
    {
        return $this->godMode;
    }

    /** $canDie false (multiplayer, rings mode): without rings Sonic is only thrown back. */
    public function hurtPlayer(Player $player, bool $canDie = true): void
    {
        if ($this->godMode || $this->dead || $this->hurt || $this->invincibleTimer > 0 || $this->levelComplete) return;

        $this->hurt = true;
        $this->hurtTimer = 2.0;
        $dir = $player->getFacing() === 'left' ? 1 : -1;

        if ($this->shield) {
            $this->shield = false;
        } elseif ($this->rings > 0) {
            $this->rings = 0;
            $this->sfx(SoundManager::SFX_HURT);
            // SMS Sonic only pops a single ring out, which can't be recovered.
            $this->transient[] = (new Effect($this->sdl, $player->getX() + 12, $player->getY(), self::RING_POP, 0.05, 1.0))
                ->withMotion(0, -360, 500);
        } elseif (!$canDie) {
            $this->sfx(SoundManager::SFX_HURT);
        } else {
            $this->killPlayer($player);
            return;
        }

        $player->setGrounded(false);
        $player->setAcceleration(0, 0);
        $player->setVelocity($dir * 120, -4 * 80);
        $player->setState('hurt');   // thrown back, can't attack until landing
    }

    public function killPlayer(Player $player, bool $drowned = false): void
    {
        if ($this->dead || $this->godMode) return;
        $this->dead = true;
        $this->deadTimer = 0;
        $this->deadFrames = 0;
        // _3618: Yspeed -6, $D287 = $60; drowning (_367e): $D287 = $C0, sinks at $80/256
        $this->drowned = $drowned;
        $this->deadSpeed = $drowned ? 0x80 / 256 : -6;
        $this->deadLength = ($drowned ? 0xC0 : 0x60) * 2;
        $this->invincibleTimer = 0;
        $this->speedShoesTimer = 0;
        $this->emit('music', ['death', false]);
        $this->hurt = false;
        $this->shield = false;
        $player->setSpeedBoost(false);
        $player->setGrounded(false);
        $player->setAcceleration(0, 0);
        $player->setVelocity(0, $drowned ? 0 : -560);
        $player->setState('dead');
        $player->setAnimation($drowned ? 'drowned' : 'dead');   // Player::update() doesn't run while dead
    }

    private function updateDeath(float $deltaTime, Player $player): void
    {
        if ($this->deadLength === 0) return;   // game over: waiting for the screen
        $this->deadTimer += $deltaTime;
        while ($this->deadTimer >= 1 / 60 && $this->deadFrames < $this->deadLength) {
            $this->deadTimer -= 1 / 60;
            // _543c: still while $D287 = $60 (the first 2 frames), then gravity $30 up to 6 px/frame
            if ($this->deadFrames++ < 2) continue;
            if (!$this->drowned && ($this->deadSpeed < 0 || $this->deadSpeed < 6)) {
                $this->deadSpeed += 0x30 / 256;
            }
            $player->setY($player->getY() + $this->deadSpeed);
        }
        $player->setVelocity(0, $this->deadSpeed * 60);

        if ($this->deadFrames < $this->deadLength) return;

        if (!$this->cheat('lives')) $this->lives--;
        $this->continuePending = false;
        // cheat: a continue is always offered
        if ($this->cheat('continues')) $this->continues = max(1, $this->continues);
        // Game fades the screen out, then restarts the act (respawnPlayer) or shows the game over (_1401);
        // Sonic stays dead (no second death below the screen) until then
        $this->deadLength = 0;
        $this->emit($this->lives <= 0 ? 'gameOver' : 'deathOver', [$this->continues]);
    }

    /** After the death fade: the act starts again (from the checkpoint). */
    public function respawnPlayer(Player $player): void
    {
        if (!$this->dead) return;
        $this->dead = false;
        $this->respawn($player);
    }

    private function respawn(Player $player): void
    {
        $level = $this->level;
        $this->loadLevel($level, true);
        [$x, $y] = $this->checkpoint ?? [$level->getCurrentPositionSoniceXinTile() * 32, $level->getCurrentPositionSoniceYinTile() * 32];
        $player->setXY($x, $y);
        $player->setVelocity(0, 0);
        $player->setAcceleration(0, 0);
        $player->setState('idle');
        $this->emit('playerRespawned', []);
    }

    /** Multiplayer: Sonic just appeared somewhere: blinking, the badniks can't hurt him for $seconds. */
    public function protectPlayer(float $seconds): void
    {
        $this->hurt = true;
        $this->hurtTimer = max($this->hurtTimer, $seconds);
    }

    /** X of the sign at the end of the act, or null (boss acts, special stages). */
    public function getEndSignX(): ?float
    {
        return $this->endSignX;
    }

    public function isPlayerDead(): bool { return $this->dead; }
    public function isPlayerHurt(): bool { return $this->hurt; }
    public function hasShield(): bool { return $this->shield; }
    public function hasInvincibility(): bool { return $this->invincibleTimer > 0; }
    public function hasSpeedShoes(): bool { return $this->speedShoesTimer > 0; }
    public function getRingCount(): int { return $this->rings; }
    public function getScore(): int { return $this->score; }
    /** The act-complete tally adds the ring and time bonuses. */
    public function setScore(int $score): void { $this->score = $score; }
    public function getEmeralds(): int { return $this->emeralds; }
    public function getLives(): int { return $this->lives; }

    /** Continue taken on the game over screen: 3 lives, the act starts again from its beginning, score kept. */
    public function useContinue(): void
    {
        if (!$this->cheat('continues')) $this->continues = max(0, $this->continues - 1);
        $this->lives = 3;
        $this->checkpoint = null;
    }
    public function getLevelTime(): float { return $this->levelTime; }
    public function hasCheckpoint(): bool { return $this->checkpoint !== null; }

    /** Where Sonic starts the act again after a death (the checkpoint monitor), or null for the level start. */
    public function getCheckpoint(): ?array
    {
        return $this->checkpoint;
    }

    // _2405: time of the special stages ($1C-$23 = our levels 28-35), in seconds
    private const SPECIAL_STAGE_TIME = [28 => 60, 29 => 60, 30 => 45, 31 => 50, 32 => 45, 33 => 50, 34 => 50, 35 => 30];

    public function getTimeLimit(): ?int
    {
        // multiplayer (noEnemies): the match has its own clock
        if ($this->noEnemies) return null;
        return self::SPECIAL_STAGE_TIME[$this->currentLevel] ?? null;
    }

    /** Time shown by the HUD: elapsed, or what is left in a special stage. */
    public function getDisplayTime(): float
    {
        $limit = $this->getTimeLimit();
        return $limit === null ? $this->levelTime : max(0, $limit - $this->levelTime);
    }
    public function isLevelComplete(): bool { return $this->levelComplete; }
    public function getCurrentLevelNumber(): int { return $this->currentLevel; }

    /** @return GameObject[] */
    public function getVisibleObjects(float $cameraX, float $cameraY, int $viewWidth, int $viewHeight): array
    {
        $margin = 96;
        $result = [];
        foreach (array_merge($this->objects, $this->transient) as $obj) {
            // the Sky Base boss also draws its glass and weapon, even once Robotnik has gone
            if ($obj instanceof Boss && $obj->getObjectType() === Boss::SKY_BASE) {
                $result[] = $obj;
                continue;
            }
            if (!$obj->isVisible() || !$obj->isActive()) continue;
            $ox = $obj->getPosX();
            $oy = $obj->getPosY();
            if ($ox >= $cameraX - $margin && $ox <= $cameraX + $viewWidth + $margin
                && $oy >= $cameraY - $margin && $oy <= $cameraY + $viewHeight + $margin) {
                $result[] = $obj;
            }
        }
        // the rising emerald of the end, on odd frames
        if ($this->getFinalEmerald() !== null && $this->finalFrames % 2 === 1) {
            $result[] = $this->finalEmerald;
        }
        return $result;
    }

    public function resetForNewLevel(): void
    {
        $this->level = null;
        $this->currentLevel = 0;
        $this->checkpoint = null;
        $this->dead = false;
        $this->hurt = false;
    }
}
