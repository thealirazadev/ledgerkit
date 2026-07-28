<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LedgerKit\Exceptions\UnsupportedDriverException;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('ledgerkit.connection');
    }

    public function up(): void
    {
        $this->guardDriver();

        Schema::connection($this->getConnection())->create('ledger_balance_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('account_id');

            // Inclusive boundary: the snapshot covers postings with id <= this.
            $table->unsignedBigInteger('last_posting_id');

            $table->bigInteger('balance');
            $table->unsignedBigInteger('posting_count');
            $table->timestamp('created_at');

            $table->foreign('account_id')
                ->references('id')
                ->on('ledger_accounts')
                ->restrictOnDelete();

            // Append only: makes a rebuild at the same boundary a no-op and
            // serves the latest-snapshot lookup.
            $table->unique(['account_id', 'last_posting_id']);
        });
    }

    public function down(): void
    {
        $this->guardDriver();

        Schema::connection($this->getConnection())->dropIfExists('ledger_balance_snapshots');
    }

    /**
     * @throws UnsupportedDriverException
     */
    private function guardDriver(): void
    {
        $driver = DB::connection($this->getConnection())->getDriverName();

        if (! in_array($driver, UnsupportedDriverException::SUPPORTED_DRIVERS, true)) {
            throw UnsupportedDriverException::forDriver($driver);
        }
    }
};
