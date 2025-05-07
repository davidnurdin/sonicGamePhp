<?php

namespace SonicGame\InputManager;

use Evenement\EventEmitter;

class InputManager extends EventEmitter
{

    public function __construct(private InputKeyboard $inputKeyboard)
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
}
