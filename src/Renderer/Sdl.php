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

    /**
     * Textures registered without being loaded (the blocks of the 35 levels: ~200 MB once decoded): loaded on
     * first use or with loadGroup(), freed with releaseGroupsExcept(). name => [path, transparent colour, group]
     */
    private array $lazy = [];
    /** Lazy textures loaded, by file (several levels share the same blocks): key => textures */
    private array $byPath = [];

    public function registerTexture(string $name, string $path, string $group, $transparentColor = null): void
    {
        $this->lazy[$name] = [$path, $transparentColor, $group];
    }

    /** Loads every texture of $group not loaded yet. */
    public function loadGroup(string $group): void
    {
        foreach ($this->lazy as $name => [, , $g]) {
            if ($g === $group && !isset($this->textures[$name])) $this->loadLazy($name);
        }
    }

    /** Frees the lazy textures of the other groups (their files, unless $group uses them too). */
    public function releaseGroupsExcept(string $group): void
    {
        $keep = [];
        foreach ($this->lazy as [$path, $key, $g]) {
            if ($g === $group) $keep[self::pathKey($path, $key)] = true;
        }
        foreach ($this->lazy as $name => [$path, $key, $g]) {
            if ($g === $group || !isset($this->textures[$name])) continue;
            unset($this->textures[$name]);
            $pathKey = self::pathKey($path, $key);
            if (isset($keep[$pathKey]) || !isset($this->byPath[$pathKey])) continue;
            foreach ($this->byPath[$pathKey] as $texture) \SDL_DestroyTexture($texture['texture']);
            unset($this->byPath[$pathKey]);
        }
    }

    /** Forgets a lazy texture and frees its file, with the other names of that file (registered again later). */
    public function releaseTexture(string $name): void
    {
        if (!isset($this->lazy[$name])) return;
        [$path, $key] = $this->lazy[$name];
        $pathKey = self::pathKey($path, $key);
        foreach ($this->lazy as $other => [$otherPath, $otherKey]) {
            if (self::pathKey($otherPath, $otherKey) === $pathKey) unset($this->textures[$other], $this->lazy[$other]);
        }
        foreach ($this->byPath[$pathKey] ?? [] as $texture) \SDL_DestroyTexture($texture['texture']);
        unset($this->byPath[$pathKey]);
    }

    /** Sizes of the parts of a texture ([['width' => , 'height' => ], ...]) without loading it. */
    public function getTextureSizes(string $name): array
    {
        if (isset($this->textures[$name]) || !isset($this->lazy[$name])) return $this->getTextures($name);
        return $this->sdlImage->imageSizes($this->lazy[$name][0]);
    }

    private function loadLazy(string $name): array
    {
        [$path, $key] = $this->lazy[$name];
        $pathKey = self::pathKey($path, $key);
        if (!isset($this->byPath[$pathKey])) {
            $texture = $this->sdlImage->loadImage($path, $this->renderer->getRenderer(), $key);
            if ($texture === null) {
                throw new \RuntimeException('Failed to create texture: ' . \SDL_GetError());
            }
            $this->byPath[$pathKey] = $texture;
        }
        return $this->textures[$name] = $this->byPath[$pathKey];
    }

    private static function pathKey(string $path, $key): string
    {
        return $path . ($key === null ? '' : '|' . implode(',', $key));
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
        return isset($this->textures[$name]) || isset($this->lazy[$name]);
    }

    public function getTextures($name)
    {
        if ($this->textureVariant !== '' && $this->hasTexture($name . $this->textureVariant)) {
            $name .= $this->textureVariant;
        }
        return $this->textures[$name] ?? $this->loadLazy($name);
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
