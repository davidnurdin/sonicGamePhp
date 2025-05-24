<?php

$_SERVER['PHP_SELF'] = __FILE__;
$_SERVER['SCRIPT_NAME'] = __FILE__;
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require __DIR__.'/vendor/autoload.php';

use SonicGame\Command\DefaultCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

if (!extension_loaded('sdl')) {
    die('La bibliothèque SDL n\'est pas chargée.');
}

// Création du container DI
$container = new ContainerBuilder();
$loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/config'));
$loader->load('services.yaml');
$container->compile();

// Récupération de la commande via le container
$command = $container->get(DefaultCommand::class);

// Création de l'application Symfony Console
$application = new Application();
$application->add($command);
$application->setDefaultCommand($command->getName(), true); // Set the default command

// TODO : detecter quand on viens de castor passer les $input+$output de celui ci
// delete arguments that are passed by castor
$input = new ArrayInput([
]);

// Exécution (avec Input/Output gérés automatiquement)
/** @var $application Application */
$application->doRun($input, new Symfony\Component\Console\Output\ConsoleOutput());
return $command ;
