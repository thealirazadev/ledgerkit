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

        Schema::connection($this->getConnection())->create('ledger_postings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('entry_id', 26);
            $table->unsignedBigInteger('account_id');
            $table->char('currency', 3);

            // Signed minor units, debit positive, so a balanced entry is
            // exactly SUM(amount) = 0 per currency.
            $table->bigInteger('amount');

            $table->timestamp('posted_at');
            $table->timestamp('created_at');

            $table->foreign('entry_id')
                ->references('id')
                ->on('ledger_entries')
                ->restrictOnDelete();

            $table->foreign(['account_id', 'currency'], 'ledger_postings_account_currency_foreign')
                ->references(['id', 'currency'])
                ->on('ledger_accounts')
                ->restrictOnDelete();

            $table->index(['account_id', 'id']);
            $table->index(['account_id', 'posted_at']);
        });

        DB::connection($this->getConnection())->statement(
            'ALTER TABLE ledger_postings ADD CONSTRAINT ledger_postings_amount_check CHECK (amount <> 0)'
        );
    }

    public function down(): void
    {
        $this->guardDriver();

        Schema::connection($this->getConnection())->dropIfExists('ledger_postings');
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
