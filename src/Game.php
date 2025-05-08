<?php

namespace SonicGame;

use Evenement\EventEmitter;
use React\EventLoop\TimerInterface;
use SonicGame\Entities\Player;
use SonicGame\InputManager\InputKeyboard;
use SonicGame\InputManager\InputManager;
use SonicGame\Level\LevelManager;
use SonicGame\Loop\GameLoop;
use SonicGame\Renderer\Sdl;
use SonicGame\Scene\Level;
use SonicGame\Scene\Scene;

class Game extends EventEmitter
{

    private int $debugMode = 0 ;


    public function __construct(
        private GameLoop $gameLoop,
        private InputManager $inputManager,
        private Sdl $sdl,
        private Player $player,
        private Scene $scene,
        private LevelManager $levelManager,
    )
    {

    }

    private function registerEvents()
    {
        $this->inputManager->on('exitGame', fn() => $this->eventExitGame());
        $this->inputManager->on('keyPress', fn($keyboard, $key) => $this->eventKeyPressed($keyboard, $key));
    }

    public function run(): void
    {
        $vars = [] ;
        // Init SDL
        $this->sdl->initSDL(fullscreen: false, title: 'SonicGame');

        // Init Textures

        for ($i = 1 ; $i < 30 ; $i++)
            $this->sdl->loadTexture('tileset' . $i, 'tileset/levels/tileset' . $i . '.png');

        $this->sdl->loadTexture('sonic', 'tileset/sprites/tileset-sonic.png',['r' => 0 , 'g' => '72' , 'b' => 0]);
        $this->sdl->loadTexture('background', 'background_large.jpg');
        $this->sdl->loadFont('sonic','fonts/NiseSegaSonic.TTF') ;

        $this->levelManager->loadLevels();

        $this->registerEvents();
        $frameDuration = 1 / 60; // 60Hz
        $inputDuration = 1 / 600; // 240Hz

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



        $iterationCamera = 0 ;
        $sens = 0 ;
        // Auto move the camera on X
        $this->gameLoop->addPeriodicTimer(1/100, function (TimerInterface $timer) use (&$iterationCamera,&$sens) {
            $iterationCamera++ ;
            if ($iterationCamera > 200)
            {
                $iterationCamera = 0 ;
                $sens++;
                if ($sens > 3)
                    $sens = 0 ;
            }

            if ($sens == 0 )
                $this->scene->getCamera()->setX($this->scene->getCamera()->getX() + 1);
            elseif ($sens == 1)
                $this->scene->getCamera()->setX($this->scene->getCamera()->getX() - 1);
            elseif ($sens == 2)
                $this->scene->getCamera()->setY($this->scene->getCamera()->getY() + 1);
            elseif ($sens == 3)
                $this->scene->getCamera()->setY($this->scene->getCamera()->getY() - 1);


        });


        $this->gameLoop->addPeriodicTimer(1/1, function (TimerInterface $timer) use (&$vars) {
            // DUmp the key pressed
            $keyPressed = $this->inputManager->getKeyboard()->getCurrentKeysPressed();
            dump($keyPressed);
        });


        // Event LOOP Inputs
        $this->gameLoop->addPeriodicTimer($inputDuration, function (TimerInterface $timer) use (&$vars) {
            $this->inputManager->poll();

            // Force emit keyPress to have key with $inputDuration
            if ($this->inputManager->getKeyboard()->haveOneKeyPressed()) {
                // get the last key pressed
                $keyPressed = $this->inputManager->getKeyboard()->getLastKeyPressed();
                $this->inputManager->emit('keyPress', [$this->inputManager->getKeyboard(), $keyPressed]);
            }

            $this->inputManager->getKeyboard()->resetTransientStates();
        });


        // Event LOOP Display
        $this->gameLoop->addPeriodicTimer($frameDuration, function (TimerInterface $timer) use (&$vars) {
            $now = microtime(true);
            $delta = $now - $vars['lastTime'];
            $vars['lastTime'] = $now;
            ++$vars['fps'];
            $vars['deltaSum'] += $delta;

            // Rendu de la scène
            $this->scene->setDebugMode($this->debugMode);

            $this->sdl->getRenderer()->clear();
            $this->sdl->getRenderer()->createScene($this->scene,$this->player,$this->sdl,$this->sdl->getFont('sonic'),$this->levelManager->getCurrentLevel());
            $this->sdl->getRenderer()->present();

            // Update the player

        });

        $this->gameLoop->start();
        $this->sdl->exitSDL($this->sdl->getWindow()->getWindow(), $this->sdl->getRenderer()->getRenderer());
    }

    // Renders your game objects here
    private function eventExitGame()
    {
        $this->gameLoop->stop();
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
                $this->player->moveUp();
                // Move the player to up
            }
            else {
                $this->player->moveDown();
                // Move the player to down
            }
        }
        else {
            if ($keyboard->isKeyHeld(\SDLK_UP)) {
               $this->player->moveUp();
                // Move the player to up
            }

            if ($keyboard->isKeyHeld(\SDLK_DOWN)) {
                $this->player->moveDown();
                // Move the player to down
            }
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
            // next level
            $this->levelManager->previousLevel();
        }

    }


}
