<?php

namespace SonicGame\SoundManager;

class SoundManager
{
    private array $sounds = [];
    private array $music = [];
    private array $soundEffects = [];

    public function Init()
    {
        \Mix_Init(0x00000008 | 0x00000010 | 0x00000020 ); // MP3 | wav | OGG

        if (\Mix_OpenAudio(44100, \MIX_DEFAULT_FORMAT, 2, 2048) < 0) {
            echo "Erreur Mix_OpenAudio: " . Mix_GetError() . PHP_EOL;
            exit(1);
        }

        // Load sounds and music
//        $this->loadSounds();
//        $this->loadMusic();
    }
    public function __construct()
    {
//        $this->loadMusic();
//        $this->loadSoundEffects();
    }

    public function __destruct()
    {
        // TODO: Implement __destruct() method.
//        Mix_FreeMusic($music);
        Mix_CloseAudio();
        Mix_Quit();
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
