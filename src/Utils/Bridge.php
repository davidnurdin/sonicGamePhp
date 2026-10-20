<?php

namespace SonicGame\Utils;

use PhpWasmNetwork\Network;

/**
 * Requests from the game to its web page (php-wasm: index.html reads them): PHP in the browser can't call
 * JavaScript, so it writes one JSON line per request in BRIDGE_FILE, which the page reads and empties a few
 * times a second. Nothing happens in the native game.
 *
 * Requests: {"t": "share", "url", "text"} (the phone's share sheet, else the clipboard), {"t": "back"} (back
 * to the site that opened the game, if any).
 */
final class Bridge
{
    public const BRIDGE_FILE = '/tmp/sonic-bridge';

    public static function available(): bool
    {
        return Network::isWasm();
    }

    public static function send(array $request): bool
    {
        if (!self::available()) return false;
        return @file_put_contents(self::BRIDGE_FILE, json_encode($request) . "\n", FILE_APPEND) !== false;
    }

    /** The game was opened from a room of the site (SONIC_ROOM): it goes back there after the match. */
    public static function fromSite(): bool
    {
        return self::available() && defined('SONIC_RETURN') && constant('SONIC_RETURN') === true;
    }
}
