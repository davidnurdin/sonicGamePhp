<?php

namespace SonicGame\Renderer;

class Sdl
{

    private ?Window $window = null;
    private $textures = [];
    private $fonts = [];
    private string $textureVariant = '';
    public function __construct(
        public Renderer $renderer,
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

    public function hasTexture(string $name): bool
    {
        return isset($this->textures[$name]);
    }

    public function getTextures($name)
    {
        if ($this->textureVariant !== '' && isset($this->textures[$name . $this->textureVariant])) {
            return $this->textures[$name . $this->textureVariant];
        }
        return $this->textures[$name];
    }

    /** Suffix tried first by getTextures() (e.g. "-water": the underwater palette below the water line). */
    public function setTextureVariant(string $variant): void
    {
        $this->textureVariant = $variant;
    }

    /** Loads "{name}-water" from "{path without .png}-water.png" when that file exists. */
    public function loadTextureVariant(string $name, string $path, string $variant, $transparentColor = null): void
    {
        $variantPath = substr($path, 0, -4) . $variant . '.png';
        if (file_exists(dirname(__DIR__, 2) . '/assets/' . $variantPath)) {
            $this->loadTexture($name . $variant, $variantPath, $transparentColor);
        }
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
