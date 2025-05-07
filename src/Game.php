<?php

namespace SonicGame;

use Evenement\EventEmitter;
use React\EventLoop\TimerInterface;
use SonicGame\InputManager\InputKeyboard;
use SonicGame\InputManager\InputManager;
use SonicGame\Loop\GameLoop;
use SonicGame\Renderer\Sdl;

class Game extends EventEmitter
{

    public function __construct(
        private GameLoop $gameLoop,
        private InputManager $inputManager,
        private Sdl $sdl,
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
        $this->sdl->initSDL();
        $this->registerEvents();
        $frameDuration = 1 / 60; // wanted FPS : 60 fps

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

        $this->gameLoop->addPeriodicTimer($frameDuration, function (TimerInterface $timer) use (&$vars) {
            $now = microtime(true);
            $delta = $now - $vars['lastTime'];
            $vars['lastTime'] = $now;
            ++$vars['fps'];
            $vars['deltaSum'] += $delta;

            // poll events
            $this->inputManager->poll();

            // Rendu de la scène
            $this->sdl->getRenderer()->setColor(rand(1,255), 0, 0, 255);
            $this->sdl->getRenderer()->clear();
            $this->sdl->getRenderer()->createScene();
            $this->sdl->getRenderer()->present();

            // reset transient states
            $this->inputManager->getKeyboard()->resetTransientStates();
        });

        $this->gameLoop->start();
        $this->sdl->exitSDL($this->sdl->getWindow()->getWindow(), $this->sdl->getRenderer()->getRenderer());
    }

    // Renders your game objects here
    private function eventExitGame()
    {
        $this->gameLoop->stop();
    }
    private function eventKeyPressed(InputKeyboard $keyboard, int $keyPressed)
    {
//        dump('KeyPress : ' . $keyPressed);

        // escape
        if ($keyboard->isKeyPressed(\SDLK_ESCAPE))
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
            // Move the player to left
            echo "Left key pressed !" ;
        }

        if ($keyboard->isKeyPressed(\SDLK_F12))
        {
           $this->window->toggleFullscreen();
        }

    }


}
