<?php

namespace LedgerKit\Tests;

use Dotenv\Dotenv;
use LedgerKit\LedgerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        self::loadEnvironmentFile();

        parent::setUp();

        $this->artisan('migrate:fresh')->run();
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [LedgerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = env('LEDGERKIT_TEST_DB', 'pgsql');

        $app['config']->set('database.default', $driver);

        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_PGSQL_HOST', '127.0.0.1'),
            'port' => (int) env('DB_PGSQL_PORT', 5432),
            'database' => env('DB_PGSQL_DATABASE', 'ledgerkit_test'),
            'username' => env('DB_PGSQL_USERNAME', 'ledgerkit'),
            'password' => env('DB_PGSQL_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        $app['config']->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => env('DB_MYSQL_HOST', '127.0.0.1'),
            'port' => (int) env('DB_MYSQL_PORT', 3306),
            'database' => env('DB_MYSQL_DATABASE', 'ledgerkit_test'),
            'username' => env('DB_MYSQL_USERNAME', 'ledgerkit'),
            'password' => env('DB_MYSQL_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);

        // Exists so the unsupported driver path has something to run against.
        $app['config']->set('database.connections.sqlite_unsupported', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('ledgerkit.connection', $driver);
    }

    private static function loadEnvironmentFile(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $loaded = true;
        $root = dirname(__DIR__);

        if (is_file($root.'/.env')) {
            // safeLoad: a malformed or unreadable file must not abort the suite.
            Dotenv::createImmutable($root)->safeLoad();
        }
    }
}
