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
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();

            $table->foreignId('payment_provider_id')
                ->constrained('payment_providers')
                ->restrictOnDelete();

            $table->uuid('attempt_uuid')->unique();

            $table->string('provider_payment_id', 150)->nullable();
            $table->string('provider_order_id', 150)->nullable();

            $table->string('idempotency_key', 150)->nullable();

            $table->unsignedBigInteger('amount');

            $table->string('status', 40)->default('created');

            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();

            $table->string('failure_code', 100)->nullable();
            $table->string('failure_reason', 500)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            /*
             * Query indexes
             */
            $table->index(
                ['payment_id', 'status'],
                'attempt_payment_status_idx'
            );

            $table->index(
                ['payment_provider_id', 'status'],
                'attempt_provider_status_idx'
            );

            $table->index(
                ['status', 'created_at'],
                'attempt_status_created_idx'
            );

            /*
             * Provider transaction lookup.
             * provider_payment_id is nullable, which is intentional.
             */
            $table->unique(
                ['payment_provider_id', 'provider_payment_id'],
                'attempt_provider_payment_unique'
            );

            /*
             * Idempotency protection.
             */
            $table->unique(
                ['payment_provider_id', 'idempotency_key'],
                'attempt_provider_idempotency_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
