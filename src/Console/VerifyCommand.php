<?php

namespace LedgerKit\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LedgerKit\Exceptions\UnknownAccountException;

class VerifyCommand extends Command
{
    private const USAGE_ERROR = 2;

    protected $signature = 'ledger:verify {--account= : Limit the snapshot pass to one account code}';

    protected $description = 'Re-prove ledger invariants from the stored postings';

    public function handle(): int
    {
        $connection = DB::connection(config('ledgerkit.connection'));

        $this->line("connection: {$connection->getName()} ({$connection->getDatabaseName()})");

        $accountId = null;
        $code = $this->option('account');

        if ($code !== null) {
            $accountId = $connection->table('ledger_accounts')->where('code', $code)->value('id');

            if ($accountId === null) {
                $e = UnknownAccountException::forCode((string) $code);
                $this->getOutput()->getErrorStyle()->writeln("error [{$e->errorCode()}]: {$e->getMessage()}");

                return self::USAGE_ERROR;
            }
        }

        $entryViolations = $this->entryViolations($connection);
        $entriesChecked = $connection->table('ledger_entries')->count();
        $this->line(sprintf(
            'entries checked:   %6d .. %s (zero-sum, 2+ postings, posted_at agreement)',
            $entriesChecked,
            $entryViolations === [] ? 'ok' : 'FAIL'
        ));

        [$snapshotsChecked, $snapshotViolations] = $this->snapshotViolations($connection, $accountId);
        $this->line(sprintf(
            'snapshots checked: %6d .. %s (balance and count match full recomputation)',
            $snapshotsChecked,
            $snapshotViolations === [] ? 'ok' : 'FAIL'
        ));

        $violations = [...$entryViolations, ...$snapshotViolations];

        if ($violations === []) {
            Log::info('ledger.verify_passed', [
                'entries_checked' => $entriesChecked,
                'snapshots_checked' => $snapshotsChecked,
            ]);
            $this->line('verify: clean');

            return self::SUCCESS;
        }

        foreach ($violations as $violation) {
            $this->line($violation);
        }

        Log::error('ledger.verify_failed', ['violations' => $violations]);
        $this->line(sprintf('verify: %d violation(s)', count($violations)));

        return self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function entryViolations(ConnectionInterface $connection): array
    {
        $violations = [];

        $short = $connection->table('ledger_entries as e')
            ->leftJoin('ledger_postings as p', 'p.entry_id', '=', 'e.id')
            ->groupBy('e.id')
            ->havingRaw('COUNT(p.id) < 2')
            ->orderBy('e.id')
            ->selectRaw('e.id AS entry_id, COUNT(p.id) AS posting_count')
            ->get();

        foreach ($short as $row) {
            $violations[] = "FAIL entry {$row->entry_id} has {$row->posting_count} posting(s) (expected at least 2)";
        }

        $unbalanced = $connection->table('ledger_postings')
            ->groupBy('entry_id', 'currency')
            ->havingRaw('SUM(amount) <> 0')
            ->orderBy('entry_id')
            ->orderBy('currency')
            ->selectRaw('entry_id, currency, SUM(amount) AS total')
            ->get();

        foreach ($unbalanced as $row) {
            $violations[] = "FAIL entry {$row->entry_id} currency {$row->currency} sum {$row->total} (expected 0)";
        }

        $drifted = $connection->table('ledger_postings as p')
            ->join('ledger_entries as e', 'e.id', '=', 'p.entry_id')
            ->whereColumn('p.posted_at', '<>', 'e.posted_at')
            ->orderBy('p.id')
            ->get(['p.id as posting_id', 'p.entry_id']);

        foreach ($drifted as $row) {
            $violations[] = "FAIL posting {$row->posting_id} posted_at differs from entry {$row->entry_id}";
        }

        return $violations;
    }

    /**
     * Checks the latest snapshot of each account, the one balance reads start from.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function snapshotViolations(ConnectionInterface $connection, int|string|null $accountId): array
    {
        $latest = $connection->table('ledger_balance_snapshots')
            ->groupBy('account_id')
            ->selectRaw('account_id, MAX(last_posting_id) AS boundary');

        $snapshots = $connection->table('ledger_balance_snapshots as s')
            ->joinSub($latest, 'latest', function (JoinClause $join) {
                $join->on('latest.account_id', '=', 's.account_id')
                    ->on('latest.boundary', '=', 's.last_posting_id');
            })
            ->join('ledger_accounts as a', 'a.id', '=', 's.account_id')
            ->when($accountId !== null, fn ($query) => $query->where('s.account_id', $accountId))
            ->orderBy('s.id')
            ->get(['s.id', 's.account_id', 's.last_posting_id', 's.balance', 's.posting_count', 'a.code']);

        $violations = [];

        foreach ($snapshots as $snapshot) {
            $recomputed = $connection->table('ledger_postings')
                ->where('account_id', $snapshot->account_id)
                ->where('id', '<=', $snapshot->last_posting_id)
                ->selectRaw('COALESCE(SUM(amount), 0) AS balance, COUNT(*) AS posting_count')
                ->first();

            // Sums come back as numeric strings on both engines; compare exactly.
            if (bccomp((string) $snapshot->balance, (string) $recomputed->balance, 0) !== 0) {
                $violations[] = "FAIL snapshot {$snapshot->id} account {$snapshot->code} stored {$snapshot->balance} recomputed {$recomputed->balance}";
            }

            if ((int) $snapshot->posting_count !== (int) $recomputed->posting_count) {
                $violations[] = "FAIL snapshot {$snapshot->id} account {$snapshot->code} stored count {$snapshot->posting_count} recomputed {$recomputed->posting_count}";
            }
        }

        return [count($snapshots), $violations];
    }
}
