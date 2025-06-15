<?php

namespace SonicGame;

use Evenement\EventEmitter;
use React\EventLoop\TimerInterface;
use SonicGame\Entities\Physics\CollisionSystem;
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
		private CollisionSystem $collisionSystem
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
        // $this->sdl->initSDL(fullscreen: false, title: 'SonicGame',width:240,height:226,vsync: true);
		$this->sdl->initSDL(fullscreen: false, title: 'SonicGame',width:840,height:426,vsync: true);

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

			\SDL_SetRenderTarget($this->sdl->getRenderer()->getRenderer(), $this->sdl->getRenderer()->getRenderTexture()); // draw to texture

            // Rendu de la scène
            $this->scene->setDebugMode($this->debugMode);
			$this->sdl->getRenderer()->clear(); // 1
            $this->sdl->getRenderer()->createScene(
                $this->scene,
                $this->player,
                $this->sdl->getFont('sonic'),
                $this->levelManager->getCurrentLevel()
            );

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

			$closureInputs(); // GET Inputs

			$this->player->update($deltaTime); // Update Player

			// NOUVEAU : Vérification des collisions après la mise à jour du joueur
			if ($this->scene->getCurrentLevel()) {
				$this->collisionSystem->checkCollisions($this->player, $this->scene->getCurrentLevel());
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

			//$this->scene->getCamera()->noSmooth = false;
            $this->scene->getCamera()->disableStick = false ;
        }

		if ($keyboard->isKeyPressed(\SDLK_KP_PLUS))
		{
			// zoom IN
			dd('ok');

		}

		if ($keyboard->isKeyPressed(\SDLK_KP_MINUS))
		{
			// zoom OUT
			dd('ok');
		}
    }


}
