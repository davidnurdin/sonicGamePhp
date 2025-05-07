<?php

namespace SonicGame\InputManager;

class InputKeyboard
{

    public function __construct()
    {
    }

    private array $held = [];
    private array $pressed = [];
    private array $released = [];

    public function handle($event): void
    {
        $key = $event->key->keysym->sym;

//        if ($event->type === \SDL_EVENT_KEYDOWN && !$event->key->repeat) {
//            $this->pressed[$key] = true;
//            $this->held[$key] = true;
//        } elseif ($event->type === \SDL_EVENT_KEYUP) {
//            $this->released[$key] = true;
//            unset($this->held[$key]);
//        }
    }

    public function isKeyPressed($key): bool
    {
        return $this->pressed[$key] ?? false;
    }

    public function isKeyHeld($key): bool
    {
        return $this->held[$key] ?? false;
    }

    public function isKeyReleased($key): bool
    {
        return $this->released[$key] ?? false;
    }

    public function resetTransientStates(): void
    {
        $this->pressed = [];
        $this->released = [];
    }

}
