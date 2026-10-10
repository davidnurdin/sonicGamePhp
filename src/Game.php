<?php

namespace SonicGame;

use Evenement\EventEmitter;
use React\EventLoop\TimerInterface;
use SonicGame\Entities\GameObjectManager;
use SonicGame\Entities\Physics\CollisionSystem;
use SonicGame\Entities\Player;
use SonicGame\InputManager\InputKeyboard;
use SonicGame\InputManager\InputManager;
use SonicGame\InputManager\InputTouchpad;
use SonicGame\Level\LevelManager;
use SonicGame\Loop\GameLoop;
use SonicGame\Renderer\Sdl;
use SonicGame\Scene\Scene;
use SonicGame\Scene\Screens;
use SonicGame\SoundManager\SoundManager;

class Game extends EventEmitter
{

    private int $debugMode = 0 ;

	public bool $disableSdl = false ;

    private GameObjectManager $objectManager;

    private Screens $screens;

    /** Start button pressed since the last tick (title / map / act complete screens). */
    private bool $buttonPressed = false;
    /** Act that led to a Scrap Brain transporter room (the game resumes after it). */
    private ?int $warpFrom = null;
    /** Debug (keypad 5): Sonic follows the free camera. */
    private bool $carryPlayer = false;

    // View: game pixels -> screen pixels. The automatic zoom keeps a view about 426px high whatever the window /
    // screen size, the keypad +/- step from it, * switches to the 256x192 Master System screen.
    private const ZOOMS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2, 2.5, 3, 3.5, 4, 5, 6, 8];
    private const DEFAULT_VIEW_HEIGHT = 426;
    private int $zoomOffset = 0;
    private bool $smsView = false;

    public function __construct(
        public GameLoop $gameLoop,
        private InputManager $inputManager,
        public Sdl $sdl,
        private Player $player,
        private Scene $scene,
        private LevelManager $levelManager,
        private SoundManager $soundManager,
		private CollisionSystem $collisionSystem
)
    {
        $scene->setPlayer($this->player);
        $this->objectManager = new GameObjectManager($sdl);
        $scene->setObjectManager($this->objectManager);
        $this->screens = new Screens($sdl);
    }

    /** Next special stage ($D23F, from $1C = our level 28) and the act to go on from after it. */
    private int $nextSpecialStage = 28;

    /**
     * Demo mode (_1bad): Green Hill act 1 played with the joypad of _1bc6, one byte every 32 frames
     * (active low: bit 0 up, 1 down, 2 left, 3 right, 4-5 buttons). A button press, Sonic's death or the end
     * of the table go back to the title screen.
     */
    private const DEMO_INPUT = [
        0xF7, 0xF7, 0xF7, 0xF7, 0xDF, 0xF7, 0xFF, 0xFF, 0xD7, 0xF7, 0xF7, 0xF7, 0xFF, 0xDF, 0xF7, 0xF7, 0xDF, 0xF7, 0xF7, 0xF7,
        0xF7, 0xFF, 0xFF, 0xDF, 0xF7, 0xFF, 0xFF, 0xFF, 0xFB, 0xF7, 0xF7, 0xF5, 0xFF, 0xFF, 0xFF, 0xFF, 0xFB, 0xFB, 0xF9, 0xFF,
        0xFF, 0xFF, 0xFF, 0xF7, 0xF7, 0xF7, 0xF7, 0xD7, 0xFF, 0xFF, 0xD7, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xD7, 0xFB,
        0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xD7, 0xF7, 0xF7, 0xFF, 0xD7,
        0xFB, 0xF7, 0xF7, 0xF7, 0xF7, 0xFB, 0xFB, 0xF7, 0xFF, 0xD7, 0xFB, 0xFF, 0xF7, 0xF7, 0xD7, 0xFB, 0xD7, 0xF7, 0xF7, 0xF7,
        0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xF7, 0xF7, 0xF7, 0xD7, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF,
        0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF,
    ];
    private bool $demo = false;
    private int $demoFrames = 0;
    private float $demoClock = 0;
    private int $demoPrevious = 0xFF;
    private ?int $specialReturn = null;

    private function registerEvents()
    {
        $this->inputManager->on('exitGame', fn() => $this->eventExitGame());
        $this->inputManager->on('keyPress', fn($keyboard, $key) => $this->eventKeyPressed($keyboard, $key));
        $this->inputManager->on('touchPressed', fn($touchpad, $action) => $this->eventTouchPressed($touchpad, $action));
        $this->levelManager->on('levelChanged', fn($level) => $this->levelReload($level));

        // Connecte le CollisionSystem à Scene pour les événements de debug
        $this->scene->listenToCollisionEvents($this->collisionSystem);

        $this->collisionSystem->setObjectGroundProvider(
            fn(array $rect, float $vy, string $state) => $this->objectManager->findObjectGround($rect, $vy, $state)
        );
        $this->collisionSystem->setWaterLineProvider(fn() => $this->objectManager->getWaterLine());
        $this->collisionSystem->on('playerHazard', fn() => $this->objectManager->hurtPlayer($this->player));
        $this->collisionSystem->on('paletteFlash', fn() => $this->objectManager->flashPalette());
        $this->collisionSystem->on('playerFell', fn() => $this->objectManager->playerFellInPit($this->player));
        $this->objectManager->on('levelComplete', fn() => $this->screens->showActComplete(
            $this->levelManager->getCurrentLevel()->getLevel(),
            $this->objectManager->getScore(),
            $this->objectManager->getRingCount(),
            $this->objectManager->getLevelTime(),
            $this->objectManager->getLives(),
            $this->objectManager->getEmeralds()
        ));
        // Sound: every part of the engine emits 'sfx' / 'music' events
        $playSfx = fn(int $index, bool $loop = false) => $this->soundManager->playSfx($index, $loop);
        $this->player->on('sfx', $playSfx);
        $this->collisionSystem->on('sfx', $playSfx);
        $this->scene->on('sfx', $playSfx);
        $this->objectManager->on('sfx', $playSfx);
        $this->objectManager->on('stopSfx', fn(int $index) => $this->soundManager->stopSfx($index));
        $this->objectManager->on('music', fn(string $name, bool $loop) => $this->soundManager->playMusic($name, $loop, true));
        $this->objectManager->on('levelMusic', fn() => $this->playLevelMusic(true));
        $this->objectManager->on('extraLife', fn() => $this->soundManager->playSfx(SoundManager::SFX_EXTRA_LIFE));
        $this->objectManager->on('playerRespawned', function () {
            if (!$this->screens->isActive()) $this->playLevelMusic(true);   // not after a game over (title)
        });

        // _2067: once $D287 has run out the screen fades out; 60 black frames, then the act restarts
        $this->objectManager->on('deathOver', fn() => $this->fadeLevelOut(0x3C, function () {
            // the act is loaded again like at its start (_1CED): camera, scrolling, Sonic
            $this->player->resetForStart();
            $this->objectManager->respawnPlayer($this->player);
            $this->scene->resetLevel();
        }));
        $this->objectManager->on('gameOver', fn(int $continues) => $this->fadeLevelOut(0, fn() => $this->screens->showGameOver($continues)));
        $this->screens->on('gameOverDone', function (bool $continued) {
            if ($continued) {
                // the act from its beginning, with its map
                $this->objectManager->useContinue();
                $this->levelReload($this->levelManager->getCurrentLevel()->getLevel());
                $this->startLevel();
                return;
            }
            $this->objectManager->resetGame();
            $this->warpFrom = null;
            $this->specialReturn = null;
            $this->nextSpecialStage = 28;
            $this->levelManager->firstLevel();
            $this->levelReload(1);   // also when the game ended in act 1
            $this->screens->showTitle();
        });

        // end of the game (level 19): island, final tally and credits; then back to the title, new game
        $this->objectManager->on('gameEnd', fn() => $this->screens->showEnding(
            $this->objectManager->getScore(),
            $this->objectManager->getLives(),
            $this->objectManager->getEmeralds(),
            $this->objectManager->getStats()
        ));
        $this->screens->on('creditsDone', function () {
            $this->objectManager->resetGame();
            $this->warpFrom = null;
            $this->specialReturn = null;
            $this->nextSpecialStage = 28;
            $this->levelManager->firstLevel();
            $this->screens->showTitle();
        });

        // Title -> map -> level -> act complete -> map -> next level...
        $this->screens->on('titleDone', fn() => $this->startLevel());
        $this->screens->on('titleDemo', function () {
            $this->demo = true;
            $this->demoFrames = 0;
            $this->demoClock = 0;
            $this->demoPrevious = 0xFF;
            $this->objectManager->resetGame();
            $this->levelManager->firstLevel();
            $this->startLevel();
        });
        $this->screens->on('mapDone', fn() => $this->playLevelMusic(true));
        $this->screens->on('actCompleteDone', function () {
            $this->objectManager->setScore($this->screens->getScore());
            $current = $this->levelManager->getCurrentLevel()->getLevel();
            // _1fa9: the sign showed "!": the next special stage ($D23F) is played, then the next act
            if ($this->objectManager->takeSpecialStage() && $this->nextSpecialStage <= 35) {
                $this->specialReturn = $this->warpFrom ?? $current;
                $this->warpFrom = null;
                $this->levelManager->goToLevel($this->nextSpecialStage++);
                $this->startLevel();
                return;
            }
            if ($this->specialReturn !== null && $current >= 28) {
                $this->levelManager->goToLevel($this->specialReturn);
                $this->specialReturn = null;
            }
            // after a transporter room the game goes on from the act that led there
            if ($this->warpFrom !== null) {
                $this->levelManager->goToLevel($this->warpFrom);
                $this->warpFrom = null;
            }
            $this->levelManager->nextLevel();
            $this->startLevel();
        });
        // Scrap Brain transporter: the target room starts at once, without tally
        $this->objectManager->on('warp', function (int $level) {
            $this->warpFrom ??= $this->levelManager->getCurrentLevel()->getLevel();
            $this->levelManager->goToLevel($level);
            $this->playLevelMusic(true);
        });
    }

    /** fadeOut over the level ($0A6E: 4 steps of 10 frames, the game stopped), then $black frames, then $then. */
    private ?array $levelFade = null;
    private float $levelFadeClock = 0;

    private function fadeLevelOut(int $black, \Closure $then): void
    {
        if ($this->levelFade !== null) return;
        $this->levelFade = [0, $black, $then];
        $this->levelFadeClock = 0;
    }

    /** Darkness of the level fade, 0 (none) to 3 (black). */
    private function levelFadeStep(): int
    {
        return $this->levelFade === null ? 0 : min(3, intdiv($this->levelFade[0], Screens::FADE_STEP) + 1);
    }

    /** Back to the title screen after the demo (sound and screen faded out, _20b8), with a new game. */
    private function endDemo(): void
    {
        if ($this->levelFade !== null) return;
        $this->soundManager->stopMusic();
        $this->fadeLevelOut(0, fn() => $this->closeDemo());
    }

    private function closeDemo(): void
    {
        $this->demo = false;
        $this->soundManager->stopMusic();
        $this->objectManager->resetGame();
        $this->levelManager->firstLevel();
        // the demo played act 1: load it again from the start
        $this->levelReload(1);
        $this->player->setVelocity(0, 0);
        $this->player->setAcceleration(0, 0);
        $this->screens->showTitle();
    }

    /** Joypad of the demo for this frame (60Hz). */
    private function playDemo(float $deltaTime): void
    {
        $this->demoClock += $deltaTime;
        while ($this->demoClock >= 1 / 60) {
            $this->demoClock -= 1 / 60;
            $this->demoFrames++;
        }
        $pad = self::DEMO_INPUT[intdiv($this->demoFrames, 32)] ?? null;
        if ($pad === null || $this->objectManager->isPlayerDead()) {
            $this->endDemo();
            return;
        }
        $locked = $this->objectManager->isLevelComplete() || $this->objectManager->isCutscene();
        if (!$locked) {
            if (!($pad & 0x04)) $this->player->moveLeft();
            if (!($pad & 0x08)) $this->player->moveRight();
            if (!($pad & 0x01)) $this->player->watchUp();
            if (!($pad & 0x02)) $this->player->roll();
        }
        $button = ($pad & 0x30) !== 0x30;
        $wasButton = ($this->demoPrevious & 0x30) !== 0x30;
        if ($button && !$wasButton && !$locked) $this->player->jump();
        if (!$button && $wasButton) $this->player->jumpRelease();
        $this->objectManager->setButtonHeld($button);
        $this->demoPrevious = $pad;
    }

    /** Shows the map before the acts that have one (levels 1-18), the level starts when it ends. */
    private function startLevel(): void
    {
        $level = $this->levelManager->getCurrentLevel()->getLevel();
        if ($this->screens->hasMap($level)) {
            $this->screens->showMap($level, $this->objectManager->getLives());
        } else {
            $this->playLevelMusic(true);
        }
    }

    /**
     * Music of the current level from its header (MU byte, index into the driver's song list),
     * or the invincibility / boss song while they are active ($FF = keep the current song).
     */
    private function playLevelMusic(bool $restart = false): void
    {
        if ($this->objectManager->isInvincible()) {
            $this->soundManager->playMusic('invincibility', true, $restart);
        } elseif ($this->objectManager->isBossFight()) {
            $this->soundManager->playMusic('boss', true, $restart);
        } else {
            $index = $this->levelManager->getCurrentLevel()->getMeta('MU', 0);
            if ($index === 0xFF) return;
            $name = SoundManager::MUSIC_BY_INDEX[$index] ?? null;
            if ($name) $this->soundManager->playMusic($name, true, $restart);
        }
    }

    private function levelReload(int $level)
    {
        $this->scene->resetLevel();
        $this->objectManager->resetForNewLevel();
        dump('Loaded level : ' . $level);
    }


    public function run(): void
    {
        $vars = [] ;
        // Init SDL
        // TODO : voir vsync ce qu'on fait.
        // $this->sdl->initSDL(fullscreen: false, title: 'SonicGame',width:240,height:226,vsync: true);
		$this->sdl->initSDL(fullscreen: false, title: 'SonicGame',width:840,height:426,vsync: true);

        $this->soundManager->Init();
        $this->screens->setSoundManager($this->soundManager);

        // Init Textures

		$this->player->initTexture($this->sdl);
        $sprites = [
            'objects' => ['r' => 170, 'g' => 0, 'b' => 170],
            'enemies' => ['r' => 170, 'g' => 0, 'b' => 170],
            'eggman' => ['r' => 0, 'g' => 170, 'b' => 255],
            'sonic' => ['r' => 0, 'g' => 72, 'b' => 0],
            'flower' => ['r' => 170, 'g' => 0, 'b' => 170],   // background tiles (tools/flowers.py), opaque
            'rom' => ['r' => 170, 'g' => 0, 'b' => 170],      // sprites rendered from the ROM (tools/romsprites.py)
            'skyglass' => ['r' => 170, 'g' => 0, 'b' => 170], // Sky Base boss broken glass (tools/skybaseglass.py)
            'boss' => ['r' => 170, 'g' => 0, 'b' => 170],     // act 3 bosses from the ROM layouts (tools/bosssprites.py)
        ];
        foreach ($sprites as $name => $key) {
            if ($name !== 'sonic') {
                $this->sdl->loadTexture($name, 'tileset/sprites/tileset-' . $name . '.png', $key);
            }
            // underwater palette (Labyrinth), see tools/waterpalette.py
            $this->sdl->loadTextureVariant($name, 'tileset/sprites/tileset-' . $name . '.png', '-water', $key);
            // boss hit: outlines flashing white
            $this->sdl->loadTextureVariant($name, 'tileset/sprites/tileset-' . $name . '.png', '-flash', $key);
        }
        $this->sdl->loadFont('sonic','fonts/NiseSegaSonic.TTF') ;
        $this->screens->loadTextures();

        $this->levelManager->loadLevels();
        $this->registerEvents();
        $this->screens->showTitle();
        $frameDuration = 0 ; // 1 / 60; // 60Hz
        $inputDuration = 0 ; // 1 / 600; // 240Hz


//		$window = new \Vrzno;
//		$response = vrzno_await($window->fetch('https://api.weather.gov/gridpoints/TOP/40,74/forecast'));
//		$json = vrzno_await($response->json());
//		// Module.getValue is not a function ...
		// non on px pas utilisé vrzno il utilise php-wasm (phpbase.js entre autre)
//		var_dump($json);
//		dump($window);

        $vars['fps'] = 0;
        $vars['deltaSum'] = 0.0;
        $vars['lastTime'] = microtime(true);


        // Boucle principale
        $this->gameLoop->addPeriodicTimer(1, function (TimerInterface $timer) use (&$vars) {
            $deltaMoyen = $vars['fps'] > 0 ? $vars['deltaSum'] / $vars['fps'] : 0;
            echo sprintf("FPS réel : %d | Δ moyen : %.3f ms", $vars['fps'], $deltaMoyen * 1000) . PHP_EOL;
            $vars['fps'] = 0;
            $vars['deltaSum'] = 0.0;
        });

        // Nettoyage périodique des anciennes tiles de collision (toutes les 100 ms)
        $this->gameLoop->addPeriodicTimer(0.1, function () {
            $this->scene->cleanupOldCollisionTiles();
        });

//		$this->gameLoop->addPeriodicTimer(1/150, function (TimerInterface $timer) use (&$vars) {
//			$this->player->moveRight();
//		});

        $closureInputs = function() use (&$vars)
        {

            $this->inputManager->poll();

            // Force emit keyPress to have key with $inputDuration
            if ($this->inputManager->getKeyboard()->haveOneKeyPressed()) {
                // get the last key pressed
                $keyPressed = $this->inputManager->getKeyboard()->getLastKeyPressed();
                $this->inputManager->emit('keyPress', [$this->inputManager->getKeyboard(), $keyPressed]);
            }

            // Same with Touchpad
            if ( ($this->inputManager->getTouchpad()->haveOneFingerHelded()) || $this->inputManager->getTouchpad()->isActionPressed('jump'))
			{
				// get the last key pressed
				 $fingerId = $this->inputManager->getTouchpad()->getLastFingerPressed();

				 if ($fingerId == null) // jump
					 $fingerId = 0 ;

				 $this->inputManager->emit('touchPressed', [$this->inputManager->getTouchpad(), $fingerId]);
			}

			$this->inputManager->getKeyboard()->resetTransientStates();
            $this->inputManager->getTouchpad()->resetTransientStates();

        };
        $closureDisplay = function($deltaTime) use (&$vars)
        {

			if (!$this->disableSdl) {

			$this->updateView();
			\SDL_SetRenderTarget($this->sdl->getRenderer()->getRenderer(), $this->sdl->getRenderer()->getRenderTexture()); // draw to texture

            // Rendu de la scène
            $this->scene->setDebugMode($this->debugMode);
			$this->sdl->getRenderer()->clear(); // 1
            if ($this->screens->isActive()) {
                $this->screens->render();
            } else {
                $this->sdl->getRenderer()->createScene(
                    $this->scene,
                    $this->player,
                    $this->sdl->getFont('sonic'),
                    $this->levelManager->getCurrentLevel()
                );
                // level fade: fade.png, black at 1/3, 2/3, 3/3
                if (($step = $this->levelFadeStep()) > 0) {
                    $src = new \SDL_Rect;
                    [$src->x, $src->y, $src->w, $src->h] = [($step - 1) * 8, 8, 8, 8];
                    $dst = new \SDL_Rect;
                    [$dst->x, $dst->y, $dst->w, $dst->h] = [0, 0, $this->sdl->getWindow()->getWidth(), $this->sdl->getWindow()->getHeight()];
                    \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures('screen-fade')[0]['texture'], $src, $dst);
                }
            }

			// TODO : voir pk en web fatal error sdl setrendertarget supplied resource is not a valid sdl texture resource ?? =>
				$width = $height = 0 ;

				\SDL_SetRenderTarget($this->sdl->getRenderer()->getRenderer(),NULL); // draw to window
				\SDL_GetRendererOutputSize($this->sdl->getRenderer()->getRenderer(),$width,$height);

				$scaleX = $width / $this->sdl->getWindow()->getWidth();
				$scaleY = $height / $this->sdl->getWindow()->getHeight();

				$scale = min($scaleX, $scaleY);
				$outputW = (int)($this->sdl->getWindow()->getWidth() * $scale);
				$outputH = (int)($this->sdl->getWindow()->getHeight() * $scale);
				$offsetX = (int)(($width - $outputW) / 2);
				$offsetY = (int)(($height - $outputH) / 2);

				$dst = new \SDL_Rect;
				$dst->x = $offsetX;
				$dst->y = $offsetY;
				$dst->w = $outputW;
				$dst->h = $outputH;

				$this->sdl->getRenderer()->setColor(0, 0, 0, 255);   // borders (256x192 mode, rounding)
				$this->sdl->getRenderer()->clear();
				\SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(),$this->sdl->getRenderer()->getRenderTexture(), null, $dst); // on copie la texture dans le renderer
				$this->sdl->getRenderer()->present();

			// SDL_HINT_RENDER_SCALE_QUALITY ?


			}
		} ;


    

        $this->gameLoop->addPeriodicTimer(1/120, function (TimerInterface $timer) use ($closureDisplay, $closureInputs,&$vars) {

			$now = microtime(true);
			$deltaTime = $now - $vars['lastTime'];
			$vars['lastTime'] = $now;
			++$vars['fps'];
			$vars['deltaSum'] += $deltaTime;

            // $deltaTime = 0.01 ; // Pour debug

			$closureInputs(); // GET Inputs
			$this->soundManager->update();

			// the level is fading out: the game waits (fadeOut runs between interrupts only)
			if ($this->levelFade !== null && !$this->screens->isActive()) {
				$this->levelFadeClock += $deltaTime;
				while ($this->levelFade !== null && $this->levelFadeClock >= 1 / 60) {
					$this->levelFadeClock -= 1 / 60;
					if (++$this->levelFade[0] >= 4 * Screens::FADE_STEP + $this->levelFade[1]) {
						$then = $this->levelFade[2];
						$this->levelFade = null;
						$then();
					}
				}
				$this->buttonPressed = false;
				$closureDisplay($deltaTime);
				return;
			}
			$this->levelFade = null;

			// demo mode: the joypad of the ROM (may end the demo and go back to the title screen)
			if ($this->demo && !$this->screens->isActive() && $this->scene->getCurrentLevel()) {
				$this->playDemo($deltaTime);
			}

			if ($this->screens->isActive()) {
				$this->screens->update($deltaTime, $this->buttonPressed);
				$this->buttonPressed = false;
				$closureDisplay($deltaTime);
				return;
			}

			if ($this->scene->getCurrentLevel() && $this->carryPlayer) {
                // debug (keypad 5): Sonic is carried at the centre of the free camera, no physics
                $camera = $this->scene->getCamera();
                $this->player->setXY(
                    $camera->getX() + $this->sdl->getWindow()->getWidth() / 2 - 12,
                    $camera->getY() + $this->sdl->getWindow()->getHeight() / 2 - 16
                );
                $this->player->setVelocity(0, 0);
                $this->player->setAcceleration(0, 0);
                $this->player->updateSprite($deltaTime);
                $this->objectManager->update($deltaTime, $this->player, $this->scene->getCurrentLevel());
			} elseif ($this->scene->getCurrentLevel()) {
                // after the sign: the joypad is forced to the right (flags6 bit 1)
                if ($this->objectManager->signRun($this->player)) {
                    $this->player->moveRight();
                }
                if (!$this->objectManager->isPlayerDead()) {
                    // after a slow frame the real delta can be large: move Sonic in steps of at most 1/120s
                    // so that he can't go through a floor or a wall in one go (seen in the bonus stages)
                    $steps = min(12, max(1, (int)ceil($deltaTime * 120 - 0.01)));
                    // the joypad of this frame holds for every step (update() clears the flag)
                    $moving = $this->player->isMovingFromInput;
                    $look = $this->player->getLookRequest();
                    for ($i = 0; $i < $steps; $i++) {
                        $this->player->isMovingFromInput = $moving;
                        $this->player->setLookRequest($look);
                        $this->player->update($deltaTime / $steps);
                        $this->collisionSystem->checkCollisions($this->player, $this->scene->getCurrentLevel(), $deltaTime / $steps);
                    }
                } else {
                    $this->player->updateSprite($deltaTime);
                }
                $this->objectManager->setRomCamera($this->scene->getCamera()->getRomCamera($this->player->getX(), $this->player->getY()));
                $this->objectManager->setWave($this->scene->getCamera()->getWave());
                $this->objectManager->update($deltaTime, $this->player, $this->scene->getCurrentLevel());

                // a jingle (emerald...) has finished: back to the level music
                if (!$this->objectManager->isPlayerDead() && !$this->objectManager->isLevelComplete()
                    && !$this->soundManager->isMusicPlaying()) {
                    $this->playLevelMusic();
                }

                // Boss arena: camera locked like the original, Sonic kept inside the arena
                $this->scene->getCamera()->lock = $this->objectManager->getCameraLock();
                if ($signLimits = $this->objectManager->getSignLimits()) {
                    $this->scene->getCamera()->applySignLimits($signLimits[0], $signLimits[1]);
                }
                if ($bounds = $this->objectManager->getPlayerBounds()) {
                    $x = $this->player->getX();
                    if ($x < $bounds[0] || $x > $bounds[1]) {
                        $this->player->setXY(max($bounds[0], min($bounds[1], $x)), $this->player->getY());
                        $this->player->setVelocity(0, $this->player->getVelocity()[1]);
                    }
                }

                // Level header limits: Sonic stays between LEFT + 8 and RIGHT + 224 (pushed by auto-scrolling)
                // and dies once he falls below the original screen
                $camera = $this->scene->getCamera();
                if (!$this->objectManager->isPlayerDead() && $this->scene->getCurrentLevel() === $this->levelManager->getCurrentLevel()
                    && !$this->objectManager->isSignRun()) {
                    [$minX, $maxX] = $camera->getPlayerLimits();
                    $x = $this->player->getCollisionRect()['x'];
                    if ($x < $minX || $x > $maxX) {
                        $this->player->setX(max($minX, min($maxX, $x)));
                        $this->player->setVelocity(0, $this->player->getVelocity()[1]);
                    }
                    if ($this->player->getCollisionRect()['y'] > $camera->getDeathLine($this->sdl->getWindow()->getHeight())) {
                        $this->objectManager->playerFellInPit($this->player);
                    }
                }
                $this->player->settleFrame();
			}
               


			$this->scene->getCamera()->update($deltaTime); // UpdateCamera

			$closureDisplay($deltaTime); // Update display

        });



    }

    // Renders your game objects here
    private function eventExitGame()
    {
        $this->gameLoop->stop();
    }

    private function eventTouchPressed(InputTouchpad $touchpad, int $fingerID)
	{
		$directions = ['left', 'right'] ; //, 'up', 'down'];
		$actions = ['jump', 'roll'];

		$inputTouchpad = $this->inputManager->getTouchpad();

		foreach ($directions as $dir) {
			if ($inputTouchpad->isActionHeld($dir)) {
                $this->player->move($dir);
				break;
			}
		}

		foreach ($actions as $action) {
			if ($inputTouchpad->isActionPressed($action)) {
				$this->player->action($action);
				break;
			}
		}


	}


    private function eventKeyPressed(InputKeyboard $keyboard, ?int $keyPressed)
    {
        if ($this->demo && ($keyboard->isKeyPressed(\SDLK_SPACE) || $keyboard->isKeyPressed(\SDLK_RETURN))) {
            $this->endDemo();
            return;
        }
        if ($this->screens->isActive()) {
            if ($keyboard->isKeyPressed(\SDLK_SPACE) || $keyboard->isKeyPressed(\SDLK_RETURN)) {
                $this->buttonPressed = true;
            }
            if ($keyboard->isKeyPressed(\SDLK_ESCAPE)) {
                $this->inputManager->emit('exitGame', []);
            }
            if ($keyboard->isKeyPressed(\SDLK_F12)) {
                $this->sdl->getWindow()->toggleFullscreen();
            }
            return;
        }

        if ($this->demo) {
            // only F12 / Escape below the demo's own joypad
            if ($keyboard->isKeyPressed(\SDLK_ESCAPE)) $this->inputManager->emit('exitGame', []);
            if ($keyboard->isKeyPressed(\SDLK_F12)) $this->sdl->getWindow()->toggleFullscreen();
            return;
        }
        $this->objectManager->setButtonHeld($keyboard->isKeyHeld(\SDLK_SPACE));
        $this->player->resetIdle();
        $playerLocked = $this->objectManager->isPlayerDead() || $this->objectManager->isLevelComplete()
            || $this->objectManager->isCutscene();

//        dump('KeyPress : ' . $keyPressed);

        // we can have multiple key pressed
        if ($playerLocked) {
            // no movement while dying or after the signpost
        }
        elseif ($keyboard->isKeyHeld(\SDLK_RIGHT) && $keyboard->isKeyHeld(\SDLK_LEFT))
        {
            // use the last key
            if ($keyboard->getLastKeyPressed() == \SDLK_RIGHT) {
                $this->player->moveRight();
                // Move the player to right
            }
            else {
                $this->player->moveLeft();
                // Move the player to left
            }
        }
        else {
            if ($keyboard->isKeyHeld(\SDLK_RIGHT)) {
                $this->player->moveRight();
                // Move the player to right
            }

            if ($keyboard->isKeyHeld(\SDLK_LEFT)) {
                $this->player->moveLeft();
                // Move the player to left
            }
        }


        // Write up&down like left&right
        if ($keyboard->isKeyHeld(\SDLK_UP) && $keyboard->isKeyHeld(\SDLK_DOWN))
        {
            // use the last key
            if ($keyboard->getLastKeyPressed() == \SDLK_UP) {
                $this->player->watchUp();
                // Move the player to up
            }
            else {
                $this->player->watchDown();
                // Move the player to down
            }
        }
        else {
            if ($keyboard->isKeyHeld(\SDLK_UP)) {
               $this->player->watchUp();
                // Move the player to up
            }

            if ($keyboard->isKeyHeld(\SDLK_DOWN)) {
                $this->player->roll();
            }
        }

        if ($keyboard->isKeyPressed(\SDLK_SPACE) && !$playerLocked)
        {
            $this->player->jump();
        }

        if ($keyboard->isKeyReleased(\SDLK_SPACE))
        {
            $this->player->jumpRelease();
        }

        if ($keyboard->isKeyPressed(\SDLK_F12))
        {
           $this->sdl->getWindow()->toggleFullscreen();
        }

        if ($keyboard->isKeyPressed(\SDLK_ESCAPE))
        {
            // Exit the game
            $this->inputManager->emit('exitGame', []);
        }

        if ($keyboard->isKeyPressed(\SDLK_F1))
        {
            if ( ($this->debugMode == 0) || ($this->debugMode == 2) )
                $this->debugMode = 1 ;
            else
                $this->debugMode = 0 ;
        }

        if ($keyboard->isKeyPressed(\SDLK_F2))
        {
            if ( ($this->debugMode == 1) || ($this->debugMode == 0) )
                $this->debugMode = 2 ;
            elseif ($this->debugMode == 2)
                $this->debugMode = 3 ;
            elseif ($this->debugMode == 3)
                $this->debugMode = 4 ;
            elseif ($this->debugMode == 4)
                $this->debugMode = 2 ;

        }

        if ($keyboard->isKeyPressed(\SDLK_F5))
        {
            // debug: invincible against everything (enemies, traps, pits, drowning), Sonic with red eyes
            $this->player->setGodLook($this->objectManager->toggleGodMode());
        }

        if ($keyboard->isKeyPressed(\SDLK_F4))
        {
            // next level, with its music
            $this->levelManager->nextLevel();
            $this->warpFrom = null;
            $this->playLevelMusic(true);
        }

        if ($keyboard->isKeyPressed(\SDLK_F3))
        {
            // prev level, with its music
            $this->levelManager->previousLevel();
            $this->warpFrom = null;
            $this->playLevelMusic(true);
        }

        // Free camera on the keypad (2 4 6 8), kept inside the map; 5 toggles "Sonic follows the camera"
        $camera = $this->scene->getCamera();
        $moves = [\SDLK_KP_2 => [0, 10], \SDLK_KP_8 => [0, -10], \SDLK_KP_4 => [-10, 0], \SDLK_KP_6 => [10, 0]];
        foreach ($moves as $key => [$dx, $dy]) {
            if ($keyboard->isKeyHeld($key)) {
                $camera->disableStick = true;
                $this->moveDebugCamera($dx, $dy);
            }
        }

        if ($keyboard->isKeyPressed(\SDLK_KP_5))
        {
            $this->carryPlayer = !$this->carryPlayer;
            // on: the camera stays where it is and carries Sonic; off: it follows Sonic again
            $camera->disableStick = $this->carryPlayer;
            if (!$this->carryPlayer) {
                $this->player->setVelocity(0, 0);
            }
        }

		if ($keyboard->isKeyPressed(\SDLK_KP_PLUS))
		{
			// zoom in: bigger pixels, fewer tiles
			$this->changeZoom(1);
		}

		if ($keyboard->isKeyPressed(\SDLK_KP_MINUS))
		{
			// zoom out: more tiles on screen
			$this->changeZoom(-1);
		}

		if ($keyboard->isKeyPressed(\SDLK_KP_MULTIPLY))
		{
			// the 256x192 screen of the Master System, scaled to fit (on / off)
			$this->smsView = !$this->smsView;
		}
    }

    private function changeZoom(int $step): void
    {
        if ($this->smsView) {
            $this->smsView = false;   // leave the 256x192 screen at the zoom it looks like
            $this->zoomOffset = $this->zoomIndexFor($this->outputHeight() / 192) - $this->zoomIndexFor($this->outputHeight() / self::DEFAULT_VIEW_HEIGHT);
            return;
        }
        $auto = $this->zoomIndexFor($this->outputHeight() / self::DEFAULT_VIEW_HEIGHT);
        $this->zoomOffset = max(-$auto, min(count(self::ZOOMS) - 1 - $auto, $this->zoomOffset + $step));
    }

    private function zoomIndexFor(float $zoom): int
    {
        $best = 0;
        foreach (self::ZOOMS as $i => $z) {
            if (abs($z - $zoom) < abs(self::ZOOMS[$best] - $zoom)) {
                $best = $i;
            }
        }
        return $best;
    }

    private function outputHeight(): int
    {
        $width = $height = 0;
        \SDL_GetRendererOutputSize($this->sdl->getRenderer()->getRenderer(), $width, $height);
        return max(1, $height);
    }

    /**
     * Sizes the game view to the window: same ratio as the window (more tiles on a wide screen, never
     * stretched sprites), or exactly 256x192 in Master System mode (black borders).
     */
    private function updateView(): void
    {
        $width = $height = 0;
        \SDL_GetRendererOutputSize($this->sdl->getRenderer()->getRenderer(), $width, $height);
        if ($width <= 0 || $height <= 0) return;
        if ($this->smsView) {
            [$viewW, $viewH] = [256, 192];
        } else {
            $auto = $this->zoomIndexFor($height / self::DEFAULT_VIEW_HEIGHT);
            $index = max(0, min(count(self::ZOOMS) - 1, $auto + $this->zoomOffset));
            $zoom = self::ZOOMS[$index];
            [$viewW, $viewH] = [(int)round($width / $zoom), (int)round($height / $zoom)];
        }
        $window = $this->sdl->getWindow();
        if ($viewW === $window->getWidth() && $viewH === $window->getHeight()) return;
        $window->setViewSize($viewW, $viewH);
        $this->sdl->getRenderer()->resizeTarget($viewW, $viewH);
        $this->scene->getCamera()->noSmooth = true;   // re-centre at once in the new view
    }

    /** Free debug camera, kept inside the map. */
    private function moveDebugCamera(int $dx, int $dy): void
    {
        $camera = $this->scene->getCamera();
        $level = $this->levelManager->getCurrentLevel();
        $maxX = max(0, $level->getMapWidth() * 32 - $this->sdl->getWindow()->getWidth());
        $maxY = max(0, $level->getMapHeight() * 32 - $this->sdl->getWindow()->getHeight());
        $camera->setXY(max(0, min($maxX, $camera->getX() + $dx)), max(0, min($maxY, $camera->getY() + $dy)));
    }


}
