<?php

namespace SonicGame\Utils;

/**
 * The levels made in the site's editor, for the menu NIVEAUX DU SITE: a page of the list, then the level to play
 * (LevelManager::loadCustom). In the browser the page fetches them with the player's account (Bridge: {"t":
 * "levels", "page"}, {"t": "level", "id", "lt"}; his private levels too) and writes the answer in a file read
 * here; the native game asks the site itself, without an account (the public levels only) unless SONIC_STATS_TOKEN.
 *
 * List: {page, pages, account (null: no account), levels: [{id, name, author, mine, public, lt, rating (the average of
 * the stars, or null), votes, plays, favourites, stars (the player's), fav}]} or {error: true}; sort: top (the best
 * rated), new, plays, fav (the most liked), mine, favourites (his). What the player thinks of one: vote().
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

    public function requestPage(int $page, string $sort = 'top'): void
    {
        $this->waiting = 'list';
        $this->answer = null;
        if (Bridge::available()) {
            @unlink(self::LIST_ANSWER);
            Bridge::send(['t' => 'levels', 'page' => $page, 'sort' => $sort]);
            return;
        }
        $data = $this->http('/api/niveaux', json_encode(['st' => self::token(), 'page' => $page, 'sort' => $sort]));
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
            // its own sprites: {sheet: PNG in base64} -> sprite-<sheet>.png, and their names in the level
            $sprites = [];
            foreach (is_array($data['sprites'] ?? null) ? $data['sprites'] : [] as $sheet => $png) {
                $png = is_string($png) ? base64_decode($png, true) : false;
                if (is_string($sheet) && preg_match('/^[a-z]{1,20}$/', $sheet) && $png
                    && @file_put_contents($this->folder . '/sprite-' . $sheet . '.png', $png) !== false) {
                    $sprites[] = $sheet;
                }
            }
            $data['sprites'] = $sprites;
            // its own music and sound effects (WAV in base64) -> music.wav, sfx-<index>.wav
            $music = is_string($data['music_wav'] ?? null) ? base64_decode($data['music_wav'], true) : false;
            $data['custom_music'] = $music && @file_put_contents($this->folder . '/music.wav', $music) !== false;
            $sfx = [];
            foreach (is_array($data['sfx_wav'] ?? null) ? $data['sfx_wav'] : [] as $index => $wav) {
                $wav = is_string($wav) ? base64_decode($wav, true) : false;
                if (is_numeric($index) && $wav && @file_put_contents($this->folder . '/sfx-' . (int)$index . '.wav', $wav) !== false) {
                    $sfx[] = (int)$index;
                }
            }
            $data['custom_sfx'] = $sfx;
            unset($data['music_wav'], $data['sfx_wav']);
            // the other pictures of its tiles (colour cycling, under water...): tiles<variant>.png, and their names
            $variants = [];
            foreach (is_array($data['tile_variants'] ?? null) ? $data['tile_variants'] : [] as $variant => $png) {
                $png = is_string($png) ? base64_decode($png, true) : false;
                if (is_string($variant) && preg_match('/^-[a-z0-9-]{1,20}$/', $variant) && $png
                    && @file_put_contents($this->folder . '/tiles' . $variant . '.png', $png) !== false) {
                    $variants[] = $variant;
                }
            }
            $data['tile_variants'] = $variants;
            // the replay of its record: its ghost (record.json.gz)
            $replay = $data['records'][0]['replay'] ?? null;
            $ghost = is_int($replay) ? $this->raw('/api/replay/' . $replay) : null;
            $data['record_ghost'] = $ghost !== null && @file_put_contents($this->folder . '/record.json.gz', $ghost) !== false;
            if (@file_put_contents($this->folder . '/tiles.png', $tiles) !== false
                && @file_put_contents($this->folder . '/level.json', json_encode($data)) !== false) {
                $file = $this->folder . '/level.json';
            }
        }
        $this->answer = ['level', $file];
    }

    /**
     * The stars (1 to 5, 0: none) the player gives a level and whether it is in his favourites: sent by the page with
     * his account (no answer awaited); the native game with SONIC_STATS_TOKEN only.
     */
    public function vote(int $id, int $stars, bool $favourite): void
    {
        $vote = ['id' => $id, 'fav' => $favourite];
        if ($stars > 0) $vote['stars'] = min(5, $stars);
        if (Bridge::available()) {
            Bridge::send(['t' => 'vote'] + $vote);
        } elseif (self::token() !== '') {
            $this->http('/api/niveaux/note', json_encode(['st' => self::token()] + $vote));
        }
    }

    /** The native game: the token of an account (SONIC_STATS_TOKEN, given by the site), or none. */
    private static function token(): string
    {
        return defined('SONIC_STATS_TOKEN') ? (string)constant('SONIC_STATS_TOKEN') : '';
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

    /** The native game: a file of the site (a replay...), or null. */
    private function raw(string $path): ?string
    {
        $context = stream_context_create(['http' => ['header' => "User-Agent: SonicPHP\r\n", 'timeout' => 8]]);
        $data = @file_get_contents(rtrim($this->site, '/') . $path, false, $context);
        return is_string($data) && $data !== '' && strlen($data) < 8 * 1024 * 1024 ? $data : null;
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
