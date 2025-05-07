<?php

namespace SonicGame;

use Evenement\EventEmitter;
use React\EventLoop\TimerInterface;
use SonicGame\InputManager\InputKeyboard;
use SonicGame\InputManager\InputManager;
use SonicGame\Loop\GameLoop;

class Game extends EventEmitter
{
    public function __construct(
        private GameLoop $gameLoop,
        private InputManager $inputManager
    )
    {

    }

    public function initSDL()
    {
        // The object window is important there is a bug in SDL Wrapper Php..
        \SDL_Init(\SDL_INIT_VIDEO);
        $window = \SDL_CreateWindow("XXX", \SDL_WINDOWPOS_UNDEFINED, \SDL_WINDOWPOS_UNDEFINED, 500,500, \SDL_WINDOW_SHOWN);
        return $window ;
    }

    public function exitSDL($window)
    {
        \SDL_DestroyWindow($window);
        \SDL_Quit();
    }
    public function run(): void
    {
        // Init SDL
        $window = $this->initSDL();

        $this->registerEvents();
        $frameDuration = 1/60 ; // 60 fps
        $this->gameLoop->addPeriodicTimer($frameDuration, function (TimerInterface $timer) {
//            echo "Sonic is running at " . microtime(true) . PHP_EOL;
            $this->inputManager->poll();
            // update / render
            $this->inputManager->getKeyboard()->resetTransientStates();

        });
        $this->gameLoop->start();
        $this->exitSDL($window);

    }

    private function eventExitGame()
    {
        $this->gameLoop->stop();
    }
    private function eventKeyPressed(InputKeyboard $keyboard, int $keyPressed)
    {
        dump('KeyPress : ' . $keyPressed);

        // escape
        if ($keyPressed == \SDLK_ESCAPE)
        {
            // Exit the game
            $this->inputManager->emit('exitGame', []);
        }

        if ($keyboard->isKeyHeld(\SDLK_RIGHT))
        {
            // Move the player to right
            echo "Right key pressed !" ;
        }

        if ($keyboard->isKeyHeld(\SDLK_LEFT))
        {
            // Move the player to right
            echo "Left key pressed !" ;
        }

    }

    private function registerEvents()
    {
        $this->inputManager->on('exitGame', fn() => $this->eventExitGame());
        $this->inputManager->on('keyPress', fn($keyboard, $key) => $this->eventKeyPressed($keyboard, $key));

    }
}
