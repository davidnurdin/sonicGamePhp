<?php

namespace SonicGame\Multiplayer;

use Evenement\EventEmitter;
use SonicGame\Entities\GameObjectManager;
use SonicGame\Entities\Physics\CollisionSystem;
use SonicGame\Entities\Player;
use SonicGame\Level\Level;
use SonicGame\Renderer\Sdl;
use SonicGame\Renderer\TextRenderer;
use SonicGame\Scene\Camera;
use SonicGame\SoundManager\SoundManager;

/**
 * Multiplayer match against computer Sonics (title menu > MULTIJOUEUR), on one act:
 *  - race ('COURSE', 2-5 Sonics): the first one at the finish line (where the sign was) wins;
 *  - flag ('DRAPEAU', 1 to 3 blue against as many red): each team has a base at one end of the act, a flag
 *    appears half-way; it has to be taken and brought back to the base of the team. Its carrier is slower and
 *    drops it when hit. 3 flags brought back win (3 minutes at most: the most flags);
 *  - rings ('ANNEAUX', 2-5): 3 minutes, every ring taken (or lost) comes back somewhere else, the most rings wins;
 *  - deathmatch (2-5): 3 minutes, the most knock-outs wins.
 * A Sonic jumping or rolling into an opponent that doesn't hurts it: it loses its rings, or, without rings,
 * it is knocked out (race: back to the start; flag: near its base; deathmatch: somewhere else; rings: just
 * thrown back).
 * Flag mode: only the other team can be hurt. Both attacking: they bounce off each other.
 *
 * The human is the first Rival (his Player, GameObjectManager for his rings, hurt, deaths); the others are
 * Players of their own, moved by a BotBrain with the same physics and CollisionSystem, drawn with a sheet of
 * another colour (tools/soniccolors.py). They ignore the objects (except what they stand on). The act has no
 * badniks and no end sign (GameObjectManager::$noEnemies): the race ends at a finish line drawn here.
 *
 * Events: 'sfx' [index], 'music' [name, loop].
 */
class Versus extends EventEmitter
{
    public const MODES = ['race' => 'COURSE', 'flag' => 'DRAPEAU', 'rings' => 'ANNEAUX', 'deathmatch' => 'DEATHMATCH'];
    /** Acts that can be played: 1 and 2 of each zone (the third one is the boss). */
    public const LEVELS = [1, 2, 4, 5, 7, 8, 10, 11, 13, 14, 16, 17];
    private const ZONES = ['GREEN HILL', 'BRIDGE', 'JUNGLE', 'LABYRINTH', 'SCRAP BRAIN', 'SKY BASE'];
    public const TIME_LIMIT = 180;
    /** Rings mode: a fall this long after a hit / bump gives the rings to the one who pushed. */
    private const PUSH_TIME = 5.0;
    /** Flag mode: flags to bring back to win, seconds before a dropped flag can be taken, carrier speed. */
    private const FLAG_CAPTURES = 3;
    private const FLAG_DROP_DELAY = 1.0;
    private const CARRIER_SPEED = 0.75;
    /** Flag mode: top speed of the bots (Sonic: 500 px/s), slower than in a race. */
    private const BOT_FLAG_SPEED = 500 * 0.6;
    /** Flag mode: a defender leaves its base for an opponent carrying the flag this close to it. */
    private const DEFEND_RADIUS = 900;
    /** Flag mode: Sonics (re)appear around their base, this far at most. */
    private const BASE_RADIUS = 640;
    private const COUNTDOWN = 3.0;
    /** Race: top speed of the bots (Sonic: 500 px/s), and far ahead of the human. */
    private const BOT_TOP_SPEED = 500 * 0.72;
    private const BOT_TOP_SPEED_STEP = 500 * 0.04;
    private const RUBBER_BAND = 1200;
    /** Race: once the first Sonic has arrived, the others have this long. */
    private const RACE_GRACE = 30.0;
    /** [sheet, colour of the name] of each player in the modes without teams. */
    private const COLOURS = [
        ['sonic', [85, 170, 255]], ['sonic-red', [255, 85, 85]], ['sonic-green', [85, 255, 85]],
        ['sonic-yellow', [255, 255, 85]], ['sonic-purple', [200, 130, 255]], ['sonic-orange', [255, 170, 0]],
    ];
    private const TEAM_COLOURS = [[85, 170, 255], [255, 85, 85]];
    private const TEAM_NAMES = ['BLEUS', 'ROUGES'];

    /** @var Rival[] */
    private array $rivals = [];
    private ?Rival $me = null;
    private ?Level $level = null;
    /** 'countdown', 'play', 'over' */
    private string $state = 'countdown';
    private float $countdown = self::COUNTDOWN;
    private float $clock = 0;
    private bool $simulating = false;
    /** Ring places of the act at the start: [tx, ty, bit, x, y] (the bots look for the nearest one). */
    private array $ringSpots = [];
    /** Places to (re)appear: on the ground under the rings of the act, and the start. */
    private array $spawns = [];
    private int $humanRings = 0;
    /** The human's death was seen (his rings were given away). */
    private bool $humanDead = false;
    /** Race: x of the finish line (the sign of the act, or the end of the act). */
    private float $goalX = 0;
    private ?float $firstArrival = null;
    /** Flag mode: [x, y] of the foot of the pole (on the ground, or over its carrier). */
    private ?array $flag = null;
    private ?Rival $carrier = null;
    /** Flag mode: the last carrier can't take it back at once, nobody can during FLAG_DROP_DELAY. */
    private ?Rival $lastCarrier = null;
    private float $flagDelay = 0;
    /** Flag mode: [x, y] of the foot of the base of each team, and the flags each team brought back. */
    private array $bases = [];
    private array $captures = [0, 0];
    /** A message in the middle of the screen for a while: [text, colour, seconds left]. */
    private ?array $banner = null;
    /** Rivals hit by a hazard / fallen in a pit during their physics step. */
    private array $fell = [];
    private array $hazard = [];
    /** Where the human went (race), for the bots that are stuck: [x, y] every half second. */
    private array $trail = [];
    private float $trailClock = 0;
    /** Results once over: verdict line, colour, and the ranking [name, value, colour, me]. */
    private string $verdict = '';
    private array $verdictColour = [255, 255, 255];
    private array $ranking = [];
    private array $start = [0, 0];
    /** Last time (match clock) each sound effect was played: the same one is not restarted many times in a row. */
    private array $sfxTime = [];
    /** Race: y of the ground under the finish line, by height of the view (32 px bands). */
    private array $finishGround = [];
    /** Bots: the ring they go to, and when to look for the nearest one again. */
    private array $ringTarget = [];
    /** The human died (pit, badnik...): put somewhere else once the act is back (the Scene puts him at the start). */
    private bool $humanSpawnPending = false;

    public function __construct(
        private Sdl $sdl,
        private CollisionSystem $collisions,
        private GameObjectManager $objects,
        private Camera $camera,
        private Player $human,
        public readonly string $mode,
        private int $players,
    ) {
        if (!isset(self::MODES[$mode])) throw new \InvalidArgumentException("Mode '$mode' is not valid.");
        // flag: two teams of 1 to 3
        $this->players = $mode === 'flag' ? max(2, min(6, $players - $players % 2)) : max(2, min(5, $players));
    }

    /** Rival sheets (tools/soniccolors.py), loaded once. */
    public static function loadTextures(Sdl $sdl): void
    {
        foreach (self::COLOURS as [$sheet]) {
            if ($sheet !== 'sonic' && !$sdl->hasTexture($sheet)) {
                $sdl->loadTexture($sheet, 'tileset/sprites/tileset-' . $sheet . '.png', ['r' => 0, 'g' => 72, 'b' => 0]);
            }
        }
    }

    /** "GREEN HILL 1" */
    public static function levelName(int $level): string
    {
        return self::ZONES[intdiv($level - 1, 3)] . ' ' . (($level - 1) % 3 + 1);
    }

    /** Plays a sound effect, unless the same one has just been played (bots taking rings, bouncing...). */
    private function sfx(int $index): void
    {
        $now = microtime(true);
        if ($now - ($this->sfxTime[$index] ?? 0) < 0.15) return;
        $this->sfxTime[$index] = $now;
        $this->emit('sfx', [$index]);
    }

    public function isSimulating(): bool { return $this->simulating; }
    public function isOver(): bool { return $this->state === 'over'; }
    /** The human can't move (countdown, results). */
    public function isLocked(): bool { return $this->state !== 'play'; }

    /** The human blinks (hit / respawned by the match, not by GameObjectManager). */
    public function isHumanBlinking(): bool
    {
        return $this->me !== null && $this->me->invulnerable > 0;
    }

    // ------------------------------------------------------------------ setup

    private function begin(Level $level): void
    {
        $this->level = $level;
        $this->start = [$this->human->getX(), $this->human->getY()];
        $this->scanRings($level);
        $this->goalX = $this->objects->getEndSignX() ?? ($level->getMapWidth() * 32 - 96);
        $this->objects->ringWrap = false;
        $this->objects->keepTilemap = true;
        self::loadTextures($this->sdl);

        $teams = $this->mode === 'flag';
        if ($teams) $this->placeBases();
        for ($i = 0; $i < $this->players; $i++) {
            $team = $teams ? ($i < $this->players / 2 ? 0 : 1) : $i;
            [$sheet, $rgb] = $teams ? [$team === 0 ? 'sonic' : 'sonic-red', self::TEAM_COLOURS[$team]] : self::COLOURS[$i];
            if ($i === 0) {
                $rival = new Rival($this->human, true, 'J1', 'sonic', $rgb, $team);
            } else {
                $body = new Player($this->sdl);
                // a little better each: the last ones are the hardest
                $rival = new Rival($body, false, 'CPU' . ($i + 1), $sheet, $rgb, $team, new BotBrain(0.7 + 0.06 * $i));
            }
            $this->rivals[] = $rival;
        }
        $this->me = $this->rivals[0];

        if ($this->mode === 'race') {
            // everybody on the start line, the bots just behind
            foreach ($this->rivals as $i => $rival) {
                $rival->start = [$this->start[0] - 6 * $i, $this->start[1]];
                if (!$rival->human) $rival->placeAt($rival->start[0], $rival->start[1], false);
            }
        } else {
            foreach ($this->rivals as $rival) {
                [$x, $y] = $this->randomSpawn($rival);
                $rival->start = [$x, $y];
                $rival->placeAt($x, $y, false);
            }
            // the countdown is long enough to look around: protected when it ends
            $this->objects->protectPlayer(self::COUNTDOWN + 2.0);
            $this->camera->noSmooth = true;
        }
        $this->humanRings = $this->objects->getRingCount();
    }

    /** Rings of the map at the start (the rings mode puts them back there) and the places on the ground under them. */
    private function scanRings(Level $level): void
    {
        $this->ringSpots = [];
        $this->spawns = [[$this->start[0], $this->start[1]]];
        $seen = [];
        for ($ty = 0; $ty < $level->getMapHeight(); $ty++) {
            for ($tx = 0; $tx < $level->getMapWidth(); $tx++) {
                $block = $level->getTile($tx, $ty);
                if ($block === null || !GameObjectManager::isRingBlock($block)) continue;
                foreach ([1 => 0, 2 => 16] as $bit => $offsetX) {
                    if (!($block & $bit)) continue;
                    $x = $tx * 32 + $offsetX;
                    $y = $ty * 32 + 8;
                    $this->ringSpots[] = [$tx, $ty, $bit, $x, $y];
                    // one place to appear per block, on the ground below the ring
                    if (isset($seen[$tx][$ty])) continue;
                    $seen[$tx][$ty] = true;
                    $ground = $level->findSurfaceY($x + 8, $y + 16, 320);
                    if ($ground !== null && !$level->isSolidAt($x + 8, $ground - 20)) {
                        $this->spawns[] = [$x + 8 - 12, $ground - 32];
                    }
                }
            }
        }
    }

    /** A place to appear, away from the others (and, for the flag, not too close to it; near the base of its team). */
    private function randomSpawn(?Rival $for = null): array
    {
        $spawns = $this->spawns;
        if ($this->mode === 'flag' && $for !== null && isset($this->bases[$for->team])) {
            $baseX = $this->bases[$for->team][0];
            $near = array_values(array_filter($spawns, fn($s) => abs($s[0] + 12 - $baseX) < self::BASE_RADIUS));
            if ($near) $spawns = $near;
        }
        $best = $spawns[0];
        $bestScore = -1;
        for ($try = 0; $try < 12; $try++) {
            $spawn = $spawns[mt_rand(0, count($spawns) - 1)];
            $nearest = INF;
            foreach ($this->rivals as $rival) {
                if ($rival === $for) continue;
                $nearest = min($nearest, hypot($rival->player->getX() - $spawn[0], $rival->player->getY() - $spawn[1]));
            }
            if ($this->flag !== null) $nearest = min($nearest, hypot($this->flag[0] - $spawn[0], $this->flag[1] - $spawn[1]) * 1.5);
            if ($this->mode === 'flag') $nearest = min($nearest, 300);   // close to the base is fine
            $score = min($nearest, 600) + mt_rand(0, 100);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $spawn;
            }
        }
        return $best;
    }

    /**
     * Flag mode: the bases, near the two ends of the act (the places to appear, sorted from left to right):
     * blue (the human's team) on the left, red on the right.
     */
    private function placeBases(): void
    {
        $spawns = $this->spawns;
        usort($spawns, fn($a, $b) => $a[0] <=> $b[0]);
        $last = count($spawns) - 1;
        foreach ([0 => 0.08, 1 => 0.92] as $team => $at) {
            [$x, $y] = $spawns[(int)round($last * $at)];
            $this->bases[$team] = [$x + 12, $y + 32];
        }
    }

    /** Flag mode: on the ground half-way between the bases, as far as possible from every Sonic. */
    private function placeFlag(): void
    {
        $left = min($this->bases[0][0], $this->bases[1][0]);
        $right = max($this->bases[0][0], $this->bases[1][0]);
        $middle = array_values(array_filter($this->spawns,
            fn($s) => $s[0] > $left + ($right - $left) * 0.4 && $s[0] < $left + ($right - $left) * 0.6));
        $spawns = $middle ?: $this->spawns;
        $best = null;
        $bestScore = -1;
        for ($try = 0; $try < 24; $try++) {
            [$x, $y] = $spawns[mt_rand(0, count($spawns) - 1)];
            $nearest = INF;
            foreach ($this->rivals as $rival) {
                $nearest = min($nearest, hypot($rival->player->getX() - $x, $rival->player->getY() - $y));
            }
            $score = min($nearest, 1200) + mt_rand(0, 150);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$x + 12, $y + 32];
            }
        }
        $this->flag = $best;
        $this->carrier = $this->lastCarrier = null;
        $this->flagDelay = 0;
        $this->sfx(SoundManager::SFX_SIGN_STOP);
    }

    /** The carrier drops the flag where it is (on the ground below; in a pit: a new flag half-way). */
    private function dropFlag(): void
    {
        $carrier = $this->carrier;
        if ($carrier === null) return;
        $this->carrier = null;
        $this->lastCarrier = $carrier;
        $this->flagDelay = self::FLAG_DROP_DELAY;
        $this->restoreSpeed($carrier);
        $x = (int)round($carrier->player->getX() + 12);
        $ground = $this->level->findSurfaceY($x, (int)$carrier->player->getY() + 16, 240);
        if ($ground === null) {
            $this->placeFlag();
            return;
        }
        $this->flag = [$x, $ground];
        $this->sfx(SoundManager::SFX_HURT);
    }

    /** Back to the normal top speed after carrying the flag. */
    private function restoreSpeed(Rival $rival): void
    {
        if ($rival->human) $this->human->setSpeedBoost($this->objects->hasSpeedShoes());
    }

    // ------------------------------------------------------------------ frame

    public function update(float $deltaTime, ?Level $level): void
    {
        if ($level === null) return;
        if ($this->level === null) $this->begin($level);
        if ($level !== $this->level) return;

        if ($this->humanSpawnPending) {
            $this->humanSpawnPending = false;
            [$x, $y] = $this->randomSpawn($this->me);
            $this->placeHuman($x, $y);
        }

        foreach ($this->rivals as $rival) {
            $rival->invulnerable = max(0, $rival->invulnerable - $deltaTime);
        }

        if ($this->state === 'countdown') {
            $before = (int)ceil($this->countdown);
            $this->countdown -= $deltaTime;
            if ((int)ceil($this->countdown) !== $before) {
                $this->sfx($this->countdown <= 0 ? SoundManager::SFX_EXTRA_LIFE : SoundManager::SFX_SWITCH);
            }
            if ($this->countdown <= 0) {
                $this->state = 'play';
                if ($this->mode === 'flag') $this->placeFlag();
            }
        } elseif ($this->state === 'play') {
            $this->clock += $deltaTime;
        }

        $this->updateBots($deltaTime, $level);
        if ($this->state !== 'play') return;

        $this->followHuman($deltaTime);
        if ($this->objects->isPlayerDead() && !$this->humanDead) {
            // pit, spikes, drowned...: his rings go at once (GameObjectManager would only reset them on respawn)
            $this->humanDead = true;
            if ($this->mode === 'rings') $this->objects->takeRings();
        }
        $this->countHumanRings();
        $this->contacts();

        if ($this->banner !== null && ($this->banner[2] -= $deltaTime) <= 0) $this->banner = null;
        match ($this->mode) {
            'race' => $this->updateRace(),
            'flag' => $this->updateFlag($deltaTime),
            default => null,
        };
        if ($this->state === 'play' && $this->mode !== 'race' && $this->clock >= self::TIME_LIMIT) {
            $this->finish();
        }
    }

    private function updateBots(float $deltaTime, Level $level): void
    {
        $this->simulating = true;
        $width = $level->getMapWidth() * 32 - 24;
        foreach ($this->rivals as $rival) {
            if ($rival->human) continue;
            $p = $rival->player;
            if ($rival->isKnockedOut()) {
                if ($rival->updateKnockOut($deltaTime)) $this->respawn($rival);
                continue;
            }

            if ($this->mode === 'race') {
                // a little slower than Sonic, and slower still far ahead of him (the race stays close)
                $top = self::BOT_TOP_SPEED + self::BOT_TOP_SPEED_STEP * array_search($rival, $this->rivals, true);
                $p->setTopSpeed($p->getX() - $this->human->getX() > self::RUBBER_BAND ? $top * 0.7 : $top);
            } elseif ($this->mode === 'flag') {
                // slower than Sonic, the carrier slower still: the human has the time to catch up
                $top = self::BOT_FLAG_SPEED + self::BOT_TOP_SPEED_STEP * array_search($rival, $this->rivals, true);
                $p->setTopSpeed($rival === $this->carrier ? $top * self::CARRIER_SPEED : $top);
            }
            $input = ['dir' => 0, 'jump' => false, 'super' => false, 'down' => false, 'warp' => false];
            if ($this->state === 'play' && $rival->finishTime === null) {
                [$target, $chase, $foe] = $this->targetOf($rival) + [2 => null];
                $input = $rival->brain->think($p, $level, $target, $deltaTime, $chase, $foe !== null && $this->isAttacking($foe));
            }
            if ($input['warp']) {
                if ($rival === $this->carrier) $this->dropFlag();
                $this->unstick($rival);
                continue;
            }
            if ($input['jump'] && $p->isGrounded()) {
                $p->jump();
                if ($input['super']) $p->setVelocity($p->getVelocity()[0], -620);
                $rival->jumpHold = 0.12 + mt_rand(0, 20) / 100;
            }
            if ($rival->jumpHold > 0) {
                $rival->jumpHold -= $deltaTime;
                if ($rival->jumpHold <= 0) $p->jumpRelease();
            }

            // steps of at most 1/60 s (Sonic: 1/120 s; the bots are many, and 1/60 s is the speed of the original)
            $steps = min(4, max(1, (int)ceil($deltaTime * 60 - 0.01)));
            for ($i = 0; $i < $steps; $i++) {
                if ($input['dir'] !== 0) $p->moveDirection($input['dir'] > 0 ? 'right' : 'left');
                if ($input['down']) $p->roll();
                $p->update($deltaTime / $steps);
                $this->collisions->checkCollisions($p, $level, $deltaTime / $steps);
                if (isset($this->fell[spl_object_id($p)])) break;
            }
            if ($p->getX() < 0) $p->setX(0);
            if ($p->getX() > $width) $p->setX($width);
            $rival->rememberSafePlace($deltaTime);
            $this->collectRings($rival, $level);
        }
        $this->simulating = false;

        foreach ($this->rivals as $rival) {
            $id = spl_object_id($rival->player);
            if (isset($this->fell[$id])) {
                // a pit: back where it stood a few seconds ago (no penalty but the time lost; rings mode: its rings)
                if ($rival === $this->carrier) $this->dropFlag();
                if ($this->mode === 'rings') $this->loseRings($rival, null);
                [$x, $y] = $rival->safePlace() ?? $rival->start;
                $rival->placeAt($x, $y);
                $rival->brain?->reset();
            } elseif (isset($this->hazard[$id])) {
                $this->hit($rival, null);
            }
        }
        $this->fell = [];
        $this->hazard = [];
    }

    /** Where a bot goes: [[x, y], chase an opponent?] */
    private function targetOf(Rival $rival): array
    {
        $p = $rival->player;
        switch ($this->mode) {
            case 'race':
                return [[$this->goalX + 400, $p->getY()], false];
            case 'flag':
                $carrier = $this->carrier;
                if ($carrier === $rival) return [$this->bases[$rival->team], false];
                if ($this->isDefender($rival)) return $this->defenderTarget($rival);
                if ($carrier !== null) {
                    $cp = $carrier->player;
                    // the other team has it: after the carrier
                    if ($carrier->team !== $rival->team) return [[$cp->getX() + 12, $cp->getY()], true, $carrier];
                    // ours: after the opponent closest to the carrier, or along with it
                    $foe = null;
                    $distance = 360;
                    foreach ($this->rivals as $other) {
                        if ($other->team === $rival->team || $other->isKnockedOut()) continue;
                        $d = hypot($other->player->getX() - $cp->getX(), $other->player->getY() - $cp->getY());
                        if ($d < $distance) {
                            $distance = $d;
                            $foe = $other;
                        }
                    }
                    if ($foe) return [[$foe->player->getX() + 12, $foe->player->getY()], true, $foe];
                    return [[$cp->getX() + 12, $cp->getY()], false];
                }
                if ($this->flag !== null) return [$this->flag, false];
                return [[$p->getX(), $p->getY()], false];
            case 'rings':
                if (($ring = $this->ringFor($rival)) !== null) return [$ring, false];
                // no more rings in the act: hunt the richest Sonic (a hit takes its rings)
                $richest = null;
                foreach ($this->rivals as $other) {
                    if ($other === $rival || $other->isKnockedOut() || $other->rings === 0) continue;
                    if ($richest === null || $other->rings > $richest->rings) $richest = $other;
                }
                if ($richest) return [[$richest->player->getX() + 12, $richest->player->getY()], true, $richest];
                return [[$this->human->getX(), $this->human->getY()], false];
            default:
                $nearest = null;
                $distance = INF;
                foreach ($this->rivals as $other) {
                    if ($other === $rival || $other->isKnockedOut() || $other->team === $rival->team) continue;
                    $d = hypot($other->player->getX() - $p->getX(), $other->player->getY() - $p->getY());
                    if ($d < $distance) {
                        $distance = $d;
                        $nearest = $other;
                    }
                }
                // with no rings it takes some first (a hit would knock it out)
                if ($rival->rings === 0 && $distance > 250 && ($ring = $this->ringFor($rival, 400)) !== null) {
                    return [$ring, false];
                }
                return $nearest ? [[$nearest->player->getX() + 12, $nearest->player->getY()], true, $nearest] : [[$p->getX(), $p->getY()], false];
        }
    }

    /**
     * The ring a bot goes to: the nearest one, looked for again twice a second (all the rings of the act are
     * scanned) or as soon as it is taken.
     */
    private function ringFor(Rival $rival, float $max = INF): ?array
    {
        $id = spl_object_id($rival);
        [$ring, $until, $tx, $ty, $bit] = $this->ringTarget[$id] ?? [null, -1, 0, 0, 0];
        if ($this->clock >= $until || ($ring !== null && !(($this->level->getTile($tx, $ty) ?? 0) & $bit))) {
            $p = $rival->player;
            $found = $this->nearestRing($p->getX() + 12, $p->getY() + 16, $max);
            $ring = $found ? [$found[0], $found[1]] : null;
            $this->ringTarget[$id] = [$ring, $this->clock + 0.5, ...($found ? array_slice($found, 2) : [0, 0, 0])];
        }
        return $ring;
    }

    /** Flag mode: the last bot of a team with two bots or more stays to guard its base. */
    private function isDefender(Rival $rival): bool
    {
        $bots = array_values(array_filter($this->rivals, fn(Rival $r) => !$r->human && $r->team === $rival->team));
        return count($bots) >= 2 && end($bots) === $rival;
    }

    /** A defender: near its base; after an opponent carrying the flag close to it; takes a flag dropped nearby. */
    private function defenderTarget(Rival $rival): array
    {
        [$bx, $by] = $this->bases[$rival->team];
        $carrier = $this->carrier;
        if ($carrier !== null && $carrier->team !== $rival->team) {
            $cp = $carrier->player;
            if (hypot($cp->getX() + 12 - $bx, $cp->getY() - $by) < self::DEFEND_RADIUS) {
                return [[$cp->getX() + 12, $cp->getY()], true, $carrier];
            }
        } elseif ($carrier === null && $this->flag !== null && hypot($this->flag[0] - $bx, $this->flag[1] - $by) < self::DEFEND_RADIUS / 2) {
            return [$this->flag, false];
        }
        // a little in front of the base, towards the middle of the act
        $front = $bx < $this->bases[1 - $rival->team][0] ? 80 : -80;
        return [[$bx + $front, $by - 16], false];
    }

    /** Centre of the nearest ring still there (world pixels) and its tile [x, y, tx, ty, bit], or null. */
    private function nearestRing(float $x, float $y, float $max = INF): ?array
    {
        $best = null;
        $bestDistance = $max;
        foreach ($this->ringSpots as [$tx, $ty, $bit, $rx, $ry]) {
            $d = abs($rx + 8 - $x) + abs($ry + 8 - $y) * 1.5;
            if ($d >= $bestDistance) continue;
            if (!(($this->level->getTile($tx, $ty) ?? 0) & $bit)) continue;
            $bestDistance = $d;
            $best = [$rx + 8, $ry + 8, $tx, $ty, $bit];
        }
        return $best;
    }

    /** A bot stuck for long: put back on the way (race: where the human went; otherwise near its target). */
    private function unstick(Rival $rival): void
    {
        $p = $rival->player;
        if ($this->mode === 'race') {
            $place = null;
            foreach ($this->trail as $point) {
                if ($point[0] > $p->getX() + 64) {
                    $place = $point;
                    break;
                }
            }
            $place ??= $rival->safePlace() ?? $rival->start;
        } else {
            [$target] = $this->targetOf($rival);
            $place = $this->spawns[0];
            $best = INF;
            foreach ($this->spawns as $spawn) {
                $d = hypot($spawn[0] - $target[0], $spawn[1] - $target[1]) + mt_rand(0, 150);
                if ($d < $best && hypot($spawn[0] - $p->getX(), $spawn[1] - $p->getY()) > 100) {
                    $best = $d;
                    $place = $spawn;
                }
            }
        }
        $rival->placeAt($place[0], $place[1]);
        $rival->brain?->reset();
    }

    /** Race: the human's way, for unstick(). */
    private function followHuman(float $deltaTime): void
    {
        if ($this->mode !== 'race') return;
        $this->trailClock += $deltaTime;
        if ($this->trailClock < 0.5 || !$this->human->isGrounded() || $this->objects->isPlayerDead()) return;
        $this->trailClock = 0;
        $x = $this->human->getX();
        if ($this->trail && $x <= end($this->trail)[0] + 16) return;
        $this->trail[] = [$x, $this->human->getY()];
    }

    /** Rings taken by a bot: counted for it (same rule as GameObjectManager::collectRings). */
    private function collectRings(Rival $rival, Level $level): void
    {
        $rect = $rival->player->getCollisionRect();
        for ($ty = (int)floor($rect['y'] / 32); $ty <= (int)floor(($rect['y'] + $rect['height'] - 1) / 32); $ty++) {
            for ($tx = (int)floor($rect['x'] / 32); $tx <= (int)floor(($rect['x'] + $rect['width'] - 1) / 32); $tx++) {
                $block = $level->getTile($tx, $ty);
                if ($block === null || !GameObjectManager::isRingBlock($block)) continue;
                foreach ([1 => 0, 2 => 16] as $bit => $offsetX) {
                    if (!($block & $bit)) continue;
                    $rx = $tx * 32 + $offsetX;
                    $ry = $ty * 32 + 8;
                    if ($rect['x'] < $rx + 16 && $rect['x'] + $rect['width'] > $rx && $rect['y'] < $ry + 16 && $rect['y'] + $rect['height'] > $ry) {
                        $block ^= $bit;
                        $level->setTile($tx, $ty, $block);
                        $rival->rings++;
                        if ($this->isOnScreen($rival)) $this->sfx(SoundManager::SFX_RING);
                    }
                }
            }
        }
    }

    /** The human's rings: GameObjectManager counts them; rings mode: the lost ones go to the others. */
    private function countHumanRings(): void
    {
        $rings = $this->objects->getRingCount();
        $lost = $this->humanRings - $rings;
        $this->humanRings = $rings;
        $this->me->rings = $rings;
        if ($lost > 0) $this->giveAway($this->me, $lost, null);
    }

    /** Rings mode: $victim loses its rings (hit, pit, spikes...): see giveAway. */
    private function loseRings(Rival $victim, ?Rival $attacker): void
    {
        if ($victim->human) {
            if ($this->mode === 'rings') $this->objects->takeRings();
            $this->countHumanRings();
            return;
        }
        $lost = $victim->rings;
        $victim->rings = 0;
        $this->giveAway($victim, $lost, $attacker);
    }

    /**
     * Rings mode: the rings lost by $victim go to the Sonic that hit it, or that hit / bumped it less than
     * PUSH_TIME seconds before an accident (pit, spikes...); otherwise shared out between all the others.
     */
    private function giveAway(Rival $victim, int $count, ?Rival $attacker): void
    {
        if ($this->mode !== 'rings' || $count <= 0) return;
        if ($attacker === null && $victim->lastHitBy !== null && $this->clock - $victim->lastHitTime <= self::PUSH_TIME) {
            $attacker = $victim->lastHitBy;
        }
        if ($attacker !== null && $attacker !== $victim) {
            $this->addRings($attacker, $count);
            return;
        }
        $others = array_values(array_filter($this->rivals, fn(Rival $r) => $r !== $victim));
        shuffle($others);   // the remainder to random ones
        foreach ($others as $i => $other) {
            $share = intdiv($count, count($others)) + ($i < $count % count($others) ? 1 : 0);
            if ($share > 0) $this->addRings($other, $share);
        }
    }

    private function addRings(Rival $rival, int $count): void
    {
        if ($rival->human) {
            $this->objects->gainRings($count);
            $this->humanRings = $this->objects->getRingCount();
            $rival->rings = $this->humanRings;
        } else {
            $rival->rings += $count;
        }
        if ($this->isOnScreen($rival)) $this->sfx(SoundManager::SFX_RING);
    }

    // ------------------------------------------------------------------ contacts

    /** Sonic against Sonic: an attacker (jumping / rolling) hurts an opponent that isn't attacking. */
    private function contacts(): void
    {
        $count = count($this->rivals);
        for ($i = 0; $i < $count; $i++) {
            $a = $this->rivals[$i];
            if (!$this->canTouch($a)) continue;
            for ($j = $i + 1; $j < $count; $j++) {
                $b = $this->rivals[$j];
                if (!$this->canTouch($b)) continue;
                if ($this->mode === 'flag' && $a->team === $b->team) continue;
                if (!$this->overlap($a, $b)) continue;
                $attackA = $this->isAttacking($a);
                $attackB = $this->isAttacking($b);
                if ($attackA && !$attackB) {
                    $this->hit($b, $a);
                } elseif ($attackB && !$attackA) {
                    $this->hit($a, $b);
                } elseif ($attackA && $attackB) {
                    $this->bounce($a, $b);
                }
            }
        }
    }

    private function isAttacking(Rival $rival): bool
    {
        return $rival->isAttacking() || ($rival->human && $this->objects->hasInvincibility());
    }

    private function canTouch(Rival $rival): bool
    {
        if ($rival->isKnockedOut() || $rival->finishTime !== null) return false;
        if ($rival->human && ($this->objects->isPlayerDead() || $this->objects->isLevelComplete())) return false;
        return true;
    }

    private function overlap(Rival $a, Rival $b): bool
    {
        $ra = $a->player->getCollisionRect();
        $rb = $b->player->getCollisionRect();
        return $ra['x'] + 3 < $rb['x'] + $rb['width'] - 3 && $rb['x'] + 3 < $ra['x'] + $ra['width'] - 3
            && $ra['y'] + 4 < $rb['y'] + $rb['height'] && $rb['y'] + 4 < $ra['y'] + $ra['height'];
    }

    /** Both attacking: they bounce off each other, nobody is hurt. */
    private function bounce(Rival $a, Rival $b): void
    {
        if ($a->invulnerable > 0.0 && $b->invulnerable > 0.0) return;
        $dir = $a->player->getX() < $b->player->getX() ? -1 : 1;
        foreach ([[$a, $dir], [$b, -$dir]] as [$rival, $d]) {
            $rival->player->setVelocity($d * 240, min($rival->player->getVelocity()[1], -200));
            $rival->player->setGrounded(false);
            $rival->invulnerable = max($rival->invulnerable, 0.3);
        }
        $this->pushedBy($a, $b);
        $this->pushedBy($b, $a);
        if ($this->isOnScreen($a) || $this->isOnScreen($b)) $this->sfx(SoundManager::SFX_BUMPER);
    }

    private function pushedBy(Rival $victim, Rival $by): void
    {
        $victim->lastHitBy = $by;
        $victim->lastHitTime = $this->clock;
    }

    /**
     * $victim hit by $attacker (null: a hazard of the act): loses its rings, or without rings is knocked out
     * (the rings mode only throws it back; the attacker takes its rings).
     */
    private function hit(Rival $victim, ?Rival $attacker): void
    {
        if ($victim->invulnerable > 0) return;
        if ($attacker) $this->pushedBy($victim, $attacker);
        if ($victim === $this->carrier) $this->dropFlag();
        $fromX = $attacker ? $attacker->player->getX() : $victim->player->getX() + 1;
        // the attacker bounces up like on a badnik
        if ($attacker && !$attacker->player->isGrounded()) {
            $attacker->player->setVelocity($attacker->player->getVelocity()[0], -300);
        }

        if ($victim->human) {
            if ($this->objects->isPlayerHurt() || $this->objects->hasInvincibility()) return;
            if ($this->objects->getRingCount() > 0 || $this->objects->hasShield() || $this->mode === 'rings') {
                // GameObjectManager: rings lost (one pops out), thrown back, blinking
                if ($this->mode === 'rings' && !$this->objects->hasShield()) $this->loseRings($victim, $attacker);
                $this->objects->hurtPlayer($this->human, false);
                $victim->invulnerable = 2.0;
                return;
            }
            $this->knockOutHuman($attacker);
            return;
        }

        $sound = $this->isOnScreen($victim);
        if ($victim->rings > 0 || $this->mode === 'rings') {
            $this->loseRings($victim, $attacker);
            $victim->rings = 0;
            $victim->knockBack($fromX);
            if ($sound) $this->sfx(SoundManager::SFX_HURT);
            return;
        }
        $victim->knockOut();
        $victim->deaths++;
        if ($attacker) $attacker->kills++;
        if ($sound) $this->sfx(SoundManager::SFX_EXPLOSION);
    }

    /** The human knocked out by a Sonic: race: back to the start; otherwise somewhere else. */
    private function knockOutHuman(?Rival $attacker): void
    {
        $this->me->deaths++;
        if ($attacker) $attacker->kills++;
        [$x, $y] = $this->mode === 'race' ? $this->start : $this->randomSpawn($this->me);
        $this->human->resetForStart();
        $this->placeHuman($x, $y);
        $this->sfx(SoundManager::SFX_HURT);
    }

    /** The human somewhere else: the camera jumps there, 2 s of blinking (nor Sonics nor badniks hurt him). */
    private function placeHuman(float $x, float $y): void
    {
        $this->me->placeAt($x, $y);
        $this->objects->protectPlayer(2.0);
        $this->camera->noSmooth = true;
    }

    /** GameObjectManager put the human back after a death (pit, badnik...): in the open modes, somewhere else. */
    public function humanRespawned(): void
    {
        if ($this->me === null) return;
        $this->me->deaths++;
        $this->humanDead = false;
        $this->humanRings = $this->objects->getRingCount();
        if ($this->mode !== 'race') $this->humanSpawnPending = true;
    }

    /** A bot back in the game after a knock-out. */
    private function respawn(Rival $rival): void
    {
        [$x, $y] = $this->mode === 'race' ? $rival->start : $this->randomSpawn($rival);
        $rival->placeAt($x, $y);
        $rival->brain?->reset();
    }

    /** CollisionSystem events during a bot's physics step (Game forwards the ones that aren't the human's). */
    public function entityFell(object $entity): void
    {
        $this->fell[spl_object_id($entity)] = true;
    }

    public function entityHazard(object $entity): void
    {
        $this->hazard[spl_object_id($entity)] = true;
    }

    // ------------------------------------------------------------------ modes

    private function updateRace(): void
    {
        foreach ($this->rivals as $rival) {
            if ($rival->finishTime !== null || !$this->canTouch($rival)) continue;
            if ($rival->player->getX() + 12 >= $this->goalX) $this->arrive($rival);
        }
        $everybody = true;
        foreach ($this->rivals as $rival) {
            if ($rival->finishTime === null) $everybody = false;
        }
        if ($this->me->finishTime !== null || $everybody
            || ($this->firstArrival !== null && $this->clock - $this->firstArrival > self::RACE_GRACE)) {
            $this->finish();
        }
    }

    private function arrive(Rival $rival): void
    {
        $rival->finishTime = $this->clock;
        $this->firstArrival ??= $this->clock;
        $this->sfx(SoundManager::SFX_SIGN_STOP);
    }

    /** The human passed the sign (GameObjectManager 'levelComplete'). */
    public function humanFinished(): void
    {
        if ($this->state !== 'play' || $this->me->finishTime !== null) return;
        $this->arrive($this->me);
    }

    private function updateFlag(float $deltaTime): void
    {
        if ($this->flag === null) return;
        $this->flagDelay = max(0, $this->flagDelay - $deltaTime);

        if ($this->carrier !== null) {
            $carrier = $this->carrier;
            $p = $carrier->player;
            // hurt or dead (spikes, pit...) without a hit from a Sonic: dropped as well
            if (!$this->canTouch($carrier) || $p->getState() === 'hurt'
                || ($carrier->human && ($this->objects->isPlayerHurt() || $this->objects->isPlayerDead()))) {
                $this->dropFlag();
                return;
            }
            if ($carrier->human) $this->human->setTopSpeed(($this->objects->hasSpeedShoes() ? 1000 : 500) * self::CARRIER_SPEED);
            $this->flag = [$p->getX() + 12, $p->getY()];
            [$bx, $by] = $this->bases[$carrier->team];
            $r = $p->getCollisionRect();
            if (abs($r['x'] + $r['width'] / 2 - $bx) < 20 && $r['y'] < $by && $r['y'] + $r['height'] > $by - 56) {
                $this->capture($carrier);
            }
            return;
        }

        if ($this->flagDelay > 0) return;
        [$fx, $fy] = $this->flag;
        foreach ($this->rivals as $rival) {
            if (!$this->canTouch($rival) || $rival->player->getState() === 'hurt') continue;
            $r = $rival->player->getCollisionRect();
            if ($r['x'] < $fx + 8 && $r['x'] + $r['width'] > $fx - 8 && $r['y'] < $fy && $r['y'] + $r['height'] > $fy - 40) {
                $this->carrier = $rival;
                $this->sfx(SoundManager::SFX_SWITCH);
                $this->banner = [$rival->name . ' A LE DRAPEAU', $rival->rgb, 1.5];
                return;
            }
        }
    }

    /** The carrier is at its base: a point for its team, a new flag half-way (or the end of the match). */
    private function capture(Rival $carrier): void
    {
        $this->carrier = null;
        $this->restoreSpeed($carrier);
        $carrier->captures++;
        $this->captures[$carrier->team]++;
        $this->sfx(SoundManager::SFX_EXTRA_LIFE);
        if ($this->captures[$carrier->team] >= self::FLAG_CAPTURES) {
            $this->finish($carrier->team);
            return;
        }
        $this->banner = ['POINT POUR LES ' . self::TEAM_NAMES[$carrier->team], self::TEAM_COLOURS[$carrier->team], 2.0];
        $this->placeFlag();
    }

    /** End of the match: the ranking, the verdict, the jingle. $flagTeam: the team that took the flag. */
    private function finish(?int $flagTeam = null): void
    {
        $this->state = 'over';
        $this->me->rings = $this->objects->getRingCount();
        $won = false;
        $rows = [];
        switch ($this->mode) {
            case 'race':
                $sorted = $this->rivals;
                usort($sorted, fn(Rival $a, Rival $b) => [$a->finishTime === null, $a->finishTime ?? -$a->player->getX()]
                    <=> [$b->finishTime === null, $b->finishTime ?? -$b->player->getX()]);
                foreach ($sorted as $i => $rival) {
                    $rows[] = [$rival, $rival->finishTime !== null ? self::time($rival->finishTime) : 'ABANDON'];
                }
                $won = $sorted[0] === $this->me && $this->me->finishTime !== null;
                $this->verdict = $won ? 'VICTOIRE' : ($sorted[0]->finishTime !== null ? $sorted[0]->name . ' GAGNE' : 'PERSONNE N EST ARRIVE');
                break;
            case 'flag':
                // time over: the team with the most flags (none: a draw)
                if ($flagTeam === null && $this->captures[0] !== $this->captures[1]) {
                    $flagTeam = $this->captures[0] > $this->captures[1] ? 0 : 1;
                }
                $sorted = $this->rivals;
                usort($sorted, fn(Rival $a, Rival $b) => [$a->team !== $flagTeam, -$a->captures, $a->deaths]
                    <=> [$b->team !== $flagTeam, -$b->captures, $b->deaths]);
                foreach ($sorted as $rival) $rows[] = [$rival, self::TEAM_NAMES[$rival->team] . '  ' . $rival->captures . ' DRAPEAU' . ($rival->captures > 1 ? 'X' : '')];
                $score = $this->captures[0] . '-' . $this->captures[1];
                if ($flagTeam === null) {
                    $this->verdict = 'EGALITE ' . $score;
                } else {
                    $won = $flagTeam === $this->me->team;
                    $this->verdict = 'LES ' . self::TEAM_NAMES[$flagTeam] . ' GAGNENT ' . $score;
                }
                break;
            case 'rings':
                $sorted = $this->rivals;
                usort($sorted, fn(Rival $a, Rival $b) => $b->rings <=> $a->rings);
                foreach ($sorted as $rival) $rows[] = [$rival, $rival->rings . ' ANNEAUX'];
                $won = $sorted[0] === $this->me || $sorted[0]->rings === $this->me->rings;
                $this->verdict = $won ? 'VICTOIRE' : $sorted[0]->name . ' GAGNE';
                break;
            default:
                $sorted = $this->rivals;
                usort($sorted, fn(Rival $a, Rival $b) => [$b->kills, $a->deaths] <=> [$a->kills, $b->deaths]);
                foreach ($sorted as $rival) $rows[] = [$rival, $rival->kills . ' KO  ' . $rival->deaths . ' MORTS'];
                $won = $sorted[0] === $this->me || ($sorted[0]->kills === $this->me->kills && $sorted[0]->deaths === $this->me->deaths);
                $this->verdict = $won ? 'VICTOIRE' : $sorted[0]->name . ' GAGNE';
        }
        $this->verdictColour = $won ? [255, 255, 85] : [255, 170, 170];
        $this->ranking = array_map(fn($row) => [$row[0]->name, $row[1], $row[0]->rgb, $row[0] === $this->me], $rows);
        $this->emit('music', [$won ? 'actcomplete' : 'death', false]);
    }

    private static function time(float $seconds): string
    {
        return sprintf('%d:%05.2F', intdiv((int)$seconds, 60), fmod($seconds, 60));
    }

    private function isOnScreen(Rival $rival): bool
    {
        $w = $this->sdl->getWindow();
        $x = $rival->player->getX() - $this->camera->getX();
        $y = $rival->player->getY() - $this->camera->getY();
        return $x > -32 && $x < $w->getWidth() + 32 && $y > -32 && $y < $w->getHeight() + 32;
    }

    // ------------------------------------------------------------------ drawing

    /** In the game view (Scene, before Sonic): the other Sonics and the flag. */
    public function drawWorld(float $cameraX, float $cameraY): void
    {
        $renderer = $this->sdl->getRenderer()->getRenderer();
        foreach ($this->bases as $team => [$bx, $by]) {
            $this->drawBase((int)round($bx - $cameraX), (int)round($by - $cameraY), self::TEAM_COLOURS[$team]);
        }
        if ($this->flag !== null && $this->state !== 'countdown' && $this->carrier === null) {
            $this->drawFlag((int)round($this->flag[0] - $cameraX), (int)round($this->flag[1] - $cameraY), 40);
        }
        if ($this->mode === 'race') {
            $this->drawFinishLine((int)round($this->goalX - $cameraX), $cameraY);
        }
        foreach ($this->rivals as $rival) {
            if ($rival->human || !$rival->isVisible()) continue;
            [$sx, $sy, $sw, $sh, $flip] = $rival->player->getFrameSnapshot();
            $src = new \SDL_Rect;
            [$src->x, $src->y, $src->w, $src->h] = [$sx, $sy, $sw, $sh];
            $dst = new \SDL_Rect;
            [$dst->x, $dst->y, $dst->w, $dst->h] = [(int)round($rival->player->getX() - 4 - $cameraX), (int)round($rival->player->getY() - $cameraY), 32, 32];
            \SDL_RenderCopyEx($renderer, $this->sdl->getTextures($rival->texture)[0]['texture'], $src, $dst, 0, null,
                $flip ? \SDL_FLIP_HORIZONTAL : \SDL_FLIP_NONE);
        }
        // carried: a small flag over the head of its carrier
        if ($this->carrier !== null && $this->carrier->isVisible()) {
            $p = $this->carrier->player;
            $this->drawFlag((int)round($p->getX() + 12 - $cameraX), (int)round($p->getY() - 2 - $cameraY), 22);
        }
    }

    /** The flag, the foot of its pole at ($x, $y) (view pixels). */
    private function drawFlag(int $x, int $y, int $pole): void
    {
        $wave = (int)(fmod(microtime(true), 86400.0) * 6) % 2;
        $this->fill($x - 1, $y - $pole, 3, $pole, [240, 240, 240]);
        $this->fill($x - 1, $y - $pole - 2, 3, 3, [255, 216, 0]);
        $this->fill($x + 2, $y - $pole + 1 + $wave, 16, 11, [255, 216, 0]);
        $this->fill($x + 2, $y - $pole + 1 + $wave, 16, 2, [255, 255, 170]);
        $this->fill($x + 8, $y - $pole + 4 + $wave, 4, 4, [200, 40, 40]);
    }

    /** A base of the flag mode: a pad and a tall pole of the colour of the team. */
    private function drawBase(int $x, int $y, array $rgb): void
    {
        if ($x < -40 || $x > $this->sdl->getWindow()->getWidth() + 40) return;
        // dark outlines and deep colours: pale ones melted into the sky and looked like white blocks
        $deep = [(int)($rgb[0] * 0.7), (int)($rgb[1] * 0.55), (int)($rgb[2] * 0.7)];
        $this->fill($x - 21, $y - 7, 42, 7, [16, 16, 40]);
        $this->fill($x - 20, $y - 6, 40, 5, $deep);
        $this->fill($x - 20, $y - 6, 40, 1, $rgb);
        $this->fill($x - 3, $y - 66, 6, 60, [16, 16, 40]);
        $this->fill($x - 2, $y - 65, 4, 59, [120, 90, 60]);
        $this->fill($x + 3, $y - 66, 22, 16, [16, 16, 40]);
        $this->fill($x + 3, $y - 65, 21, 14, $deep);
        $this->fill($x + 3, $y - 65, 21, 3, $rgb);
    }

    /** Race: a chequered post from the ground up, at x (view pixels) of the goal. */
    private function drawFinishLine(int $x, float $cameraY): void
    {
        $height = $this->sdl->getWindow()->getHeight();
        if ($x < -8 || $x > $this->sdl->getWindow()->getWidth()) return;
        // the ground under the line, near the middle of the view
        $band = (int)floor($cameraY / 32);
        $ground = $this->finishGround[$band] ??= $this->level?->findSurfaceY((int)$this->goalX, $band * 32 + intdiv($height, 2), $height)
            ?? $band * 32 + $height;
        $bottom = (int)round($ground - $cameraY);
        for ($y = $bottom - 8, $row = 0; $y > $bottom - 96; $y -= 8, $row++) {
            $this->fill($x - 4, $y, 4, 8, $row % 2 ? [20, 20, 20] : [240, 240, 240]);
            $this->fill($x, $y, 4, 8, $row % 2 ? [240, 240, 240] : [20, 20, 20]);
        }
        $this->fill($x - 6, $bottom - 100, 12, 4, [255, 216, 0]);
    }

    private function fill(int $x, int $y, int $w, int $h, array $rgb): void
    {
        $rect = new \SDL_Rect;
        [$rect->x, $rect->y, $rect->w, $rect->h] = [$x, $y, $w, $h];
        $this->sdl->getRenderer()->setColor($rgb[0], $rgb[1], $rgb[2], 255);
        \SDL_RenderFillRect($this->sdl->getRenderer()->getRenderer(), $rect);
    }

    /**
     * Over the scaled picture, in window pixels ($dst: where the game view is, $scale: window pixels per game
     * pixel): names over the Sonics, marks on the edges for the ones (and the flag) out of the screen, the
     * scores, the countdown, the results.
     */
    public function drawOverlay(TextRenderer $text, \SDL_Rect $dst, float $scale, int $windowHeight): void
    {
        $size = max(12, (int)round($windowHeight / 34));
        $camX = $this->camera->getX();
        $camY = $this->camera->getY();
        $toWindow = fn(float $x, float $y) => [$dst->x + ($x - $camX) * $scale, $dst->y + ($y - $camY) * $scale];
        $margin = $size;

        // names over the heads, marks on the edges
        foreach ($this->rivals as $rival) {
            if ($rival->isKnockedOut()) continue;
            [$wx, $wy] = $toWindow($rival->player->getX() + 12, $rival->player->getY());
            $label = $rival->name;
            $w = $text->width($label, $size);
            if ($wx >= $dst->x && $wx <= $dst->x + $dst->w && $wy >= $dst->y && $wy <= $dst->y + $dst->h + 32 * $scale) {
                $text->draw($label, (int)($wx - $w / 2), (int)($wy - $size * 1.3), $size, $rival->rgb);
            } else {
                $this->edgeMark($text, $label, $rival->rgb, $wx, $wy, $dst, $size, $margin);
            }
        }
        if ($this->flag !== null && $this->state === 'play' && $this->carrier === null) {
            [$wx, $wy] = $toWindow($this->flag[0], $this->flag[1] - 20);
            if ($wx < $dst->x || $wx > $dst->x + $dst->w || $wy < $dst->y || $wy > $dst->y + $dst->h) {
                $this->edgeMark($text, 'DRAPEAU', [255, 216, 0], $wx, $wy, $dst, $size, $margin);
            }
        }
        // the base of the human's team, when it is out of the screen
        if ($this->me !== null && isset($this->bases[$this->me->team]) && $this->state === 'play') {
            [$bx, $by] = $this->bases[$this->me->team];
            [$wx, $wy] = $toWindow($bx, $by - 32);
            if ($wx < $dst->x || $wx > $dst->x + $dst->w || $wy < $dst->y || $wy > $dst->y + $dst->h) {
                $this->edgeMark($text, 'BASE', self::TEAM_COLOURS[$this->me->team], $wx, $wy, $dst, $size, $margin);
            }
        }

        $this->drawScores($text, $dst, $size);

        if ($this->state === 'countdown') {
            $big = $size * 4;
            $label = (string)max(1, (int)ceil($this->countdown));
            $w = $text->width($label, $big);
            $text->draw($label, (int)($dst->x + ($dst->w - $w) / 2), (int)($dst->y + $dst->h / 2 - $big), $big, [255, 255, 85]);
            $mode = self::MODES[$this->mode];
            $w = $text->width($mode, $size * 2);
            $text->draw($mode, (int)($dst->x + ($dst->w - $w) / 2), (int)($dst->y + $dst->h / 2 + $size), $size * 2, [255, 255, 255]);
        } elseif ($this->state === 'play' && $this->clock < 1) {
            $big = $size * 4;
            $w = $text->width('GO', $big);
            $text->draw('GO', (int)($dst->x + ($dst->w - $w) / 2), (int)($dst->y + $dst->h / 2 - $big), $big, [85, 255, 85]);
        } elseif ($this->state === 'over') {
            $this->drawResults($text, $dst, $size);
        } elseif ($this->banner !== null) {
            [$label, $rgb] = $this->banner;
            $big = $size * 2;
            $w = $text->width($label, $big);
            if ($w > $dst->w - $size * 2) {
                $big = $size;
                $w = $text->width($label, $big);
            }
            $text->draw($label, (int)($dst->x + ($dst->w - $w) / 2), (int)($dst->y + $dst->h / 3), $big, $rgb);
        }
    }

    /** A Sonic (or the flag) out of the screen: a square of its colour and its name on the nearest edge. */
    private function edgeMark(TextRenderer $text, string $label, array $rgb, float $wx, float $wy, \SDL_Rect $dst, int $size, int $margin): void
    {
        $w = $text->width($label, $size);
        $x = (int)max($dst->x + $margin, min($dst->x + $dst->w - $margin - $w, $wx - $w / 2));
        $y = (int)max($dst->y + $margin * 4, min($dst->y + $dst->h - $margin * 3, $wy));
        $this->fill($x - (int)($size * 0.9), $y + (int)($size * 0.15), (int)($size * 0.7), (int)($size * 0.7), $rgb);
        $text->draw($label, $x, $y, $size, $rgb);
    }

    /** Race: place of each Sonic (arrived ones first, in their order; then the furthest right). */
    private function racePlaces(): array
    {
        $order = array_keys($this->rivals);
        usort($order, fn($a, $b) => [$this->rivals[$a]->finishTime === null, $this->rivals[$a]->finishTime ?? -$this->rivals[$a]->player->getX()]
            <=> [$this->rivals[$b]->finishTime === null, $this->rivals[$b]->finishTime ?? -$this->rivals[$b]->player->getX()]);
        $places = [];
        foreach ($order as $place => $i) $places[$i] = $place + 1;
        return $places;
    }

    /** Top right: the mode, the time, the score of each Sonic (team scores in the flag mode). */
    private function drawScores(TextRenderer $text, \SDL_Rect $dst, int $size): void
    {
        $lines = [];
        $time = match ($this->mode) {
            'race' => self::time($this->clock),
            // time left in whole seconds: the line changes once a second (kept in a texture, TextRenderer)
            default => sprintf('%d:%02d', intdiv($left = (int)ceil(max(0, self::TIME_LIMIT - $this->clock)), 60), $left % 60),
        };
        $lines[] = [self::MODES[$this->mode] . '  ' . $time, [255, 255, 255]];
        if ($this->mode === 'flag') {
            $lines[] = ['BLEUS ' . $this->captures[0] . ' - ' . $this->captures[1] . ' ROUGES', [255, 255, 85]];
        }
        $places = $this->mode === 'race' ? $this->racePlaces() : [];
        foreach ($this->rivals as $i => $rival) {
            $value = match ($this->mode) {
                'race' => ($places[$i] === 1 ? '1ER' : $places[$i] . 'E') . ($rival->finishTime !== null ? ' ARRIVE' : ''),
                'flag' => self::TEAM_NAMES[$rival->team] . ($rival === $this->carrier ? '  DRAPEAU' : ''),
                'rings' => $rival->rings . ' ANNEAUX',
                default => $rival->kills . ' KO',
            };
            $lines[] = [($rival->human ? '> ' : '  ') . $rival->name . '  ' . $value, $rival->rgb];
        }
        $line = (int)round($size * 1.25);
        $width = max(array_map(fn($l) => $text->width($l[0], $size), $lines));
        $x = $dst->x + $dst->w - $width - $size * 2;
        $y = $dst->y + (int)($size * 0.6);
        $text->panel($x - $size, $y - (int)($size / 2), $width + $size * 2, count($lines) * $line + $size);
        foreach ($lines as $i => [$label, $rgb]) {
            $text->draw($label, $x, $y + $i * $line, $size, $rgb);
        }
    }

    private function drawResults(TextRenderer $text, \SDL_Rect $dst, int $size): void
    {
        $line = (int)round($size * 1.4);
        $rows = count($this->ranking);
        $width = (int)min($dst->w - $size * 2, max($size * 26, $text->width($this->verdict, $size * 2) + $size * 3));
        // the verdict in big letters, smaller if the screen is too narrow for it
        $big = $text->width($this->verdict, $size * 2) + $size * 2 <= $width ? $size * 2 : (int)($size * 1.4);
        $height = $size * 4 + $rows * $line + $size * 3;
        $x = (int)($dst->x + ($dst->w - $width) / 2);
        $y = (int)($dst->y + ($dst->h - $height) / 2);
        // twice: darker
        $text->panel($x, $y, $width, $height);
        $text->panel($x, $y, $width, $height);
        $w = $text->width($this->verdict, $big);
        $text->draw($this->verdict, (int)($x + ($width - $w) / 2), $y + $size, $big, $this->verdictColour);
        foreach ($this->ranking as $i => [$name, $value, $rgb, $me]) {
            $ly = $y + $size * 4 + $i * $line;
            $text->draw(($i + 1) . '  ' . $name, $x + $size * 2, $ly, $size, $rgb);
            $w = $text->width($value, $size);
            $text->draw($value, $x + $width - $size * 2 - $w, $ly, $size, $me ? [255, 255, 85] : [255, 255, 255]);
        }
        $hint = 'SAUT POUR CONTINUER';
        if ((int)(fmod(microtime(true), 86400.0) * 2) % 2 === 0) {
            $w = $text->width($hint, $size);
            $text->draw($hint, (int)($x + ($width - $w) / 2), $y + $height - (int)($size * 1.6), $size, [200, 200, 200]);
        }
    }
}
