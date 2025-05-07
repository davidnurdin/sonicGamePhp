<?php

namespace SonicGame\Renderer;

class Window
{
    private int $width;
    private int $height;
    private string $title;

    private $window ;

    public function __construct(int $width, int $height, string $title,$fullscreen = false)
    {
        $this->width = $width;
        $this->height = $height;
        $this->title = $title;

        $flag = \SDL_WINDOW_SHOWN;
        if ($fullscreen) {
            $flag = \SDL_WINDOW_FULLSCREEN;
        }


        $this->window = \SDL_CreateWindow(
            $this->title,
            \SDL_WINDOWPOS_UNDEFINED,
            \SDL_WINDOWPOS_UNDEFINED,
            $this->width,
            $this->height,
            $flag,
        );


    }

    public function getWindow()
    {
        return $this->window;
    }

}

