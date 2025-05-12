<?php

namespace SonicGame\Renderer;

use SonicGame\Entities\Player;
use SonicGame\Level\Level;
use SonicGame\Scene\Scene;

class Renderer
{

    private $renderer ;
    private $renderTexture ;


    public function createRenderer(Window $window)
    {
//        $this->renderer = \SDL_CreateRenderer($window, -1, \SDL_RENDERER_ACCELERATED);
        $this->renderer = \SDL_CreateRenderer($window->getWindow(), -1, \SDL_RENDERER_ACCELERATED | \SDL_RENDERER_PRESENTVSYNC);
        $this->renderTexture = \SDL_CreateTexture($this->renderer, \SDL_PIXELFORMAT_RGBA8888, \SDL_TEXTUREACCESS_TARGET, $window->getWidth(), $window->getHeight());

        return $this->renderer;
    }

    public function getRenderTexture()
    {
        return $this->renderTexture;
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

    public function createScene(Scene $scene,Player $player,$fontTab,Level $level)
    {
        $scene->drawScene($fontTab,$level);
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
