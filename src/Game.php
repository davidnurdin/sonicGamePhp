<?php

namespace SonicGame;

use Evenement\EventEmitter;
use React\EventLoop\TimerInterface;
use SonicGame\InputManager\InputKeyboard;
use SonicGame\InputManager\InputManager;
use SonicGame\Loop\GameLoop;
use SonicGame\Renderer\Renderer;
use SonicGame\Renderer\Window;

class Game extends EventEmitter
{
    private Window $window ;
    public function __construct(
        private GameLoop $gameLoop,
        private InputManager $inputManager,
        private Renderer $renderer
    )
    {

    }

    private function registerEvents()
    {
        $this->inputManager->on('exitGame', fn() => $this->eventExitGame());
        $this->inputManager->on('keyPress', fn($keyboard, $key) => $this->eventKeyPressed($keyboard, $key));
    }

    public function initSDL()
    {
        \SDL_Init(\SDL_INIT_VIDEO);

        $this->window = (new Window(800, 600, 'Sonic Game',fullscreen:false)) ;

        // Création de la fenêtre SDL
        $window = $this->window->getWindow() ;
        // Création du renderer SDL associé à la fenêtre
        $renderer = $this->renderer->createRenderer($window);

        return [$window, $renderer];  // Retourne la fenêtre et le renderer
    }

    public function exitSDL($window, $renderer)
    {
        // Détuire le renderer et la fenêtre avant de quitter SDL
        \SDL_DestroyRenderer($renderer);
        \SDL_DestroyWindow($window);
        \SDL_Quit();
    }


    public function run(): void
    {
        $vars = [] ;
        // Init SDL
        [$window, $renderer] = $this->initSDL();

        $vars['renderer'] = $renderer;
        $vars['window'] = $window;

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
            $this->renderer->setColor(rand(1,255), 0, 0, 255);
            $this->renderer->clear();
            $this->renderer->createScene();
            $this->renderer->present();

            // reset transient states
            $this->inputManager->getKeyboard()->resetTransientStates();
        });

        $this->gameLoop->start();
        $this->exitSDL($vars['window'], $vars['renderer']);
    }

    // Renders your game objects here
    private function render($window)
    {
        // Example of a basic rendering process
        // Clear the screen (usually with a color or background)
        \SDL_SetRenderDrawColor($window, 0, 0, 0, 255); // Black color
        \SDL_RenderClear($window); // Clears the window with the set color

        // Example: draw something (e.g., a rectangle)
        \SDL_SetRenderDrawColor($window, 255, 0, 0, 255); // Red color
        // Correct creation of SDL_Rect
        $rect = new \SDL_Rect();
        $rect->x = 100;
        $rect->y = 100;
        $rect->w = 50;
        $rect->h = 50;

// Draw the rectangle
        \SDL_RenderFillRect($window, $rect);


        // Finally, present the new frame to the screen
        \SDL_RenderPresent($window);
    }


    private function eventExitGame()
    {
        $this->gameLoop->stop();
    }
    private function eventKeyPressed(InputKeyboard $keyboard, int $keyPressed)
    {
//        dump('KeyPress : ' . $keyPressed);

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

}
