<?php

namespace SonicGame\InputManager;

use Evenement\EventEmitter;

class InputManager extends EventEmitter
{

    public function __construct(private ?InputKeyboard $inputKeyboard = null)
    {
        // Initialize SDL

        if (!$this->inputKeyboard) {
            $this->inputKeyboard = new InputKeyboard();
        }
    }

    public function poll()
    {
//        echo "Poll Event input ... and send event" . PHP_EOL;
        $event = new \SDL_Event;
        while (SDL_PollEvent($event)) {
            $this->emit('eventSdl', [$event]);
        }



//        while (\SDL::PollEvent($event)) {
//            switch ($event->type) {
//                case \SDL_EVENT_KEYDOWN:
//                case \SDL_EVENT_KEYUP:
//                    $this->keyboard->handle($event);
//                    break;
//
//                case \SDL_EVENT_MOUSEBUTTONDOWN:
//                case \SDL_EVENT_MOUSEBUTTONUP:
//                case \SDL_EVENT_MOUSEMOTION:
//                    $this->mouse->handle($event);
//                    break;
//            }
//        }


    }

    public function getKeyboard()
    {
        return $this->inputKeyboard;
    }
}
