<?php

use Illuminate\Support\Facades\Schema;
use LedgerKit\Exceptions\UnsupportedDriverException;

$tables = ['ledger_accounts', 'ledger_entries', 'ledger_postings', 'ledger_balance_snapshots'];

$migrations = [
    '0001_create_ledger_accounts_table.php',
    '0002_create_ledger_entries_table.php',
    '0003_create_ledger_postings_table.php',
    '0004_create_ledger_snapshots_table.php',
];

it('migrates every ledger table', function () use ($tables) {
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }
});

it('rolls back every ledger table', function () use ($tables) {
    $this->artisan('migrate:rollback')->run();

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse("table {$table} survived the rollback");
    }
});

it('refuses to migrate an unsupported driver', function (string $file) {
    config(['ledgerkit.connection' => 'sqlite_unsupported']);

    $migration = require dirname(__DIR__, 2).'/database/migrations/'.$file;

    expect(fn () => $migration->up())
        ->toThrow(UnsupportedDriverException::class);

    expect(Schema::connection('sqlite_unsupported')->hasTable('ledger_accounts'))->toBeFalse();
})->with($migrations);

it('reports the unsupported driver with a stable error code', function () {
    config(['ledgerkit.connection' => 'sqlite_unsupported']);

    $migration = require dirname(__DIR__, 2).'/database/migrations/0001_create_ledger_accounts_table.php';

    try {
        $migration->up();
        $this->fail('Expected sqlite to be refused.');
    } catch (UnsupportedDriverException $e) {
        expect($e->errorCode())->toBe('unsupported_driver')
            ->and($e->context())->toBe(['driver' => 'sqlite'])
            ->and($e->getMessage())->toContain('pgsql, mysql');
    }
});
