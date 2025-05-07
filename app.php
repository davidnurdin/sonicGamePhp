<?php

require __DIR__.'/vendor/autoload.php';

use SonicGame\Command\DefaultCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;

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

// delete arguments that are passed by castor
$input = new ArrayInput([
]);

// Exécution (avec Input/Output gérés automatiquement)
$application->run($input);
