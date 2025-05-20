<?php

namespace SonicGame\Renderer;

class Sdl
{

    private ?Window $window = null;
    private $textures = [];
    private $fonts = [];
    public function __construct(
        private Renderer $renderer,
        private SdlImage $sdlImage,
        private SdlTtf $sdlFont,
    )
    {

    }

    public function initSDL($fullscreen = false ,$title='xxx',$width = 800,$height = 600,$vsync = true)
    {
        \SDL_Init(\SDL_INIT_VIDEO);
        \SDL_SetHint("SDL_RENDER_SCALE_QUALITY", "0");
        $this->createSdlObjects($title,$fullscreen,$width,$height,$vsync);

        // TODO : voir
        return [$this->window->getWindow(), $this->renderer->getRenderer()];  // Retourne la fenêtre et le renderer
    }

    public function loadTexture($name,$path,$transparentColor = null)
    {
        $texture = $this->sdlImage->loadImage($path,$this->renderer->getRenderer(),$transparentColor);
        if ($texture === null) {
            throw new \RuntimeException('Failed to create texture: ' . \SDL_GetError());
        }
        $this->textures[$name] = $texture;
    }

    public function getTextures($name)
    {
        return $this->textures[$name];
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

    private function createWindow($title,$fullscreen = false,$width = 800,$height = 600)
    {
        if ($this?->window?->isInitialized())
            $this->destroySdlObject();

        $this->window = (new Window($width, $height, $title,fullscreen:$fullscreen)) ;
    }

    private function destroySdlObject()
    {
        // Détuire le renderer et la fenêtre avant de quitter SDL
        $this->renderer->destroy();
        $this->window->destroy();
    }

    private function createSdlObjects($title,$fullscreen = false,$width = 800,$height = 600,$vsync = true)
    {
        $this->createWindow($title,$fullscreen,$width,$height);
        // Création de la fenêtre SDL
        $window = $this->window ;
        // Création du renderer SDL associé à la fenêtre
        $renderer = $this->renderer->createRenderer($window,$vsync);

        return [$window, $renderer];
    }

    public function loadFont($name,$path)
    {
        $font = $this->sdlFont->loadFont($path,$this->renderer->getRenderer());
        $this->fonts[$name] = $font ;
    }

    public function getFont($name)
    {
        return $this->fonts[$name];
    }


}
