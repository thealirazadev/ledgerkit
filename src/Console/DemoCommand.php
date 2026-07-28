<?php

namespace LedgerKit\Console;

use Illuminate\Console\Command;

class DemoCommand extends Command
{
    protected $signature = 'ledger:demo {--yes : Skip the confirmation prompt}';

    protected $description = 'Run the scripted end to end ledger scenario against a scratch database';

    public function handle(): int
    {
        $this->components->error('ledger:demo is not implemented yet.');

        return self::FAILURE;
    }
}
