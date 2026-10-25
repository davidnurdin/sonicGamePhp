<?php

namespace SonicGame\Utils;

/**
 * Who plays online, for the menus (the page SALONS, the players online on the title screen): the site's list of the
 * public rooms (GET /salons.json, from the relay server). In the browser the page fetches it (Bridge: {"t": "rooms"})
 * and writes the answer in a file read here; the native game asks the site itself. Read again every few seconds
 * while a menu shows it (tick()).
 *
 * Data: {players (in the relay's rooms), ingame (games open), list: [{room, players, max, locked, names, mode, level,
 * site (the name of a level of the site), state ('lobby' / 'match')}]}, or null (not known yet, or the site out of reach).
 */
final class SiteRooms
{
    private const ANSWER = '/tmp/sonic-rooms.json';
    private const EVERY = 5.0;
    /** No answer after that long: asked again. */
    private const TIMEOUT = 10.0;

    private ?array $data = null;
    private float $wait = 0;
    private bool $waiting = false;
    private float $waited = 0;

    public function __construct(private string $site)
    {
    }

    public function data(): ?array
    {
        return $this->data;
    }

    /** A menu shows it: the answer when it came, a new request every EVERY seconds. */
    public function tick(float $deltaTime): void
    {
        if ($this->waiting) {
            $this->waited += $deltaTime;
            if (is_file(self::ANSWER)) {
                $this->take(json_decode((string)@file_get_contents(self::ANSWER), true));
                @unlink(self::ANSWER);
            } elseif ($this->waited > self::TIMEOUT) {
                $this->waiting = false;
            }
            return;
        }
        $this->wait -= $deltaTime;
        if ($this->wait > 0) return;
        $this->wait = self::EVERY;
        if (Bridge::available()) {
            @unlink(self::ANSWER);
            $this->waiting = Bridge::send(['t' => 'rooms']);
            $this->waited = 0;
            return;
        }
        // the native game: the site itself (short: the menu waits for it)
        $context = stream_context_create(['http' => ['header' => "User-Agent: SonicPHP\r\n", 'timeout' => 2, 'ignore_errors' => true]]);
        $text = @file_get_contents(rtrim($this->site, '/') . '/salons.json', false, $context);
        $this->take(is_string($text) ? json_decode($text, true) : null);
        // it stops the game a moment: less often; out of reach, even less
        $this->wait = $this->data === null ? 30.0 : 15.0;
    }

    private function take(mixed $data): void
    {
        $this->waiting = false;
        if (!is_array($data) || !is_array($data['list'] ?? null) || ($data['players'] ?? null) === null) {
            $this->data = null;
            return;
        }
        $list = [];
        foreach ($data['list'] as $room) {
            if (!is_array($room) || !preg_match('/^[A-Z]{4}$/', (string)($room['room'] ?? ''))) continue;
            $list[] = [
                'room' => (string)$room['room'],
                'players' => (int)($room['players'] ?? 0),
                'max' => (int)($room['max'] ?? 6),
                'names' => array_values(array_map('strval', (array)($room['names'] ?? []))),
                'mode' => (string)($room['mode'] ?? 'race'),
                'level' => (int)($room['level'] ?? 1),
                'site' => (string)($room['site'] ?? ''),
                'state' => ($room['state'] ?? '') === 'match' ? 'match' : 'lobby',
            ];
        }
        $this->data = ['players' => (int)$data['players'], 'ingame' => (int)($data['ingame'] ?? $data['players']), 'list' => $list];
    }
}
