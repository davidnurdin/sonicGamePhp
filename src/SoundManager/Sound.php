<?php

namespace SonicGame\SoundManager;

class Sound
{
    private string $path;
    private int $volume;
    private bool $loop;

    public function __construct(string $path, int $volume = 100, bool $loop = false)
    {
        $this->path = $path;
        $this->volume = $volume;
        $this->loop = $loop;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getVolume(): int
    {
        return $this->volume;
    }

    public function isLoop(): bool
    {
        return $this->loop;
    }

    // play pause stop rewind
    public function play(): void
    {
        // Implement play logic here
        // For example, using SDL_mixer to play the sound
        // \Mix_PlayChannel(-1, $this->path, $this->loop ? -1 : 0);
    }

    public function pause(): void
    {
        // Implement pause logic here
        // For example, using SDL_mixer to pause the sound
        // \Mix_Pause(-1);
    }
    public function stop(): void
    {
        // Implement stop logic here
        // For example, using SDL_mixer to stop the sound
        // \Mix_HaltChannel(-1);
    }
    public function rewind(): void
    {
        // Implement rewind logic here
        // For example, using SDL_mixer to rewind the sound
        // \Mix_Rewind(-1);
    }
    public function setVolume(int $volume): void
    {
        $this->volume = $volume;
        // Implement volume change logic here
        // For example, using SDL_mixer to set the volume
        // \Mix_Volume(-1, $this->volume);
    }
    public function setLoop(bool $loop): void
    {
        $this->loop = $loop;
        // Implement loop change logic here
        // For example, using SDL_mixer to set the loop
        // \Mix_SetLoop(-1, $this->loop ? -1 : 0);
    }
}
