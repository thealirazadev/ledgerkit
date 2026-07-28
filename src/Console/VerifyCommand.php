<?php

namespace LedgerKit\Console;

use Illuminate\Console\Command;

class VerifyCommand extends Command
{
    protected $signature = 'ledger:verify {--account= : Limit the snapshot pass to one account code}';

    protected $description = 'Re-prove ledger invariants from the stored postings';

    public function handle(): int
    {
        $this->components->error('ledger:verify is not implemented yet.');

        return self::FAILURE;
    }
}
