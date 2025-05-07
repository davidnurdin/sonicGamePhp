<?php

namespace SonicGame\Renderer;

class Window
{
    private int $width;
    private int $height;
    private string $title;

    private $window;
    private bool $isInit = false ;
    private bool $isFullScreen = false ;

    public function __construct(int $width, int $height, string $title,$fullscreen = false)
    {
        $this->isFullScreen = $fullscreen;
        $this->width = $width;
        $this->height = $height;
        $this->title = $title;

        $flag = \SDL_WINDOW_SHOWN;
        if ($fullscreen) {
            $flag = \SDL_WINDOW_FULLSCREEN;
        }

        dump('CREATE WINDOW');

        $this->window = \SDL_CreateWindow(
            $this->title,
            \SDL_WINDOWPOS_UNDEFINED,
            \SDL_WINDOWPOS_UNDEFINED,
            $this->width,
            $this->height,
            $flag,
        );

        $this->isInit = true ;
    }

    public function getWindow()
    {
        return $this->window;
    }

    public function destroy()
    {
        \SDL_DestroyWindow($this->window);
        $this->isInit = false ;
    }

    public function isInitialized()
    {
        return $this->isInit ;
    }

    public function toggleFullscreen()
    {
        $this->isFullScreen = $this->isFullScreen ? false : true ;

        if ($this->isFullScreen)
            \SDL_SetWindowFullscreen($this->window, \SDL_WINDOW_FULLSCREEN);
        else
            \SDL_SetWindowFullscreen($this->window, 0);

    }

}

