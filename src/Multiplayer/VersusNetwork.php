<?php

namespace SonicGame\Multiplayer;

use SonicGame\Entities\GameObjectManager;
use SonicGame\Entities\Player;
use SonicGame\SoundManager\SoundManager;

/**
 * Online match, part of Versus (a trait: it reads and changes the state of the match).
 *
 * The host is the authority: his Versus runs the bots and all the rules (contacts, rings given, flag, finish
 * line, scores, clock). Each player moves his own Sonic on his machine (no delay for him) and sends its
 * state 30 times a second (Online::STATE); on the host it is a puppet that the rules see. The host sends
 * the whole match 20 times a second (Online::SNAPSHOT) and, reliably, what a player must apply to his Sonic
 * (Online::EVENT: hurt, bounce, rings won, where to appear) and the end. The other Sonics are drawn
 * DELAY seconds late, between two received positions. The rings taken (blocks of the map) are sent as events
 * and applied everywhere.
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
            if (!$this->applyingTiles && GameObjectManager::isRingBlock($value)) $this->tileOutbox[] = [$x, $y, $value];
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
    }

    private function netEvent(array $event, ?int $to = null): void
    {
        $this->online?->sendJson(Online::EVENT, $event, $to);
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
            $frame = $rival->puppet && $rival->netFrame ? $rival->netFrame : $p->getFrameSnapshot();
            $dead = $rival->human ? $this->objects->isPlayerDead() : !empty($rival->netInfo['dead']);
            $flags = ($frame[4] ? 1 : 0) | ($rival->invulnerable > 0 || ($rival->human && $this->objects->isPlayerHurt()) ? 2 : 0)
                | ($rival->isKnockedOut() ? 4 : 0) | ($this->isAttacking($rival) ? 8 : 0)
                | ($p->getState() === 'hurt' ? 16 : 0) | ($dead ? 32 : 0)
                | (($rival->human ? $this->objects->hasShield() : $rival->shield) ? 64 : 0)
                | (($rival->human ? $this->objects->hasInvincibility() : $rival->invincible) ? 128 : 0);
            $data .= pack('ggvvCCCCvvvCg', $p->getX(), $p->getY(), $frame[0], $frame[1], $frame[2], $frame[3], $flags,
                self::stateCode($p->getState()), min(65535, $rival->rings), $rival->kills, $rival->deaths, $rival->captures,
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
        $frame = [$s['sx'], $s['sy'], $s['sw'], $s['sh'], $s['flags'] & 1];
        $this->remember($rival, $s['x'], $s['y'], $frame);
        $rival->player->setVelocity($s['vx'], $s['vy']);
        $rival->player->setState(self::STATES[$s['state']] ?? 'idle');
        if ($s['flags'] & 2) $rival->invulnerable = max($rival->invulnerable, 0.2);
        $rival->rings = $s['rings'];
        $rival->netInfo = ['rings' => $s['rings'], 'shield' => (bool)($s['flags'] & 32), 'dead' => (bool)($s['flags'] & 16),
            'attacking' => (bool)($s['flags'] & 4), 'invincible' => (bool)($s['flags'] & 64)];
        $rival->shield = $rival->netInfo['shield'];
        $rival->invincible = $rival->netInfo['invincible'];
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
        $this->showBanner($rival->name . ' REMPLACE PAR UN BOT', [255, 255, 255], 2.0);
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
        $frame = $p->getFrameSnapshot();
        [$vx, $vy] = $p->getVelocity();
        $flags = ($frame[4] ? 1 : 0) | ($this->me->invulnerable > 0 || $this->objects->isPlayerHurt() ? 2 : 0)
            | ($this->isAttacking($this->me) ? 4 : 0) | ($this->objects->isPlayerDead() ? 16 : 0)
            | ($this->objects->hasShield() ? 32 : 0) | ($this->objects->hasInvincibility() ? 64 : 0);
        return pack('ggggvvCCCCv', $p->getX(), $p->getY(), $vx, $vy, $frame[0], $frame[1], $frame[2], $frame[3], $flags,
            self::stateCode($p->getState()), min(65535, $this->objects->getRingCount()));
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
            $this->remember($rival, $r['x'], $r['y'], [$r['sx'], $r['sy'], $r['sw'], $r['sh'], $r['flags'] & 1]);
            $rival->player->setState(($r['flags'] & 8) ? 'jump' : (self::STATES[$r['state']] ?? 'idle'));
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
                    if ($current === null || !GameObjectManager::isRingBlock($current)) continue;
                    $taken = ($current & 3) & ~((int)$value & 3);
                    if ($taken) $this->level->setTile((int)$x, (int)$y, $current & ~$taken);
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
                    $victim->rings = max(0, $victim->rings - (int)$event['n']);
                    $this->giveAway($victim, (int)$event['n'], $by >= 0 ? ($this->rivals[$by] ?? null) : null);
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
                    $this->banner = [(string)$event['text'], $event['rgb'] ?? [255, 255, 255], (float)($event['s'] ?? 2)];
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

    /** A message in the middle of the screen, here and (host) on every machine. */
    private function showBanner(string $text, array $rgb, float $seconds, ?int $sfx = null): void
    {
        $this->banner = [$text, $rgb, $seconds];
        if ($sfx !== null) $this->sfx($sfx);
        if ($this->online !== null && $this->isHost()) {
            $this->netEvent(['e' => 'banner', 'text' => $text, 'rgb' => $rgb, 's' => $seconds] + ($sfx !== null ? ['sfx' => $sfx] : []));
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

    private static function stateCode(string $state): int
    {
        $code = array_search($state, self::STATES, true);
        return $code === false ? 0 : $code;
    }
}
