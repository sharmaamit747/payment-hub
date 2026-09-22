<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_provider_health', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_provider_id')
                ->unique()
                ->constrained('payment_providers')
                ->cascadeOnDelete();

            $table->string('state', 20)->default('closed');

            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->unsignedInteger('failure_threshold')->default(5);

            $table->unsignedInteger('recovery_timeout_seconds')->default(60);

            $table->timestamp('opened_at')->nullable();

            $table->timestamp('next_retry_at')->nullable();

            $table->timestamp('last_failure_at')->nullable();

            $table->timestamp('last_success_at')->nullable();

            $table->timestamps();

            $table->index(
                ['state', 'next_retry_at'],
                'provider_health_state_retry_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_health');
    }
};