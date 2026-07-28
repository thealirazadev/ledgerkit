<?php

namespace LedgerKit;

use Illuminate\Support\ServiceProvider;
use LedgerKit\Console\DemoCommand;
use LedgerKit\Console\SnapshotCommand;
use LedgerKit\Console\VerifyCommand;

class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ledgerkit.php', 'ledgerkit');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ledgerkit.php' => $this->app->configPath('ledgerkit.php'),
            ], 'ledgerkit-config');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                DemoCommand::class,
                SnapshotCommand::class,
                VerifyCommand::class,
            ]);
        }
    }
}
