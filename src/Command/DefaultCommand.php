<?php

namespace SonicGame\Command;

use SonicGame\Game;
use SonicGame\Service\SampleClass;
use React\EventLoop\Loop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DefaultCommand extends Command
{
    protected static $defaultName = 'default';

    public function __construct(public Game $game)
    {
        parent::__construct(self::$defaultName);
    }

    protected function configure(): void
    {

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->game->run();
        return Command::SUCCESS;
    }
}
