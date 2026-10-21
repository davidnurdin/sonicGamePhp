<?php

namespace SonicGame\Utils;

/**
 * The levels made in the site's editor, for the menu NIVEAUX DU SITE: a page of the list, then the level to play
 * (LevelManager::loadCustom). In the browser the page fetches them with the player's account (Bridge: {"t":
 * "levels", "page"}, {"t": "level", "id", "lt"}; his private levels too) and writes the answer in a file read
 * here; the native game asks the site itself, without an account (the public levels only).
 *
 * List: {page, pages, account (null: no account), levels: [{id, name, author, mine, public, lt}]} or {error: true}.
 */
final class SiteLevels
{
    private const LIST_ANSWER = '/tmp/sonic-levels.json';
    private const LEVEL_ANSWER = '/tmp/sonic-level.json';

    /** 'list' / 'level' while an answer is awaited. */
    private ?string $waiting = null;
    private ?array $answer = null;

    public function __construct(private string $site, private string $folder)
    {
    }

    public function requestPage(int $page): void
    {
        $this->waiting = 'list';
        $this->answer = null;
        if (Bridge::available()) {
            @unlink(self::LIST_ANSWER);
            Bridge::send(['t' => 'levels', 'page' => $page]);
            return;
        }
        $data = $this->http('/api/niveaux', json_encode(['page' => $page]));
        $this->answer = ['list', is_array($data) && isset($data['levels']) ? $data : ['error' => true]];
    }

    public function requestLevel(int $id, string $key): void
    {
        $this->waiting = 'level';
        $this->answer = null;
        if (Bridge::available()) {
            @unlink(self::LEVEL_ANSWER);
            Bridge::send(['t' => 'level', 'id' => $id, 'lt' => $key]);
            return;
        }
        $data = $this->http('/niveau/' . $id . '/jeu.json?lt=' . rawurlencode($key));
        $file = null;
        $tiles = is_array($data) ? base64_decode((string)($data['tiles'] ?? ''), true) : false;
        if ($tiles !== false && $tiles !== '' && (is_dir($this->folder) || @mkdir($this->folder, 0775, true))) {
            unset($data['tiles']);
            if (@file_put_contents($this->folder . '/tiles.png', $tiles) !== false
                && @file_put_contents($this->folder . '/level.json', json_encode($data)) !== false) {
                $file = $this->folder . '/level.json';
            }
        }
        $this->answer = ['level', $file];
    }

    /** The answer once it is there: ['list', data] or ['level', the level's file or null]; null meanwhile. */
    public function poll(): ?array
    {
        if ($this->waiting === null) return null;
        if ($this->answer === null && Bridge::available()) {
            $file = $this->waiting === 'list' ? self::LIST_ANSWER : self::LEVEL_ANSWER;
            if (!is_file($file)) return null;
            $data = json_decode((string)@file_get_contents($file), true);
            @unlink($file);
            $this->answer = $this->waiting === 'list'
                ? ['list', is_array($data) && isset($data['levels']) ? $data : ['error' => true]]
                : ['level', is_array($data) && !empty($data['ok']) && is_string($data['file'] ?? null) ? $data['file'] : null];
        }
        $answer = $this->answer;
        $this->waiting = null;
        $this->answer = null;
        return $answer;
    }

    /** The native game: a request to the site (POST when there is a body), its JSON answer or null. */
    private function http(string $path, ?string $body = null): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => $body === null ? 'GET' : 'POST',
            'header' => "Content-Type: text/plain\r\nUser-Agent: SonicPHP\r\n",
            'content' => $body ?? '',
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);
        $text = @file_get_contents(rtrim($this->site, '/') . $path, false, $context);
        $data = is_string($text) ? json_decode($text, true) : null;
        return is_array($data) ? $data : null;
    }
}
