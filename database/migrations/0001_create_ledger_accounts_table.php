<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('ledgerkit.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('ledger_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique();
            $table->string('name', 255);
            $table->string('type', 16);
            $table->char('currency', 3);
            $table->timestamps();

            // Sole purpose: be the target of the composite foreign key from
            // ledger_postings, which makes "posting currency equals account
            // currency" a database guarantee on both engines with no trigger.
            $table->unique(['id', 'currency'], 'ledger_accounts_id_currency_unique');
        });

        $connection = DB::connection($this->getConnection());

        $connection->statement(
            "ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check
             CHECK (type IN ('asset', 'liability', 'income', 'expense', 'equity'))"
        );

        $connection->statement(match ($connection->getDriverName()) {
            'pgsql' => "ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_currency_check
                        CHECK (currency ~ '^[A-Z]{3}\$')",
            // MySQL string comparison is case insensitive by default, so the
            // uppercase half of the rule needs a binary comparison.
            'mysql' => "ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_currency_check
                        CHECK (currency REGEXP '^[A-Za-z]{3}\$'
                               AND CAST(currency AS BINARY) = CAST(UPPER(currency) AS BINARY))",
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('ledger_accounts');
    }
};
