<?php

namespace SonicGame\Entities;

use SonicGame\Entities\Physics\Colision;
use SonicGame\Entities\Physics\Gravity;
use SonicGame\Renderer\Sdl;
use SonicGame\SoundManager\SoundManager;
use SonicGame\Utils\Vector;

class Player extends Entity
{


	use Sprite {
		Sprite::update as updateSprite; // Crée un alias interne
	}

	use Gravity {
		Gravity::update as updateGravity;
	}

	use Colision {
		Colision::update as updateColision;
	}


	public $isMovingFromInput = false ;

	private float $maxSpeed = 500.0;

	// Air control from the ROM (doObjectCode_Sonic, tables at $4DCB): +$10/256 px/frame² towards the
	// pressed direction, -$30/256 when reversing above 1 px/frame, $08/256 drag with no direction.
	// Same in the air as on the ground in the original; converted to px/s² and scaled to this engine's
	// speeds (500 px/s top speed for the original 3 px/frame).
	private const ROM_TO_ENGINE = 500 / 180;
	private const AIR_ACCEL = 0x10 / 256 * 3600 * self::ROM_TO_ENGINE;
	private const AIR_REVERSE = 0x30 / 256 * 3600 * self::ROM_TO_ENGINE;
	private const AIR_DRAG = 0x08 / 256 * 3600 * self::ROM_TO_ENGINE;
	private const REVERSE_MIN_SPEED = 60 * self::ROM_TO_ENGINE;

	// Vertical physics of the ROM (_4b7f, _509d, tables $4DCB / $4DDD) at this engine's vertical scale
	// (80 px/s for 1 px/frame, gravity 1400 = $38/256 px/frame²): the jump starts at -$380/256 px/frame
	// (-$280 underwater) and that speed is kept while the button is held, 16 frames at most ($D28E);
	// then gravity. Falls are capped at 8 px/frame (2 underwater).
	public const V_SCALE = 80.0;
	private const JUMP_SPEED = 0x380 / 256 * self::V_SCALE;
	private const JUMP_SPEED_WATER = 0x280 / 256 * self::V_SCALE;
	private const JUMP_HOLD = 16 / 60 / (self::V_SCALE / 60);
	private float $jumpHold = 0;

	/** Up / down held this frame ('up' / 'down' / null). */
	private ?string $lookRequest = null;
	/** Camera shift while looking (the original moves it 1 px per frame, up to 44 px up / 80 px down). */
	private float $lookOffset = 0.0;

	public function setSpeedBoost(bool $enabled): void
	{
		$this->maxSpeed = $enabled ? 800.0 : 500.0;
	}


	protected array $animations = [
        'idleRight' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*9,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
            ]
        ],
        'idleLeft' => 'reverseIdleRight',
        'walkRight' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*0,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*1,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*2,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*3,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*4,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*5,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
            ]

        ],
        'walkLeft' => 'reverseWalkRight',
        'runRight' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*11,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*12,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*13,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*14,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
            ]
        ],
        'runLeft' => 'reverseRunRight',
        'rollRight' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*0,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*1,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*2,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*3,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
            ]
        ],
        'rollLeft' => 'reverseRollRight',
        'watchUpRight' => [

            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*10,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
            ]

        ] ,
        'watchUpLeft' => 'reverseWatchUpRight',
        'watchDownRight' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*15,
                    'y' => 3,
                    'w' => 23,
                    'h' => 32,
                ],
            ]
        ],
        'jump' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*0,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*1,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*2,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*3,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
            ]
        ],
        'jumpRight' => [
            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*0,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*1,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*2,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*3,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
            ]
        ],
        'jumpLeft' => 'reverseJumpRight',
        'stopRunRight' => [

            'flags' => [] ,
            'coords' => [
                [
                    'x' => 3 + 27*5,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],
                [
                    'x' => 3 + 27*6,
                    'y' => 3 + 35,
                    'w' => 23,
                    'h' => 32,
                ],

            ],
        ],
        'stopRunLeft' => 'reverseStopRunRight',
        'watchDownLeft' => 'reverseWatchDownRight',
        // Sonic frames of the ROM ($20000 + frame * $120, 3bpp) in the animation table _5965:
        // front-facing, arms up: shown while Sonic falls off the screen (animation $0B, frame 17)
        'dead' => [
            'flags' => [],
            'coords' => [['x' => 3 + 27*7, 'y' => 3 + 35, 'w' => 23, 'h' => 32]],
        ],
        // drowned: mouth wide open (animation $15, frame 35)
        'drowned' => [
            'flags' => [],
            'coords' => [['x' => 3 + 27*8, 'y' => 3 + 35, 'w' => 23, 'h' => 32]],
        ],
        // thrown back after a hit (animation $10, frame 18)
        'hurtRight' => [
            'flags' => [],
            'coords' => [['x' => 3 + 27*10, 'y' => 3 + 35, 'w' => 23, 'h' => 32]],
        ],
        'hurtLeft' => 'reverseHurtRight',
        // gulping a big air bubble (animation $14, frame 34), for 32 frames ($D2FB)
        'breatheRight' => [
            'flags' => [],
            'coords' => [['x' => 3 + 27*9, 'y' => 3 + 35, 'w' => 23, 'h' => 32]],
        ],
        'breatheLeft' => 'reverseBreatheRight',

    ];


    public function moveUp(float $deltaTime = 1)
    {
//        $this->setY($this->getY() - 5* $deltaTime);
    }
    public function moveDown(float $deltaTime = 1)
    {
        $this->roll();
    }
    public function moveLeft(float $deltaTime = 1)
    {
        // walk
        $this->moveDirection('left', $deltaTime);

    }
    public function moveRight(float $deltaTime = 1)
    {

        // walk
        $this->moveDirection('right', $deltaTime);

    }

	public function update(float $deltaTime)
	{
		// _51bc: landing ends the hurt state and stops Sonic
		if ($this->state === 'hurt' && $this->isGrounded() && $this->vy >= 0) {
			$this->vx = 0;
			$this->setState('idle');
		}

		if (!$this->isMovingFromInput && $this->state !== 'roll')
			$this->idle();

		if (!$this->isGrounded() && !$this->isMovingFromInput) {
			// no direction in the air: stop accelerating and apply the small drag of the original
			$this->ax = 0;
			$drag = min(abs($this->vx), self::AIR_DRAG * $deltaTime);
			$this->vx -= ($this->vx > 0 ? 1 : -1) * $drag;
		}

		$this->updateLook($deltaTime);

		// $D299: counts while nothing is pressed, Sonic still on the ground
		if (!$this->isMovingFromInput && $this->isGrounded() && $this->vx == 0 && $this->state === 'idle'
			&& $this->getLookOffset() == 0.0) {
			$this->idleClock += $deltaTime;
		} else {
			$this->idleClock = 0;
		}
		$this->isMovingFromInput = false ;

		$this->updateRoll($deltaTime);

		$speed = abs($this->vx);
		if ($this->state === 'jump' || $this->state === 'roll') {
			$this->frameDuration = $speed > 200 ? 0.03 : 0.05;
		} elseif ($this->state === 'run') {
			$this->frameDuration = 0.05;
		} elseif ($this->state === 'walk') {
			$this->frameDuration = $speed > 150 ? 0.07 : 0.1;
		} else {
			$this->frameDuration = 0.1;
		}

		if ($this->inWater) {
			$this->gravity = 0x10 / 256 * self::V_SCALE * self::V_SCALE;
			$this->maxFallSpeed = 2 * self::V_SCALE;
			$waterMaxSpeed = 250;
			if (abs($this->vx) > $waterMaxSpeed) {
				$this->vx = ($this->vx > 0 ? 1 : -1) * $waterMaxSpeed;
			}
		} else {
			$this->gravity = 0x38 / 256 * self::V_SCALE * self::V_SCALE;
			$this->maxFallSpeed = 8 * self::V_SCALE;
		}

		// call the trait Sprite update function
        $this->updateGravity($deltaTime);
		// button held after the jump: the take-off speed is kept (no gravity) for up to 16 frames
		if ($this->jumpHold > 0) {
			if ($this->state !== 'jump' || $this->isGrounded() || $this->vy >= 0) {
				$this->jumpHold = 0;   // landed, hit a ceiling, or thrown by something else
			} else {
				$this->jumpHold -= $deltaTime;
				$this->ay = 0;
				$this->vy = -($this->inWater ? self::JUMP_SPEED_WATER : self::JUMP_SPEED);
			}
		}
        $this->updateColision($deltaTime);
		$this->updateSprite($deltaTime);
        parent::update($deltaTime); // update the position of entity

		$this->updateSprung();
		// special stages (_5100): the rolling flag is set every frame, Sonic is always a ball
		if ($this->ballOnly && in_array($this->state, ['idle', 'walk', 'run', 'stopRun', 'push', 'lookUp', 'lookDown'], true)) {
			$this->setAnimation('roll' . ucfirst($this->facing));
		}
		if ($this->slideTimer > 0) {
			$this->slideTimer = max(0, $this->slideTimer - $deltaTime);
			$this->slideClock += $deltaTime;
		}
		if ($this->breatheTimer > 0) {
			$this->breatheTimer -= $deltaTime;
			$this->setAnimation('breathe' . ucfirst($this->facing));
		}
	}

	private float $breatheTimer = 0;

	/**
	 * Thrown up by a spring or a balance: not rolling ($D414 bit 2 clear). Going up faster than 1 px/frame the
	 * original shows animation $13 (frame $26, feet down); once falling faster than 1 px/frame, animation $01.
	 */
	private bool $sprung = false;
	private bool $sprungFalling = false;

	/**
	 * Labyrinth water slide (block behaviours 13-16): $D28C = 6 frames, kept while on the slide, shows
	 * animation $11 (frames $12 / $24, 6 frames each).
	 */
	private float $slideTimer = 0;
	private bool $ballOnly = false;

	public function setBallOnly(bool $ballOnly): void
	{
		$this->ballOnly = $ballOnly;
	}
	private float $slideClock = 0;

	public function setSliding(bool $left): void
	{
		if ($this->slideTimer <= 0) $this->slideClock = 0;
		$this->slideTimer = 6 / 60;
		$this->sprung = false;
		$this->setFacing($left ? 'left' : 'right');
	}

	/** Index in RomSprites::SONIC_SLIDE while sliding, or null. */
	public function getSlideFrame(): ?int
	{
		return $this->slideTimer > 0 ? intdiv((int)floor($this->slideClock * 60), 6) % 2 : null;
	}

	public function setSprung(): void
	{
		$this->sprung = true;
		$this->sprungFalling = false;
	}

	/** ROM frame to draw instead of the sheet ($26), or null. */
	public function getSpringFrame(): ?int
	{
		return $this->sprung && !$this->sprungFalling && !$this->ballOnly ? 0x26 : null;
	}

	private function updateSprung(): void
	{
		if (!$this->sprung) return;
		if ($this->isGrounded() || $this->state !== 'jump' || $this->ballOnly) {
			$this->sprung = false;
			return;
		}
		if ($this->vy > self::V_SCALE) {
			$this->sprungFalling = true;
		}
		if ($this->sprungFalling) {
			$this->setAnimation('walk' . ucfirst($this->facing));
		}
	}

	/** Frames standing still without the joypad ($D299). */
	private float $idleClock = 0;

	/**
	 * After $168 frames standing still with no key pressed (on the ground, X speed 0), animation $0D:
	 * frame $16 for 16 frames, $15 for 18, then $16 / $17 (tapping his foot) 16 frames each, looping.
	 * Returns the ROM frame to draw, or null.
	 */
	public function getWaitFrame(): ?int
	{
		$frames = (int)floor($this->idleClock * 60) - 0x168;
		if ($frames < 0) return null;
		if ($frames < 16) return 0x16;
		if ($frames < 34) return 0x15;
		return (intdiv($frames - 34, 16) % 2) ? 0x17 : 0x16;
	}

	/** _5206: a big bubble was taken, Sonic gulps it for $20 frames. */
	public function breathe(): void
	{
		$this->breatheTimer = 0x20 / 60;
	}

    public function moveDirection(string $direction, float $deltaTime = 1)
    {
        // hurt (flags6 bit 6): the joypad is ignored until Sonic lands
        if ($this->state === 'hurt' && !$this->isGrounded()) return;
		$this->isMovingFromInput = true ;

        $factor = ($direction == 'left') ? -1 : 1;
        $this->setFacing($direction);
        $this->setFriction(1);

        if ($this->state === 'roll') {
            $movingOpposite = ($this->vx > 30 && $direction == 'left') || ($this->vx < -30 && $direction == 'right');
            if ($movingOpposite) {
                $this->unroll();
                $this->setState('stopRun');
                $this->setAcceleration($factor * 2500, 0);
            }
            return;
        }

        if (!$this->isGrounded()) {
            $reversing = $this->vx * $factor < 0 && abs($this->vx) >= self::REVERSE_MIN_SPEED;
            $this->ax = $factor * ($reversing ? self::AIR_REVERSE : self::AIR_ACCEL);
            if (abs($this->vx) >= $this->maxSpeed && $this->vx * $factor > 0) {
                $this->vx = $factor * $this->maxSpeed;
                $this->ax = 0;
            }
            return;
        }

        $currentSpeed = $this->getSpeedX();
        $movingOpposite = ($currentSpeed > 30 && $direction == 'left') || ($currentSpeed < -30 && $direction == 'right');

        if ($movingOpposite) {
            $this->setState('stopRun');
            $this->setAcceleration($factor * 2500, 0);
            return;
        }

        if (abs($currentSpeed) < 60) {
            $this->vx = $factor * 60;
        }

        $this->setAcceleration($factor * ($this->maxSpeed > 500 ? 400 : 250), 0);

        if (abs($this->vx) > $this->maxSpeed) {
            $this->vx = $factor * $this->maxSpeed;
            $this->setAcceleration(0, 0);
        }

        if (abs($this->vx) > 300)
            $this->setState('run');
        else
            $this->setState('walk');
    }

    public function idle($deltaTime = 1)
    {
        if (!$this->isGrounded()) return;
        if ($this->state === 'roll') return;

        $currentSpeed = abs($this->getSpeedX());
        $this->setAcceleration(0, 0);
        $this->setFriction(1);

        if ($currentSpeed < 15) {
            $this->vx = 0;
            $this->setState('idle');
            return;
        }

        $decel = 250;
        if ($this->vx > 0) {
            $this->vx = max(0, $this->vx - $decel * (1.0/120));
        } else {
            $this->vx = min(0, $this->vx + $decel * (1.0/120));
        }

        if ($currentSpeed > 300)
            $this->setState('run');
        elseif ($currentSpeed > 15)
            $this->setState('walk');
        else
            $this->setState('idle');
    }

    public function move(string $dir,float $deltaTime = 1)
    {
		if ($dir === 'up') {
			$this->moveUp($deltaTime);
		} elseif ($dir === 'down') {
			$this->moveDown($deltaTime);
		} elseif ($dir === 'left') {
			$this->moveLeft($deltaTime);
		} elseif ($dir === 'right') {
			$this->moveRight($deltaTime);
		} else {
			throw new \InvalidArgumentException("Direction '$dir' is not valid.");

		}
    }

    /** Speed left by the last physics step (after the collisions), before this frame's input. */
    private float $settledVx = 0;

    /** Called once the collisions of the frame are resolved. */
    public function settleFrame(): void
    {
        $this->settledVx = $this->vx;
    }

    public function roll()
    {
        if (!$this->isGrounded()) return;
        if ($this->state === 'roll') return;
        // the real speed counts, not the push given by the direction key this frame: against a wall
        // (or a screen edge) Sonic crouches instead of rolling / unrolling every frame
        if (abs($this->vx) < 60 || abs($this->settledVx) < 60) {
            $this->watchDown();
            return;
        }

        $this->setState('roll');
        $this->emit('sfx', [SoundManager::SFX_ROLL]);
    }

    public function unroll()
    {
        if ($this->state !== 'roll') return;
        if (!$this->isGrounded()) return;

        if (abs($this->vx) > 15) {
            $this->setState('walk');
        } else {
            $this->vx = 0;
            $this->setState('idle');
        }
    }

    public function updateRoll(float $deltaTime)
    {
        if ($this->state !== 'roll') return;
        if (!$this->isGrounded()) return;

        $this->setFriction(1);
        $this->setAcceleration(0, 0);

        $rollFriction = 120;
        if ($this->vx > 0) {
            $this->vx = max(0, $this->vx - $rollFriction * $deltaTime);
        } elseif ($this->vx < 0) {
            $this->vx = min(0, $this->vx + $rollFriction * $deltaTime);
        }

        if (abs($this->vx) < 30) {
            $this->unroll();
        }
    }

	public function action($action)
	{
		if ($action === 'jump') {
			$this->jump();
		} elseif ($action === 'roll') {
			$this->roll();
		} else {
			throw new \InvalidArgumentException("Action '$action' is not valid.");
		}
	}

	/** A new start of the act: standing, facing right, no spring / slide / look / speed shoes. */
	public function resetForStart(): void
	{
		$this->setVelocity(0, 0);
		$this->setAcceleration(0, 0);
		$this->setFacing('right');
		$this->setState('idle');
		$this->setSpeedBoost(false);
		$this->sprung = false;
		$this->sprungFalling = false;
		$this->slideTimer = 0;
		$this->breatheTimer = 0;
		$this->idleClock = 0;
		$this->setLookRequest(null);
		$this->lookOffset = 0.0;
	}

	/** Any key resets the waiting counter. */
	public function resetIdle(): void
	{
		$this->idleClock = 0;
	}

	public function jump()
	{
		if (!$this->isGrounded()) return;

		$this->grounded = false;
		$this->ax = 0;   // a ground brake (stopRun) must not carry on in the air
		$this->setVelocity($this->vx, -($this->inWater ? self::JUMP_SPEED_WATER : self::JUMP_SPEED));
		$this->jumpHold = self::JUMP_HOLD;
		$this->setState('jump');
		$this->emit('sfx', [SoundManager::SFX_JUMP]);
	}

	public function jumpRelease()
	{
		if ($this->isGrounded()) return;
		// the button is released: gravity again ($D28E = 0)
		$this->jumpHold = 0;
	}

	public function initTexture(Sdl $sdl)
	{
		$this->sdl = $sdl;
		$this->tilesetName = 'sonic' ;
		$sdl->loadTexture($this->tilesetName, 'tileset/sprites/tileset-sonic.png',['r' => 0 , 'g' => '72' , 'b' => 0]);
		// debug invincibility (F5): red eyes, tools/sonicgod.py
		$sdl->loadTexture('sonic-god', 'tileset/sprites/tileset-sonic-god.png',['r' => 0 , 'g' => '72' , 'b' => 0]);
		$sdl->loadTextureVariant('sonic-god', 'tileset/sprites/tileset-sonic-god.png', '-water', ['r' => 0 , 'g' => '72' , 'b' => 0]);
		// the frames drawn from the ROM sheet (waiting, spring, water slide)
		$sdl->loadTexture('rom-god', 'tileset/sprites/tileset-rom-god.png', ['r' => 170, 'g' => 0, 'b' => 170]);
		// ghost of the best run (tools/sonicghost.py): white metal, half transparent (alpha channel, no colour key)
		$sdl->loadTexture('sonic-ghost', 'tileset/sprites/tileset-sonic-ghost.png');
	}

    /** Sheet of Sonic's frames drawn from the ROM art (RomSprites::SHEET, or its red-eyed copy). */
    public function getRomSheet(): string
    {
        return $this->tilesetName === 'sonic-god' ? 'rom-god' : \SonicGame\Entities\Objects\RomSprites::SHEET;
    }

    public function setGodLook(bool $on): void
    {
        $this->tilesetName = $on ? 'sonic-god' : 'sonic';
    }

    /** Up held: looks up when standing still (anim $0C in the original). */
    public function watchUp()
    {
        $this->lookRequest = 'up';
    }

    /** Down held while standing still: crouches (anim $07). */
    public function watchDown()
    {
        $this->lookRequest = 'down';
    }

    /** Up / down request of this frame (kept by Game for each physics step). */
    public function getLookRequest(): ?string
    {
        return $this->lookRequest;
    }

    public function setLookRequest(?string $request): void
    {
        $this->lookRequest = $request;
    }

    private function updateLook(float $deltaTime): void
    {
        $still = $this->isGrounded() && abs($this->vx) < 15 && !$this->isMovingFromInput
            && in_array($this->state, ['idle', 'watchUp', 'watchDown'], true);
        $look = $still ? $this->lookRequest : null;
        $this->lookRequest = null;

        if ($look !== null) {
            $this->vx = 0;
            $this->setState($look === 'up' ? 'watchUp' : 'watchDown');
        } elseif ($this->state === 'watchUp' || $this->state === 'watchDown') {
            $this->setState('idle');
        }

        $target = match ($look) { 'up' => -44.0, 'down' => 80.0, default => 0.0 };
        $step = 60 * $deltaTime;
        if ($this->lookOffset < $target) {
            $this->lookOffset = min($target, $this->lookOffset + $step);
        } elseif ($this->lookOffset > $target) {
            $this->lookOffset = max($target, $this->lookOffset - $step);
        }
    }

    public function getLookOffset(): float
    {
        return $this->lookOffset;
    }



}

