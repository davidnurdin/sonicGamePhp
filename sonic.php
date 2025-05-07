<?php
require __DIR__ . '/vendor/autoload.php';

use SonicGame\Game;

if (!extension_loaded('sdl')) {
    die('La bibliothèque SDL n\'est pas chargée.');
}


echo "Welcome to Sonic Php Game !" . PHP_EOL;

$game = new Game();
$game->run();
