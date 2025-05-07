<?php

use Castor\Attribute\AsTask;

use function Castor\io;
use function Castor\capture;
use function Castor\app;


#[AsTask(description: 'Execute the project SF !')]
function run(): void
{
    include('./app.php');
}
app()->setDefaultCommand('run');

## ADD more helpers :)
