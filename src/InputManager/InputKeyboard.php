<?php

namespace SonicGame\InputManager;

class InputKeyboard
{
    private array $held = [];
    private array $pressed = [];
    private array $released = [];

    public function __construct()
    {
    }

    public function handle($event): void
    {
        $type = $event->type;
        $key = $event->key->keysym->sym;

        if ($type === SDL_KEYDOWN) {
            if (!isset($this->held[$key])) {
                $this->pressed[$key] = true;
            }
            $this->held[$key] = true;
        } elseif ($type === SDL_KEYUP) {
            $this->released[$key] = true;
            unset($this->held[$key]);
        }
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
