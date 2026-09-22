<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_reconciliations', function (Blueprint $table) {
            $table->unique(
                ['payment_attempt_id', 'provider_transaction_id'],
                'reconciliation_attempt_provider_txn_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payment_reconciliations', function (Blueprint $table) {
            $table->dropUnique(
                'reconciliation_attempt_provider_txn_unique'
            );
        });
    }
};