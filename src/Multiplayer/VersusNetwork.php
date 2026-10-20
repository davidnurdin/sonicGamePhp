<?php

namespace SonicGame\Multiplayer;

use SonicGame\Entities\GameObjectManager;
use SonicGame\Entities\Player;
use SonicGame\SoundManager\SoundManager;
use SonicGame\Utils\Lang;

/**
 * Online match, part of Versus (a trait: it reads and changes the state of the match).
 *
 * The host is the authority: his Versus runs the bots and all the rules (contacts, rings given, flag, finish
 * line, scores, clock). Each player moves his own Sonic on his machine (no delay for him) and sends its
 * state 30 times a second (Online::STATE); on the host it is a puppet that the rules see. The host sends
 * the whole match 20 times a second (Online::SNAPSHOT) and, reliably, what a player must apply to his Sonic
 * (Online::EVENT: hurt, bounce, rings won, where to appear) and the end. The other Sonics are drawn
 * DELAY seconds late, between two received positions. The rings taken and the bridges (blocks of the map), the
 * monitors broken, badniks destroyed, platforms stood on and floor switches (GameObjectManager ids) are sent as
 * events and applied everywhere.
 */
trait VersusNetwork
{
    private ?Online $online = null;
    private ?array $netConfig = null;
    private int $mySlot = 0;
    private float $snapshotTimer = 0;
    private float $stateTimer = 0;
    private bool $snapshotSeen = false;
    /** Ring blocks changed here, to send: [x, y, value]. */
    private array $tileOutbox = [];
    private bool $applyingTiles = false;
    /** What was done here to the objects, to send: [id, what] (GameObjectManager::applyObject). */
    private array $objectOutbox = [];
    /**
     * Host, anti-cheat: per player, what his states may say (a player can't go faster than MAX_SPEED, nor have
     * rings, a shield or the invincibility he didn't get). rings: accepted; credit: rings he may still gain
     * (blocks and monitors he took, rings given by the host); dropped: rings lost, that he may give away;
     * shield / invincible (until): from a monitor he broke; excuse (until): a jump in place is allowed (start,
     * death, hit by the host); x, y, t: his last accepted place.
     */
    private array $guards = [];
    /** Host, anti-cheat: px per second a Sonic can't go faster than (springs included), and the slack in px. */
    private const MAX_SPEED = 1800;
    private const MAX_JUMP = 64;

    /** The client lost his host: the match is over for him. */
    private ?string $netLost = null;

    /** Seconds the other Sonics are drawn late (two snapshots at 20 per second, and some jitter). */
    private const DELAY = 0.12;
    private const SNAPSHOT_RATE = 20;
    private const STATE_RATE = 30;
    private const STATES = ['idle', 'walk', 'run', 'jump', 'roll', 'hurt', 'dead', 'stopRun'];

    /**
     * Host: the match for the players of the room. Every player has a slot (its Sonic, its colour, its team);
     * the free slots are bots. Flag mode: the players are shared out between the two teams.
     */
    public static function startConfig(Online $online, string $mode, int $level, int $difficulty, int $players): array
    {
        $peers = $online->peers();
        $humans = count($peers);
        if ($mode === 'flag') {
            $total = max(2, min(6, max($players, $humans + $humans % 2)));
            $total -= $total % 2;
            $half = intdiv($total, 2);
            $order = [];
            for ($i = 0; $i < $half; $i++) array_push($order, $i, $half + $i);
        } else {
            $total = max(2, min(6, max($players, $humans)));
            $order = range(0, $total - 1);
        }
        $slots = array_fill(0, $total, null);
        foreach ($peers as $k => $peer) {
            if (!isset($order[$k])) break;
            $slots[$order[$k]] = ['peer' => $peer, 'name' => $online->names[$peer] ?? 'J' . ($peer + 1)];
        }
        return ['mode' => $mode, 'level' => $level, 'difficulty' => $difficulty, 'players' => $total, 'slots' => $slots,
            'swap' => mt_rand(0, 1) === 1];
    }

    public function isOnline(): bool
    {
        return $this->online !== null;
    }

    private function isHost(): bool
    {
        return $this->online === null || $this->online->isHost();
    }

    private function isClient(): bool
    {
        return $this->online !== null && !$this->online->isHost();
    }

    /** begin(): the Rival of slot $i (online), or null (offline: Versus makes it). */
    private function netRival(int $i, int $team, string $sheet, array $rgb): ?Rival
    {
        if ($this->online === null) return null;
        $slot = $this->netConfig['slots'][$i] ?? null;
        if ($slot !== null && $slot['peer'] === $this->online->me) {
            $this->mySlot = $i;
            $this->human->setSheet($sheet);
            return new Rival($this->human, true, $slot['name'], $sheet, $rgb, $team);
        }
        $body = new Player($this->sdl);
        $rival = new Rival($body, false, $slot['name'] ?? 'CPU' . ($i + 1), $sheet, $rgb, $team,
            $slot === null ? new BotBrain($this->difficulty) : null);
        $rival->peer = $slot['peer'] ?? null;
        // the client sees everybody through the network; the host, the other players
        $rival->puppet = $this->isClient() || $rival->peer !== null;
        return $rival;
    }

    /** begin(), once the Sonics are placed. */
    private function netBegin(): void
    {
        if ($this->online === null) return;
        $this->me = $this->rivals[$this->mySlot];
        $this->level->tileListener = function (int $x, int $y, int $value) {
            // rings taken, bridges falling apart (the block before the change: $88-$8A)
            $before = $this->level->getTile($x, $y) ?? 0;
            if (!$this->applyingTiles && (GameObjectManager::isRingBlock($value) || ($before >= 0x88 && $before <= 0x8A))) {
                $this->tileOutbox[] = [$x, $y, $value];
            }
        };
        $this->objects->objectListener = function (int $id, string $what) {
            if (!$this->applyingTiles) $this->objectOutbox[] = [$id, $what];
        };
        if ($this->isHost()) {
            // every player where the host placed him
            foreach ($this->rivals as $rival) {
                if ($rival->peer !== null) {
                    $this->netEvent(['e' => 'place', 'x' => $rival->player->getX(), 'y' => $rival->player->getY()], $rival->peer);
                }
            }
        }
    }

    /** The match is left (Game::clearVersus): the map stops reporting its blocks. */
    public function close(): void
    {
        if ($this->level !== null) $this->level->tileListener = null;
        $this->objects->objectListener = null;
    }

    private function netEvent(array $event, ?int $to = null): void
    {
        $this->online?->sendJson(Online::EVENT, $event, $to);
        if ($to === null || !$this->isHost()) return;
        // anti-cheat: what the host gives a player
        $guard = &$this->guard($to);
        if ($event['e'] === 'gain') $guard['credit'] += (int)$event['n'];
        if ($event['e'] === 'hurt' || $event['e'] === 'place') $guard['excuse'] = microtime(true) + 4;
    }

    private function &guard(int $peer): array
    {
        $this->guards[$peer] ??= ['rings' => 0, 'credit' => 0, 'dropped' => 0, 'shield' => false, 'invincible' => 0.0,
            'excuse' => microtime(true) + 6, 'x' => null, 'y' => null, 't' => 0.0];
        return $this->guards[$peer];
    }

    private function slotOf(?Rival $rival): int
    {
        if ($rival === null) return -1;
        $slot = array_search($rival, $this->rivals, true);
        return $slot === false ? -1 : $slot;
    }

    private function rivalOfPeer(int $peer): ?Rival
    {
        foreach ($this->rivals as $rival) {
            if ($rival->peer === $peer) return $rival;
        }
        return null;
    }

    // ------------------------------------------------------------------ every frame

    /** Start of update(): what came from the network. */
    private function netReceive(): void
    {
        $online = $this->online;
        if ($online === null) return;
        if ($this->isClient() && ($online->status === 'error' || $online->status === 'closed')) {
            $this->netAbort('CONNEXION PERDUE');
            return;
        }
        $inbox = $online->inbox;
        $online->inbox = [];
        foreach ($inbox as $message) {
            [$type, $from] = $message;
            if ($type === 'leave') {
                $this->peerLeft($from);
                continue;
            }
            if ($type === 'host') {
                // the host left: the match can't go on without him
                if ($this->isClient() || $from === $online->me) $this->netAbort('L HOTE EST PARTI');
                continue;
            }
            $payload = $message[2];
            match ($type) {
                Online::SNAPSHOT => $from === $online->host && $this->isClient() ? $this->applySnapshot($payload) : null,
                Online::STATE => $this->isHost() ? $this->applyState($from, $payload) : null,
                Online::EVENT => $this->applyEvent($from, json_decode($payload, true) ?: []),
                default => null,
            };
        }
    }

    /** End of update(): what to send. */
    private function netSend(float $deltaTime): void
    {
        if ($this->online === null || $this->netLost !== null) return;
        if ($this->tileOutbox) {
            $this->netEvent(['e' => 'tiles', 't' => $this->tileOutbox]);
            $this->tileOutbox = [];
        }
        if ($this->objectOutbox) {
            $this->netEvent(['e' => 'obj', 'o' => $this->objectOutbox]);
            $this->objectOutbox = [];
        }
        if ($this->isHost()) {
            $this->snapshotTimer += $deltaTime;
            if ($this->snapshotTimer >= 1 / self::SNAPSHOT_RATE) {
                $this->snapshotTimer = 0;
                $this->online->send(Online::SNAPSHOT, $this->encodeSnapshot(), null, false);
            }
        } else {
            $this->stateTimer += $deltaTime;
            if ($this->stateTimer >= 1 / self::STATE_RATE) {
                $this->stateTimer = 0;
                $this->online->send(Online::STATE, $this->encodeState(), $this->online->host, false);
            }
        }
    }

    /** Puppets: drawn DELAY seconds late, between the two positions around that time. */
    private function movePuppets(): void
    {
        $time = microtime(true) - self::DELAY;
        foreach ($this->rivals as $rival) {
            if (!$rival->puppet || !$rival->track) continue;
            $track = &$rival->track;
            while (count($track) > 2 && $track[1][0] <= $time) array_shift($track);
            [$t0, $x0, $y0, $frame] = $track[0];
            [$x, $y] = [$x0, $y0];
            if (isset($track[1]) && $track[1][0] > $t0) {
                [$t1, $x1, $y1] = $track[1];
                $k = max(0.0, min(1.0, ($time - $t0) / ($t1 - $t0)));
                // a jump of more than 3 screens: a respawn, no slide between the two places
                if (abs($x1 - $x0) + abs($y1 - $y0) < 600) {
                    $x = $x0 + ($x1 - $x0) * $k;
                    $y = $y0 + ($y1 - $y0) * $k;
                } elseif ($k >= 0.5) {
                    [$x, $y] = [$x1, $y1];
                }
                if ($k >= 0.5) $frame = $track[1][3];
            }
            unset($track);
            $rival->player->setXY($x, $y);
            $rival->netFrame = $frame;
        }
    }

    private function remember(Rival $rival, float $x, float $y, array $frame): void
    {
        $rival->track[] = [microtime(true), $x, $y, $frame];
        if (count($rival->track) > 12) array_shift($rival->track);
    }

    // ------------------------------------------------------------------ host

    /** The state of the match, for the clients. */
    private function encodeSnapshot(): string
    {
        $state = ['countdown' => 0, 'play' => 1, 'over' => 2][$this->state];
        [$fx, $fy] = $this->flag ?? [-99999, -99999];
        $data = pack('CggCCcggC', $state, $this->countdown, $this->clock, $this->captures[0], $this->captures[1],
            $this->slotOf($this->carrier), $fx, $fy, count($this->rivals));
        foreach ($this->rivals as $rival) {
            $p = $rival->player;
            $frame = $rival->puppet && $rival->netFrame ? $rival->netFrame : $p->getDrawnFrame();
            $dead = $rival->human ? $this->objects->isPlayerDead() : !empty($rival->netInfo['dead']);
            $flags = ($frame[4] ? 1 : 0) | ($rival->invulnerable > 0 || ($rival->human && $this->objects->isPlayerHurt()) ? 2 : 0)
                | ($rival->isKnockedOut() ? 4 : 0) | ($this->isAttacking($rival) ? 8 : 0)
                | ($p->getState() === 'hurt' ? 16 : 0) | ($dead ? 32 : 0)
                | (($rival->human ? $this->objects->hasShield() : $rival->shield) ? 64 : 0)
                | (($rival->human ? $this->objects->hasInvincibility() : $rival->invincible) ? 128 : 0);
            $data .= pack('ggvvCCCCvvvCg', $p->getX(), $p->getY(), $frame[0], $frame[1], $frame[2], $frame[3], $flags,
                self::stateCode($p->getState(), $frame[5]), min(65535, $rival->rings), $rival->kills, $rival->deaths, $rival->captures,
                $rival->finishTime ?? -1.0);
        }
        return $data;
    }

    /** A player's Sonic (host side): its puppet takes the reported place. */
    private function applyState(int $peer, string $data): void
    {
        $rival = $this->rivalOfPeer($peer);
        if ($rival === null || strlen($data) < 26) return;
        $s = unpack('gx/gy/gvx/gvy/vsx/vsy/Csw/Csh/Cflags/Cstate/vrings', $data);
        if (!is_finite($s['x']) || !is_finite($s['y']) || !is_finite($s['vx']) || !is_finite($s['vy'])) return;
        // anti-cheat: no jump in place (but after a death, a hit, the start, or to a place to appear)
        $guard = &$this->guard($peer);
        $now = microtime(true);
        if ($guard['x'] !== null && $now > $guard['excuse'] && !$this->nearSpawn($s['x'], $s['y'])
            && hypot($s['x'] - $guard['x'], $s['y'] - $guard['y']) > self::MAX_SPEED * max(1 / 30, $now - $guard['t']) + self::MAX_JUMP) {
            return;
        }
        if ($s['flags'] & 16) $guard['excuse'] = $now + 4;
        [$guard['x'], $guard['y'], $guard['t']] = [$s['x'], $s['y'], $now];
        $vx = max(-self::MAX_SPEED, min(self::MAX_SPEED, $s['vx']));
        $vy = max(-self::MAX_SPEED, min(self::MAX_SPEED, $s['vy']));
        // rings: only those he could take; shield and invincibility: only from a monitor he broke
        if ($s['rings'] > $guard['rings']) {
            $up = min($s['rings'] - $guard['rings'], $guard['credit']);
            $guard['credit'] -= $up;
            $guard['rings'] += $up;
        } else {
            $guard['dropped'] += $guard['rings'] - $s['rings'];
            $guard['rings'] = $s['rings'];
        }
        if (!($s['flags'] & 32)) $guard['shield'] = false;
        $shield = ($s['flags'] & 32) && $guard['shield'];
        $invincible = ($s['flags'] & 64) && $now < $guard['invincible'];

        $frame = [$s['sx'], $s['sy'], $s['sw'], $s['sh'], $s['flags'] & 1, $s['state'] >> 7];
        $this->remember($rival, $s['x'], $s['y'], $frame);
        $rival->latest = [$now, $s['x'], $s['y'], $vx, $vy];
        $rival->player->setVelocity($vx, $vy);
        $rival->player->setState(self::STATES[$s['state'] & 0x7F] ?? 'idle');
        if ($s['flags'] & 2) $rival->invulnerable = max($rival->invulnerable, 0.2);
        $rival->rings = $guard['rings'];
        $rival->netInfo = ['rings' => $guard['rings'], 'shield' => $shield, 'dead' => (bool)($s['flags'] & 16),
            'attacking' => (bool)($s['flags'] & 4), 'invincible' => $invincible];
        $rival->shield = $rival->netInfo['shield'];
        $rival->invincible = $rival->netInfo['invincible'];
    }

    /** Anti-cheat: ($x, $y) is a place where a Sonic may appear (start, places to appear, bases). */
    private function nearSpawn(float $x, float $y): bool
    {
        foreach ([$this->start, ...$this->spawns] as [$sx, $sy]) {
            if (abs($x - $sx) < 96 && abs($y - $sy) < 96) return true;
        }
        return false;
    }

    /**
     * Host, lag compensation: the collision rect of a Sonic for the contacts. A player of another machine is
     * drawn DELAY late; for the contacts he is where he is on his screen now: his last state, moved by his
     * speed for its age and the time it took to come (about the host's ping: half his way to the server,
     * half the player's).
     */
    private function contactRect(Rival $rival): array
    {
        $rect = $rival->player->getCollisionRect();
        if (!$rival->puppet || $rival->latest === null || !$this->isHost()) return $rect;
        [$time, $x, $y, $vx, $vy] = $rival->latest;
        $ahead = min(0.25, microtime(true) - $time + ($this->online?->pingMs ?? 0) / 1000);
        $rect['x'] += $x + $vx * $ahead - $rival->player->getX();
        $rect['y'] += $y + $vy * $ahead - $rival->player->getY();
        return $rect;
    }

    /** A player left (host): a bot takes his Sonic over. */
    private function peerLeft(int $peer): void
    {
        $rival = $this->rivalOfPeer($peer);
        if ($rival === null || !$this->isHost()) return;
        if ($rival === $this->carrier) $this->dropFlag();
        $rival->peer = null;
        $rival->puppet = false;
        $rival->netFrame = null;
        $rival->track = [];
        $rival->shield = $rival->invincible = false;
        $rival->brain = new BotBrain($this->difficulty);
        $rival->name = 'CPU' . ($this->slotOf($rival) + 1);
        $this->showBanner('{name} REMPLACE PAR UN BOT', ['name' => $rival->name], [255, 255, 255], 2.0);
    }

    private function netFinish(?int $flagTeam): void
    {
        if ($this->online === null || !$this->isHost()) return;
        $this->netEvent(['e' => 'over', 'flagTeam' => $flagTeam, 'scores' => array_map(fn(Rival $r) => [
            $r->rings, $r->kills, $r->deaths, $r->captures, $r->finishTime], $this->rivals)]);
        // the last snapshot (state "over"), reliable
        $this->online->send(Online::SNAPSHOT, $this->encodeSnapshot());
    }

    // ------------------------------------------------------------------ client

    /** My Sonic, for the host. */
    private function encodeState(): string
    {
        $p = $this->human;
        $frame = $p->getDrawnFrame();
        [$vx, $vy] = $p->getVelocity();
        $flags = ($frame[4] ? 1 : 0) | ($this->me->invulnerable > 0 || $this->objects->isPlayerHurt() ? 2 : 0)
            | ($this->isAttacking($this->me) ? 4 : 0) | ($this->objects->isPlayerDead() ? 16 : 0)
            | ($this->objects->hasShield() ? 32 : 0) | ($this->objects->hasInvincibility() ? 64 : 0);
        return pack('ggggvvCCCCv', $p->getX(), $p->getY(), $vx, $vy, $frame[0], $frame[1], $frame[2], $frame[3], $flags,
            self::stateCode($p->getState(), $frame[5]), min(65535, $this->objects->getRingCount()));
    }

    private function applySnapshot(string $data): void
    {
        if (strlen($data) < 21) return;
        $h = unpack('Cstate/gcountdown/gclock/Ccap0/Ccap1/ccarrier/gfx/gfy/Cn', $data);
        if ($h['n'] !== count($this->rivals)) return;
        $this->snapshotSeen = true;
        $state = ['countdown', 'play', 'over'][$h['state']] ?? 'countdown';
        if ($this->state === 'over') return;
        // countdown: the same beeps as the host
        if ($state === 'countdown' && (int)ceil($h['countdown']) !== (int)ceil($this->countdown)) $this->sfx(SoundManager::SFX_SWITCH);
        if ($state === 'play' && $this->state === 'countdown') $this->sfx(SoundManager::SFX_EXTRA_LIFE);
        if ($state !== 'over') $this->state = $state;
        $this->countdown = $h['countdown'];
        $this->clock = $h['clock'];
        $this->captures = [$h['cap0'], $h['cap1']];
        $carrier = $h['carrier'] >= 0 ? ($this->rivals[$h['carrier']] ?? null) : null;
        if ($carrier !== $this->carrier) {
            if ($this->carrier === $this->me) $this->restoreSpeed($this->me);
            $this->carrier = $carrier;
        }
        $this->flag = $h['fx'] > -99990 ? [$h['fx'], $h['fy']] : null;
        $offset = 21;
        foreach ($this->rivals as $i => $rival) {
            $r = unpack('gx/gy/vsx/vsy/Csw/Csh/Cflags/Cstate/vrings/vkills/vdeaths/Ccaptures/gfinish', $data, $offset);
            $offset += 27;
            $rival->kills = $r['kills'];
            $rival->deaths = $r['deaths'];
            $rival->captures = $r['captures'];
            $rival->finishTime = $r['finish'] >= 0 ? $r['finish'] : null;
            if ($rival === $this->me) continue;   // my Sonic and my rings are mine
            $rival->rings = $r['rings'];
            $this->remember($rival, $r['x'], $r['y'], [$r['sx'], $r['sy'], $r['sw'], $r['sh'], $r['flags'] & 1, $r['state'] >> 7]);
            $rival->player->setState(($r['flags'] & 8) ? 'jump' : (self::STATES[$r['state'] & 0x7F] ?? 'idle'));
            $rival->invulnerable = ($r['flags'] & 2) ? max($rival->invulnerable, 0.2) : 0;
            $rival->koTimer = ($r['flags'] & 4) ? 1.0 : 0;
            $rival->shield = (bool)($r['flags'] & 64);
            $rival->invincible = (bool)($r['flags'] & 128);
        }
    }

    /** Client, in update() during the match: my part of the rules. */
    private function clientUpdate(float $deltaTime): void
    {
        $this->me->invulnerable = max(0, $this->me->invulnerable - $deltaTime);
        if ($this->banner !== null && ($this->banner[2] -= $deltaTime) <= 0) $this->banner = null;
        if ($this->state !== 'play') return;
        if ($this->objects->isPlayerDead() && !$this->humanDead) {
            $this->humanDead = true;
            if ($this->mode === 'rings') $this->objects->takeRings();
        }
        $this->countHumanRings();
        if ($this->carrier === $this->me) {
            $this->human->setTopSpeed(($this->objects->hasSpeedShoes() ? 1000 : 500) * self::CARRIER_SPEED);
        }
    }

    /** The host is gone (or the connection): the match ends where it is. */
    private function netAbort(string $why): void
    {
        if ($this->netLost !== null) return;
        $this->netLost = $why;
        if ($this->state !== 'over') {
            $this->finish();
            $this->verdict = $why;
            $this->verdictColour = [255, 170, 170];
        }
    }

    // ------------------------------------------------------------------ events (both ways)

    private function applyEvent(int $from, array $event): void
    {
        $host = $from === $this->online->host;
        switch ($event['e'] ?? '') {
            case 'tiles':
                // rings taken somewhere: taken here too; the host passes them on to the others
                $this->applyingTiles = true;
                foreach ($event['t'] ?? [] as [$x, $y, $value]) {
                    $current = $this->level->getTile((int)$x, (int)$y);
                    if ($current >= 0x88 && $current <= 0x8A) {
                        $this->objects->collapseBridge((int)$x, (int)$y, (int)$value);
                        continue;
                    }
                    if ($current === null || !GameObjectManager::isRingBlock($current)) continue;
                    $taken = ($current & 3) & ~((int)$value & 3);
                    if ($taken) $this->level->setTile((int)$x, (int)$y, $current & ~$taken);
                    // anti-cheat: the rings a player took (and that were still there) may be counted for him
                    if ($taken && $this->isHost() && !$host) $this->guard($from)['credit'] += ($taken & 1) + ($taken >> 1);
                }
                $this->applyingTiles = false;
                if ($this->isHost() && !$host) $this->netEvent($event);
                break;
            case 'obj':
                // monitors broken, badniks destroyed, platforms stood on, switches somewhere: here too; the host
                // passes them on
                $this->applyingTiles = true;
                foreach ($event['o'] ?? [] as [$id, $what]) {
                    // anti-cheat: what the monitor he broke gives (if it was still there)
                    $item = $what === 'gone' && $this->isHost() && !$host ? $this->objects->monitorItem((int)$id) : null;
                    $this->objects->applyObject((int)$id, (string)$what);
                    $guard = &$this->guard($from);
                    match ($item) {
                        'ring' => $guard['credit'] += 10,
                        'shield' => $guard['shield'] = true,
                        'invincibility' => $guard['invincible'] = microtime(true) + 21,
                        default => null,
                    };
                    unset($guard);
                }
                $this->applyingTiles = false;
                if ($this->isHost() && !$host) $this->netEvent($event);
                break;
            case 'place':
                if ($host) $this->placeHuman((float)$event['x'], (float)$event['y']);
                break;
            case 'hurt':
                // the host saw a Sonic hit mine: same rules as a local hit
                if ($host) {
                    $by = (int)($event['by'] ?? -1);
                    $this->me->invulnerable = 0;
                    $this->hit($this->me, $by >= 0 ? ($this->rivals[$by] ?? null) : null);
                }
                break;
            case 'bounce':
                if ($host) {
                    $this->human->setVelocity((float)$event['vx'], min($this->human->getVelocity()[1], (float)$event['vy']));
                    $this->human->setGrounded(false);
                    $this->me->invulnerable = max($this->me->invulnerable, 0.3);
                    $this->sfx(SoundManager::SFX_BUMPER);
                }
                break;
            case 'gain':
                if ($host) {
                    $this->objects->gainRings((int)$event['n']);
                    $this->humanRings = $this->objects->getRingCount();
                    $this->me->rings = $this->humanRings;
                    $this->sfx(SoundManager::SFX_RING);
                }
                break;
            case 'lost':
                // a player lost rings (rings mode): the host gives them away
                $victim = $this->isHost() ? $this->rivalOfPeer($from) : null;
                if ($victim !== null) {
                    $by = (int)($event['by'] ?? -1);
                    // anti-cheat: no more than he had
                    $guard = &$this->guard($from);
                    $n = max(0, min((int)$event['n'], $guard['dropped'] + $victim->rings));
                    $guard['dropped'] = max(0, $guard['dropped'] - $n);
                    $victim->rings = max(0, $victim->rings - $n);
                    $this->giveAway($victim, $n, $by >= 0 ? ($this->rivals[$by] ?? null) : null);
                }
                break;
            case 'died':
                $victim = $this->isHost() ? $this->rivalOfPeer($from) : null;
                if ($victim !== null) {
                    $victim->deaths++;
                    if ($victim === $this->carrier) $this->dropFlag();
                }
                break;
            case 'banner':
                if ($host) {
                    // the host sends the text to translate (Lang key) and its values: each one in his language
                    $values = array_map(fn($v) => Lang::t((string)$v), (array)($event['v'] ?? []));
                    $this->banner = [Lang::t((string)$event['text'], $values), $event['rgb'] ?? [255, 255, 255], (float)($event['s'] ?? 2)];
                    if (isset($event['sfx'])) $this->sfx((int)$event['sfx']);
                }
                break;
            case 'sfx':
                if ($host) $this->sfx((int)$event['i']);
                break;
            case 'over':
                if (!$host || $this->state === 'over') break;
                foreach ($event['scores'] ?? [] as $i => [$rings, $kills, $deaths, $captures, $finish]) {
                    if (!isset($this->rivals[$i])) continue;
                    $rival = $this->rivals[$i];
                    [$rival->rings, $rival->kills, $rival->deaths, $rival->captures, $rival->finishTime] = [$rings, $kills, $deaths, $captures, $finish];
                }
                $this->finish($event['flagTeam'] ?? null);
                break;
        }
    }

    /** A message in the middle of the screen ($text: a Lang key, {key} from $values), here and (host) on every machine. */
    private function showBanner(string $text, array $values, array $rgb, float $seconds, ?int $sfx = null): void
    {
        $this->banner = [Lang::t($text, array_map(fn($v) => Lang::t((string)$v), $values)), $rgb, $seconds];
        if ($sfx !== null) $this->sfx($sfx);
        if ($this->online !== null && $this->isHost()) {
            $this->netEvent(['e' => 'banner', 'text' => $text, 'v' => $values, 'rgb' => $rgb, 's' => $seconds] + ($sfx !== null ? ['sfx' => $sfx] : []));
        }
    }

    /** Host: a sound every machine plays. */
    private function sfxAll(int $index): void
    {
        $this->sfx($index);
        if ($this->online !== null && $this->isHost()) $this->netEvent(['e' => 'sfx', 'i' => $index]);
    }

    /** Race: every player (here and online) has arrived. */
    private function humansArrived(): bool
    {
        foreach ($this->rivals as $rival) {
            if (($rival->human || $rival->peer !== null) && $rival->finishTime === null) return false;
        }
        return true;
    }

    /** The state of a Sonic in one byte; bit 7: his frame is one of the ROM art (Player::getDrawnFrame). */
    private static function stateCode(string $state, int $rom = 0): int
    {
        $code = array_search($state, self::STATES, true);
        return ($code === false ? 0 : $code) | ($rom ? 0x80 : 0);
    }
}
