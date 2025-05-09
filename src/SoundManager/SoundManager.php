<?php

namespace SonicGame\SoundManager;

class SoundManager
{
    private array $sounds = [];
    private array $music = [];
    private array $soundEffects = [];

    public function __construct(private string $assetFolder)
    {
        $this->loadMusic();
        $this->loadSoundEffects();
    }

    private function loadSounds(): void
    {
        // Load sound files from the asset folder
        $this->sounds = [
            'jump' => $this->assetFolder . '/sounds/jump.wav',
            'coin' => $this->assetFolder . '/sounds/coin.wav',
            // Add more sounds as needed
        ];
    }

    private function loadMusic(): void
    {
        // Load music files from the asset folder
        $this->music = [
            'level1' => $this->assetFolder . '/music/level1.mp3',
            'level2' => $this->assetFolder . '/music/level2.mp3',
            // Add more music tracks as needed
        ];
    }


}
