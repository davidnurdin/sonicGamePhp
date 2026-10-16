<?php

namespace SonicGame\Scene;

use Evenement\EventEmitter;
use SonicGame\Multiplayer\Versus;
use SonicGame\Renderer\Sdl;
use SonicGame\Renderer\TextRenderer;
use SonicGame\Save\GhostRecorder;
use SonicGame\Save\BestTimes;
use SonicGame\Save\Options;
use SonicGame\SoundManager\SoundManager;

/**
 * Title screen, map screen and act-complete tally, rendered from the images extracted from the ROM
 * (assets/screens, see screens.data.php for the original positions / timings).
 * Timings are counted in 60Hz frames like the original. SMS screens are 256x192, drawn x2 centred.
 *
 * End of the game (_LABEL_258B_133): the island screens, the emerald burst with 6 emeralds, the final tally
 * (_172f) and the credits (_LABEL_1CED_131), from assets/screens/ending.data.php (tools/ending.py).
 *
 * Game over (_1401): "GAME OVER" for 120 frames then 180 more (or the button); with continues left ($D284),
 * "CONTINUE", one icon per continue and a countdown from 9 (one a second, sfx $1A): the button continues.
 *
 * Events: titleDone, titleDemo, mapDone, actCompleteDone, creditsDone, gameOverDone [bool continued].
 */
class Screens extends EventEmitter
{
    /** Whole scale of the 256x192 screens in the current view (set by render()). */
    private int $scale = 2;
    private const FRAME = 1 / 60;

    private array $data;
    private ?string $screen = null;
    private float $frameClock = 0;
    private int $frames = 0;
    private int $offsetX = 0;
    private int $offsetY = 0;

    // map
    private int $mapLevel = 1;
    private int $mapStep = 0;
    private int $mapStepFrames = 0;
    private int $lives = 3;

    // act complete
    private int $actLevel = 1;
    private int $score = 0;
    private int $rings = 0;
    private int $timeBonus = 0;
    private int $emeralds = 0;
    private int $tallyEnd = -1;

    // end of the game
    private array $ending;
    /** @var array[] remaining steps of the ending: [name, frames, ...] */
    private array $steps = [];
    private int $stepFrames = 0;
    private bool $allEmeralds = false;
    private string $tallyPage = 'emerald';
    private int $specialBonus = 0;
    private ?array $burst = null;
    private ?array $fade = null;
    private string $islandImage = 'island';
    // credits
    private array $creditNames = [];
    private int $scriptPos = 0;
    private int $scriptWait = 0;
    private int $printCol = 0;
    private int $printRow = 0;
    private array $printQueue = [];
    private int $scrollsLeft = 0;
    private array $anims = [];
    private bool $creditsOver = false;

    // game over
    private int $continues = 0;
    private int $countdown = 9;

    private ?SoundManager $sound = null;

    // title menu (instead of "PRESS BUTTON"): START / OPTIONS, and the options page (ghost on / off)
    private ?TextRenderer $text = null;
    private ?Options $options = null;
    private ?GhostRecorder $ghosts = null;
    /** Options > erase the ghosts: '' / 'confirm' (asked once more) / 'done' (shown until the cursor moves). */
    private string $eraseState = '';
    private string $titlePage = 'menu';
    private int $menuIndex = 0;
    private int $titleIdle = 0;
    private bool $pendingButton = false;
    private const MENU_Y = 129;
    // two-tone text like the game's screens: yellow over orange, the selected line white over yellow
    private const MENU_TOP = [255, 255, 85];
    private const MENU_BOTTOM = [255, 170, 0];
    private const SELECTED_TOP = [255, 255, 255];
    private const SELECTED_BOTTOM = [255, 255, 85];
    private ?BestTimes $bestTimes = null;
    /** Best times page: the act shown (left / right). */
    private int $timesAct = 1;
    // jukebox: the songs of the game and the sound effects $00-$22
    private const SONGS = [
        'greenhill' => 'GREEN HILL', 'bridge' => 'BRIDGE', 'jungle' => 'JUNGLE', 'labyrinth' => 'LABYRINTH',
        'scrapbrain' => 'SCRAP BRAIN', 'skybase' => 'SKY BASE', 'title' => 'TITRE', 'map' => 'CARTE',
        'invincibility' => 'INVINCIBLE', 'boss' => 'BOSS', 'actcomplete' => 'ACTE TERMINE', 'death' => 'MORT',
        'specialstage' => 'SPECIAL STAGE', 'emerald' => 'EMERAUDE', 'allemeralds' => 'TOUTES EMERAUDES', 'ending' => 'FIN',
    ];
    private const SFX_COUNT = 0x23;
    private int $song = 0;
    private int $sfx = 0;

    // fadeOut ($0A6E): 4 steps of 10 frames darken the palette, then [black frames]; _b50: 10 black frames and
    // 4 steps of 10 frames back to the palette. [event, args, frames, black frames]
    private ?array $closing = null;
    private int $fadeInFrames = 0;
    public const FADE_STEP = 10;

    public function setSoundManager(SoundManager $sound): void
    {
        $this->sound = $sound;
    }

    public function setMenu(TextRenderer $text, Options $options, GhostRecorder $ghosts, BestTimes $bestTimes): void
    {
        $this->text = $text;
        $this->options = $options;
        $this->ghosts = $ghosts;
        $this->bestTimes = $bestTimes;
    }

    /** The best times page opens on this act (the one just completed). */
    public function showTimesOf(int $act): void
    {
        $this->timesAct = $act;
    }

    /**
     * Entries of the current menu page: [label, value shown on the right or null, button, left / right (or null)].
     * Pages: 'menu' (on the title picture), 'multi', 'options', 'jukebox', 'cheats', 'scores'.
     */
    private function menuItems(): array
    {
        $options = $this->options;
        switch ($this->titlePage) {
            case 'options':
                $ghost = fn() => $this->changed(fn() => $options->setGhostEnabled(!$options->isGhostEnabled()));
                $crt = fn() => $this->changed(fn() => $options->setCrtEnabled(!$options->isCrtEnabled()));
                $speed = fn() => $this->changed(fn() => $options->setSpeed($options->getSpeed() === 'pal' ? 'ntsc' : 'pal'));
                return [
                    ['GHOST', $options->isGhostEnabled() ? 'ON' : 'OFF', $ghost, $ghost],
                    [match ($this->eraseState) { 'confirm' => 'CONFIRMER ?', 'done' => 'GHOSTS EFFACES', default => 'EFFACER GHOSTS' },
                        null, fn() => $this->eraseGhosts(), null],
                    ['FILTRE CRT', $options->isCrtEnabled() ? 'ON' : 'OFF', $crt, $crt],
                    ['VITESSE', $options->getSpeed() === 'pal' ? 'PAL 50HZ' : 'NTSC 60HZ', $speed, $speed],
                    ['JUKEBOX', null, fn() => $this->openPage('jukebox', 0), null],
                    ['CHEATS', null, fn() => $this->openPage('cheats', 0), null],
                    ['RETOUR', null, fn() => $this->openPage('menu', 2), null],
                ];
            case 'multi':
                // multiplayer: mode, number of Sonics, act; LANCER starts the match (event versusStart)
                $modes = array_keys(Versus::MODES);
                $mode = $options->getVersusMode();
                $players = $options->getVersusPlayers();
                $level = $options->getVersusLevel();
                $levels = Versus::LEVELS;
                $set = fn(string $m, int $p, int $l) => $this->changed(fn() => $options->setVersus($m, $p, $l));
                $cycle = fn(array $list, $current, int $step) => $list[((int)array_search($current, $list, true) + $step + count($list)) % count($list)];
                $nextMode = fn(int $step = 1) => $set($cycle($modes, $mode, $step), $players, $level);
                $team = $options->getVersusTeamSize();
                $nextPlayers = fn(int $step = 1) => $mode === 'flag'
                    ? $this->changed(fn() => $options->setVersusTeamSize(($team - 1 + $step + 3) % 3 + 1))
                    : $set($mode, ($players - 2 + $step + 4) % 4 + 2, $level);
                $nextLevel = fn(int $step = 1) => $set($mode, $players, $cycle($levels, $level, $step));
                return [
                    ['MODE', Versus::MODES[$mode], $nextMode, $nextMode],
                    ['SONICS', $mode === 'flag' ? $team . ' CONTRE ' . $team : (string)$players, $nextPlayers, $nextPlayers],
                    ['ZONE', Versus::levelName($level), $nextLevel, $nextLevel],
                    ['LANCER', null, fn() => $this->finish('versusStart'), null],
                    ['RETOUR', null, fn() => $this->openPage('menu', 1), null],
                ];
            case 'jukebox':
                $songs = array_keys(self::SONGS);
                $play = function () use ($songs) {
                    $this->sound?->playMusic($songs[$this->song], true, true);
                };
                $sfx = fn() => $this->sound?->playSfx($this->sfx);
                return [
                    ['MUSIQUE', self::SONGS[$songs[$this->song]], $play, function (int $direction) use ($songs, $play) {
                        $this->song = ($this->song + $direction + count($songs)) % count($songs);
                        $play();
                    }],
                    ['BRUITAGE', sprintf('%02X', $this->sfx), $sfx, function (int $direction) use ($sfx) {
                        $this->sfx = ($this->sfx + $direction + self::SFX_COUNT) % self::SFX_COUNT;
                        $sfx();
                    }],
                    ['STOP', null, fn() => $this->sound?->stopMusic(), null],
                    ['RETOUR', null, function () {
                        $this->sound?->playMusic('title', false, true);
                        $this->openPage('options', 4);
                    }, null],
                ];
            case 'cheats':
                $items = [];
                foreach (Options::CHEATS as $cheat => $label) {
                    $toggle = fn() => $this->changed(fn() => $options->toggleCheat($cheat));
                    $items[] = [$label, $options->isCheatOn($cheat) ? 'ON' : 'OFF', $toggle, $toggle];
                }
                // the act a new game starts with: left / right, or the button for the next one
                $start = $options->getStartLevel();
                $items[] = ['DEPART', BestTimes::place($start),
                    fn() => $this->changed(fn() => $options->setStartLevel($start % 18 + 1)),
                    fn(int $direction) => $this->changed(fn() => $options->setStartLevel(($start - 1 + $direction + 18) % 18 + 1))];
                $items[] = ['RETOUR', null, fn() => $this->openPage('options', 5), null];
                return $items;
            case 'scores':
                // the act: left / right, or the button for the next one
                $act = fn(int $direction) => [$this->timesAct = ($this->timesAct - 1 + $direction + 18) % 18 + 1,
                    $this->sound?->playSfx(SoundManager::SFX_SWITCH)];
                return [
                    [BestTimes::place($this->timesAct), null, fn() => $act(1), $act],
                    ['RETOUR', null, fn() => $this->openPage('menu', 3), null],
                ];
            default:
                return [
                    ['DEMARRER', null, fn() => $this->finish('titleDone'), null],
                    ['MULTIJOUEUR', null, fn() => $this->openPage('multi', 0), null],
                    ['OPTIONS', null, fn() => $this->openPage('options', 0), null],
                    ['MEILLEURS TEMPS', null, fn() => $this->openPage('scores', 0), null],
                ];
        }
    }

    private function openPage(string $page, int $index): void
    {
        $this->titlePage = $page;
        $this->menuIndex = $index;
        $this->eraseState = '';
        $this->sound?->playSfx(SoundManager::SFX_SWITCH);
    }

    private function changed(\Closure $change): void
    {
        $change();
        $this->sound?->playSfx(SoundManager::SFX_RING);
    }

    /** Options > erase the ghosts, asked twice: the saved runs are deleted for good. */
    private function eraseGhosts(): void
    {
        if ($this->eraseState === 'confirm') {
            $this->ghosts?->clearAll();
            $this->eraseState = 'done';
            $this->sound?->playSfx(SoundManager::SFX_EXPLOSION);
        } elseif ($this->eraseState === '') {
            $this->eraseState = 'confirm';
            $this->sound?->playSfx(SoundManager::SFX_SWITCH);
        }
    }

    /**
     * Escape: 'quit' on the main menu; a page of the menu goes back to the one before it (its RETOUR) and the
     * name entry keeps the name typed ('handled'); any other screen: 'title' (Game goes back to the menu).
     */
    public function back(): string
    {
        if ($this->screen === 'nameEntry') {
            $this->typeName('ok');
            return 'handled';
        }
        if ($this->screen !== 'title') return 'title';
        if ($this->titlePage === 'menu' || $this->options === null) return 'quit';
        $items = $this->menuItems();
        (end($items)[2])();
        return 'handled';
    }

    /** Arrow keys on the title menu: 'up', 'down', 'left', 'right'. */
    public function menuInput(string $direction): void
    {
        if ($this->screen !== 'title' || $this->closing !== null || $this->options === null) return;
        $this->titleIdle = 0;
        $items = $this->menuItems();
        if ($direction === 'up' || $direction === 'down') {
            $count = count($items);
            $this->menuIndex = ($this->menuIndex + ($direction === 'up' ? $count - 1 : 1)) % $count;
            $this->eraseState = '';
            $this->sound?->playSfx(SoundManager::SFX_SWITCH);
        } elseif ($side = $items[$this->menuIndex][3] ?? null) {
            $side($direction === 'left' ? -1 : 1);
        }
    }

    public function __construct(private Sdl $sdl)
    {
        $this->data = require dirname(__DIR__, 2) . '/assets/screens/screens.data.php';
        $this->ending = require dirname(__DIR__, 2) . '/assets/screens/ending.data.php';
    }

    public function loadTextures(): void
    {
        $files = ['title', 'title-press', 'title-sonic', 'map1-sonic', 'map2-sonic', 'map1-digits', 'map2-digits',
            'actcomplete-act1', 'actcomplete-act2', 'actcomplete-act3', 'actcomplete-bonus', 'actcomplete-digits',
            'actcomplete-emerald', 'ending-island', 'ending-island-clear', 'ending-emerald', 'ending-digits',
            'credits-tiles', 'credits-sprites', 'fade', 'gameover', 'continue', 'continue-digits', 'continue-icon'];
        foreach (['emerald', 'lives', 'special'] as $page) {
            $files[] = 'ending-tally-' . $page . '-island';
            $files[] = 'ending-tally-' . $page . '-clear';
        }
        for ($zone = 1; $zone <= 6; $zone++) {
            $files[] = 'map-zone' . $zone;
            $files[] = 'map-zone' . $zone . '-fg';
        }
        foreach ($files as $file) {
            $this->sdl->loadTexture('screen-' . $file, 'screens/' . $file . '.png');
        }
    }

    public function isActive(): bool
    {
        return $this->screen !== null;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function hasMap(int $level): bool
    {
        return isset($this->data['maps'][$level]);
    }

    public function close(): void
    {
        $this->screen = null;
        $this->closing = null;
        $this->fadeInFrames = 0;
        $this->fade = null;
    }

    /** The title screen, on the main menu (or another page of it: 'multi' after a multiplayer match). */
    public function showTitle(string $page = 'menu'): void
    {
        $this->sound?->playMusic('title', false, true);
        $this->titlePage = $page;
        $this->menuIndex = 0;
        $this->titleIdle = 0;
        $this->eraseState = '';
        $this->start('title');
    }

    // new best time: the player's name on an on-screen keyboard (event nameDone [name])
    private string $entryAct = '';
    private int $entryRank = 1;
    private string $entryTime = '';
    private string $entryName = '';
    private int $entryRow = 0;
    private int $entryCol = 0;
    private const NAME_LENGTH = 8;
    // 10 columns; the last row: SPACE (columns 0-2), DELETE (3-6), OK (7-9)
    private const KEYBOARD = ['ABCDEFGHIJ', 'KLMNOPQRST', 'UVWXYZ.-!?', '0123456789'];
    private const KEYBOARD_KEYS = [['ESPACE', ' ', 0, 3], ['EFFACER', 'back', 3, 4], ['OK', 'ok', 7, 3]];
    private const KEY_W = 20;
    private const KEY_H = 15;
    private const KEYBOARD_X = 28;
    private const KEYBOARD_Y = 92;

    /** A time of the act entered the top 5: the name is typed on the on-screen keyboard. */
    public function showNameEntry(string $act, int $rank, string $time): void
    {
        $this->entryAct = $act;
        $this->entryRank = $rank;
        $this->entryTime = $time;
        $this->entryName = '';
        $this->entryRow = 0;
        $this->entryCol = 0;
        $this->start('nameEntry');
    }

    public function isNameEntry(): bool
    {
        return $this->screen === 'nameEntry';
    }

    /** Arrows 'up' / 'down' / 'left' / 'right' move on the keyboard; a typed letter / digit, or 'back'. */
    public function nameKey(string $key): void
    {
        if ($this->screen !== 'nameEntry' || $this->closing !== null || $this->fadeInFrames > 0) return;
        $rows = count(self::KEYBOARD) + 1;
        switch ($key) {
            case 'up':
            case 'down':
                $this->entryRow = ($this->entryRow + ($key === 'up' ? $rows - 1 : 1)) % $rows;
                $this->sound?->playSfx(SoundManager::SFX_SWITCH);
                return;
            case 'left':
            case 'right':
                if ($this->entryRow === $rows - 1) {
                    // the wide keys of the last row: to the previous / next one
                    $next = ($this->bottomKey() + ($key === 'left' ? 2 : 1)) % 3;
                    $this->entryCol = self::KEYBOARD_KEYS[$next][2];
                } else {
                    $this->entryCol = ($this->entryCol + ($key === 'left' ? 9 : 1)) % 10;
                }
                $this->sound?->playSfx(SoundManager::SFX_SWITCH);
                return;
            case 'back':
                $this->typeName('back');
                return;
            default:
                $this->typeName(strtoupper($key));
        }
    }

    /** Index (0-2) of the wide key of the last row under the cursor. */
    private function bottomKey(): int
    {
        foreach (self::KEYBOARD_KEYS as $i => [, , $from, $span]) {
            if ($this->entryCol >= $from && $this->entryCol < $from + $span) return $i;
        }
        return 0;
    }

    /** The button on the key under the cursor. */
    private function pressNameKey(): void
    {
        if ($this->entryRow < count(self::KEYBOARD)) {
            $this->typeName(self::KEYBOARD[$this->entryRow][$this->entryCol]);
        } else {
            $this->typeName(self::KEYBOARD_KEYS[$this->bottomKey()][1]);
        }
    }

    /** A character, 'back' (delete the last one) or 'ok' (the name is done, "SONIC" when empty). */
    private function typeName(string $value): void
    {
        if ($value === 'ok') {
            $name = trim($this->entryName);
            $this->screen = null;
            $this->sound?->playSfx(SoundManager::SFX_EXTRA_LIFE);
            $this->emit('nameDone', [$name === '' ? 'SONIC' : $name]);
            return;
        }
        $name = $this->entryName;
        if ($value === 'back') {
            $name = substr($name, 0, -1);
        } elseif (strlen($value) === 1 && strlen($name) < self::NAME_LENGTH && !($value === ' ' && $name === '')
            && ($value === ' ' || str_contains(implode('', self::KEYBOARD), $value))) {
            $name .= $value;
        }
        $this->sound?->playSfx($name !== $this->entryName ? SoundManager::SFX_RING : SoundManager::SFX_SWITCH);
        $this->entryName = $name;
    }

    public function showGameOver(int $continues): void
    {
        $this->continues = $continues;
        $this->countdown = 9;
        $this->sound?->stopMusic();
        $this->start('gameOver');
    }

    public function showMap(int $level, int $lives): void
    {
        $this->mapLevel = $level;
        $this->lives = $lives;
        $this->mapStep = 0;
        $this->mapStepFrames = 0;
        $this->sound?->playMusic('map', false, true);
        $this->start('map');
        $this->fadeInFrames = 5 * self::FADE_STEP;
    }

    /** Act complete: rings x100 and the time bonus are counted down into the score, 100 points per frame each. */
    public function showActComplete(int $level, int $score, int $rings, float $time, int $lives, int $emeralds): void
    {
        $this->actLevel = $level;
        $this->score = $score;
        $this->rings = $rings;
        $this->lives = $lives;
        $this->emeralds = $emeralds;
        $this->timeBonus = 0;
        if (!$this->isBonusStage()) {
            foreach ($this->data['time_bonus'] as [$threshold, $bonus]) {
                if ((int)$time >= $threshold) {
                    $this->timeBonus = $bonus;
                    break;
                }
            }
        }
        $this->tallyEnd = -1;
        // already started when the sign stopped / the capsule opened
        $this->sound?->playMusic('actcomplete', false);
        $this->start('actComplete');
    }

    /**
     * End of the game, after the final animation of level 19. $stats: 1-ups taken from monitors outside the
     * special stages ($D280), special stages entered ($D281) and finished before their time ran out ($D282),
     * fell into a pit at least once ($D283).
     */
    public function showEnding(int $score, int $lives, int $emeralds, array $stats): void
    {
        $this->score = $score;
        $this->lives = $lives;
        $this->emeralds = $emeralds;
        $this->allEmeralds = $emeralds >= 6;
        // special bonus ($D2FF, BCD tens of thousands)
        $bonus = $this->allEmeralds ? 5 : 0;
        if ($stats['oneUps'] >= 0x12) $bonus += 5;
        if ($stats['specialEntered'] >= 8) $bonus += 5;
        if ($stats['specialCleared'] >= 8) $bonus += 5;
        if (!$stats['fellInPit']) $bonus += 10;
        if ($bonus === 30) $bonus += 20;
        $this->specialBonus = $bonus;
        $this->islandImage = 'island';
        $this->tallyPage = '';
        $this->burst = null;
        $this->fade = null;

        $steps = [];
        if ($this->allEmeralds) {
            // the emerald shown 60 frames, then it bursts into 6 (61 steps of 2 frames), the screen fades to
            // white and comes back without the dark cloud
            $steps[] = ['emerald', 60];
            $steps[] = ['burst', 0x3D * 2];
            $steps[] = ['fadeOut', 32, [255, 255, 255]];
            $steps[] = ['clearIsland', 0];
            $steps[] = ['fadeIn', 32, [255, 255, 255]];
        }
        $steps[] = ['wait', 240];
        // _172f: the chaos emeralds (20000 each, every 60 frames), the lives left (5000, every 30 frames),
        // the special bonus (10000 a unit, every 30 frames)
        // shortened: the original counts a unit every 60 / 30 / 30 frames with 180-480 frame pauses (up to
        // ~40 s with a big special bonus), the same points are counted here in a few seconds
        $steps[] = ['page', 0, 'emerald'];
        $steps[] = ['wait', 90];
        $steps[] = ['count', 12, 'emerald'];
        $steps[] = ['wait', 90];
        $steps[] = ['page', 0, 'lives'];
        $steps[] = ['wait', 90];
        $steps[] = ['count', 8, 'lives'];
        $steps[] = ['wait', 90];
        $steps[] = ['page', 0, 'special'];
        $steps[] = ['wait', 90];
        $steps[] = ['count', 4, 'special'];
        $steps[] = ['wait', 180];
        $steps[] = ['fadeOut', 32, [0, 0, 0]];
        $steps[] = ['black', 120];
        $steps[] = ['credits', 0];
        $this->steps = $steps;
        $this->stepFrames = 0;
        $this->start('ending');
    }

    private function start(string $screen): void
    {
        $this->closing = null;
        $this->fadeInFrames = 0;
        if ($screen !== 'credits') $this->fade = null;
        $this->screen = $screen;
        $this->pendingButton = false;
        $this->frames = 0;
        $this->frameClock = 0;
    }

    private function isBonusStage(): bool
    {
        return $this->actLevel >= 28;
    }

    public function update(float $deltaTime, bool $buttonPressed): void
    {
        if ($this->screen === null) return;
        // kept until the next 60Hz frame: the loop may call this between two frames
        $this->pendingButton = $this->pendingButton || $buttonPressed;
        $buttonPressed = false;
        $this->frameClock += $deltaTime;
        while ($this->frameClock >= self::FRAME && $this->screen !== null) {
            $this->frameClock -= self::FRAME;
            if ($this->closing !== null) {
                $this->pendingButton = false;
                $this->tickClosing();
                continue;
            }
            if ($this->fadeInFrames > 0) {
                // _b50: the screen is frozen while it fades in
                $this->pendingButton = false;
                $done = 5 * self::FADE_STEP - $this->fadeInFrames--;
                $this->fade = [[0, 0, 0], max(0, 3 - intdiv(max(0, $done - self::FADE_STEP), self::FADE_STEP) - 1) / 3];
                if ($done < self::FADE_STEP) $this->fade = [[0, 0, 0], 1];
                if ($this->fadeInFrames === 0) $this->fade = null;
                continue;
            }
            $this->frames++;
            $this->tick($this->pendingButton);
            $this->pendingButton = false;
        }
    }

    private function tick(bool $buttonPressed): void
    {
        switch ($this->screen) {
            case 'title':
                $this->titleIdle++;
                if ($buttonPressed) {
                    $this->titleIdle = 0;
                    if ($this->options === null) {
                        $this->finish('titleDone');
                    } else {
                        $items = $this->menuItems();
                        ($items[$this->menuIndex][2])();
                    }
                } elseif ($this->titlePage === 'menu'
                    && $this->titleIdle >= $this->data['title']['wags'] * $this->data['title']['frame_ticks'] + 2 * 0xFF) {
                    // _1372: the finger animation ends (22 x 8 frames, then 2 x 255 frames): the demo starts
                    $this->finish('titleDemo');
                }
                break;

            case 'map':
                $frames = $this->data['maps'][$this->mapLevel]['frames'];
                if (++$this->mapStepFrames >= $frames[$this->mapStep][3]) {
                    $this->mapStepFrames = 0;
                    $this->mapStep = ($this->mapStep + 1) % count($frames);
                }
                // 300 frames, or the button
                if ($buttonPressed || $this->frames >= 300) $this->fadeOutThen('mapDone', [], 0x3C);
                break;

            case 'ending':
                $this->tickEnding();
                break;

            case 'gameOver':
                // the screen is shown for $78 frames first
                if ($this->frames <= 0x78) break;
                if ($this->continues === 0) {
                    if ($buttonPressed || $this->frames >= 0x78 + 0xB4) $this->finishGameOver(false);
                    break;
                }
                if ($buttonPressed) {
                    $this->finishGameOver(true);
                } elseif (($this->frames - 0x78) % 0x3C === 0) {
                    $this->sound?->playSfx(SoundManager::SFX_SWITCH);
                    if ($this->countdown === 0) {
                        $this->finishGameOver(false);
                    } else {
                        $this->countdown--;
                    }
                }
                break;

            case 'credits':
                $this->tickCredits($buttonPressed);
                break;

            case 'nameEntry':
                if ($buttonPressed) $this->pressNameKey();
                break;

            case 'actComplete':
                if ($this->frames <= 120) break;
                if ($this->tallyEnd < 0) {
                    if ($this->rings > 0) {
                        $this->rings--;
                        $this->score += 100;
                    }
                    // faster than the original (100 a frame): the same total, counted 5 times quicker
                    if ($this->timeBonus > 0) {
                        $step = min($this->timeBonus, 500);
                        $this->timeBonus -= $step;
                        $this->score += $step;
                    }
                    // `_155e`: the ring sound every 4 frames while counting
                    if ($this->frames % 4 === 0) $this->sound?->playSfx(SoundManager::SFX_RING);
                    if ($this->rings === 0 && $this->timeBonus === 0) $this->tallyEnd = $this->frames;
                } elseif ($buttonPressed || $this->frames - $this->tallyEnd >= 180) {
                    $this->fadeOutThen('actCompleteDone', []);
                }
                break;
        }
    }

    private function tickEnding(): void
    {
        while ($this->steps) {
            $step = $this->steps[0];
            [$name, $frames] = $step;
            if ($this->stepFrames === 0) {
                // first frame of a step
                switch ($name) {
                    case 'burst':
                        $this->sound?->playMusic('allemeralds', false, true);
                        break;
                    case 'clearIsland':
                        $this->islandImage = 'clear';
                        break;
                    case 'page':
                        $this->tallyPage = $step[2];
                        break;
                    case 'credits':
                        $this->startCredits();
                        return;
                }
            }
            if ($name === 'count') {
                // one unit every $frames frames, until nothing is left
                if (++$this->stepFrames % $frames !== 0) return;
                $left = match ($step[2]) {
                    'emerald' => $this->emeralds,
                    'lives' => $this->lives,
                    default => $this->specialBonus,
                };
                if ($left === 0) {
                    array_shift($this->steps);
                    $this->stepFrames = 0;
                    continue;
                }
                match ($step[2]) {
                    'emerald' => [$this->emeralds--, $this->score += 20000],
                    'lives' => [$this->lives--, $this->score += 5000],
                    default => [$this->specialBonus--, $this->score += 10000],
                };
                $this->sound?->playSfx(SoundManager::SFX_RING);
                return;
            }
            if ($this->stepFrames < $frames) {
                $this->stepFrames++;
                $this->burst = $name === 'burst' ? $this->ending['burst'][min(0x3C, intdiv($this->stepFrames - 1, 2))] : null;
                $this->fade = match ($name) {
                    'fadeOut' => [$step[2], $this->stepFrames / $frames],
                    'fadeIn' => [$step[2], 1 - $this->stepFrames / $frames],
                    'black' => [[0, 0, 0], 1],
                    default => null,
                };
                return;
            }
            array_shift($this->steps);
            $this->stepFrames = 0;
        }
    }

    private function startCredits(): void
    {
        $this->creditNames = $this->ending['names'];
        $this->scriptPos = 0;
        $this->scriptWait = 0;
        $this->printQueue = [];
        $this->scrollsLeft = 0;
        $this->creditsOver = false;
        // the 4 sprite animations of $D322: [entry, frame counter]
        $this->anims = array_fill(0, count($this->ending['anims']), [0, 0]);
        $this->sound?->playMusic('ending', false, true);
        $this->fade = [[0, 0, 0], 1];
        $this->start('credits');
    }

    /** S1_Credits_Text interpreter (_2795): a character every 8 frames, waits, scrolls of the lower box. */
    private function tickCredits(bool $buttonPressed): void
    {
        // the palette fades in (_b50)
        $this->fade = $this->frames < 32 ? [[0, 0, 0], 1 - $this->frames / 32] : null;

        // _275a: each entry is shown for its duration + 1 frames
        foreach ($this->ending['anims'] as $k => $steps) {
            [$entry, $counter] = $this->anims[$k];
            if (++$counter > $steps[$entry][0]) {
                $counter = 0;
                $entry = ($entry + 1) % count($steps);
            }
            $this->anims[$k] = [$entry, $counter];
        }

        if ($this->creditsOver) {
            // the original stops here for good; the button goes back to the title screen
            if ($buttonPressed) $this->finish('creditsDone');
            return;
        }
        if ($this->scriptWait > 0) {
            $this->scriptWait--;
            if ($this->scriptWait > 0) return;
            if ($this->printQueue) {
                $this->creditNames[$this->printRow * 32 + $this->printCol++] = array_shift($this->printQueue);
            } elseif ($this->scrollsLeft > 0) {
                $this->scrollCredits();
                $this->scrollsLeft--;
            }
        }
        if ($this->printQueue) {
            $this->scriptWait = 8;
            return;
        }
        if ($this->scrollsLeft > 0) {
            $this->scriptWait = 12;
            return;
        }
        $script = $this->ending['script'];
        if ($this->scriptPos >= count($script)) {
            $this->creditsOver = true;
            return;
        }
        $command = $script[$this->scriptPos++];
        switch ($command[0]) {
            case 'p':
                [, $this->printCol, $this->printRow, $this->printQueue] = $command;
                $this->scriptWait = 8;
                break;
            case 'w':
                $this->scriptWait = $command[1];
                break;
            case 's':
                $this->scrollsLeft = $command[1];
                $this->scriptWait = 12;
                break;
        }
    }

    /** The lower text box moves up a line: rows 11-19 of columns 18-27 copied one row up. */
    private function scrollCredits(): void
    {
        for ($row = 10; $row < 19; $row++) {
            for ($col = 18; $col < 28; $col++) {
                $this->creditNames[$row * 32 + $col] = $this->creditNames[($row + 1) * 32 + $col];
            }
        }
    }

    private function finishGameOver(bool $continued): void
    {
        $this->fadeOutThen('gameOverDone', [$continued]);
    }

    /** fadeOut, then $black frames of a black screen, then the event (the screen closes). */
    private function fadeOutThen(string $event, array $args, int $black = 0): void
    {
        $this->closing = [$event, $args, 0, $black];
    }

    private function tickClosing(): void
    {
        [$event, $args, $frames, $black] = $this->closing;
        $this->closing[2] = ++$frames;
        $this->fade = [[0, 0, 0], min(3, intdiv($frames - 1, self::FADE_STEP) + 1) / 3];
        if ($frames < 4 * self::FADE_STEP + $black) return;
        $this->closing = null;
        $this->fade = null;
        $this->screen = null;
        $this->emit($event, $args);
    }

    private function renderGameOver(): void
    {
        $this->clear(0, 0, 0);
        if ($this->continues === 0) {
            $this->blit('gameover', 0, 0, 256, 192, 0, 0);
            return;
        }
        $layout = $this->data['continue'];
        $this->blit('continue', 0, 0, 256, 192, 0, 0);
        // _16d9: the icons 2 columns apart, from column $0B - count / 2
        $col = $layout['icon_col'] - intdiv($this->continues, 2);
        for ($i = 0; $i < $this->continues; $i++) {
            $this->blit('continue-icon', 0, 0, 16, 16, ($col + 2 * $i) * 8, $layout['icon_row'] * 8);
        }
        if ($this->frames > 0x78) {
            // sprite: one line lower
            $this->drawNumber('continue-digits', (string)$this->countdown, $layout['digit'][0], $layout['digit'][1] + 1);
        }
    }

    private function finish(string $event): void
    {
        $this->screen = null;
        $this->emit($event, []);
    }

    public function render(): void
    {
        if ($this->screen === null) return;
        $renderer = $this->sdl->getRenderer()->getRenderer();
        $winW = $this->sdl->getWindow()->getWidth();
        $winH = $this->sdl->getWindow()->getHeight();
        // biggest whole scale that fits the view (2 in the default 840x426 view, 1 in the 256x192 one)
        $this->scale = max(1, (int)floor(min($winW / 256, $winH / 192)));
        $this->offsetX = (int)(($winW - 256 * $this->scale) / 2);
        $this->offsetY = (int)(($winH - 192 * $this->scale) / 2);

        match ($this->screen) {
            'title' => $this->renderTitle(),
            'map' => $this->renderMap(),
            'actComplete' => $this->renderActComplete(),
            'ending' => $this->renderEnding(),
            'credits' => $this->renderCredits(),
            'gameOver' => $this->renderGameOver(),
            'nameEntry' => $this->renderNameEntry(),
        };
        if ($this->fade !== null) {
            // fade.png: white / black at 1/3, 2/3 and 3/3 (the palette fades in steps)
            [[$r], $alpha] = $this->fade;
            $step = (int)ceil(max(0, min(1, $alpha)) * 3);
            if ($step > 0) {
                $src = new \SDL_Rect;
                [$src->x, $src->y, $src->w, $src->h] = [($step - 1) * 8, $r > 0 ? 0 : 8, 8, 8];
                $dst = new \SDL_Rect;
                [$dst->x, $dst->y, $dst->w, $dst->h] = [0, 0, $winW, $winH];
                \SDL_RenderCopy($renderer, $this->sdl->getTextures('screen-fade')[0]['texture'], $src, $dst);
            }
        }
    }

    private function renderTitle(): void
    {
        $title = $this->data['title'];
        $this->clear(0, 0, 0);
        if ($this->titlePage !== 'menu' && $this->text !== null) {
            $this->renderMenuPage();
            return;
        }
        // the menu takes the place of "PRESS BUTTON" (without the menu: shown for 64 frames out of 100)
        $press = $this->text === null && ($this->frames % $title['press_period']) < $title['press_visible'];
        $this->blit($press ? 'title-press' : 'title', 0, 0, 256, 192, 0, 0);
        if ($this->text !== null) {
            foreach ($this->menuItems() as $i => [$label]) {
                // 4 lines between the logo and the copyright line of the picture
                $this->menuLine($label, $i === $this->menuIndex, self::MENU_Y + 10 * $i);
            }
        }
        // wagging finger: alternates every 8 frames 22 times, then rests on the first frame
        $tick = intdiv($this->frames, $title['frame_ticks']);
        $frame = $tick < $title['wags'] ? $tick % 2 : 0;
        $this->blit('title-sonic', $frame * 48, 0, 48, 48, $title['x'], $title['y']);
    }

    /** One centred line of the title menu at $y (SMS pixels); the selected one has the arrow and blinks. */
    private function menuLine(string $line, bool $selected, int $y): void
    {
        $label = ($selected ? '> ' : '') . $line;
        $size = 8 * $this->scale;
        $width = $this->text->styledWidth($label, $size, $this->scale);
        $this->styled($label, (int)((256 * $this->scale - $width) / 2 / $this->scale), $y, $size, $this->lineColours($selected));
    }

    /**
     * Text at SMS position ($x, $y) with the outline of the game's screens. $overBlack: kept in a texture
     * (TextRenderer::drawStyled), not over a coloured box.
     */
    private function styled(string $text, float $x, int $y, int $size, array $colours, bool $overBlack = true): void
    {
        $this->text->drawStyled($text, $this->offsetX + (int)round($x * $this->scale), $this->offsetY + $y * $this->scale,
            $size, $colours[0], $colours[1], $this->scale, $overBlack);
    }

    private function lineColours(bool $selected): array
    {
        return $selected && ($this->frames % 40) < 24
            ? [self::SELECTED_TOP, self::SELECTED_BOTTOM]
            : [self::MENU_TOP, self::MENU_BOTTOM];
    }

    /** Options, cheats and high scores: a page of its own, its title on top, the entries below. */
    private function renderMenuPage(): void
    {
        $scale = $this->scale;
        $title = match ($this->titlePage) {
            'cheats' => 'CHEATS', 'scores' => 'MEILLEURS TEMPS', 'jukebox' => 'JUKEBOX', 'multi' => 'MULTIJOUEUR', default => 'OPTIONS',
        };
        $big = 12 * $scale;
        $width = $this->text->styledWidth($title, $big, $scale);
        $this->styled($title, (256 * $scale - $width) / 2 / $scale, 12, $big, [[255, 255, 255], [85, 170, 255]]);

        $items = $this->menuItems();
        if ($this->titlePage === 'scores') {
            $this->renderBestTimes();
            return;
        }
        $size = 8 * $scale;
        $step = count($items) > 6 ? 14 : 20;
        foreach ($items as $i => [$label, $value]) {
            $y = 40 + $step * $i;
            $selected = $i === $this->menuIndex;
            if ($selected) $this->styled('>', 18, $y, $size, $this->lineColours(true));
            $this->styled($label, 32, $y, $size, $this->lineColours($selected));
            if ($value !== null) {
                // ON in green, OFF in grey, other values in the menu colours; right-aligned
                $colours = match ($value) {
                    'ON' => [[170, 255, 170], [0, 200, 0]],
                    'OFF' => [[170, 170, 170], [85, 85, 85]],
                    default => $this->lineColours($selected),
                };
                $width = $this->text->styledWidth($value, $size, $scale) / $scale;
                $this->styled($value, 236 - $width, $y, $size, $colours);
            }
        }
        if ($this->titlePage === 'multi') {
            // the rules of the mode chosen, under the entries
            $rules = [
                'race' => ['LE PREMIER A L ARRIVEE GAGNE', 'SAUTE OU ROULE SUR LES AUTRES'],
                'flag' => ['RAMENE LE DRAPEAU A TA BASE', 'TOUCHE TU LE LACHES  3 POINTS'],
                'rings' => ['LES ANNEAUX REVIENNENT', '3 MIN  LE PLUS D ANNEAUX GAGNE'],
                'deathmatch' => ['SAUTE OU ROULE SUR LES AUTRES', '3 MIN  LE PLUS DE KO GAGNE'],
            ][$this->options->getVersusMode()];
            foreach ($rules as $i => $rule) {
                $width = $this->text->styledWidth($rule, $size, $scale);
                $this->styled($rule, (256 * $scale - $width) / 2 / $scale, 150 + 12 * $i, $size, [[255, 255, 255], [170, 170, 170]]);
            }
        }
    }

    /** New record: "NOUVEAU RECORD !", the act, place and time, the name and the on-screen keyboard. */
    private function renderNameEntry(): void
    {
        $this->clear(0, 0, 0);
        if ($this->text === null) return;
        $scale = $this->scale;
        $centre = function (string $text, int $y, int $size, array $colours) use ($scale): void {
            $width = $this->text->styledWidth($text, $size * $scale, $scale);
            $this->styled($text, (256 * $scale - $width) / 2 / $scale, $y, $size * $scale, $colours);
        };
        $blue = [[255, 255, 255], [85, 170, 255]];
        $centre('NOUVEAU RECORD !', 8, 12, ($this->frames % 30) < 20 ? $blue : [[255, 255, 85], [255, 170, 0]]);
        $centre($this->entryAct . '   ' . ($this->entryRank === 1 ? '1ER' : $this->entryRank . 'E') . '   ' . $this->entryTime,
            32, 8, [self::MENU_TOP, self::MENU_BOTTOM]);
        // the name, with a blinking cursor, in a fixed-width line
        $cursor = strlen($this->entryName) < self::NAME_LENGTH && ($this->frames % 30) < 15 ? '_' : ' ';
        $shown = str_pad($this->entryName . $cursor, self::NAME_LENGTH, ' ');
        $width = $this->text->styledWidth(str_repeat('W', self::NAME_LENGTH), 12 * $scale, $scale);
        $this->box(64, 52, 128, 24, [0, 0, 85]);
        $this->styled($shown, (256 * $scale - $width) / 2 / $scale, 57, 12 * $scale, $blue, false);

        // keyboard: the key under the cursor on a blue background
        $size = 8 * $scale;
        $rows = self::KEYBOARD;
        foreach ($rows as $r => $chars) {
            foreach (str_split($chars) as $c => $char) {
                $this->keyCap($char, self::KEYBOARD_X + $c * self::KEY_W, self::KEYBOARD_Y + $r * self::KEY_H, self::KEY_W,
                    $r === $this->entryRow && $c === $this->entryCol, $size);
            }
        }
        $bottom = count($rows) === $this->entryRow ? $this->bottomKey() : -1;
        foreach (self::KEYBOARD_KEYS as $i => [$label, , $from, $span]) {
            $this->keyCap($label, self::KEYBOARD_X + $from * self::KEY_W, self::KEYBOARD_Y + count($rows) * self::KEY_H,
                $span * self::KEY_W, $i === $bottom, $size);
        }
    }

    /** One key of the on-screen keyboard, centred in its cell (SMS pixels). */
    private function keyCap(string $label, int $x, int $y, int $w, bool $selected, int $size): void
    {
        if ($selected) $this->box($x + 1, $y - 2, $w - 2, self::KEY_H - 1, [0, 85, 170]);
        $width = $this->text->styledWidth($label, $size, $this->scale) / $this->scale;
        $this->styled($label, $x + ($w - $width) / 2, $y + 1, $size,
            $selected ? [self::SELECTED_TOP, self::SELECTED_BOTTOM] : [self::MENU_TOP, self::MENU_BOTTOM], !$selected);
    }

    /** Opaque rectangle (SMS pixels). */
    private function box(int $x, int $y, int $w, int $h, array $rgb): void
    {
        $renderer = $this->sdl->getRenderer()->getRenderer();
        \SDL_SetRenderDrawColor($renderer, $rgb[0], $rgb[1], $rgb[2], 255);
        $rect = new \SDL_Rect;
        [$rect->x, $rect->y, $rect->w, $rect->h] = [$this->offsetX + $x * $this->scale, $this->offsetY + $y * $this->scale,
            $w * $this->scale, $h * $this->scale];
        \SDL_RenderFillRect($renderer, $rect);
    }

    /** Best times page: "< ZONE 1 ACTE 1 >", its 5 best times with their date (the last one blinking), RETOUR. */
    private function renderBestTimes(): void
    {
        $selected = $this->menuIndex === 0;
        $label = '< ' . BestTimes::place($this->timesAct) . ' >';
        $size = 8 * $this->scale;
        $width = $this->text->styledWidth($label, $size, $this->scale);
        $this->styled($label, (256 * $this->scale - $width) / 2 / $this->scale, 38, $size, $this->lineColours($selected));

        $entries = $this->bestTimes?->top($this->timesAct) ?? [];
        $last = $this->bestTimes?->lastIndex($this->timesAct);
        if (!$entries) {
            $this->menuLine('AUCUN TEMPS', false, 92);
        }
        foreach ($entries as $i => $entry) {
            // the time just made blinks
            if ($i === $last && ($this->frames % 40) >= 28) continue;
            $colours = $i === 0 ? [[255, 255, 255], [255, 255, 85]] : [self::MENU_TOP, self::MENU_BOTTOM];
            $y = 62 + 18 * $i;
            $this->styled(($i + 1) . '.', 14, $y, $size, $colours);
            $this->styled($entry['name'] !== '' ? $entry['name'] : '-', 32, $y, $size, $colours);
            $this->styled(BestTimes::format($entry['time']), 112, $y, $size, $colours);
            $this->styled($entry['date'], 182, $y, $size, $colours);
        }
        $this->menuLine('RETOUR', $this->menuIndex === 1, 168);
    }

    private function renderMap(): void
    {
        $map = $this->data['maps'][$this->mapLevel];
        $zone = $map['zone'];
        $this->clearFromTexturePixel();
        $this->blit('map-zone' . $zone, 0, 0, 256, 192, 0, 0);
        [$cell, $x, $y] = $map['frames'][$this->mapStep];
        $this->blit('map' . $map['screen'] . '-sonic', $cell * 48, 0, 48, 48, $x, $y);
        $this->drawNumber('map' . $map['screen'] . '-digits', sprintf('%02d', min(99, $this->lives)),
            $this->data['map_lives']['x'], $this->data['map_lives']['y']);
        $this->blit('map-zone' . $zone . '-fg', 0, 0, 256, 192, 0, 0);
    }

    private function renderActComplete(): void
    {
        $layout = $this->data['act_layout'];
        $this->clear(0x55, 0x55, 0xFF);
        if ($this->isBonusStage()) {
            $this->blit('actcomplete-bonus', 0, 0, 256, 192, 0, 0);
        } else {
            $act = intdiv($this->data['act_act'][$this->actLevel - 1] ?? 0, 8) + 1;
            $this->blit('actcomplete-act' . $act, 0, 0, 256, 192, 0, 0);
        }
        $digits = 'actcomplete-digits';
        $this->drawNumber($digits, $this->blankZeros(sprintf('%08d', $this->score)), ...$layout['score']);
        if ($this->isBonusStage()) {
            $this->drawNumber($digits, $this->blankZeros(sprintf('%02d', $this->rings)), ...$layout['bonus_rings']);
            $this->drawNumber($digits, ' 100', $layout['ring_value'][0], $layout['bonus_rings'][1]);
        } else {
            $this->drawNumber($digits, $this->blankZeros(sprintf('%08d', $this->timeBonus)), ...$layout['time_bonus']);
            $this->drawNumber($digits, $this->blankZeros(sprintf('%02d', $this->rings)), ...$layout['rings']);
            $this->drawNumber($digits, ' 100', ...$layout['ring_value']);
        }
        $this->drawNumber($digits, sprintf('%02d', min(99, $this->lives)), ...$layout['lives']);

        // chaos emeralds collected so far, 2 columns apart and centred on column 16
        $col = $layout['emerald_col'] - intdiv($this->emeralds, 2);
        for ($i = 0; $i < $this->emeralds; $i++) {
            $this->blit('actcomplete-emerald', 0, 0, 16, 16, ($col + 2 * $i) * 8, $layout['emerald_row'] * 8);
        }
    }

    private function renderEnding(): void
    {
        $this->clear(0x55, 0x55, 0xFF);
        if ($this->tallyPage !== '') {
            $this->blit('ending-tally-' . $this->tallyPage . '-' . $this->islandImage, 0, 0, 256, 192, 0, 0);
            $digits = 'ending-digits';
            // _1860: the score at ($90, $80), the count at ($90, $60), what it is worth at ($A0, $60)
            $this->drawNumber($digits, $this->blankZeros(sprintf('%08d', min(99999999, $this->score))), 0x90, 0x81);
            match ($this->tallyPage) {
                'emerald' => [
                    $this->drawNumber($digits, $this->blankZeros(sprintf('%02d', $this->emeralds)), 0x90, 0x61),
                    $this->drawNumber($digits, ' 20000', 0xA0, 0x61),
                ],
                'lives' => [
                    $this->drawNumber($digits, sprintf('%02d', min(99, $this->lives)), 0x90, 0x61),
                    $this->drawNumber($digits, '  5000', 0xA0, 0x61),
                ],
                default => $this->drawNumber($digits, $this->blankZeros(sprintf('%02d0000', $this->specialBonus)), 0xA0, 0x61),
            };
            return;
        }
        $this->blit($this->islandImage === 'clear' ? 'ending-island-clear' : 'ending-island', 0, 0, 256, 192, 0, 0);
        $step = $this->steps[0][0] ?? '';
        if ($step === 'emerald') {
            $this->blit('ending-emerald', 0, 0, 16, 16, 0x70, 0x61);
        } elseif ($this->burst !== null) {
            foreach ($this->burst as [$dx, $dy]) {
                $this->blit('ending-emerald', 0, 0, 16, 16, 0x70 + $dx, 0x61 + $dy);
                $this->blit('ending-emerald', 0, 0, 16, 16, 0x70 - $dx, 0x61 - $dy);
            }
        }
    }

    private function renderCredits(): void
    {
        $this->clear(0, 0, 0);
        foreach ($this->creditNames as $i => $tile) {
            if ($i >= 32 * 24) break;
            $tile &= 0xFF;
            $this->blit('credits-tiles', ($tile % 16) * 8, intdiv($tile, 16) * 8, 8, 8, ($i % 32) * 8, intdiv($i, 32) * 8);
        }
        foreach ($this->ending['anims'] as $k => $steps) {
            $frame = $steps[$this->anims[$k][0]][1];
            [$x, $y] = $this->ending['sprite_frames'][$frame];
            $this->blit('credits-sprites', $frame * 48, 0, 48, 48, $x, $y);
        }
    }

    /** Leading zeros become blanks, like routine $1B13 (a value of zero still shows its last digit). */
    private function blankZeros(string $digits): string
    {
        $trimmed = ltrim($digits, '0');
        if ($trimmed === '') $trimmed = '0';
        return str_pad($trimmed, strlen($digits), ' ', STR_PAD_LEFT);
    }

    private function drawNumber(string $texture, string $text, int $x, int $y): void
    {
        foreach (str_split($text) as $i => $char) {
            if ($char === ' ') continue;
            $this->blit($texture, (int)$char * 8, 0, 8, 16, $x + $i * 8, $y);
        }
    }

    private function clear(int $r, int $g, int $b): void
    {
        $renderer = $this->sdl->getRenderer()->getRenderer();
        \SDL_SetRenderDrawColor($renderer, $r, $g, $b, 255);
        \SDL_RenderClear($renderer);
    }

    /** The map backdrop colour fills the borders. */
    private function clearFromTexturePixel(): void
    {
        $this->clear(0xFF, 0xAA, 0xAA);
    }

    private function blit(string $texture, int $sx, int $sy, int $w, int $h, int $x, int $y): void
    {
        $src = new \SDL_Rect;
        $src->x = $sx;
        $src->y = $sy;
        $src->w = $w;
        $src->h = $h;
        $dst = new \SDL_Rect;
        $dst->x = $this->offsetX + $x * $this->scale;
        $dst->y = $this->offsetY + $y * $this->scale;
        $dst->w = $w * $this->scale;
        $dst->h = $h * $this->scale;
        \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures('screen-' . $texture)[0]['texture'], $src, $dst);
    }
}
