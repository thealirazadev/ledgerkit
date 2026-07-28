<?php

namespace LedgerKit;

use Illuminate\Support\ServiceProvider;
use LedgerKit\Console\DemoCommand;
use LedgerKit\Console\SnapshotCommand;
use LedgerKit\Console\VerifyCommand;

class LedgerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DemoCommand::class,
                SnapshotCommand::class,
                VerifyCommand::class,
            ]);
        }
    }
}
