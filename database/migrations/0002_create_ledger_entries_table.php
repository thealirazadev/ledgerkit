<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('ledgerkit.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('ledger_entries', function (Blueprint $table) {
            $table->char('id', 26);
            $table->primary('id');
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->char('idempotency_fingerprint', 64)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('external_ref', 191)->nullable()->index();
            $table->string('actor', 191)->nullable();

            // Unique: one reversing entry per entry, race proof without a read.
            $table->char('reverses_entry_id', 26)->nullable()->unique();

            $table->timestamp('posted_at');
            $table->timestamp('created_at');

            $table->foreign('reverses_entry_id')
                ->references('id')
                ->on('ledger_entries')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('ledger_entries');
    }
};
