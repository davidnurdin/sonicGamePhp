<?php

namespace SonicGame;

use Evenement\EventEmitter;
use React\EventLoop\TimerInterface;
use SonicGame\Entities\Player;
use SonicGame\InputManager\InputKeyboard;
use SonicGame\InputManager\InputManager;
use SonicGame\InputManager\InputTouchpad;
use SonicGame\Level\LevelManager;
use SonicGame\Loop\GameLoop;
use SonicGame\Renderer\Sdl;
use SonicGame\Scene\Scene;
use SonicGame\SoundManager\Sound;
use SonicGame\SoundManager\SoundManager;

class Game extends EventEmitter
{

    private int $debugMode = 0 ;

	public bool $disableSdl = false ;


    public function __construct(
        public GameLoop $gameLoop,
        private InputManager $inputManager,
        public Sdl $sdl,
        private Player $player,
        private Scene $scene,
        private LevelManager $levelManager,
        private SoundManager $soundManager,
    )
    {
        $scene->setPlayer($this->player);
    }

    private function registerEvents()
    {
        $this->inputManager->on('exitGame', fn() => $this->eventExitGame());
        $this->inputManager->on('keyPress', fn($keyboard, $key) => $this->eventKeyPressed($keyboard, $key));
        $this->inputManager->on('touchPressed', fn($touchpad, $action) => $this->eventTouchPressed($touchpad, $action));
        $this->levelManager->on('levelChanged', fn($level) => $this->levelReload($level));

    }

    private function levelReload(int $level)
    {
        $this->scene->resetLevel();
        dump('Loaded level : ' . $level);
    }


    public function run(): void
    {
        $vars = [] ;
        // Init SDL
        // TODO : voir vsync ce qu'on fait.
        $this->sdl->initSDL(fullscreen: false, title: 'SonicGame',width:240,height:226,vsync: true);

        $this->soundManager->Init();
        $sound = new Sound(
            __DIR__ . '/../assets/mixer/music/level1.mp3'
        ) ;
        $sound->play();

        // Init Textures

		$this->player->initTexture($this->sdl);
        $this->sdl->loadFont('sonic','fonts/NiseSegaSonic.TTF') ;

        $this->levelManager->loadLevels();
        $this->registerEvents();
        $frameDuration = 0 ; // 1 / 60; // 60Hz
        $inputDuration = 0 ; // 1 / 600; // 240Hz

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


            if ($this->inputManager->getKeyboard()->haveOneKeyPressed() === false)
            {
                if ($this->inputManager->getTouchpad()->isOneActionHelded() === false)
                {
                    $this->player->idle();
				}
            }

            $this->inputManager->getKeyboard()->resetTransientStates();
            $this->inputManager->getTouchpad()->resetTransientStates();

        };
        $closureDisplay = function() use (&$vars)
        {


			// SDL_GetTicks
			// sdl delay
			// TODO : calculé sur WASM apparament le VSYNC ne fonctionne pas .. on veut avoir 60fps environ
			// \SDL_Delay(12); // 1ms delay to avoid 100% CPU usage


            $now = microtime(true);
            $delta = $now - $vars['lastTime'];
            $vars['lastTime'] = $now;
            ++$vars['fps'];
            $vars['deltaSum'] += $delta;

			if (!$this->disableSdl) {

            // Rendu de la scène
            $this->scene->setDebugMode($this->debugMode);

            $this->sdl->getRenderer()->clear();
            $this->sdl->getRenderer()->createScene(
                $this->scene,
                $this->player,
                $this->sdl->getFont('sonic'),
                $this->levelManager->getCurrentLevel()
            );


//            \SDL_SetRenderTarget($this->sdl->getRenderer()->getRenderer(), null);

//            \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(),$this->sdl->getRenderer()->getRenderTexture(), null, null);
//            $this->sdl->getRenderer()->clear();
//            \SDL_RenderCopyEx($this->sdl->getRenderer()->getRenderer(), $this->sdl->getRenderer()->getRenderTexture(), null, null, 0, null, \SDL_FLIP_NONE);
            $this->sdl->getRenderer()->present();


            $screenRect = new \SDL_Rect;
            $screenRect->x = 0;
            $screenRect->y = 0;
            $screenRect->w = $this->sdl->getWindow()->getWidth();
            $screenRect->h = $this->sdl->getWindow()->getHeight();

            /*
             *
             *     $sdl->SDL_GetRendererOutputSize($renderer, FFI::addr($actualW), FFI::addr($actualH));

    $scaleX = $actualW->cdata / $winW;
    $scaleY = $actualH->cdata / $winH;
    $scale = min($scaleX, $scaleY);
    $outputW = (int)($winW * $scale);
    $outputH = (int)($winH * $scale);
    $offsetX = (int)(($actualW->cdata - $outputW) / 2);
    $offsetY = (int)(($actualH->cdata - $outputH) / 2);

    $screenRect = $sdl->new('SDL_Rect');
    $screenRect->x = $offsetX;
    $screenRect->y = $offsetY;
    $screenRect->w = $outputW;
    $screenRect->h = $outputH;

             */
//            \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->sdl->getRenderer()->getRenderTexture(), null, $screenRect); // DOUBLE BUFFERING
//            $this->sdl->getRenderer()->present();

            // Update the player

			}
		} ;

        $closureApplyPhysic = function() use (&$vars)
        {
            // Update the player
            $now = microtime(true);
            $delta = $now - $vars['lastTime'];

			$this->player->update($delta);
//			$this->scene->getCamera()->noSmooth = false; // bug sur wasm
			$this->scene->getCamera()->update($delta);



        };

        $this->gameLoop->addPeriodicTimer(0, function (TimerInterface $timer) use ($closureDisplay,$closureApplyPhysic, $closureInputs) {
            $closureDisplay();

        });

        $this->gameLoop->addPeriodicTimer(0, function (TimerInterface $timer) use ($closureDisplay,$closureApplyPhysic, $closureInputs) {
			$closureInputs();
			$closureApplyPhysic();
          //  $closureApplyPhysic();
//			$closureDisplay();
//			$closureInputs();
//			$closureApplyPhysic();
        });


        $this->gameLoop->addPeriodicTimer($inputDuration, function (TimerInterface $timer) use ($closureInputs) {
		//	$closureInputs();
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
//        dump('KeyPress : ' . $keyPressed);

        // we can have multiple key pressed
        if ($keyboard->isKeyHeld(\SDLK_RIGHT) && $keyboard->isKeyHeld(\SDLK_LEFT))
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
                $this->player->watchDown();
                // Move the player to down
            }
        }

        if ($keyboard->isKeyPressed(\SDLK_SPACE))
        {
            $this->player->jump();
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
            // Take a screenshot

            if ($this->debugMode == 0)
                $this->debugMode = 1 ;
            else
                $this->debugMode = 0 ;
        }

        if ($keyboard->isKeyPressed(\SDLK_F2))
        {
            // Take a screenshot
            if ($this->debugMode == 1)
                $this->debugMode = 2 ;
            else
                $this->debugMode = 1 ;
        }

        if ($keyboard->isKeyPressed(\SDLK_F4))
        {

            // next level
            $this->levelManager->nextLevel();
        }

        if ($keyboard->isKeyPressed(\SDLK_F3))
        {
            // prev level
            $this->levelManager->previousLevel();
        }

        // key 2 numeric pad
        if ($keyboard->isKeyHeld(\SDLK_KP_2))
        {
            $this->scene->getCamera()->disableStick = true ;
            $this->scene->getCamera()->setY($this->scene->getCamera()->getY() + 10);
        }

        if ($keyboard->isKeyHeld(\SDLK_KP_8))
        {
            $this->scene->getCamera()->disableStick = true ;
            $this->scene->getCamera()->setY($this->scene->getCamera()->getY() - 10);
        }

        // key 2 numeric pad
        if ($keyboard->isKeyHeld(\SDLK_KP_4))
        {
            $this->scene->getCamera()->disableStick = true ;
            $this->scene->getCamera()->setX($this->scene->getCamera()->getX() - 10);
        }

        // key 2 numeric pad
        if ($keyboard->isKeyHeld(\SDLK_KP_6))
        {
            $this->scene->getCamera()->disableStick = true ;
            $this->scene->getCamera()->setX($this->scene->getCamera()->getX() +  10);
        }

        if ($keyboard->isKeyHeld(\SDLK_KP_5))
        {
            $this->scene->getCamera()->disableStick = false ;
        }

    }


}
