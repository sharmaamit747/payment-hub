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
        Schema::create('payment_refunds', function (Blueprint $table) {
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

            $table->foreignId('payment_transaction_id')
                ->nullable()
                ->constrained('payment_transactions')
                ->restrictOnDelete();

            $table->uuid('refund_uuid')->unique();

            $table->string('provider_refund_id', 150)->nullable();

            $table->string('idempotency_key', 150);

            $table->unsignedBigInteger('amount');

            $table->char('currency', 3);

            $table->string('status', 40)->default('pending');

            $table->string('reason', 255)->nullable();

            $table->string('failure_code', 100)->nullable();

            $table->string('failure_reason', 500)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            /*
             * Refund history for a payment.
             */
            $table->index(
                ['payment_id', 'status'],
                'refund_payment_status_idx'
            );

            /*
             * Provider refund lookup.
             */
            $table->index(
                ['payment_provider_id', 'status'],
                'refund_provider_status_idx'
            );

            /*
             * Operational / reconciliation queries.
             */
            $table->index(
                ['status', 'created_at'],
                'refund_status_created_idx'
            );

            /*
             * Provider must never receive the same refund
             * operation twice.
             */
            $table->unique(
                ['payment_provider_id', 'provider_refund_id'],
                'refund_provider_refund_unique'
            );

            /*
             * Application-level idempotency.
             */
            $table->unique(
                ['payment_provider_id', 'idempotency_key'],
                'refund_provider_idempotency_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
