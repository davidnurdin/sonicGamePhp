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
        // an exception or a fatal error closes the window: keep what happened in crash.log
        $log = dirname(__DIR__, 2) . '/crash.log';
        register_shutdown_function(function () use ($log) {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                @file_put_contents($log, date('c') . " fatal: {$error['message']} in {$error['file']}:{$error['line']}\n", FILE_APPEND);
            }
        });
        try {
            $this->game->run();
            // run the loop here (not in ReactPHP's shutdown function) so that its exceptions are caught
            Loop::get()->run();
        } catch (\Throwable $e) {
            @file_put_contents($log, date('c') . ' ' . $e . "\n\n", FILE_APPEND);
            throw $e;
        }
        return Command::SUCCESS;
    }
}
