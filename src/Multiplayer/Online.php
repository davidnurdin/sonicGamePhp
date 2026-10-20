<?php

namespace SonicGame\Multiplayer;

use PhpWasmNetwork\Connection;
use PhpWasmNetwork\Event;
use PhpWasmNetwork\Network;
use PhpWasmNetwork\Protocol;
use SonicGame\Utils\Lang;

/**
 * Online multiplayer session (menu MULTIJOUEUR > EN LIGNE): the connection to the relay server
 * (davidnurdin/php-wasm-network: WebSocket, or WebRTC between browsers), the room, its players, the start
 * of a match. Game polls it every frame, in the menus as in the match.
 *
 * The host (the one who created the room, or the next one if he leaves) chooses the match and starts it;
 * during the match his Versus is the authority (see VersusNetwork). Messages: one byte of type, then the
 * payload (JSON, or binary for the frequent ones).
 *
 * Watchers: the pages of the site (sonic.mimichat.fr/salon/CODE) follow the room as watchers of the relay
 * (ids from Protocol::FIRST_WATCHER, never players). They get HELLO, ROSTER, CHAT and START (sent to
 * Protocol::WATCHERS too), and send HELLO {name, site: true} and CHAT. The host also tells the relay what
 * the list of public rooms shows (setRoomInfo: names, mode, level, state).
 */
final class Online
{
    public const HELLO = 1;      // json {name, site?, re?}: a newcomer introduces himself (a watcher answers him: re)
    public const ROSTER = 2;     // json {names: {peer: name}, mode, level, state}: the host's list of players and his match
    public const START = 3;      // json: the match (VersusNetwork::startConfig)
    public const SNAPSHOT = 4;   // binary: host -> everybody, the state of the match
    public const STATE = 5;      // binary: player -> host, his Sonic
    public const EVENT = 6;      // json: reliable match events, both ways
    public const CHAT = 7;       // text: a line of the room's chat

    /**
     * Version of the messages of a match: in the meta of the rooms, a player with another version of the game
     * (an old page in the cache) can't join, and the public rooms only bring together the same versions.
     */
    public const VERSION = 4;
    private const META = ['game' => 'sonic', 'v' => self::VERSION];
    /** Chat: characters of a line (the game's font, the on-screen keyboard) and lines kept. */
    public const CHAT_LENGTH = 30;
    private const CHAT_LINES = 20;

    /** Relay server: the page defines SONIC_NET_URL (?net=), native: env SONIC_NET_URL (start.sh), else this one. */
    public const DEFAULT_URL = 'wss://sonic.mimichat.fr/ws';
    /** The site: the page of a room (share link), SONIC_SITE_URL to test another one. */
    public const SITE_URL = 'https://sonic.mimichat.fr';

    public const MAX_HUMANS = 6;

    private Connection $net;
    /** 'connecting', 'lobby' (in a room), 'match', 'error', 'closed' */
    public string $status = 'connecting';
    public string $error = '';
    public ?string $room = null;
    public int $me = -1;
    public int $host = -1;
    /** @var array<int, string> peer id => name, every player in the room (me included) */
    public array $names = [];
    /** @var array<int, string> watcher id => name: the pages of the site following the room ('' until its HELLO) */
    public array $watchers = [];
    /** @var array<int, string> peer id => 'webrtc' / 'relay' */
    public array $links = [];
    public ?float $pingMs = null;
    /** The match the host will start (Versus::MODES key, level): set by Game for the host, sent in the roster. */
    public string $mode = 'race';
    public int $level = 1;
    /** What the relay shows of the room (setRoomInfo), sent again only when it changes. */
    private string $roomInfo = '';
    /** A public room (strangers come in through SALON PUBLIC). */
    public bool $public = false;
    /**
     * @var array<int, array{0: int, 1: string, 2: string, 3: float}> chat lines [peer (-1: the game), name, text, time],
     *      oldest first; the game's lines are Lang keys with their values ("{name} EST ARRIVE" + name)
     */
    public array $chat = [];
    private float $lastChat = 0;
    /** START received (clients) or sent (host): Game launches the match with it. */
    public ?array $start = null;
    /** Match messages for VersusNetwork: [type, from, payload], or ['leave', peer] / ['host', peer]. */
    public array $inbox = [];
    private float $lastPing = 0;
    /** No answer from the server for this long: unreachable (a blocked address can stay "connecting"). */
    private const CONNECT_TIMEOUT = 8.0;
    private float $since;

    /**
     * @param string|null $join code of the room to join (null: create one)
     * @param bool $quick a public room with strangers (the server finds one with a free place, or makes one)
     */
    public function __construct(
        public readonly string $url,
        public readonly string $transport,
        private string $name,
        private ?string $join = null,
        private bool $quick = false,
    ) {
        $this->since = microtime(true);
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
                    match (true) {
                        $this->quick => $this->net->quickJoin(self::MAX_HUMANS, self::META),
                        $this->join !== null => $this->net->joinRoom($this->join),
                        default => $this->net->createRoom(self::MAX_HUMANS, self::META),
                    };
                    break;
                case Event::ROOM:
                    $info = $event->info();
                    if (($info['meta']['game'] ?? '') !== 'sonic') {
                        $this->fail('CE SALON N EST PAS UNE PARTIE DE SONIC');
                        break;
                    }
                    if (($info['meta']['v'] ?? 0) !== self::VERSION) {
                        $this->net->leaveRoom();
                        $this->fail('AUTRE VERSION  RECHARGE LA PAGE');
                        break;
                    }
                    $this->public = !empty($info['public']);
                    if ($this->public) $this->say(-1, '', 'SALON PUBLIC  DIS BONJOUR !');
                    $this->room = $info['room'];
                    $this->me = (int)$info['peer'];
                    $this->host = (int)$info['host'];
                    $this->name = self::cleanName($this->name, $this->me);
                    $this->names = [$this->me => $this->name];
                    $this->watchers = array_fill_keys(array_map('intval', $info['watchers'] ?? []), '');
                    $this->status = 'lobby';
                    $this->sendJson(self::HELLO, ['name' => $this->name], self::ALL);
                    if ($this->isHost()) $this->sendRoster();
                    break;
                case Event::PEER_JOIN:
                    $this->names[$event->from] ??= 'J' . ($event->from + 1);
                    break;
                case Event::PEER_LEAVE:
                    if ($event->from >= Protocol::FIRST_WATCHER) {
                        // a page of the site closed (or went to the game)
                        if (($this->watchers[$event->from] ?? '') !== '') $this->say(-1, '', '{name} A QUITTE LE SITE', $this->watchers[$event->from]);
                        unset($this->watchers[$event->from]);
                        if ($this->isHost()) $this->publish();
                        break;
                    }
                    if (isset($this->names[$event->from])) $this->say(-1, '', '{name} EST PARTI', $this->names[$event->from]);
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
                    // an old relay that doesn't know "info": nothing lost
                    if (($info['code'] ?? '') === 'bad-message') break;
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
        if ($this->status === 'connecting' && microtime(true) - $this->since > self::CONNECT_TIMEOUT) {
            $this->net->close();
            $this->fail('SERVEUR INJOIGNABLE');
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
        if ($from >= Protocol::FIRST_WATCHER) {
            // a page of the site: its name and its chat, nothing else
            if ($type === self::HELLO) {
                $json = json_decode($payload, true);
                $known = ($this->watchers[$from] ?? '') !== '';
                // a visitor without an account: no name, he only gets the roster
                $name = trim((string)($json['name'] ?? ''));
                $this->watchers[$from] = $name !== '' ? self::cleanName($name, $from) : '';
                if (!$known && $name !== '' && empty($json['re'])) $this->say(-1, '', '{name} EST SUR LE SITE', $this->watchers[$from]);
                if ($this->isHost()) $this->sendRoster();
            } elseif ($type === self::CHAT) {
                $text = self::cleanChat($payload);
                if ($text !== '' && isset($this->watchers[$from])) $this->say($from, $this->watchers[$from] ?: 'SITE', $text);
            }
            return;
        }
        switch ($type) {
            case self::HELLO:
                $json = json_decode($payload, true);
                $this->names[$from] = self::cleanName((string)($json['name'] ?? ''), $from);
                // the host: two players with the same name (the same account twice, a name kept by the browser)
                // are told apart, everybody gets it with the roster
                if ($this->isHost()) $this->names[$from] = $this->uniqueName($this->names[$from], $from);
                $this->say(-1, '', '{name} EST ARRIVE', $this->names[$from]);
                if ($this->isHost()) $this->sendRoster();
                // during a match: the host gives him the Sonic of a bot (VersusNetwork::peerJoined)
                if ($this->isHost() && $this->status === 'match') $this->inbox[] = ['join', $from];
                break;
            case self::ROSTER:
                if ($from !== $this->host) break;
                $json = json_decode($payload, true);
                $names = [];
                foreach (($json['names'] ?? []) as $peer => $name) $names[(int)$peer] = self::cleanName((string)$name, (int)$peer);
                if ($names) $this->names = $names;
                if (isset(Versus::MODES[$json['mode'] ?? ''])) $this->mode = $json['mode'];
                if (in_array((int)($json['level'] ?? 0), Versus::LEVELS, true)) $this->level = (int)$json['level'];
                break;
            case self::CHAT:
                $text = self::cleanChat($payload);
                if ($text !== '' && isset($this->names[$from])) $this->say($from, $this->names[$from], $text);
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
        $this->sendJson(self::ROSTER, ['names' => (object)$this->names, 'mode' => $this->mode, 'level' => $this->level,
            'state' => $this->status === 'match' ? 'match' : 'lobby'], self::ALL);
        $this->publish();
    }

    /** The host: what the relay's list of public rooms (and the site) shows of this room, when it changed. */
    private function publish(): void
    {
        if (!$this->isHost()) return;
        $info = ['names' => array_values(array_map(fn(int $peer) => $this->names[$peer], $this->peers())),
            'sites' => array_values(array_filter($this->watchers)), 'mode' => $this->mode, 'level' => $this->level,
            'state' => $this->status === 'match' ? 'match' : 'lobby', 'v' => self::VERSION];
        $json = json_encode($info);
        if ($json === $this->roomInfo) return;
        $this->roomInfo = $json;
        $this->net->setRoomInfo($info);
    }

    /** Host: the match starts for everybody (and for him: $this->start). */
    public function startMatch(array $config): void
    {
        $this->start = $config;
        $this->status = 'match';
        $this->inbox = [];
        $this->sendJson(self::START, $config, self::ALL);
        // a public room: nobody else comes in through SALON PUBLIC during the match
        if ($this->public) $this->net->lockRoom(true);
        $this->publish();
    }

    /** Back to the room after a match. */
    public function endMatch(): void
    {
        $this->start = null;
        $this->inbox = [];
        if ($this->status === 'match') $this->status = 'lobby';
        if ($this->public && $this->isHost()) $this->net->lockRoom(false);
        if ($this->isHost()) $this->sendRoster();
    }

    /** The host changed the match (EN LIGNE > MODE / ZONE): the others see it in the room. */
    public function setMatch(string $mode, int $level): void
    {
        $this->mode = $mode;
        $this->level = $level;
        if ($this->isHost()) $this->sendRoster();
    }

    /** The page of the room on the site (the share link). */
    public function shareUrl(): string
    {
        $site = defined('SONIC_SITE_URL') && constant('SONIC_SITE_URL') !== '' ? (string)constant('SONIC_SITE_URL') : self::SITE_URL;
        return rtrim($site, '/') . '/salon/' . $this->room;
    }

    /** A line of mine in the chat (one a second at most). */
    public function sendChat(string $text): void
    {
        $text = self::cleanChat($text);
        if ($text === '' || $this->status === 'error' || $this->status === 'closed' || microtime(true) - $this->lastChat < 1) return;
        $this->lastChat = microtime(true);
        $this->say($this->me, $this->name, $text);
        $this->send(self::CHAT, $text, self::ALL);
    }

    /** A line of the chat; the game's own lines ($peer -1) are Lang keys, {name} replaced when shown. */
    private function say(int $peer, string $name, string $text, string $value = ''): void
    {
        $this->chat[] = [$peer, $name, $text, microtime(true), $value];
        if (count($this->chat) > self::CHAT_LINES) array_shift($this->chat);
    }

    /** A line of the game in the chat ($text: a Lang key, {name} replaced by $value). */
    public function note(string $text, string $value = ''): void
    {
        $this->say(-1, '', $text, $value);
    }

    /** A chat line as shown: the game's lines translated. */
    public static function chatText(array $line): string
    {
        return $line[0] < 0 ? Lang::t($line[2], ['name' => $line[4] ?? '']) : $line[2];
    }

    /** What the game's font and keyboard can show: A-Z 0-9 space . - ! ?, CHAT_LENGTH characters. */
    public static function cleanChat(string $text): string
    {
        $text = preg_replace('/ +/', ' ', preg_replace('/[^A-Z0-9 .!?-]/', '', strtoupper($text)));
        return trim(substr(trim($text), 0, self::CHAT_LENGTH));
    }

    /** $to: a peer, null (every other player) or ALL (the players and the pages of the site). */
    public function sendJson(int $type, array $data, ?int $to = null): void
    {
        $this->send($type, json_encode($data), $to);
    }

    public const ALL = -1;

    public function send(int $type, string $payload, ?int $to = null, bool $reliable = true): void
    {
        if ($to === self::ALL) {
            $this->net->send(chr($type) . $payload, null, $reliable);
            if ($this->watchers) $this->net->send(chr($type) . $payload, Protocol::WATCHERS, $reliable);
            return;
        }
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

    /** $name, or NAME2, NAME3... (8 characters at most) when another player of the room has it. */
    private function uniqueName(string $name, int $peer): string
    {
        $taken = array_diff_key($this->names, [$peer => true]);
        for ($n = 2, $unique = $name; in_array($unique, $taken, true); $n++) {
            $unique = substr($name, 0, 8 - strlen((string)$n)) . $n;
        }
        return $unique;
    }

    /** A name the game's font can draw: A-Z 0-9 space, 8 characters. */
    public static function cleanName(string $name, int $peer): string
    {
        $name = substr(trim(preg_replace('/[^A-Z0-9 ]/', '', strtoupper($name))), 0, 8);
        return $name !== '' ? $name : 'J' . ($peer + 1);
    }
}
