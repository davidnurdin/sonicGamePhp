<?php

namespace SonicGame\InputManager;

use AllowDynamicProperties;
use Evenement\EventEmitter;

class InputManager extends EventEmitter
{

    public function __construct(private InputKeyboard $inputKeyboard,private InputTouchpad $inputTouchpad)
    {
    }


    public function poll()
    {
        $event = new \SDL_Event;
        while (SDL_PollEvent($event)) {

            if ($event->type == \SDL_QUIT) {
                $this->emit('exitGame', []);
            }

            switch ($event->type) {
				case SDL_FINGERDOWN:
				case SDL_FINGERUP:
				case SDL_FINGERMOTION:
					$this->inputTouchpad->handle($event);
                case \SDL_KEYDOWN:
                case \SDL_KEYUP:
                    $this->inputKeyboard->handle($event);
                   // $this->emit('keyPress', [$this->getKeyboard(), $event->key->keysym->sym]);
                    break;
            }

        }
    }

    public function getKeyboard()
    {
        return $this->inputKeyboard;
    }

	public function getTouchpad()
	{
		return $this->inputTouchpad;
	}
}
