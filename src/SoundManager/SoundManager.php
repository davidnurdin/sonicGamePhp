<?php

namespace SonicGame\SoundManager;

/**
 * Music and sound effects through SDL_mixer.
 *
 * Every file in assets/mixer/music and assets/mixer/sfx is rendered from the ROM by tools/soundrip.py:
 * the original sound driver runs on a Z80 emulator and its PSG writes go through an SN76489 emulator.
 * A looping song is split into "{name}-intro.ogg" (played once) and "{name}.ogg" (looped forever),
 * at the exact point where the driver state repeats.
 */
class SoundManager
{
    // Music indexes of the original driver (S1_MusicPointers) -> files
    public const MUSIC_BY_INDEX = [
        0x00 => 'greenhill', 0x01 => 'bridge', 0x02 => 'jungle', 0x03 => 'labyrinth', 0x04 => 'scrapbrain',
        0x05 => 'skybase', 0x06 => 'title', 0x07 => 'map', 0x08 => 'invincibility', 0x09 => 'actcomplete',
        0x0A => 'death', 0x0B => 'boss', 0x0C => 'boss', 0x0D => 'boss', 0x0E => 'ending', 0x10 => 'specialstage',
        0x13 => 'allemeralds', 0x14 => 'emerald',
    ];

    // Sound effect indexes (S1_SFXPointers), named after the routines that play them in the disassembly
    public const SFX_JUMP = 0x00;
    public const SFX_EXPLOSION = 0x01;    // badnik / monitor destroyed, boss hit
    public const SFX_RING = 0x02;         // ring, act-complete tally tick
    public const SFX_SKID = 0x03;
    public const SFX_SPRING = 0x04;
    public const SFX_LAUNCH = 0x05;       // ramp block
    public const SFX_ROLL = 0x06;
    public const SFX_BUMPER = 0x07;
    public const SFX_EXTRA_LIFE = 0x09;
    public const SFX_SHOT = 0x0A;         // crab / buzz bomber / newtron shot
    public const SFX_SIGN_SPIN = 0x0B;
    public const SFX_SIGN_STOP = 0x0C;
    public const SFX_HURT = 0x11;
    public const SFX_SPLASH = 0x12;       // Chopper, entering water
    public const SFX_ELECTRIC = 0x13;
    public const SFX_FLAME = 0x17;
    public const SFX_FIREBALL = 0x18;     // Scrap Brain cannons, Labyrinth fireball heads
    public const SFX_LIFT = 0x19;         // lifts, Scrap Brain doors
    public const SFX_SWITCH = 0x1A;       // switch, drowning countdown beep
    public const SFX_BOMB = 0x1B;
    public const SFX_CANNON = 0x1C;
    public const SFX_SPEAR = 0x1D;
    public const SFX_BRIDGE = 0x20;
    public const SFX_GLASS = 0x21;
    public const SFX_BUBBLE = 0x22;       // air bubble breathed

    private string $folder;
    private bool $ready = false;
    /** @var array<int, \Mix_Chunk> */
    private array $chunks = [];
    private array $musicCache = [];
    private ?string $currentMusic = null;
    private ?string $pendingLoop = null;
    /** Speed shoes: the "-fast" version of the song (tickDivider1 = tickDivider2 + 1 in the original driver). */
    private bool $fast = false;
    /** music/timing.json: frames of the intro / loop of every file and its tempo factor. */
    private array $timing = [];
    /** File playing ("greenhill", "greenhill-fast"...), its part ('intro' / 'loop') and when that part started. */
    private ?string $playingFile = null;
    private string $part = 'loop';
    private float $partStart = 0;
    private array $sfxChannels = [];
    /** A level's own music (a WAV of the site's sound editor) while it plays, and its key ("custom:<version>"). */
    private $customMusic = null;
    /** @var array<int, \Mix_Chunk> the game's sound effects a level replaced, to put them back */
    private array $originalChunks = [];
    private string $customSfxKey = '';

    public function __construct()
    {
        $this->folder = dirname(__DIR__, 2) . '/assets/mixer';
    }

    public function Init(): void
    {
        \Mix_Init(0x00000008 | 0x00000010); // MP3 | OGG
        // 2048 samples (46 ms): in the browser the mixer is fed between two frames, a slow frame (multiplayer,
        // phone) with a smaller buffer runs it dry and the sound slows down / repeats
        if (\Mix_OpenAudio(44100, \MIX_DEFAULT_FORMAT, 2, 2048) < 0) {
            echo 'Audio désactivé, Mix_OpenAudio: ' . \Mix_GetError() . PHP_EOL;
            return;
        }
        \Mix_AllocateChannels(8);
        foreach (glob($this->folder . '/sfx/sfx*.wav') as $file) {
            $index = hexdec(substr(basename($file, '.wav'), 3));
            $this->chunks[$index] = \Mix_LoadWAV($file);
        }
        \Mix_VolumeMusic(96);
        $timing = $this->folder . '/music/timing.json';
        if (file_exists($timing)) {
            $this->timing = json_decode(file_get_contents($timing), true) ?: [];
        }
        $this->ready = true;
    }

    public function __destruct()
    {
        if ($this->ready) {
            \Mix_HaltMusic();
            \Mix_CloseAudio();
            \Mix_Quit();
        }
    }

    public function playMusicIndex(int $index, bool $loop = true): void
    {
        if (isset(self::MUSIC_BY_INDEX[$index])) {
            $this->playMusic(self::MUSIC_BY_INDEX[$index], $loop);
        }
    }

    /** Starts a song (no restart if it is already playing). */
    public function playMusic(string $name, bool $loop = true, bool $restart = false): void
    {
        if (!$this->ready || ($name === $this->currentMusic && !$restart)) return;
        $this->currentMusic = $name;
        $this->startFile($this->fileFor($name), $loop, 'intro', 0.0);
    }

    /**
     * A level's own music, looped: $file (a WAV), $key telling one version of it from another (the same file is
     * written again for another level). Like playMusic: no restart if it is already playing.
     */
    public function playCustomMusic(string $file, string $key, bool $restart = false): void
    {
        if (!$this->ready || ($key === $this->currentMusic && !$restart)) return;
        $this->pendingLoop = null;
        $this->playingFile = null;   // no "-fast" version: the speed shoes leave it as it is
        \Mix_HaltMusic();
        if ($this->currentMusic !== $key && $this->customMusic) {
            \Mix_FreeMusic($this->customMusic);
            $this->customMusic = null;
        }
        $this->customMusic ??= \Mix_LoadMUS($file) ?: null;
        $this->currentMusic = $key;
        if ($this->customMusic) \Mix_PlayMusic($this->customMusic, -1);
    }

    /**
     * The sound effects of a level instead of the game's ([SFX index => WAV file]; [] puts the game's back). $key
     * tells one set from another.
     */
    public function useCustomSfx(array $files, string $key): void
    {
        if (!$this->ready || $key === $this->customSfxKey) return;
        $this->customSfxKey = $key;
        // the game's back (null: the game had no such sound)
        foreach ($this->originalChunks as $index => $chunk) {
            $this->stopSfx($index);
            if (isset($this->chunks[$index])) \Mix_FreeChunk($this->chunks[$index]);
            if ($chunk === null) unset($this->chunks[$index]); else $this->chunks[$index] = $chunk;
        }
        $this->originalChunks = [];
        foreach ($files as $index => $file) {
            $chunk = \Mix_LoadWAV($file);
            if (!$chunk) continue;
            $this->stopSfx($index);
            $this->originalChunks[$index] = $this->chunks[$index] ?? null;
            $this->chunks[$index] = $chunk;
        }
    }

    /** The speed shoes start / stop: the song goes on at the same place, faster or back to normal. */
    public function setFast(bool $fast): void
    {
        if ($fast === $this->fast) return;
        $this->fast = $fast;
        if (!$this->ready || $this->currentMusic === null || $this->playingFile === null) return;
        $file = $this->fileFor($this->currentMusic);
        if ($file === $this->playingFile) return;
        $from = $this->timing[$this->playingFile] ?? null;
        $to = $this->timing[$file] ?? null;
        if ($from === null || $to === null) return;
        // position in the part, in frames of the playing file -> in ticks of the song -> frames of the other one
        $frames = (microtime(true) - $this->partStart) * 60;
        if ($this->part === 'loop') {
            $frames = fmod($frames, max(1, $from['loop']));
        }
        $frames = $frames * $from['speed'] / $to['speed'];
        $part = $this->part;
        if ($part === 'loop' || $to['intro'] === 0) {
            $part = $to['intro'] === 0 ? 'intro' : 'loop';
            $frames = fmod($frames, max(1, $to['loop']));
        } elseif ($frames >= $to['intro']) {
            $part = 'loop';
            $frames = fmod($frames - $to['intro'], max(1, $to['loop']));
        }
        $this->startFile($file, true, $part, $frames / 60);
    }

    /** "{name}-fast" while the shoes last, when that version exists. */
    private function fileFor(string $name): string
    {
        return $this->fast && isset($this->timing[$name . '-fast']) ? $name . '-fast' : $name;
    }

    /** Plays a part of a song file ('intro' then its loop, or the loop / a jingle) from $seconds. */
    private function startFile(string $file, bool $loop, string $part, float $seconds): void
    {
        $this->pendingLoop = null;
        $this->playingFile = $file;
        $intro = $this->folder . '/music/' . $file . '-intro.ogg';
        if ($part === 'intro' && $loop && file_exists($intro)) {
            \Mix_PlayMusic($this->loadMusic($intro), 1);
            $this->pendingLoop = $file;
            $this->part = 'intro';
        } else {
            $music = $this->loadMusic($this->folder . '/music/' . $file . '.ogg');
            if (!$music) return;
            \Mix_PlayMusic($music, $loop ? -1 : 1);
            $this->part = 'loop';
        }
        if ($seconds > 0) {
            \Mix_SetMusicPosition($seconds);
        }
        $this->partStart = microtime(true) - $seconds;
    }

    public function stopMusic(): void
    {
        if (!$this->ready) return;
        \Mix_HaltMusic();
        $this->currentMusic = null;
        $this->pendingLoop = null;
        $this->playingFile = null;
    }

    public function getCurrentMusic(): ?string
    {
        return $this->currentMusic;
    }

    /** True once a non-looping song (jingle) has finished. */
    public function isMusicPlaying(): bool
    {
        return $this->ready && (\Mix_PlayingMusic() || $this->pendingLoop !== null);
    }

    /** Called every frame: chains the intro of a song to its looping part. */
    public function update(): void
    {
        if ($this->ready && $this->pendingLoop !== null && !\Mix_PlayingMusic()) {
            $music = $this->loadMusic($this->folder . '/music/' . $this->pendingLoop . '.ogg');
            $this->pendingLoop = null;
            if ($music) {
                \Mix_PlayMusic($music, -1);
                $this->part = 'loop';
                $this->partStart = microtime(true);
            }
        }
    }

    /** Plays a sound effect; the same effect restarts instead of stacking (like the single SFX track of the driver). */
    public function playSfx(int $index, bool $loop = false): void
    {
        if (!$this->ready || !isset($this->chunks[$index])) return;
        if (isset($this->sfxChannels[$index]) && \Mix_Playing($this->sfxChannels[$index])) {
            \Mix_HaltChannel($this->sfxChannels[$index]);
        }
        $channel = \Mix_PlayChannel(-1, $this->chunks[$index], $loop ? -1 : 0);
        if ($channel >= 0) {
            $this->sfxChannels[$index] = $channel;
        }
    }

    public function stopSfx(int $index): void
    {
        if ($this->ready && isset($this->sfxChannels[$index]) && \Mix_Playing($this->sfxChannels[$index])) {
            \Mix_HaltChannel($this->sfxChannels[$index]);
        }
        unset($this->sfxChannels[$index]);
    }

    private function loadMusic(string $path)
    {
        if (!file_exists($path)) return null;
        return $this->musicCache[$path] ??= \Mix_LoadMUS($path);
    }
}
