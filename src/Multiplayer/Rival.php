<?php

namespace SonicGame\Multiplayer;

use SonicGame\Entities\Player;

/**
 * One Sonic of a multiplayer match: the human player (his Player, rings counted by GameObjectManager) or a
 * computer one (its own Player, moved by a BotBrain, rings counted here).
 */
class Rival
{
    /** Seconds of blinking after a hit or a respawn: can't be hit again. */
    public float $invulnerable = 0;
    /** > 0: knocked out (flying off the screen), respawns when it reaches 0. */
    public float $koTimer = 0;
    private float $koSpeed = 0;
    public int $rings = 0;
    public int $kills = 0;
    public int $deaths = 0;
    /** Flag mode: flags brought back to its base. */
    public int $captures = 0;
    /** Race: time it reached the sign (null: still running). */
    public ?float $finishTime = null;
    /** Last places it stood on (x, y), one per second, to come back to after a pit. */
    public array $safe = [];
    private float $safeClock = 0;
    /** Bots: when the jump button was pressed (seconds left to hold it). */
    public float $jumpHold = 0;
    /** Bots: where they come back to in a race after a knock-out (the start). */
    public array $start = [0, 0];

    /**
     * @param string $texture sheet of the Sonic ('sonic' or 'sonic-<colour>', tools/soniccolors.py)
     * @param array $rgb colour of its name and of its marks
     * @param int $team flag mode: 0 blue, 1 red; the other modes: its own index (everybody against everybody)
     */
    public function __construct(
        public Player $player,
        public bool $human,
        public string $name,
        public string $texture,
        public array $rgb,
        public int $team,
        public ?BotBrain $brain = null,
    ) {
    }

    public function isKnockedOut(): bool
    {
        return $this->koTimer > 0;
    }

    /** Jumping or rolling: touching an opponent hurts it. */
    public function isAttacking(): bool
    {
        $state = $this->player->getState();
        return $state === 'jump' || $state === 'roll';
    }

    /** Thrown back (hurt state of Player) and blinking for 2 seconds. */
    public function knockBack(float $fromX): void
    {
        $dir = $this->player->getX() < $fromX ? -1 : 1;
        $this->player->setGrounded(false);
        $this->player->setAcceleration(0, 0);
        $this->player->setVelocity($dir * 120, -4 * 80);
        $this->player->setState('hurt');
        $this->invulnerable = 2.0;
    }

    /** Bots: knocked out like Sonic's death (thrown up, then falls through the floor), then respawn. */
    public function knockOut(): void
    {
        $this->koTimer = 1.6;
        $this->koSpeed = -560;
        $this->rings = 0;
        $this->jumpHold = 0;
        $this->player->setAcceleration(0, 0);
        $this->player->setVelocity(0, 0);
        $this->player->setState('dead');
        $this->player->setAnimation('dead');
    }

    /** Knock-out flight: no collision, gravity only. Returns true when it is over. */
    public function updateKnockOut(float $deltaTime): bool
    {
        $this->koTimer -= $deltaTime;
        $this->koSpeed = min(600, $this->koSpeed + 1400 * $deltaTime);
        $this->player->setY($this->player->getY() + $this->koSpeed * $deltaTime);
        return $this->koTimer <= 0;
    }

    /** Puts it at ($x, $y), standing, blinking. */
    public function placeAt(float $x, float $y, bool $blink = true): void
    {
        $this->koTimer = 0;
        $this->player->setXY($x, $y);
        $this->player->setVelocity(0, 0);
        $this->player->setAcceleration(0, 0);
        $this->player->setGrounded(false);
        $this->player->setState('idle');
        $this->player->setAnimation('idleRight');
        $this->invulnerable = $blink ? 2.0 : 0;
        $this->safe = [];
        $this->safeClock = 0;
    }

    /** Remembers where it stands (every second, the last 5). */
    public function rememberSafePlace(float $deltaTime): void
    {
        $this->safeClock += $deltaTime;
        if ($this->safeClock < 1 || !$this->player->isGrounded()) return;
        $this->safeClock = 0;
        $this->safe[] = [$this->player->getX(), $this->player->getY()];
        if (count($this->safe) > 5) array_shift($this->safe);
    }

    /** A place it stood on a few seconds ago (before running into the pit), or null. */
    public function safePlace(): ?array
    {
        return $this->safe[0] ?? null;
    }

    /** Should it be drawn this frame (blinking while invulnerable)? */
    public function isVisible(): bool
    {
        return $this->invulnerable <= 0 || (int)floor($this->invulnerable * 15) % 2 === 0;
    }
}
