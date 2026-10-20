<?php

namespace SonicGame;

use Evenement\EventEmitter;
use React\EventLoop\TimerInterface;
use SonicGame\Entities\GameObjectManager;
use SonicGame\Entities\Physics\CollisionSystem;
use SonicGame\Entities\Player;
use SonicGame\InputManager\InputKeyboard;
use SonicGame\InputManager\InputManager;
use SonicGame\InputManager\InputTouchpad;
use SonicGame\Level\LevelManager;
use SonicGame\Loop\GameLoop;
use SonicGame\Multiplayer\Online;
use SonicGame\Multiplayer\Versus;
use SonicGame\Renderer\CrtFilter;
use SonicGame\Renderer\Sdl;
use SonicGame\Renderer\TextRenderer;
use SonicGame\Save\GhostRecorder;
use SonicGame\Save\BestTimes;
use SonicGame\Save\Options;
use SonicGame\Scene\Scene;
use SonicGame\Scene\Screens;
use SonicGame\SoundManager\SoundManager;

class Game extends EventEmitter
{

    private int $debugMode = 0 ;

	public bool $disableSdl = false ;

    private GameObjectManager $objectManager;

    private Screens $screens;

    /** Start button pressed since the last tick (title / map / act complete screens). */
    private bool $buttonPressed = false;
    /** Act that led to a Scrap Brain transporter room (the game resumes after it). */
    private ?int $warpFrom = null;
    /** Debug (keypad 5): Sonic follows the free camera. */
    private bool $carryPlayer = false;

    // View: game pixels -> screen pixels. The automatic zoom keeps a view about 426px high whatever the window /
    // screen size, the keypad +/- step from it, * switches to the 256x192 Master System screen.
    private const ZOOMS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2, 2.5, 3, 3.5, 4, 5, 6, 8];
    private const DEFAULT_VIEW_HEIGHT = 426;
    private int $zoomOffset = 0;
    /** Height (px) the automatic zoom aims at: DEFAULT_VIEW_HEIGHT, ~192 on a phone (bigger sprites, full width). */
    public int $viewHeight = self::DEFAULT_VIEW_HEIGHT;
    /** Frames of the last second, shown in the F6 panel. */
    private int $fpsShown = 0;
    /** Multiplayer match in progress (title menu > MULTIJOUEUR), or null. */
    private ?Versus $versus = null;
    /** Online session (menu MULTIJOUEUR > EN LIGNE), kept from the room to the matches and back. */
    private ?Online $online = null;
    /** The START of the online match being played (a new one from the host starts the next match). */
    private ?array $versusConfig = null;
    /** Options > FILTRE CRT (created on first use). */
    private ?CrtFilter $crt = null;
    private bool $smsView = false;

    /** F6: panel with Sonic's coordinates over the screen (to report bugs). */
    private bool $coordsPanel = false;
    private TextRenderer $text;
    /** Title menu options (var/options.json) and the ghost of the fastest run of each level (var/ghosts). */
    private Options $options;
    private GhostRecorder $ghost;
    /** Best times of each act (title menu > best times), var/besttimes.json. */
    private BestTimes $bestTimes;
    /** Time already spent in the act before a Scrap Brain transporter / the Sky Base 2 door (rooms reset the timer). */
    private float $actTimeBefore = 0;
    /** Name typed for the first best time of this session, used for the next ones until the game is closed. */
    private ?string $playerName = null;
    /** Online: the action of the menu EN LIGNE waiting for the player's name ('create' / 'join'). */
    private ?string $afterPlayerName = null;
    /** Best time waiting for the player's name: [act, index, time], and what to do once it is typed. */
    private ?array $pendingName = null;
    private ?\Closure $afterName = null;

    public function __construct(
        public GameLoop $gameLoop,
        private InputManager $inputManager,
        public Sdl $sdl,
        private Player $player,
        private Scene $scene,
        private LevelManager $levelManager,
        private SoundManager $soundManager,
		private CollisionSystem $collisionSystem
)
    {
        $scene->setPlayer($this->player);
        $this->objectManager = new GameObjectManager($sdl);
        $scene->setObjectManager($this->objectManager);
        $this->screens = new Screens($sdl);
        $root = dirname(__DIR__);
        $this->text = new TextRenderer($sdl, $root . '/assets');
        $this->options = new Options($root . '/var/options.json');
        $this->ghost = new GhostRecorder($root . '/var/ghosts');
        $this->bestTimes = new BestTimes($root . '/var/besttimes.json');
        $this->screens->setMenu($this->text, $this->options, $this->ghost, $this->bestTimes);
    }

    /** Next special stage ($D23F, from $1C = our level 28) and the act to go on from after it. */
    private int $nextSpecialStage = 28;

    /**
     * Demo mode (_1bad): Green Hill act 1 played with the joypad of _1bc6, one byte every 32 frames
     * (active low: bit 0 up, 1 down, 2 left, 3 right, 4-5 buttons). A button press, Sonic's death or the end
     * of the table go back to the title screen.
     */
    private const DEMO_INPUT = [
        0xF7, 0xF7, 0xF7, 0xF7, 0xDF, 0xF7, 0xFF, 0xFF, 0xD7, 0xF7, 0xF7, 0xF7, 0xFF, 0xDF, 0xF7, 0xF7, 0xDF, 0xF7, 0xF7, 0xF7,
        0xF7, 0xFF, 0xFF, 0xDF, 0xF7, 0xFF, 0xFF, 0xFF, 0xFB, 0xF7, 0xF7, 0xF5, 0xFF, 0xFF, 0xFF, 0xFF, 0xFB, 0xFB, 0xF9, 0xFF,
        0xFF, 0xFF, 0xFF, 0xF7, 0xF7, 0xF7, 0xF7, 0xD7, 0xFF, 0xFF, 0xD7, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xD7, 0xFB,
        0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xD7, 0xF7, 0xF7, 0xFF, 0xD7,
        0xFB, 0xF7, 0xF7, 0xF7, 0xF7, 0xFB, 0xFB, 0xF7, 0xFF, 0xD7, 0xFB, 0xFF, 0xF7, 0xF7, 0xD7, 0xFB, 0xD7, 0xF7, 0xF7, 0xF7,
        0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xF7, 0xF7, 0xF7, 0xD7, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF,
        0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF,
    ];
    private bool $demo = false;
    private int $demoFrames = 0;
    private float $demoClock = 0;
    private int $demoPrevious = 0xFF;
    private ?int $specialReturn = null;

    private function registerEvents()
    {
        $this->inputManager->on('exitGame', fn() => $this->eventExitGame());
        $this->inputManager->on('keyPress', fn($keyboard, $key) => $this->eventKeyPressed($keyboard, $key));
        $this->inputManager->on('touchPressed', fn($touchpad, $action) => $this->eventTouchPressed($touchpad, $action));
        $this->levelManager->on('levelChanged', fn($level) => $this->levelReload($level));

        // Connecte le CollisionSystem à Scene pour les événements de debug
        $this->scene->listenToCollisionEvents($this->collisionSystem);

        $this->collisionSystem->setObjectGroundProvider(
            fn(array $rect, float $vy, string $state) => $this->objectManager->findObjectGround($rect, $vy, $state)
        );
        $this->collisionSystem->setWaterLineProvider(fn() => $this->objectManager->getWaterLine());
        // the multiplayer Sonics use the same CollisionSystem: their hazards / pits go to the match
        $this->collisionSystem->on('playerHazard', fn($entity) => $entity === $this->player
            ? $this->objectManager->hurtPlayer($this->player) : $this->versus?->entityHazard($entity));
        $this->collisionSystem->on('paletteFlash', fn() => $this->objectManager->flashPalette());
        $this->collisionSystem->on('playerFell', fn($entity) => $entity === $this->player
            ? $this->objectManager->playerFellInPit($this->player) : $this->versus?->entityFell($entity));
        // multiplayer race: passing the sign ends the race for Sonic, no tally
        $this->objectManager->on('levelComplete', fn() => $this->versus ? $this->versus->humanFinished() : $this->screens->showActComplete(
            $this->levelManager->getCurrentLevel()->getLevel(),
            $this->objectManager->getScore(),
            $this->objectManager->getRingCount(),
            $this->objectManager->getLevelTime(),
            $this->objectManager->getLives(),
            $this->objectManager->getEmeralds()
        ));
        // Sound: every part of the engine emits 'sfx' / 'music' events
        // the multiplayer Sonics' springs, bumpers... are silent (Versus plays its own sounds)
        $playSfx = fn(int $index, bool $loop = false) => $this->versus?->isSimulating() ? null : $this->soundManager->playSfx($index, $loop);
        $this->player->on('sfx', $playSfx);
        $this->collisionSystem->on('sfx', $playSfx);
        $this->scene->on('sfx', $playSfx);
        $this->objectManager->on('sfx', $playSfx);
        $this->objectManager->on('stopSfx', fn(int $index) => $this->soundManager->stopSfx($index));
        $this->objectManager->on('music', fn(string $name, bool $loop) => $this->soundManager->playMusic($name, $loop, true));
        $this->objectManager->on('levelMusic', fn() => $this->playLevelMusic(true));
        $this->objectManager->on('extraLife', fn() => $this->soundManager->playSfx(SoundManager::SFX_EXTRA_LIFE));
        // ghost: a completed act is kept when it is the fastest; a restart from a checkpoint is not recorded
        // best times: the act timer when it is completed (not with cheats)
        $this->objectManager->on('levelComplete', fn() => $this->recordActTime());
        $this->objectManager->on('teleportEnd', fn() => $this->recordActTime());
        $this->objectManager->on('levelComplete', fn() => $this->objectManager->hasCheats() ? null : $this->ghost->finish(
            $this->levelManager->getCurrentLevel()->getLevel(), $this->objectManager->getLevelTime()));
        $this->objectManager->on('teleportEnd', fn() => $this->objectManager->hasCheats() ? null : $this->ghost->finish(
            $this->levelManager->getCurrentLevel()->getLevel(), $this->objectManager->getLevelTime()));
        $this->objectManager->on('playerRespawned', fn() => $this->ghost->begin(
            $this->levelManager->getCurrentLevel()->getLevel(), !$this->objectManager->hasCheckpoint()));
        $this->objectManager->on('playerRespawned', function () {
            if (!$this->screens->isActive()) $this->playLevelMusic(true);   // not after a game over (title)
            $this->versus?->humanRespawned();
        });

        // _2067: once $D287 has run out the screen fades out; 60 black frames, then the act restarts
        $this->objectManager->on('deathOver', fn() => $this->fadeLevelOut(0x3C, function () {
            // the act is loaded again like at its start (_1CED): camera, scrolling, Sonic
            $this->player->resetForStart();
            $this->objectManager->respawnPlayer($this->player);
            $this->scene->resetLevel();
        }));
        $this->objectManager->on('gameOver', fn(int $continues) => $this->fadeLevelOut(0, fn() => $this->screens->showGameOver($continues)));
        $this->screens->on('gameOverDone', function (bool $continued) {
            if ($continued) {
                // the act from its beginning, with its map
                $this->objectManager->useContinue();
                $this->levelReload($this->levelManager->getCurrentLevel()->getLevel());
                $this->startLevel();
                return;
            }
            $this->actTimeBefore = 0;
            $this->objectManager->resetGame();
            $this->warpFrom = null;
            $this->specialReturn = null;
            $this->nextSpecialStage = 28;
            $this->levelManager->firstLevel();
            $this->levelReload(1);   // also when the game ended in act 1
            $this->screens->showTitle();
        });

        // end of the game (level 19): island, final tally and credits; then back to the title, new game
        $this->objectManager->on('gameEnd', fn() => $this->screens->showEnding(
            $this->objectManager->getScore(),
            $this->objectManager->getLives(),
            $this->objectManager->getEmeralds(),
            $this->objectManager->getStats()
        ));
        $this->screens->on('creditsDone', function () {
            $this->objectManager->resetGame();
            $this->warpFrom = null;
            $this->specialReturn = null;
            $this->nextSpecialStage = 28;
            $this->levelManager->firstLevel();
            $this->screens->showTitle();
        });

        // Title -> map -> level -> act complete -> map -> next level...
        $this->screens->on('titleDone', function () {
            $this->actTimeBefore = 0;
            $this->startGameCheats();
            $this->startLevel();
        });
        $this->screens->on('titleDemo', function () {
            // the demo is played without the cheats
            $this->objectManager->setCheats([]);
            $this->objectManager->setGodMode(false);
            $this->player->setGodLook(false);
            $this->demo = true;
            $this->demoFrames = 0;
            $this->demoClock = 0;
            $this->demoPrevious = 0xFF;
            $this->objectManager->resetGame();
            $this->levelManager->firstLevel();
            $this->startLevel();
        });
        $this->screens->on('mapDone', fn() => $this->playLevelMusic(true));
        $this->screens->on('versusStart', fn() => $this->startVersus());
        $this->screens->setOnlineView(fn() => $this->online);
        $this->screens->on('online', fn(string $action) => $this->onlineAction($action));
        $this->screens->on('codeDone', function (string $code) {
            if (strlen($code) === 4) $this->connectOnline($code);
            $this->screens->showTitle('online');
        });
        $this->screens->on('chatDone', function (string $text) {
            $this->online?->sendChat($text);
            // back on PARLER (the host's first entry is LANCER)
            $this->screens->showTitle('online', $this->online?->isHost() && $this->online->status === 'lobby' ? 1 : 0);
        });
        $this->screens->on('playerDone', function (string $name) {
            $then = $this->afterPlayerName;
            $this->afterPlayerName = null;
            if (trim($name) !== '') $this->options->setOnlineName(Online::cleanName($name, 0));
            if ($then !== null && $this->options->getOnlineName() !== '') {
                $this->onlineAction($then);
                if ($then === 'join') return;   // the code is asked now
            }
            $this->screens->showTitle('online');
        });
        // a new best time without a name yet: the name is asked after the tally (or the Sky Base 3 teleporter)
        $this->screens->on('nameDone', function (string $name) {
            $this->playerName = $name;
            if ($this->pendingName !== null) {
                $this->bestTimes->setName($this->pendingName[0], $this->pendingName[1], $name);
            }
            $this->pendingName = null;
            $then = $this->afterName;
            $this->afterName = null;
            $then && $then();
        });
        $this->screens->on('actCompleteDone', fn() => $this->askNameThen(fn() => $this->afterActComplete()));
    }

    /** Shows the name entry when a best time is waiting for it, then goes on with $then. */
    private function askNameThen(\Closure $then): void
    {
        if ($this->pendingName === null) {
            $then();
            return;
        }
        [$act, $index, $time] = $this->pendingName;
        $this->afterName = $then;
        $this->screens->showNameEntry(BestTimes::place($act), $index + 1, BestTimes::format($time));
    }

    /** After the tally: the next special stage, the act after a transporter room, or the next act. */
    private function afterActComplete(): void
    {
        $this->actTimeBefore = 0;
        $this->objectManager->setScore($this->screens->getScore());
        $current = $this->levelManager->getCurrentLevel()->getLevel();
        // _1fa9: the sign showed "!": the next special stage ($D23F) is played, then the next act
        if ($this->objectManager->takeSpecialStage() && $this->nextSpecialStage <= 35) {
            $this->specialReturn = $this->warpFrom ?? $current;
            $this->warpFrom = null;
            $this->levelManager->goToLevel($this->nextSpecialStage++);
            $this->startLevel();
            return;
        }
        if ($this->specialReturn !== null && $current >= 28) {
            $this->levelManager->goToLevel($this->specialReturn);
            $this->specialReturn = null;
        }
        // after a transporter room the game goes on from the act that led there
        if ($this->warpFrom !== null) {
            $this->levelManager->goToLevel($this->warpFrom);
            $this->warpFrom = null;
        }
        $this->levelManager->nextLevel();
        $this->startLevel();
    }

    private function registerLevelEvents(): void
    {
        // Sky Base 3 teleporter ($FF): fade out, then the next level (the end) without tally
        $this->objectManager->on('teleportEnd', fn() => $this->fadeLevelOut(0, fn() => $this->askNameThen(function () {
            $this->actTimeBefore = 0;
            $this->warpFrom = null;
            $this->levelManager->nextLevel();
            $this->startLevel();
        })));
        // Scrap Brain transporter: the target room starts at once, without tally
        $this->objectManager->on('warp', function (int $level) {
            $this->actTimeBefore += $this->objectManager->getLevelTime();
            $this->warpFrom ??= $this->levelManager->getCurrentLevel()->getLevel();
            $this->levelManager->goToLevel($level);
            $this->playLevelMusic(true);
        });
    }

    /** fadeOut over the level ($0A6E: 4 steps of 10 frames, the game stopped), then $black frames, then $then. */
    private ?array $levelFade = null;
    private float $levelFadeClock = 0;

    private function fadeLevelOut(int $black, \Closure $then): void
    {
        if ($this->levelFade !== null) return;
        $this->levelFade = [0, $black, $then];
        $this->levelFadeClock = 0;
    }

    /** Darkness of the level fade, 0 (none) to 3 (black). */
    private function levelFadeStep(): int
    {
        return $this->levelFade === null ? 0 : min(3, intdiv($this->levelFade[0], Screens::FADE_STEP) + 1);
    }

    /** Back to the title screen after the demo (sound and screen faded out, _20b8), with a new game. */
    private function endDemo(): void
    {
        if ($this->levelFade !== null) return;
        $this->soundManager->stopMusic();
        $this->fadeLevelOut(0, fn() => $this->closeDemo());
    }

    /**
     * Multiplayer (title menu > MULTIJOUEUR > LANCER): the act of the menu from its start, Sonic and the computer
     * Sonics of Versus, infinite lives (a death is not a game over), no map, no tally.
     */
    /** Menu EN LIGNE: 'public', 'create', 'join', 'name', 'chat', 'leave', 'start' (the host). */
    private function onlineAction(string $action): void
    {
        // the others see a name: asked before the first room
        if (in_array($action, ['public', 'create', 'join'], true) && $this->options->getOnlineName() === '') {
            $this->afterPlayerName = $action;
            $this->screens->showPlayerEntry($this->playerName ?? '');
            return;
        }
        switch ($action) {
            case 'name':
                $this->screens->showPlayerEntry($this->options->getOnlineName());
                break;
            case 'public':
                $this->connectOnline(null, true);
                break;
            case 'chat':
                if ($this->online !== null) $this->screens->showChatEntry();
                break;
            case 'create':
                $this->connectOnline(null);
                break;
            case 'join':
                $this->screens->showCodeEntry();
                break;
            case 'leave':
                $this->leaveOnline();
                break;
            case 'start':
                if ($this->online?->isHost() && $this->online->status === 'lobby') {
                    $mode = $this->options->getVersusMode();
                    $this->online->startMatch(Versus::startConfig($this->online, $mode, $this->options->getVersusLevel(),
                        $this->options->getVersusDifficulty(),
                        $mode === 'flag' ? 2 * $this->options->getVersusTeamSize() : $this->options->getVersusPlayers()));
                }
                break;
        }
    }

    private function connectOnline(?string $code, bool $public = false): void
    {
        $this->leaveOnline();
        // browser: WebRTC between the players, the server relays for those it can't link (php-wasm-network)
        $transport = Online::isBrowser() ? 'webrtc' : 'ws';
        $this->online = new Online(Online::serverUrl(), $transport, $this->options->getOnlineName(), $code, $public);
        $this->online->info = Versus::MODES[$this->options->getVersusMode()] . '  ' . Versus::levelName($this->options->getVersusLevel());
    }

    private function leaveOnline(): void
    {
        $this->online?->close();
        $this->online = null;
    }

    /** Every frame: the session; a match started by the host starts here too. */
    private function pollOnline(): void
    {
        if ($this->online === null) return;
        $this->online->poll();
        $start = $this->online->start;
        if ($start !== null && $start !== $this->versusConfig && $this->online->status === 'match') {
            $this->screens->close();
            $this->startVersus($start);
        }
    }

    /** Online match: a key of the chat line (Enter sends it, Escape gives it up). */
    private function typeMatchChat(InputKeyboard $keyboard): void
    {
        $draft = $this->versus->chatDraft;
        if ($keyboard->isKeyPressed(\SDLK_ESCAPE)) {
            $this->versus->chatDraft = null;
            return;
        }
        if ($keyboard->isKeyPressed(\SDLK_RETURN)) {
            $this->online?->sendChat($draft);
            $this->versus->chatDraft = null;
            return;
        }
        if ($keyboard->isKeyPressed(\SDLK_BACKSPACE)) $draft = substr($draft, 0, -1);
        foreach (array_merge(range(ord('a'), ord('z')), range(ord('0'), ord('9')), [ord('.'), ord('-'), ord(' ')]) as $code) {
            if ($keyboard->isKeyPressed($code) && strlen($draft) < Online::CHAT_LENGTH && !($code === ord(' ') && $draft === '')) {
                $draft .= strtoupper(chr($code));
            }
        }
        $this->versus->chatDraft = $draft;
    }

    /** $net: the START of an online match (VersusNetwork::startConfig); null: against the computer. */
    private function startVersus(?array $net = null): void
    {
        $this->clearVersus();
        $this->versusConfig = $net;
        $this->actTimeBefore = 0;
        $this->warpFrom = null;
        $this->specialReturn = null;
        $this->objectManager->resetGame();
        $this->objectManager->setCheats(['lives' => true]);
        $this->objectManager->setGodMode(false);
        $this->player->setGodLook(false);
        $this->objectManager->noEnemies = true;
        $level = $net !== null ? (int)$net['level'] : $this->options->getVersusLevel();
        if ($level !== $this->levelManager->getCurrentLevel()->getLevel()) {
            $this->levelManager->goToLevel($level);
        } else {
            $this->levelReload($level);
        }
        $this->player->resetForStart();
        $this->versus = new Versus($this->sdl, $this->collisionSystem, $this->objectManager, $this->scene->getCamera(),
            $this->player, $net['mode'] ?? $this->options->getVersusMode(),
            $net['players'] ?? ($this->options->getVersusMode() === 'flag' ? 2 * $this->options->getVersusTeamSize() : $this->options->getVersusPlayers()),
            $net['difficulty'] ?? $this->options->getVersusDifficulty(),
            $net !== null ? $this->online : null, $net);
        $this->versus->on('sfx', fn(int $index) => $this->soundManager->playSfx($index));
        $this->versus->on('music', fn(string $name, bool $loop) => $this->soundManager->playMusic($name, $loop, true));
        $this->scene->worldOverlay = fn(float $x, float $y) => $this->versus?->drawWorld($x, $y);
        $this->playLevelMusic(true);
    }

    private function clearVersus(): void
    {
        $this->versus?->close();
        $this->versus = null;
        $this->player->setSheet('sonic');
        $this->scene->worldOverlay = null;
        $this->scene->playerBlink = false;
        $this->objectManager->ringWrap = true;
        $this->objectManager->keepTilemap = false;
        $this->objectManager->noEnemies = false;
    }

    /** Results seen: back to the multiplayer page of the menu. */
    private function endVersus(): void
    {
        $online = $this->versusConfig !== null && $this->online !== null;
        if ($online && $this->online->start === $this->versusConfig) $this->online->endMatch();
        $this->versusConfig = null;
        $this->backToTitle();
        $this->screens->showTitle($online ? 'online' : 'multi');
    }

    /** Escape during a game: it is abandoned, back to the main menu with a new game ready (like a game over). */
    private function backToTitle(): void
    {
        $this->clearVersus();
        $this->levelFade = null;   // a death / end-of-demo fade must not go on behind the menu
        $this->soundManager->stopSfx(SoundManager::SFX_SIGN_SPIN);
        $this->demo = false;
        $this->pendingName = null;
        $this->afterName = null;
        $this->actTimeBefore = 0;
        $this->warpFrom = null;
        $this->specialReturn = null;
        $this->nextSpecialStage = 28;
        $this->objectManager->resetGame();
        $this->levelManager->firstLevel();
        $this->levelReload(1);
        $this->player->setVelocity(0, 0);
        $this->player->setAcceleration(0, 0);
        $this->screens->close();
        $this->screens->showTitle();
    }

    private function closeDemo(): void
    {
        $this->demo = false;
        $this->soundManager->stopMusic();
        $this->objectManager->resetGame();
        $this->levelManager->firstLevel();
        // the demo played act 1: load it again from the start
        $this->levelReload(1);
        $this->player->setVelocity(0, 0);
        $this->player->setAcceleration(0, 0);
        $this->screens->showTitle();
    }

    /** Joypad of the demo for this frame (60Hz). */
    private function playDemo(float $deltaTime): void
    {
        $this->demoClock += $deltaTime;
        while ($this->demoClock >= 1 / 60) {
            $this->demoClock -= 1 / 60;
            $this->demoFrames++;
        }
        $pad = self::DEMO_INPUT[intdiv($this->demoFrames, 32)] ?? null;
        if ($pad === null || $this->objectManager->isPlayerDead()) {
            $this->endDemo();
            return;
        }
        $locked = $this->objectManager->isLevelComplete() || $this->objectManager->isCutscene();
        if (!$locked) {
            if (!($pad & 0x04)) $this->player->moveLeft();
            if (!($pad & 0x08)) $this->player->moveRight();
            if (!($pad & 0x01)) $this->player->watchUp();
            if (!($pad & 0x02)) $this->player->roll();
        }
        $button = ($pad & 0x30) !== 0x30;
        $wasButton = ($this->demoPrevious & 0x30) !== 0x30;
        if ($button && !$wasButton && !$locked) $this->player->jump();
        if (!$button && $wasButton) $this->player->jumpRelease();
        $this->objectManager->setButtonHeld($button);
        $this->demoPrevious = $pad;
    }

    /** Act completed: its time goes to the best times (not with cheats); the page will open on this act. */
    private function recordActTime(): void
    {
        if ($this->demo || $this->objectManager->hasCheats()) return;
        $level = $this->levelManager->getCurrentLevel()->getLevel();
        $time = $this->actTimeBefore + $this->objectManager->getLevelTime();
        $added = $this->bestTimes->add($level, $time, $this->playerName ?? '');
        if ($added === null) return;
        [$act, $index] = $added;
        $this->screens->showTimesOf($act);
        // among the 5 best and no name yet this session: asked after the tally
        if ($index !== null && $this->playerName === null) {
            $this->pendingName = [$act, $index, $time];
        }
    }

    /**
     * New game from the title menu: the cheats of the options (invincible = F5 for the whole game, infinite
     * lives / continues, 50 rings, turbo, shield, 6 emeralds) and the act chosen to start with.
     */
    private function startGameCheats(): void
    {
        $this->objectManager->setCheats($this->options->getCheats());
        $this->objectManager->startGameCheats();
        if ($this->options->isCheatOn('invincible')) {
            $this->objectManager->setGodMode(true);
            $this->player->setGodLook(true);
        }
        $start = $this->options->getStartLevel();
        if ($start !== $this->levelManager->getCurrentLevel()->getLevel()) {
            $this->levelManager->goToLevel($start);
        }
    }

    /**
     * Ghost: Sonic's run is recorded on the level time (not in the demo, the special stages or while dying),
     * and the best run of the level is drawn when the option is on.
     */
    private function updateGhost(): void
    {
        $level = $this->levelManager->getCurrentLevel()->getLevel();
        $time = $this->objectManager->getLevelTime();
        $played = !$this->demo && !$this->versus && $level < 28 && $this->scene->getCurrentLevel() === $this->levelManager->getCurrentLevel();
        // runs with cheats are not recorded
        if ($played && !$this->objectManager->hasCheats() && !$this->objectManager->isPlayerDead() && !$this->objectManager->isLevelComplete()) {
            $this->ghost->record($level, $time, $this->player->getX(), $this->player->getY(), $this->player->getFrameSnapshot());
        }
        $this->scene->setGhostFrame($played && $this->options->isGhostEnabled() ? $this->ghost->frameAt($level, $time) : null);
    }

    /** F6 panel, in window pixels: level, Sonic's position (pixels and blocks), speed, camera, state, time. */
    private function drawCoordsPanel(int $windowHeight): void
    {
        $size = max(12, (int)round($windowHeight / 36));
        $x = (int)round($this->player->getX());
        $y = (int)round($this->player->getY());
        [$vx, $vy] = $this->player->getVelocity();
        $camera = $this->scene->getCamera();
        $time = $this->objectManager->getLevelTime();
        $level = $this->levelManager->getCurrentLevel()->getLevel();
        $best = $this->ghost->bestTime($level);
        $lines = [
            'FPS ' . $this->fpsShown,
            'NIVEAU ' . $level,
            'X ' . $x . '   Y ' . $y,
            sprintf('BLOC %d,%d  (%02X,%02X)', intdiv($x, 32), intdiv($y, 32), intdiv($x, 32), intdiv($y, 32)),
            sprintf('VX %d  VY %d', (int)round($vx / 60 * 256), (int)round($vy / 60 * 256)),
            'CAM ' . $camera->getX() . ',' . $camera->getY(),
            'ETAT ' . strtoupper($this->player->getState()),
            sprintf('TEMPS %d:%05.2F', intdiv((int)$time, 60), fmod($time, 60)),
        ];
        if ($best !== null) $lines[] = sprintf('GHOST %d:%05.2F', intdiv((int)$best, 60), fmod($best, 60));
        $line = (int)round($size * 1.3);
        $width = max(array_map(fn($l) => $this->text->width($l, $size), $lines));
        $this->text->panel(8, 8, $width + $size * 2, count($lines) * $line + $size);
        foreach ($lines as $i => $text) {
            $this->text->draw($text, 8 + $size, 8 + (int)($size / 2) + $i * $line, $size, $i === 2 ? [255, 255, 0] : [255, 255, 255]);
        }
    }

    /** Shows the map before the acts that have one (levels 1-18), the level starts when it ends. */
    private function startLevel(): void
    {
        $level = $this->levelManager->getCurrentLevel()->getLevel();
        if ($this->screens->hasMap($level)) {
            $this->screens->showMap($level, $this->objectManager->getLives());
        } else {
            $this->playLevelMusic(true);
        }
    }

    /**
     * Music of the current level from its header (MU byte, index into the driver's song list),
     * or the invincibility / boss song while they are active ($FF = keep the current song).
     */
    private function playLevelMusic(bool $restart = false): void
    {
        if ($this->objectManager->isInvincible()) {
            $this->soundManager->playMusic('invincibility', true, $restart);
        } elseif ($this->objectManager->isBossFight()) {
            $this->soundManager->playMusic('boss', true, $restart);
        } else {
            $index = $this->levelManager->getCurrentLevel()->getMeta('MU', 0);
            if ($index === 0xFF) return;
            $name = SoundManager::MUSIC_BY_INDEX[$index] ?? null;
            if ($name) $this->soundManager->playMusic($name, true, $restart);
        }
    }

    private function levelReload(int $level)
    {
        $this->scene->resetLevel();
        $this->objectManager->resetForNewLevel();
        dump('Loaded level : ' . $level);
    }


    public function run(): void
    {
        $vars = [] ;
        // Init SDL
        // TODO : voir vsync ce qu'on fait.
        // $this->sdl->initSDL(fullscreen: false, title: 'SonicGame',width:240,height:226,vsync: true);
		$this->sdl->initSDL(fullscreen: false, title: 'SonicGame',width:840,height:426,vsync: true);

        $this->soundManager->Init();
        $this->screens->setSoundManager($this->soundManager);

        // Init Textures

		$this->player->initTexture($this->sdl);
        $sprites = [
            'objects' => ['r' => 170, 'g' => 0, 'b' => 170],
            'enemies' => ['r' => 170, 'g' => 0, 'b' => 170],
            'eggman' => ['r' => 0, 'g' => 170, 'b' => 255],
            'sonic' => ['r' => 0, 'g' => 72, 'b' => 0],
            'flower' => ['r' => 170, 'g' => 0, 'b' => 170],   // background tiles (tools/flowers.py), opaque
            'rom' => ['r' => 170, 'g' => 0, 'b' => 170],      // sprites rendered from the ROM (tools/romsprites.py)
            'skyglass' => ['r' => 170, 'g' => 0, 'b' => 170], // Sky Base boss broken glass (tools/skybaseglass.py)
            'boss' => ['r' => 170, 'g' => 0, 'b' => 170],     // act 3 bosses from the ROM layouts (tools/bosssprites.py)
        ];
        foreach ($sprites as $name => $key) {
            if ($name !== 'sonic') {
                $this->sdl->loadTexture($name, 'tileset/sprites/tileset-' . $name . '.png', $key);
            }
            // underwater palette (Labyrinth), see tools/waterpalette.py
            $this->sdl->loadTextureVariant($name, 'tileset/sprites/tileset-' . $name . '.png', '-water', $key);
            // boss hit: outlines flashing white
            $this->sdl->loadTextureVariant($name, 'tileset/sprites/tileset-' . $name . '.png', '-flash', $key);
        }
        $this->sdl->loadFont('sonic','fonts/NiseSegaSonic.TTF') ;
        $this->screens->loadTextures();

        $this->levelManager->loadLevels();
        $this->registerEvents();
        $this->registerLevelEvents();
        $this->screens->showTitle();
        $frameDuration = 0 ; // 1 / 60; // 60Hz
        $inputDuration = 0 ; // 1 / 600; // 240Hz


//		$window = new \Vrzno;
//		$response = vrzno_await($window->fetch('https://api.weather.gov/gridpoints/TOP/40,74/forecast'));
//		$json = vrzno_await($response->json());
//		// Module.getValue is not a function ...
		// non on px pas utilisé vrzno il utilise php-wasm (phpbase.js entre autre)
//		var_dump($json);
//		dump($window);

        $vars['fps'] = 0;
        $vars['deltaSum'] = 0.0;
        $vars['lastTime'] = microtime(true);


        // Boucle principale
        $this->gameLoop->addPeriodicTimer(1, function (TimerInterface $timer) use (&$vars) {
            $deltaMoyen = $vars['fps'] > 0 ? $vars['deltaSum'] / $vars['fps'] : 0;
            echo sprintf("FPS réel : %d | Δ moyen : %.3f ms", $vars['fps'], $deltaMoyen * 1000) . PHP_EOL;
            $this->fpsShown = $vars['fps'];
            $vars['fps'] = 0;
            $vars['deltaSum'] = 0.0;
        });

        // Nettoyage périodique des anciennes tiles de collision (toutes les 100 ms)
        $this->gameLoop->addPeriodicTimer(0.1, function () {
            $this->scene->cleanupOldCollisionTiles();
        });

//		$this->gameLoop->addPeriodicTimer(1/150, function (TimerInterface $timer) use (&$vars) {
//			$this->player->moveRight();
//		});

        $closureInputs = function() use (&$vars)
        {
            $this->pollOnline();

            $this->inputManager->poll();

            // Force emit keyPress to have key with $inputDuration
            if ($this->inputManager->getKeyboard()->haveOneKeyPressed()) {
                // get the last key pressed
                $keyPressed = $this->inputManager->getKeyboard()->getLastKeyPressed();
                $this->inputManager->emit('keyPress', [$this->inputManager->getKeyboard(), $keyPressed]);
            }

            // Same with Touchpad
            if ( ($this->inputManager->getTouchpad()->haveOneFingerHelded()) || $this->inputManager->getTouchpad()->isActionPressed('jump'))
			{
				// get the last key pressed
				 $fingerId = $this->inputManager->getTouchpad()->getLastFingerPressed();

				 if ($fingerId == null) // jump
					 $fingerId = 0 ;

				 $this->inputManager->emit('touchPressed', [$this->inputManager->getTouchpad(), $fingerId]);
			}

			$this->inputManager->getKeyboard()->resetTransientStates();
            $this->inputManager->getTouchpad()->resetTransientStates();

        };
        $closureDisplay = function($deltaTime) use (&$vars)
        {

			if (!$this->disableSdl) {

			$this->updateView();
			if ($this->screens->isActive()) {
				$this->renderScreens();
				return;
			}
			\SDL_SetRenderTarget($this->sdl->getRenderer()->getRenderer(), $this->sdl->getRenderer()->getRenderTexture()); // draw to texture

            // Rendu de la scène
            $this->scene->setDebugMode($this->debugMode);
			$this->sdl->getRenderer()->clear(); // 1
            $this->sdl->getRenderer()->createScene(
                $this->scene,
                $this->player,
                $this->sdl->getFont('sonic'),
                $this->levelManager->getCurrentLevel()
            );
            // level fade: fade.png, black at 1/3, 2/3, 3/3
            if (($step = $this->levelFadeStep()) > 0) {
                $src = new \SDL_Rect;
                [$src->x, $src->y, $src->w, $src->h] = [($step - 1) * 8, 8, 8, 8];
                $dst = new \SDL_Rect;
                [$dst->x, $dst->y, $dst->w, $dst->h] = [0, 0, $this->sdl->getWindow()->getWidth(), $this->sdl->getWindow()->getHeight()];
                \SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(), $this->sdl->getTextures('screen-fade')[0]['texture'], $src, $dst);
            }

			// TODO : voir pk en web fatal error sdl setrendertarget supplied resource is not a valid sdl texture resource ?? =>
				$width = $height = 0 ;

				\SDL_SetRenderTarget($this->sdl->getRenderer()->getRenderer(),NULL); // draw to window
				\SDL_GetRendererOutputSize($this->sdl->getRenderer()->getRenderer(),$width,$height);

				$scaleX = $width / $this->sdl->getWindow()->getWidth();
				$scaleY = $height / $this->sdl->getWindow()->getHeight();

				$scale = min($scaleX, $scaleY);
				$outputW = (int)($this->sdl->getWindow()->getWidth() * $scale);
				$outputH = (int)($this->sdl->getWindow()->getHeight() * $scale);
				$offsetX = (int)(($width - $outputW) / 2);
				$offsetY = (int)(($height - $outputH) / 2);

				$dst = new \SDL_Rect;
				$dst->x = $offsetX;
				$dst->y = $offsetY;
				$dst->w = $outputW;
				$dst->h = $outputH;

				$this->sdl->getRenderer()->setColor(0, 0, 0, 255);   // borders (256x192 mode, rounding)
				$this->sdl->getRenderer()->clear();
				\SDL_RenderCopy($this->sdl->getRenderer()->getRenderer(),$this->sdl->getRenderer()->getRenderTexture(), null, $dst); // on copie la texture dans le renderer
				if ($this->versus && !$this->screens->isActive()) {
					$this->versus->drawOverlay($this->text, $dst, $scale, $height);
				}
				if ($this->options->isCrtEnabled()) {
					$this->crt ??= new CrtFilter($this->sdl->getRenderer());
					$this->crt->draw($dst, $this->sdl->getWindow()->getHeight());
				}
				if ($this->coordsPanel && !$this->screens->isActive()) {
					$this->drawCoordsPanel($height);
				}
				$this->sdl->getRenderer()->present();

			// SDL_HINT_RENDER_SCALE_QUALITY ?


			}
		} ;


    

        // one game frame every 1/120 s at most (the vsync of the window paces it); the browser (wasm) calls
        // the loop once per requestAnimationFrame and defines a shorter interval: one game frame per call
        $frameInterval = defined('SONIC_FRAME_INTERVAL') ? (float)constant('SONIC_FRAME_INTERVAL') : 1 / 120;
        $this->gameLoop->addPeriodicTimer($frameInterval, function (TimerInterface $timer) use ($closureDisplay, $closureInputs,&$vars) {

			$now = microtime(true);
			$deltaTime = $now - $vars['lastTime'];
			$vars['lastTime'] = $now;
			++$vars['fps'];
			$vars['deltaSum'] += $deltaTime;
			// Options > VITESSE: PAL (Europe, 50 Hz) = the whole game at 50/60 of the NTSC speed
			$deltaTime *= $this->options->getTimeScale();

            // $deltaTime = 0.01 ; // Pour debug

			$closureInputs(); // GET Inputs
			// the debug views (F1) show the blocks touched by Sonic
			$this->collisionSystem->reportTiles = $this->debugMode !== 0;
			$this->soundManager->update();

			// the level is fading out: the game waits (fadeOut runs between interrupts only)
			if ($this->levelFade !== null && !$this->screens->isActive()) {
				$this->levelFadeClock += $deltaTime;
				while ($this->levelFade !== null && $this->levelFadeClock >= 1 / 60) {
					$this->levelFadeClock -= 1 / 60;
					if (++$this->levelFade[0] >= 4 * Screens::FADE_STEP + $this->levelFade[1]) {
						$then = $this->levelFade[2];
						$this->levelFade = null;
						$then();
					}
				}
				$this->buttonPressed = false;
				$closureDisplay($deltaTime);
				return;
			}
			$this->levelFade = null;

			// demo mode: the joypad of the ROM (may end the demo and go back to the title screen)
			if ($this->demo && !$this->screens->isActive() && $this->scene->getCurrentLevel()) {
				$this->playDemo($deltaTime);
			}

			if ($this->screens->isActive()) {
				$this->screens->update($deltaTime, $this->buttonPressed);
				$this->buttonPressed = false;
				$closureDisplay($deltaTime);
				return;
			}

			if ($this->scene->getCurrentLevel() && $this->carryPlayer) {
                // debug (keypad 5): Sonic is carried at the centre of the free camera, no physics
                $camera = $this->scene->getCamera();
                $this->player->setXY(
                    $camera->getX() + $this->sdl->getWindow()->getWidth() / 2 - 12,
                    $camera->getY() + $this->sdl->getWindow()->getHeight() / 2 - 16
                );
                $this->player->setVelocity(0, 0);
                $this->player->setAcceleration(0, 0);
                $this->player->updateSprite($deltaTime);
                $this->objectManager->update($deltaTime, $this->player, $this->scene->getCurrentLevel());
			} elseif ($this->scene->getCurrentLevel()) {
                // after the sign: the joypad is forced to the right (flags6 bit 1)
                if ($this->objectManager->signRun($this->player)) {
                    $this->player->moveRight();
                }
                if (!$this->objectManager->isPlayerDead()) {
                    // after a slow frame the real delta can be large: move Sonic in steps of at most 1/120s
                    // so that he can't go through a floor or a wall in one go (seen in the bonus stages)
                    $steps = min(12, max(1, (int)ceil($deltaTime * 120 - 0.01)));
                    // the joypad of this frame holds for every step (update() clears the flag)
                    $moving = $this->player->isMovingFromInput;
                    $look = $this->player->getLookRequest();
                    for ($i = 0; $i < $steps; $i++) {
                        $this->player->isMovingFromInput = $moving;
                        $this->player->setLookRequest($look);
                        $this->player->update($deltaTime / $steps);
                        $this->collisionSystem->checkCollisions($this->player, $this->scene->getCurrentLevel(), $deltaTime / $steps);
                    }
                } else {
                    $this->player->updateSprite($deltaTime);
                }
                $this->objectManager->setRomCamera($this->scene->getCamera()->getRomCamera($this->player->getX(), $this->player->getY()));
                $window = $this->sdl->getWindow();
                $this->objectManager->setView($this->scene->getCamera()->getX(), $this->scene->getCamera()->getY(), $window->getWidth(), $window->getHeight());
                $this->objectManager->setWave($this->scene->getCamera()->getWave());
                $this->objectManager->update(($this->versus?->freezesObjects() ?? false) ? 0.0 : $deltaTime, $this->player, $this->scene->getCurrentLevel());
                $this->updateGhost();
                if ($this->versus) {
                    $this->versus->update($deltaTime, $this->scene->getCurrentLevel());
                    $this->scene->playerBlink = $this->versus->isHumanBlinking();
                }

                // speed shoes: the song plays faster while they last (the driver's tickDivider1 + 1)
                $this->soundManager->setFast($this->objectManager->hasSpeedShoes() && !$this->objectManager->isPlayerDead());

                // a jingle (emerald...) has finished: back to the level music
                if (!$this->objectManager->isPlayerDead() && !$this->objectManager->isLevelComplete()
                    && !$this->soundManager->isMusicPlaying()) {
                    $this->playLevelMusic();
                }

                // Boss arena: camera locked like the original, Sonic kept inside the arena
                $this->scene->getCamera()->lock = $this->objectManager->getCameraLock();
                if ($signLimits = $this->objectManager->getSignLimits()) {
                    $this->scene->getCamera()->applySignLimits($signLimits[0], $signLimits[1]);
                }
                if ($bounds = $this->objectManager->getPlayerBounds()) {
                    $x = $this->player->getX();
                    if ($x < $bounds[0] || $x > $bounds[1]) {
                        $this->player->setXY(max($bounds[0], min($bounds[1], $x)), $this->player->getY());
                        $this->player->setVelocity(0, $this->player->getVelocity()[1]);
                    }
                }

                // Level header limits: Sonic stays between LEFT + 8 and RIGHT + 224 (pushed by auto-scrolling)
                // and dies once he falls below the original screen
                $camera = $this->scene->getCamera();
                if (!$this->objectManager->isPlayerDead() && $this->scene->getCurrentLevel() === $this->levelManager->getCurrentLevel()
                    && !$this->objectManager->isSignRun()) {
                    [$minX, $maxX] = $camera->getPlayerLimits();
                    $x = $this->player->getCollisionRect()['x'];
                    if ($x < $minX || $x > $maxX) {
                        $this->player->setX(max($minX, min($maxX, $x)));
                        $this->player->setVelocity(0, $this->player->getVelocity()[1]);
                    }
                    if ($this->player->getCollisionRect()['y'] > $camera->getDeathLine($this->sdl->getWindow()->getHeight())) {
                        $this->objectManager->playerFellInPit($this->player);
                    }
                }
                $this->player->settleFrame();
			}
               


			$this->scene->getCamera()->update($deltaTime); // UpdateCamera

			$closureDisplay($deltaTime); // Update display

        });



    }

    // Renders your game objects here
    private function eventExitGame()
    {
        $this->gameLoop->stop();
    }

    private function eventTouchPressed(InputTouchpad $touchpad, int $fingerID)
	{
		$directions = ['left', 'right'] ; //, 'up', 'down'];
		$actions = ['jump', 'roll'];

		$inputTouchpad = $this->inputManager->getTouchpad();

		foreach ($directions as $dir) {
			if ($inputTouchpad->isActionHeld($dir)) {
                $this->player->move($dir);
				break;
			}
		}

		foreach ($actions as $action) {
			if ($inputTouchpad->isActionPressed($action)) {
				$this->player->action($action);
				break;
			}
		}


	}


    private function eventKeyPressed(InputKeyboard $keyboard, ?int $keyPressed)
    {
        if ($this->demo && ($keyboard->isKeyPressed(\SDLK_SPACE) || $keyboard->isKeyPressed(\SDLK_RETURN))) {
            $this->endDemo();
            return;
        }
        if ($this->screens->isActive()) {
            // title menu (start / options), or the on-screen keyboard of a new best time
            $naming = $this->screens->isNameEntry();
            // Enter: OK on the keyboard (Space types the key under the cursor)
            if ($keyboard->isKeyPressed(\SDLK_SPACE) || (!$naming && $keyboard->isKeyPressed(\SDLK_RETURN))) {
                $this->buttonPressed = true;
            }
            if ($naming && $keyboard->isKeyPressed(\SDLK_RETURN)) $this->screens->nameKey('ok');
            foreach ([\SDLK_UP => 'up', \SDLK_DOWN => 'down', \SDLK_LEFT => 'left', \SDLK_RIGHT => 'right'] as $key => $direction) {
                if (!$keyboard->isKeyPressed($key)) continue;
                $naming ? $this->screens->nameKey($direction) : $this->screens->menuInput($direction);
            }
            if ($naming) {
                // the real keyboard also types: A-Z, 0-9, backspace
                foreach (array_merge(range(ord('a'), ord('z')), range(ord('0'), ord('9')), [ord('.'), ord('-')]) as $code) {
                    if ($keyboard->isKeyPressed($code)) $this->screens->nameKey(chr($code));
                }
                if ($keyboard->isKeyPressed(\SDLK_BACKSPACE)) $this->screens->nameKey('back');
            }
            if ($keyboard->isKeyPressed(\SDLK_ESCAPE)) {
                // the main menu quits; its pages go back; the other screens (map, tally...) go to the menu
                match ($this->screens->back()) {
                    'quit' => $this->inputManager->emit('exitGame', []),
                    'title' => $this->backToTitle(),
                    default => null,
                };
            }
            if ($keyboard->isKeyPressed(\SDLK_F12)) {
                $this->sdl->getWindow()->toggleFullscreen();
            }
            return;
        }

        if ($this->demo) {
            // only F12 / Escape below the demo's own joypad
            if ($keyboard->isKeyPressed(\SDLK_ESCAPE)) $this->endDemo();
            if ($keyboard->isKeyPressed(\SDLK_F12)) $this->sdl->getWindow()->toggleFullscreen();
            return;
        }
        // online match: T or Enter opens a chat line (Sonic waits while it is typed)
        if ($this->versusConfig !== null && $this->online !== null && $this->versus !== null) {
            if ($this->versus->chatDraft !== null) {
                $this->typeMatchChat($keyboard);
                return;
            }
            if ($keyboard->isKeyPressed(ord('t')) || (!$this->versus->isOver() && $keyboard->isKeyPressed(\SDLK_RETURN))) {
                $this->versus->chatDraft = '';
                return;
            }
        }
        // multiplayer results: the button goes back to the menu
        if ($this->versus?->isOver() && ($keyboard->isKeyPressed(\SDLK_SPACE) || $keyboard->isKeyPressed(\SDLK_RETURN))) {
            $this->endVersus();
            return;
        }
        $this->objectManager->setButtonHeld($keyboard->isKeyHeld(\SDLK_SPACE));
        $this->player->resetIdle();
        $playerLocked = $this->objectManager->isPlayerDead() || $this->objectManager->isLevelComplete()
            || $this->objectManager->isCutscene() || ($this->versus?->isLocked() ?? false);

//        dump('KeyPress : ' . $keyPressed);

        // we can have multiple key pressed
        if ($playerLocked) {
            // no movement while dying or after the signpost
        }
        elseif ($keyboard->isKeyHeld(\SDLK_RIGHT) && $keyboard->isKeyHeld(\SDLK_LEFT))
        {
            // use the last key
            if ($keyboard->getLastKeyPressed() == \SDLK_RIGHT) {
                $this->player->moveRight();
                // Move the player to right
            }
            else {
                $this->player->moveLeft();
                // Move the player to left
            }
        }
        else {
            if ($keyboard->isKeyHeld(\SDLK_RIGHT)) {
                $this->player->moveRight();
                // Move the player to right
            }

            if ($keyboard->isKeyHeld(\SDLK_LEFT)) {
                $this->player->moveLeft();
                // Move the player to left
            }
        }


        // Write up&down like left&right
        if ($keyboard->isKeyHeld(\SDLK_UP) && $keyboard->isKeyHeld(\SDLK_DOWN))
        {
            // use the last key
            if ($keyboard->getLastKeyPressed() == \SDLK_UP) {
                $this->player->watchUp();
                // Move the player to up
            }
            else {
                $this->player->watchDown();
                // Move the player to down
            }
        }
        else {
            if ($keyboard->isKeyHeld(\SDLK_UP)) {
               $this->player->watchUp();
                // Move the player to up
            }

            if ($keyboard->isKeyHeld(\SDLK_DOWN)) {
                $this->player->roll();
            }
        }

        if ($keyboard->isKeyPressed(\SDLK_SPACE) && !$playerLocked)
        {
            $this->player->jump();
        }

        if ($keyboard->isKeyReleased(\SDLK_SPACE))
        {
            $this->player->jumpRelease();
        }

        if ($keyboard->isKeyPressed(\SDLK_F12))
        {
           $this->sdl->getWindow()->toggleFullscreen();
        }

        if ($keyboard->isKeyPressed(\SDLK_ESCAPE))
        {
            // back to the main menu (Escape there quits the game); an online match: the room is left
            if ($this->versusConfig !== null) {
                $this->leaveOnline();
                $this->versusConfig = null;
            }
            $this->backToTitle();
        }

        if ($keyboard->isKeyPressed(\SDLK_F1))
        {
            if ( ($this->debugMode == 0) || ($this->debugMode == 2) )
                $this->debugMode = 1 ;
            else
                $this->debugMode = 0 ;
        }

        if ($keyboard->isKeyPressed(\SDLK_F2))
        {
            if ( ($this->debugMode == 1) || ($this->debugMode == 0) )
                $this->debugMode = 2 ;
            elseif ($this->debugMode == 2)
                $this->debugMode = 3 ;
            elseif ($this->debugMode == 3)
                $this->debugMode = 4 ;
            elseif ($this->debugMode == 4)
                $this->debugMode = 2 ;

        }

        if ($keyboard->isKeyPressed(\SDLK_F5))
        {
            // debug: invincible against everything (enemies, traps, pits, drowning), Sonic with red eyes
            $this->player->setGodLook($this->objectManager->toggleGodMode());
        }

        if ($keyboard->isKeyPressed(\SDLK_F6))
        {
            // debug: Sonic's coordinates in a panel over the screen
            $this->coordsPanel = !$this->coordsPanel;
        }

        if ($keyboard->isKeyPressed(\SDLK_F4))
        {
            // next level, with its music
            $this->levelManager->nextLevel();
            $this->warpFrom = null;
            $this->playLevelMusic(true);
        }

        if ($keyboard->isKeyPressed(\SDLK_F3))
        {
            // prev level, with its music
            $this->levelManager->previousLevel();
            $this->warpFrom = null;
            $this->playLevelMusic(true);
        }

        // Free camera on the keypad (2 4 6 8), kept inside the map; 5 toggles "Sonic follows the camera"
        $camera = $this->scene->getCamera();
        $moves = [\SDLK_KP_2 => [0, 10], \SDLK_KP_8 => [0, -10], \SDLK_KP_4 => [-10, 0], \SDLK_KP_6 => [10, 0]];
        foreach ($moves as $key => [$dx, $dy]) {
            if ($keyboard->isKeyHeld($key)) {
                $camera->disableStick = true;
                $this->moveDebugCamera($dx, $dy);
            }
        }

        if ($keyboard->isKeyPressed(\SDLK_KP_5))
        {
            $this->carryPlayer = !$this->carryPlayer;
            // on: the camera stays where it is and carries Sonic; off: it follows Sonic again
            $camera->disableStick = $this->carryPlayer;
            if (!$this->carryPlayer) {
                $this->player->setVelocity(0, 0);
            }
        }

		if ($keyboard->isKeyPressed(\SDLK_KP_PLUS))
		{
			// zoom in: bigger pixels, fewer tiles
			$this->changeZoom(1);
		}

		if ($keyboard->isKeyPressed(\SDLK_KP_MINUS))
		{
			// zoom out: more tiles on screen
			$this->changeZoom(-1);
		}

		if ($keyboard->isKeyPressed(\SDLK_KP_MULTIPLY))
		{
			// the 256x192 screen of the Master System, scaled to fit (on / off)
			$this->smsView = !$this->smsView;
		}
    }

    private function changeZoom(int $step): void
    {
        if ($this->smsView) {
            $this->smsView = false;   // leave the 256x192 screen at the zoom it looks like
            $this->zoomOffset = $this->zoomIndexFor($this->outputHeight() / 192) - $this->zoomIndexFor($this->outputHeight() / $this->viewHeight);
            return;
        }
        $auto = $this->zoomIndexFor($this->outputHeight() / $this->viewHeight);
        $this->zoomOffset = max(-$auto, min(count(self::ZOOMS) - 1 - $auto, $this->zoomOffset + $step));
    }

    /**
     * Title, menus, tallies...: drawn straight on the window at its own resolution (whole scale of 256x192):
     * through the game view (195 lines on a phone) the texts were drawn 8 px high, then stretched.
     */
    private function renderScreens(): void
    {
        $renderer = $this->sdl->getRenderer()->getRenderer();
        $width = $height = 0;
        \SDL_SetRenderTarget($renderer, null);
        \SDL_GetRendererOutputSize($renderer, $width, $height);
        $this->sdl->getRenderer()->setColor(0, 0, 0, 255);
        $this->sdl->getRenderer()->clear();
        $this->text->onWindow = true;
        $this->screens->render($width, $height);
        $this->text->onWindow = false;
        if ($this->options->isCrtEnabled()) {
            $dst = new \SDL_Rect;
            [$dst->x, $dst->y, $dst->w, $dst->h] = [0, 0, $width, $height];
            $this->crt ??= new CrtFilter($this->sdl->getRenderer());
            $this->crt->draw($dst, $this->sdl->getWindow()->getHeight());
        }
        $this->sdl->getRenderer()->present();
    }

    private function zoomIndexFor(float $zoom): int
    {
        $best = 0;
        foreach (self::ZOOMS as $i => $z) {
            if (abs($z - $zoom) < abs(self::ZOOMS[$best] - $zoom)) {
                $best = $i;
            }
        }
        return $best;
    }

    private function outputHeight(): int
    {
        $width = $height = 0;
        \SDL_GetRendererOutputSize($this->sdl->getRenderer()->getRenderer(), $width, $height);
        return max(1, $height);
    }

    /**
     * Sizes the game view to the window: same ratio as the window (more tiles on a wide screen, never
     * stretched sprites), or exactly 256x192 in Master System mode (black borders).
     */
    private function updateView(): void
    {
        $width = $height = 0;
        \SDL_GetRendererOutputSize($this->sdl->getRenderer()->getRenderer(), $width, $height);
        if ($width <= 0 || $height <= 0) return;
        if ($this->smsView) {
            [$viewW, $viewH] = [256, 192];
        } else {
            $auto = $this->zoomIndexFor($height / $this->viewHeight);
            $index = max(0, min(count(self::ZOOMS) - 1, $auto + $this->zoomOffset));
            $zoom = self::ZOOMS[$index];
            [$viewW, $viewH] = [(int)round($width / $zoom), (int)round($height / $zoom)];
        }
        $window = $this->sdl->getWindow();
        if ($viewW === $window->getWidth() && $viewH === $window->getHeight()) return;
        $window->setViewSize($viewW, $viewH);
        $this->sdl->getRenderer()->resizeTarget($viewW, $viewH);
        $this->scene->getCamera()->noSmooth = true;   // re-centre at once in the new view
    }

    /** Free debug camera, kept inside the map. */
    private function moveDebugCamera(int $dx, int $dy): void
    {
        $camera = $this->scene->getCamera();
        $level = $this->levelManager->getCurrentLevel();
        $maxX = max(0, $level->getMapWidth() * 32 - $this->sdl->getWindow()->getWidth());
        $maxY = max(0, $level->getMapHeight() * 32 - $this->sdl->getWindow()->getHeight());
        $camera->setXY(max(0, min($maxX, $camera->getX() + $dx)), max(0, min($maxY, $camera->getY() + $dy)));
    }


}
