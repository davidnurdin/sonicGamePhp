<?php

use Castor\Attribute\AsTask;

use function Castor\io;
use function Castor\app;
use function Castor\context;


// apt install libsdl2-image-dev
#[AsTask(description: 'Execute the project SF !')]
function run(): void
{
    $context = context();
    if (!extension_loaded('sdl')) {

        if (getenv('CASTOR_SDL_LAUNCHED')) {
            io()->error('SDL extension not loaded, please install it.');
            return;
        }

        $scriptCastor = realpath($_SERVER['argv'][0]);
        $phpPath = PHP_BINARY;
        io()->warning("No Sdl extension found with Castor, relaunch dynamic castor with sdl extension");
        // TODO : windows version
        $pathToRun = implode(' ', [ $phpPath, '-d', 'extension=sdl.so', '-d' , 'extension=sdl_image.so', $scriptCastor ] , ) ;
        Castor\run($pathToRun , context: $context->withEnvironment([
            'CASTOR_SDL_LAUNCHED' => '1',
        ])); ;
        return;
    }

    if (!extension_loaded('sdl_image')) {
        io()->error('SDL_image extension not loaded, please install it.');
        return;
    }


    io()->success('SDL extension loaded, launching the game...');
    include('./app.php');
    io()->success('Game Finished');
}
app()->setDefaultCommand('run');

## ADD more helpers :)
