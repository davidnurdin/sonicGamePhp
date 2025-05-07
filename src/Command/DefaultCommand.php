<?php

namespace SonicGame\Command;

use SonicGame\Service\SampleClass;
use React\EventLoop\Loop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DefaultCommand extends Command
{
    protected static $defaultName = 'default';

    public function __construct(private SampleClass $sampleClass)
    {
        parent::__construct(self::$defaultName);
    }

    protected function configure(): void
    {

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Your command logic here
        $output->writeln(($this->sampleClass->sampleMethod()));

        $loop = Loop::get();

        $loop->addPeriodicTimer(1, function () use ($output) {
            $output->writeln('Timer tick');
        });

        $loop->run();

        return Command::SUCCESS;
    }
}
