<?php

namespace SonicGame\Renderer;

class Sdl
{

    private ?Window $window = null;

    public function __construct(private Renderer $renderer)
    {

    }

    public function initSDL($fullscreen = false ,$title='xxx')
    {
        \SDL_Init(\SDL_INIT_VIDEO);
        $this->createSdlObjects($fullscreen,$title);
        return [$this->window->getWindow(), $this->renderer->getRenderer()];  // Retourne la fenêtre et le renderer
    }

    public function exitSDL($window, $renderer)
    {

        $this->destroySdlObject();
        \SDL_Quit();
    }

    public function getWindow()
    {
        return $this->window;
    }

    public function getRenderer()
    {
        return $this->renderer;
    }

    private function createWindow($fullscreen = false,$title)
    {
        if ($this?->window?->isInitialized())
            $this->destroySdlObject();

        $this->window = (new Window(800, 600, $title,fullscreen:$fullscreen)) ;
    }

    private function destroySdlObject()
    {
        // Détuire le renderer et la fenêtre avant de quitter SDL
        $this->renderer->destroy();
        $this->window->destroy();
    }

    private function createSdlObjects($fullscreen = false,$title)
    {
        $this->createWindow($fullscreen,$title);
        // Création de la fenêtre SDL
        $window = $this->window->getWindow() ;
        // Création du renderer SDL associé à la fenêtre
        $renderer = $this->renderer->createRenderer($window);

        return [$window, $renderer];
    }


}
