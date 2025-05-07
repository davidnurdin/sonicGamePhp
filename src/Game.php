<?php

namespace SonicGame;

use Evenement\EventEmitter;
use Input\SonicGame\Input;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use SonicGame\InputManager\InputKeyboard\InputKeyboard;
use SonicGame\InputManager\InputManager;
use SonicGame\Loop\GameLoop;

class Game
{
    public function __construct(
        private ?GameLoop $gameLoop = null,
        private ?InputManager $inputManager = null)
    {
        if (!$this->gameLoop) {
            $this->gameLoop = new GameLoop();
            $this->gameLoop->init();
        }

        if (!$this->inputManager) {
            $this->inputManager = new InputManager();
        }
    }

    public function initSDL()
    {
        // The object window is important there is a bug in SDL Wrapper Php..
        \SDL_Init(\SDL_INIT_VIDEO);
        $window = \SDL_CreateWindow("XXX", \SDL_WINDOWPOS_UNDEFINED, \SDL_WINDOWPOS_UNDEFINED, 500,500, \SDL_WINDOW_SHOWN);
        return $window ;
    }
    public function run(): void
    {
        // Init SDL
        $window = $this->initSDL();

        $this->inputManager->on('eventSdl', static function ($xxx) : void {
            var_dump($xxx);
//                $logger->log(sprintf("User '%s' was created.", $user->getLogin()));
        });


        $frameDuration = 1/60 ; // 60 fps
        $this->gameLoop->addPeriodicTimer($frameDuration, function (TimerInterface $timer) {
            // Logique principale ici
            echo "Sonic is running at " . microtime(true) . PHP_EOL;
            $this->inputManager->poll();

            // update / render
            $this->inputManager->getKeyboard()->resetTransientStates();

        });
        $this->gameLoop->run();
    }
}
