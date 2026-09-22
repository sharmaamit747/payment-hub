<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();

            $table->foreignId('payment_attempt_id')
                ->nullable()
                ->constrained('payment_attempts')
                ->restrictOnDelete();

            $table->foreignId('payment_provider_id')
                ->constrained('payment_providers')
                ->restrictOnDelete();

            $table->uuid('transaction_uuid')->unique();

            $table->string('provider_transaction_id', 150)->nullable();

            $table->string('idempotency_key', 150)->nullable();

            $table->string('type', 40);

            $table->string('status', 40)->default('pending');

            $table->unsignedBigInteger('amount');

            $table->char('currency', 3);

            $table->string('failure_code', 100)->nullable();

            $table->string('failure_reason', 500)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            /*
             * Payment transaction history.
             */
            $table->index(
                ['payment_id', 'type', 'status'],
                'txn_payment_type_status_idx'
            );

            /*
             * Provider transaction lookup.
             */
            $table->index(
                ['payment_provider_id', 'status'],
                'txn_provider_status_idx'
            );

            /*
             * Reconciliation / operational queries.
             */
            $table->index(
                ['status', 'created_at'],
                'txn_status_created_idx'
            );

            /*
             * A provider transaction ID must not be processed
             * twice for the same provider.
             */
            $table->unique(
                ['payment_provider_id', 'provider_transaction_id'],
                'txn_provider_transaction_unique'
            );

            /*
             * Protect financial operations from duplicate requests.
             */
            $table->unique(
                ['payment_provider_id', 'idempotency_key'],
                'txn_provider_idempotency_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
