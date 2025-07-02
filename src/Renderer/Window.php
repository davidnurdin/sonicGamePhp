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

    // Instance unique de la classe (privée et statique)
    private static ?Window $instance = null;


    public static function getInstance(): Window
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct(int $width = 0, int $height = 0 , string $title = "",$fullscreen = false)
    {

        $this->isFullScreen = $fullscreen;
        $this->width = $width;
        $this->height = $height;
        $this->title = $title;
        self::$instance = $this;

        $flag = \SDL_WINDOW_SHOWN;
        if ($fullscreen) {
            $flag = \SDL_WINDOW_FULLSCREEN;
        }

		//TODO : voir pk marche pas en web
		$flag |= SDL_WINDOW_RESIZABLE ;

        $this->window = \SDL_CreateWindow(
            $this->title,
            -1000 , //\SDL_WINDOWPOS_UNDEFINED,
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

    public function getWidth()
    {
        return $this->width;
    }

    public function getHeight()
    {
        return $this->height;
    }

//	public function setWidth(int $int)
//	{
//		$this->width = $int;
//		//\SDL_SetWindowSize($this->window, $this->width, $this->height);
//	}
//
//	public function setHeight(int $int)
//	{
//		$this->height = $int;
//		//\SDL_SetWindowSize($this->window, $this->width, $this->height);
//	}

}

