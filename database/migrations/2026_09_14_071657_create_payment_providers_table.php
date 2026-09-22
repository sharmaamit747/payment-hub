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
        Schema::create('payment_providers', function (Blueprint $table) {
            $table->id();
            /*
             * Internal unique identifier.
             *
             * Examples:
             * razorpay
             * stripe
             * paypal
             */
            $table->string('code', 50)->unique();
            /*
             * Human-readable name shown in admin panel.
             */
            $table->string('name', 100);
            /*
             * Whether admin has enabled this provider.
             */
            $table->boolean('is_active')->default(false);
            /*
             * Priority used by routing engine.
             * Lower number = higher priority.
             */
            $table->unsignedInteger('priority')->default(100);
            /*
             * Optional provider-level configuration.
             *
             * DO NOT store secrets here.
             */
            $table->json('config')->nullable();
            /*
             * Optimistic locking/version field.
             *
             * Useful when admins update provider configuration
             * concurrently.
             */
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->index(['is_active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_providers');
    }
};
