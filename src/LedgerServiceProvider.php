<?php

namespace LedgerKit;

use Illuminate\Support\ServiceProvider;
use LedgerKit\Console\VerifyCommand;

class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ledgerkit.php', 'ledgerkit');

        $this->app->singleton(LedgerService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ledgerkit.php' => $this->app->configPath('ledgerkit.php'),
            ], 'ledgerkit-config');

            // ledger:demo and ledger:snapshot are specified in docs/api-contracts.md
            // and register once the posting path and SnapshotBuilder exist.
            $this->commands([
                VerifyCommand::class,
            ]);
        }
    }
}
