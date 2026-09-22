<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_provider_id')
                ->constrained('payment_providers')
                ->restrictOnDelete();

            $table->foreignId('payment_id')
                ->nullable()
                ->constrained('payments')
                ->nullOnDelete();

            $table->foreignId('payment_attempt_id')
                ->nullable()
                ->constrained('payment_attempts')
                ->nullOnDelete();

            $table->uuid('reconciliation_uuid')->unique();

            $table->string('provider_transaction_id', 200)->nullable();

            $table->string('provider_payment_id', 200)->nullable();

            $table->string('provider_order_id', 200)->nullable();

            $table->string('local_status', 40)->nullable();

            $table->string('provider_status', 40)->nullable();

            $table->unsignedBigInteger('local_amount')->nullable();

            $table->unsignedBigInteger('provider_amount')->nullable();

            $table->char('local_currency', 3)->nullable();

            $table->char('provider_currency', 3)->nullable();

            $table->string('status', 30)->default('matched');

            $table->string('mismatch_type', 50)->nullable();

            $table->string('resolution_status', 30)
                ->default('pending');

            $table->json('local_data')->nullable();

            $table->json('provider_data')->nullable();

            $table->text('resolution_note')->nullable();

            $table->timestamp('detected_at')->nullable();

            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(
                ['payment_provider_id', 'status', 'created_at'],
                'reconciliation_provider_status_idx'
            );

            $table->index(
                ['resolution_status', 'created_at'],
                'reconciliation_resolution_idx'
            );

            $table->index(
                ['provider_transaction_id'],
                'reconciliation_provider_txn_idx'
            );

            $table->index(
                ['payment_id', 'created_at'],
                'reconciliation_payment_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliations');
    }
};