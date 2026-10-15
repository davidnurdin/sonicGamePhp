<?php

namespace SonicGame\Save;

/**
 * Options of the title menu, kept in var/options.json: the ghost, the CRT filter, the speed (PAL / NTSC)
 * and the cheats (all off by default).
 */
class Options
{
    /** Cheats of the options > cheats page: key => label. */
    public const CHEATS = [
        'invincible' => 'INVINCIBLE',
        'lives' => 'VIES INFINIES',
        'continues' => 'CONTINUES INFINIS',
        'rings' => '50 ANNEAUX',
        'turbo' => 'VITESSE TURBO',
        'shield' => 'BOUCLIER PERMANENT',
        'emeralds' => '6 EMERAUDES',
    ];

    private array $values = ['ghost' => false, 'crt' => false, 'speed' => 'pal', 'cheats' => [], 'startLevel' => 1];

    public function __construct(private string $file)
    {
        $saved = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (is_array($saved)) {
            $this->values = array_merge($this->values, array_intersect_key($saved, $this->values));
        }
    }

    public function isGhostEnabled(): bool
    {
        return (bool)$this->values['ghost'];
    }

    public function setGhostEnabled(bool $on): void
    {
        $this->values['ghost'] = $on;
        $this->save();
    }

    /** CRT filter: scanlines and darker corners over the picture, like an old TV. */
    public function isCrtEnabled(): bool
    {
        return (bool)$this->values['crt'];
    }

    public function setCrtEnabled(bool $on): void
    {
        $this->values['crt'] = $on;
        $this->save();
    }

    /**
     * 'pal': the European Master System, 50 frames per second -> the whole game runs at 50/60 of the
     * speed of the Japanese / American one (same ROM, one frame of logic per frame); 'ntsc': 60 Hz.
     */
    public function getSpeed(): string
    {
        return $this->values['speed'] === 'ntsc' ? 'ntsc' : 'pal';
    }

    public function setSpeed(string $speed): void
    {
        $this->values['speed'] = $speed === 'ntsc' ? 'ntsc' : 'pal';
        $this->save();
    }

    /** Game time per real second: 50/60 in PAL, 1 in NTSC. */
    public function getTimeScale(): float
    {
        return $this->getSpeed() === 'pal' ? 50 / 60 : 1.0;
    }

    public function isCheatOn(string $cheat): bool
    {
        return !empty($this->values['cheats'][$cheat]);
    }

    public function toggleCheat(string $cheat): void
    {
        $this->values['cheats'][$cheat] = !$this->isCheatOn($cheat);
        $this->save();
    }

    /** @return array<string, bool> every cheat with its state */
    public function getCheats(): array
    {
        $cheats = [];
        foreach (array_keys(self::CHEATS) as $cheat) {
            $cheats[$cheat] = $this->isCheatOn($cheat);
        }
        return $cheats;
    }

    /** Cheat "start at": the act a new game starts with (1-18), 1 = normal game. */
    public function getStartLevel(): int
    {
        return max(1, min(18, (int)$this->values['startLevel']));
    }

    public function setStartLevel(int $level): void
    {
        $this->values['startLevel'] = max(1, min(18, $level));
        $this->save();
    }

    private function save(): void
    {
        @mkdir(dirname($this->file), 0777, true);
        @file_put_contents($this->file, json_encode($this->values, JSON_PRETTY_PRINT));
    }
}
