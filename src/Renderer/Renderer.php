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

    public function createScene(Scene $scene,Player $player,Sdl $sdl,$fontTab)
    {
        $scene->drawScene($sdl,$player,$fontTab);
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
