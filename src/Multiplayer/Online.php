<?php

namespace SonicGame\Multiplayer;

use PhpWasmNetwork\Connection;
use PhpWasmNetwork\Event;
use PhpWasmNetwork\Network;

/**
 * Online multiplayer session (menu MULTIJOUEUR > EN LIGNE): the connection to the relay server
 * (davidnurdin/php-wasm-network: WebSocket, or WebRTC between browsers), the room, its players, the start
 * of a match. Game polls it every frame, in the menus as in the match.
 *
 * The host (the one who created the room, or the next one if he leaves) chooses the match and starts it;
 * during the match his Versus is the authority (see VersusNetwork). Messages: one byte of type, then the
 * payload (JSON, or binary for the frequent ones).
 */
final class Online
{
    public const HELLO = 1;      // json {name}: a newcomer introduces himself
    public const ROSTER = 2;     // json {names: {peer: name}}: the host's list of players
    public const START = 3;      // json: the match (VersusNetwork::startConfig)
    public const SNAPSHOT = 4;   // binary: host -> everybody, the state of the match
    public const STATE = 5;      // binary: player -> host, his Sonic
    public const EVENT = 6;      // json: reliable match events, both ways

    /** Default relay server (the page defines SONIC_NET_URL; native: env SONIC_NET_URL). */
    public const DEFAULT_URL = 'ws://localhost:8090/ws';

    public const MAX_HUMANS = 6;

    private Connection $net;
    /** 'connecting', 'lobby' (in a room), 'match', 'error', 'closed' */
    public string $status = 'connecting';
    public string $error = '';
    public ?string $room = null;
    public int $me = -1;
    public int $host = -1;
    /** @var array<int, string> peer id => name, everybody in the room (me included) */
    public array $names = [];
    /** @var array<int, string> peer id => 'webrtc' / 'relay' */
    public array $links = [];
    public ?float $pingMs = null;
    /** START received (clients) or sent (host): Game launches the match with it. */
    public ?array $start = null;
    /** Match messages for VersusNetwork: [type, from, payload], or ['leave', peer] / ['host', peer]. */
    public array $inbox = [];
    private float $lastPing = 0;

    /**
     * @param string|null $join code of the room to join (null: create one)
     */
    public function __construct(
        public readonly string $url,
        public readonly string $transport,
        private string $name,
        private ?string $join = null,
    ) {
        $this->net = Network::connect($url, ['transport' => $transport]);
    }

    public static function serverUrl(): string
    {
        if (defined('SONIC_NET_URL') && constant('SONIC_NET_URL') !== '') return (string)constant('SONIC_NET_URL');
        return getenv('SONIC_NET_URL') ?: self::DEFAULT_URL;
    }

    public static function isBrowser(): bool
    {
        return Network::isWasm();
    }

    public function isHost(): bool
    {
        return $this->me >= 0 && $this->me === $this->host;
    }

    /** Peer ids of the room, the host first, then by id. */
    public function peers(): array
    {
        $peers = array_keys($this->names);
        usort($peers, fn(int $a, int $b) => [$a !== $this->host, $a] <=> [$b !== $this->host, $b]);
        return $peers;
    }

    public function poll(): void
    {
        foreach ($this->net->poll() as $event) {
            switch ($event->type) {
                case Event::OPEN:
                    $this->join !== null ? $this->net->joinRoom($this->join) : $this->net->createRoom(self::MAX_HUMANS, ['game' => 'sonic']);
                    break;
                case Event::ROOM:
                    $info = $event->info();
                    if (($info['meta']['game'] ?? '') !== 'sonic') {
                        $this->fail('CE SALON N EST PAS UNE PARTIE DE SONIC');
                        break;
                    }
                    $this->room = $info['room'];
                    $this->me = (int)$info['peer'];
                    $this->host = (int)$info['host'];
                    $this->name = self::cleanName($this->name, $this->me);
                    $this->names = [$this->me => $this->name];
                    $this->status = 'lobby';
                    $this->sendJson(self::HELLO, ['name' => $this->name]);
                    break;
                case Event::PEER_JOIN:
                    $this->names[$event->from] ??= 'J' . ($event->from + 1);
                    break;
                case Event::PEER_LEAVE:
                    unset($this->names[$event->from], $this->links[$event->from]);
                    if ($this->status === 'match') $this->inbox[] = ['leave', $event->from];
                    if ($this->isHost()) $this->sendRoster();
                    break;
                case Event::HOST:
                    $this->host = $event->from;
                    if ($this->status === 'match') $this->inbox[] = ['host', $event->from];
                    if ($this->isHost()) $this->sendRoster();
                    break;
                case Event::PEER_TRANSPORT:
                    $this->links[$event->from] = $event->data;
                    break;
                case Event::PONG:
                    $this->pingMs = $event->info()['ms'] ?? null;
                    break;
                case Event::ERROR:
                    $info = $event->info();
                    $this->fail(match ($info['code'] ?? '') {
                        'no-room' => 'SALON INTROUVABLE',
                        'room-full' => 'SALON COMPLET',
                        'connection' => 'SERVEUR INJOIGNABLE',
                        default => strtoupper((string)($info['code'] ?? 'ERREUR')),
                    });
                    break;
                case Event::CLOSE:
                    if ($this->status !== 'error') $this->fail('CONNEXION PERDUE');
                    $this->status = $this->status === 'error' ? 'error' : 'closed';
                    break;
                case Event::MESSAGE:
                    $this->message($event->from, $event->data);
                    break;
            }
        }
        if ($this->status !== 'error' && $this->status !== 'closed' && microtime(true) - $this->lastPing > 2) {
            $this->lastPing = microtime(true);
            if ($this->net->state() === Connection::OPEN) $this->net->ping();
        }
    }

    private function message(int $from, string $data): void
    {
        if ($data === '') return;
        $type = ord($data[0]);
        $payload = substr($data, 1);
        switch ($type) {
            case self::HELLO:
                $json = json_decode($payload, true);
                $this->names[$from] = self::cleanName((string)($json['name'] ?? ''), $from);
                if ($this->isHost()) $this->sendRoster();
                break;
            case self::ROSTER:
                if ($from !== $this->host) break;
                $json = json_decode($payload, true);
                $names = [];
                foreach (($json['names'] ?? []) as $peer => $name) $names[(int)$peer] = self::cleanName((string)$name, (int)$peer);
                if ($names) $this->names = $names;
                break;
            case self::START:
                if ($from !== $this->host) break;
                $this->start = json_decode($payload, true) ?: null;
                if ($this->start) $this->status = 'match';
                break;
            default:
                if ($this->status === 'match') $this->inbox[] = [$type, $from, $payload];
        }
    }

    private function sendRoster(): void
    {
        $this->sendJson(self::ROSTER, ['names' => (object)$this->names]);
    }

    /** Host: the match starts for everybody (and for him: $this->start). */
    public function startMatch(array $config): void
    {
        $this->start = $config;
        $this->status = 'match';
        $this->inbox = [];
        $this->sendJson(self::START, $config);
    }

    /** Back to the room after a match. */
    public function endMatch(): void
    {
        $this->start = null;
        $this->inbox = [];
        if ($this->status === 'match') $this->status = 'lobby';
    }

    public function sendJson(int $type, array $data, ?int $to = null): void
    {
        $this->send($type, json_encode($data), $to);
    }

    public function send(int $type, string $payload, ?int $to = null, bool $reliable = true): void
    {
        $this->net->send(chr($type) . $payload, $to, $reliable);
    }

    public function close(): void
    {
        $this->net->close();
        $this->status = 'closed';
    }

    private function fail(string $message): void
    {
        $this->error = $message;
        $this->status = 'error';
    }

    /** A name the game's font can draw: A-Z 0-9 space, 8 characters. */
    public static function cleanName(string $name, int $peer): string
    {
        $name = substr(trim(preg_replace('/[^A-Z0-9 ]/', '', strtoupper($name))), 0, 8);
        return $name !== '' ? $name : 'J' . ($peer + 1);
    }
}
