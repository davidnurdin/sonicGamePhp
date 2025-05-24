<?php

namespace SonicGame\InputManager;

class InputKeyboard
{
    private array $held = [];
    private array $pressed = [];
    private array $released = [];

    private $nbKeyPressed = 0 ;
    private $lastKeyPressed = [];

    public function __construct()
    {
    }

    public function handle($event): void
    {
        $type = $event->type;

//        dump(count(  $this->lastKeyPressed));

        if ($type === SDL_KEYDOWN) {
			$key = $event->key->keysym->sym;

			if (!isset($this->held[$key])) {
                $this->pressed[$key] = true;
                // add $key if not exist
                if (!in_array($key, $this->lastKeyPressed)) {
                    $this->lastKeyPressed[] = $key;
                }
                $this->nbKeyPressed = count($this->lastKeyPressed);

            }
            $this->held[$key] = true;
        } elseif ($type === SDL_KEYUP) {
			$key = $event->key->keysym->sym;

			$this->released[$key] = true;
            if (isset($this->held[$key])) {
                unset($this->held[$key]);
                // search $key in $lastKeyPressed and delete it
                $index = array_search($key, $this->lastKeyPressed);
                if ($index !== false) {
                    unset($this->lastKeyPressed[$index]);
                }
                $this->nbKeyPressed = count($this->lastKeyPressed);

            }
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

    public function haveOneKeyPressed()
    {
        return $this->nbKeyPressed > 0 ;
    }

    public function getLastKeyPressed()
    {
        if ($this->nbKeyPressed > 0) {
            $keyPress = end($this->lastKeyPressed);
            return $keyPress;
        }
        return null;
    }

    public function getCurrentKeysPressed()
    {
        $pressed = $this->lastKeyPressed;
        // renum the array
        $pressed = array_values($pressed);
        return $pressed;
    }
}
