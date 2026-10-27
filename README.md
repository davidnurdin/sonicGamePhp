# Sonic PHP

**English** · [Français](README.fr.md)

A fan remake of **Sonic the Hedgehog (Master System)**, written entirely in PHP: about 21,000 lines, running at
60 fps natively on desktop (PHP + SDL2) and **in the browser** (PHP compiled to WebAssembly), on PC and mobile,
with **online multiplayer for up to 6 players**.

**▶ Play: https://sonic.mimichat.fr**

![6 Sonics fighting over the flag in Green Hill](docs/multiplayer.gif)

## Features

- **Single player**: the zones of the original game, best times, and a ghost of your record to race against.
- **Online multiplayer** for up to 6 players, in the browser or on desktop:
  - 4 modes: **race**, **flag**, **rings**, **deathmatch**;
  - acts 1 and 2 of the six zones (Green Hill, Bridge, Jungle, Labyrinth, Scrap Brain, Sky Base) and the eight
    bonus stages;
  - public rooms, or private rooms with a 4-letter code; empty slots are filled with bots;
  - chat in the room menus and in game (T or Enter).
- **Local versus** against bots.
- **Replays** of every game, best times and ghosts saved.
- **PC and mobile**: keyboard, or touch controls on phones.
- Display options: CRT filter, PAL / NTSC speed.

## Controls

| | |
|---|---|
| ← → | run |
| ↓ | roll |
| Space | jump |
| T / Enter | chat (online) |
| Esc | leave |

On a phone: the pad at the bottom left (or swipe anywhere) to run, tap or button A to jump.

## Run it natively (desktop)

Requirements: PHP 8.2+ with the SDL2 extensions (`sdl`, `sdl_image`, `sdl_ttf`, `sdl_mixer`), and Composer.

```bash
git clone --recurse-submodules https://github.com/davidnurdin/sonicGamePhp.git
cd sonicGamePhp

# SDL2 libraries (Debian / Ubuntu)
sudo apt install libsdl2-dev libsdl2-image-dev libsdl2-ttf-dev libsdl2-mixer-dev php-dev

# PHP extensions, from the submodules
for ext in php-sdl php-sdl-image php-sdl-ttf php-mixer; do
    (cd $ext && phpize && ./configure && make && sudo make install)
done

composer install
./start.sh
```

- `SONIC_PHP=php8.3 ./start.sh`: use another PHP binary (default `php8.2`).
- `SONIC_NET_URL=ws://localhost:8090/ws ./start.sh`: use another multiplayer server (default
  `wss://sonic.mimichat.fr/ws`).

The desktop version joins the same online rooms as the browsers.

## How it works

### The game

- Plain PHP, organised with Symfony components (DependencyInjection, Console, Config, Yaml).
- The game loop is a **ReactPHP** event loop (`src/Loop`, `src/Game.php`).
- Rendering, sound and input go through **SDL2** via PHP extensions
  ([php-sdl](https://github.com/Ponup/php-sdl), [php-sdl-image](https://github.com/kea/php-sdl-image),
  [php-sdl-ttf](https://github.com/Ponup/php-sdl-ttf), [php-sdl-mixer](https://github.com/kea/php-sdl-mixer)).
- Levels, tiles, collisions and objects are in `assets/levels`; graphics in `assets/tileset`; sound in
  `assets/mixer`.
- The scripts in `tools/` decode data from the original ROM: level art and palette cycles, object sprites, and the
  music and sound effects, rendered by running the original Z80 sound driver on an emulator and capturing its
  SN76489 (PSG) output.

```
src/
  Game.php          the game
  Loop/             ReactPHP game loop
  Scene/            scene, camera, HUD, tilesets, screens, replay player
  Entities/         player, enemies, objects, physics
  Level/            levels
  Multiplayer/      versus, bots, online (Online.php, VersusNetwork.php)
  Renderer/         SDL window, renderer, text, CRT filter
  InputManager/     keyboard and touchpad
  SoundManager/     music and sound effects
  Save/             options, best times, ghosts, replays, stats
```

### In the browser

The browser version is built by [php-wasm-sdl](https://github.com/davidnurdin/php-wasm-sdl): PHP 8.3 compiled to
WebAssembly with Emscripten, with the SDL2 extension. A thin C layer runs **one tick of the ReactPHP loop per
`requestAnimationFrame`**, and PHP is never shut down between frames, so the whole game stays in memory. SDL2 draws
with WebGL, plays sound through Web Audio, and saves go to IndexedDB.

### Online multiplayer

Networking is [php-wasm-network](https://github.com/davidnurdin/php-wasm-network), with the same PHP API in the
browser and natively.

- **The host is the referee**: its game runs the match rules (contacts, flag, rings, finish, timer, scores) and the
  bots. There is no game server.
- Each player **drives their own Sonic locally**: no input lag, even with a high ping.
- Players send their state 30 times/s, the host sends a snapshot of the match 20 times/s (unreliable messages);
  events such as hits or broken monitors are reliable.
- Other Sonics are shown **120 ms in the past with interpolation**, and the host does **lag compensation**.
- Browsers talk to each other over **WebRTC**; a relay written in PHP handles rooms and signalling, with a TURN
  server for strict networks. The native version always goes through the relay.

Full explanation (French): [the online multiplayer, explained](https://github.com/davidnurdin/php-wasm-sdl/blob/main/doc/multijoueur-en-ligne.md).

## Related projects

- [php-wasm-sdl](https://github.com/davidnurdin/php-wasm-sdl): PHP + SDL in WebAssembly, the browser version.
- [php-wasm-network](https://github.com/davidnurdin/php-wasm-network): networking for PHP (WebSocket + WebRTC), lobby
  / relay / signalling server.
- [sonicGameSite](https://github.com/davidnurdin/sonicGameSite): the website (rooms, stats, replays, editors).
- Write-up: [Running a 21k-line PHP game at 60 fps in the browser with WebAssembly](https://dev.to/mimi_chat_b3bf7a39a962a82/running-a-21k-line-php-game-at-60-fps-in-the-browser-with-webassembly-2cd3).

## Disclaimer

Sonic PHP is a free, non-commercial fan project. Sonic the Hedgehog, its characters, graphics and music are the
property of SEGA. This project is not affiliated with or endorsed by SEGA.
