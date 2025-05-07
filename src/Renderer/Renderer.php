<?php

namespace SonicGame\Renderer;

use SonicGame\Entities\Player;
use SonicGame\Scene\Scene;

class Renderer
{

    private $renderer ;

    public function createRenderer($window)
    {
        $this->renderer = \SDL_CreateRenderer($window, -1, \SDL_RENDERER_ACCELERATED);
        return $this->renderer;
    }

    public function clear()
    {
        return \SDL_RenderClear($this->renderer); // Effacer l'écran
    }

    public function present()
    {
        return \SDL_RenderPresent($this->renderer);
    }

    public function setColor(int $int, int $int1, int $int2, int $int3)
    {
        \SDL_SetRenderDrawColor($this->renderer, $int, $int1, $int2, $int3);
    }

    public function createScene(Scene $scene,Player $player,Sdl $sdl)
    {
//        $sdl->getRenderer()->setColor(255, 0, 0, 255);
//        $sdl->getRenderer()->clear();

//        // Créer un rectangle
//        $rect = new \SDL_Rect();
//        $rect->x = 100;
//        $rect->y = 100;
//        $rect->w = 50;
//        $rect->h = 50;
//
//        // Dessiner le rectangle
//        \SDL_RenderFillRect($this->renderer, $rect);

//        dump($scene->getCamera()->getX());
        // Draw the player
        $scene->drawBackground($sdl);
        $scene->drawPlayer($player,$sdl);


    }

    public function destroy()
    {
        \SDL_DestroyRenderer($this->renderer);
    }

    public function getRenderer()
    {
        return $this->renderer;
    }
}
