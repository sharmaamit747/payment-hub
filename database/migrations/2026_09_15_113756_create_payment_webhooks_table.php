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
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_provider_id')
                ->constrained('payment_providers')
                ->restrictOnDelete();

            $table->foreignId('payment_id')
                ->nullable()
                ->constrained('payments')
                ->restrictOnDelete();

            $table->uuid('event_uuid')->unique();

            /*
             * Provider's unique webhook/event identifier.
             */
            $table->string('provider_event_id', 200)->nullable();

            $table->string('event_type', 100);

            $table->string('status', 40)->default('received');

            /*
             * Original provider payload.
             *
             * This must contain only data we are permitted
             * to retain and must never contain secrets such
             * as webhook signing secrets.
             */
            $table->json('payload');

            /*
             * Optional sanitized headers needed for debugging
             * or signature/reconciliation diagnostics.
             */
            $table->json('headers')->nullable();

            $table->string('signature', 500)->nullable();

            $table->unsignedInteger('attempts')->default(0);

            $table->timestamp('received_at')->nullable();

            $table->timestamp('processed_at')->nullable();

            $table->timestamp('failed_at')->nullable();

            $table->string('failure_reason', 500)->nullable();

            $table->timestamps();

            /*
             * Provider event lookup.
             */
            $table->index(
                ['payment_provider_id', 'event_type', 'status'],
                'webhook_provider_type_status_idx'
            );

            /*
             * Payment webhook history.
             */
            $table->index(
                ['payment_id', 'created_at'],
                'webhook_payment_created_idx'
            );

            /*
             * Worker/retry queries.
             */
            $table->index(
                ['status', 'created_at'],
                'webhook_status_created_idx'
            );

            /*
             * Prevent processing the same provider event twice.
             */
            $table->unique(
                ['payment_provider_id', 'provider_event_id'],
                'webhook_provider_event_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
    }
};
