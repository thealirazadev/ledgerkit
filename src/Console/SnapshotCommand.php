<?php

namespace LedgerKit\Console;

use Illuminate\Console\Command;

class SnapshotCommand extends Command
{
    protected $signature = 'ledger:snapshot {--account= : Build for one account code}
                            {--force : Ignore the configured min_tail}';

    protected $description = 'Append balance snapshots for qualifying accounts';

    public function handle(): int
    {
        $this->components->error('ledger:snapshot is not implemented yet.');

        return self::FAILURE;
    }
}
