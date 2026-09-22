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
        Schema::create('payment_routing_rules', function (Blueprint $table) {
            $table->id();
            /*
             * Payment provider this rule points to.
             */
            $table->foreignId('payment_provider_id')
                ->constrained('payment_providers')
                ->restrictOnDelete();
            /*
             * Optional ISO country code.
             *
             * Example:
             * IN
             * US
             * GB
             *
             * NULL = all countries
             */
            $table->string('country_code', 2)->nullable();
            /*
             * ISO-4217 currency.
             *
             * Example:
             * INR
             * USD
             * EUR
             *
             * NULL = all currencies
             */
            $table->string('currency', 3)->nullable();
            /*
             * Payment method.
             *
             * Examples:
             * card
             * upi
             * netbanking
             * wallet
             *
             * NULL = all methods
             */
            $table->string('payment_method', 50)->nullable();
             /*
             * Minimum amount in smallest currency unit.
             *
             * NULL = no minimum.
             */
            $table->unsignedBigInteger('min_amount')->nullable();
            /*
             * Maximum amount in smallest currency unit.
             *
             * NULL = no maximum.
             */
            $table->unsignedBigInteger('max_amount')->nullable();
             /*
             * Rule priority.
             *
             * Lower number = higher priority.
             */
            $table->unsignedInteger('priority')->default(100);
            /*
             * Admin can enable/disable a routing rule.
             */
            $table->boolean('is_active')->default(true);
             /*
             * Optional human-readable description.
             */
            $table->string('description', 255)->nullable();

            $table->timestamps();

            /*
             * Primary query used by the routing engine.
             */
            $table->index([
                'is_active',
                'priority',
            ]);

            /*
             * Provider-specific filtering.
             */
            $table->index([
                'payment_provider_id',
                'is_active',
            ]);

            /*
             * Currency/country/payment-method filtering.
             */
            $table->index(
                [
                    'country_code',
                    'currency',
                    'payment_method',
                    'is_active',
                ],
                'routing_lookup_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_routing_rules');
    }
};
